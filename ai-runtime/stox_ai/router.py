from __future__ import annotations

import asyncio
import time

import json
from uuid import uuid4
import httpx
from jsonschema import Draft202012Validator, ValidationError, SchemaError, FormatChecker

from .admission import admission

from .budgets import BudgetProjection
from .circuit_breaker import CircuitBreaker
from .registry import CapabilityRegistry
from .schemas import InferenceRequest, InferenceResult, NormalizedError, RoutingEvent
from .providers import ProviderFailure


class InferenceRouter:
    def __init__(self, registry: CapabilityRegistry, budgets: BudgetProjection | None = None, breakers: CircuitBreaker | None = None):
        self.registry = registry
        self.budgets = budgets or BudgetProjection()
        self.breakers = breakers or CircuitBreaker()
        self.admission = admission
        self.ledger = None

    async def infer(self, request: InferenceRequest) -> InferenceResult:
        try:
            capability = self.registry.get(request.capability_id)
        except KeyError:
            return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.CAPABILITY_DISABLED, error_message="Capability is unavailable")
        schemas = [schema for schema in (capability.output_schema, request.output_schema) if schema is not None]
        try:
            for schema in schemas:
                Draft202012Validator.check_schema(schema)
                def check_references(node):
                    if isinstance(node, dict):
                        for key, value in node.items():
                            if key in ("$ref", "$dynamicRef") and isinstance(value, str) and not value.startswith("#"):
                                raise SchemaError("Only local schema references are supported")
                            check_references(value)
                    elif isinstance(node, list):
                        for value in node:
                            check_references(value)
                check_references(schema)
        except SchemaError:
            return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, status="failure", degraded=True, error_code=NormalizedError.CONFIGURATION_INVALID)
        trace: list[RoutingEvent] = []
        async with self.admission.acquire(capability.capability_id, capability.max_concurrency):
            for path_id in capability.path_order:
                path = self.registry.paths[path_id]
                if not path.enabled:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason="disabled"))
                    continue
                eligible, reason = self.breakers.eligible(path_id)
                if not eligible:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason=reason))
                    continue
                scopes = list(dict.fromkeys(["overall", f"capability:{request.capability_id}", f"path:{path_id}", *path.budget_scopes, *([f"user:{request.context['user_id']}"] if request.context.get("user_id") is not None else [])]))
                eligible, reason = self.budgets.eligible(scopes)
                if not eligible:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason=reason))
                    continue
                started = time.perf_counter()
                trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="attempted"))
                try:
                    for attempt in range(2):
                        try:
                            reservation = None
                            usage = None
                            if self.ledger is not None:
                                reservation = str(uuid4())
                                try:
                                    admitted = await self.ledger.reserve({"id": reservation, "request_id": request.request_id, "capability_id": request.capability_id, "path_id": path_id, "provider": path.provider, "model": path.model, "user_id": request.context.get('user_id'), "input_token_bound": len((request.system_prompt + (request.user_prompt or str(request.input))).encode('utf-8')) + 1024})
                                    request.max_output_tokens = admitted['max_output_tokens']
                                except Exception as error:
                                    reason = 'budget_admission_unavailable'
                                    if isinstance(error, httpx.HTTPStatusError) and error.response.status_code == 422:
                                        reason = 'hard_budget_exhausted:reservation' if 'hard_budget_exhausted:' in error.response.text else 'budget_admission_configuration_invalid'
                                    raise ProviderFailure(reason, 'Laravel did not admit this attempt') from error
                            try:
                                try:
                                    async with asyncio.timeout(120):
                                        result = await path.adapter.infer(request)
                                except TimeoutError as error:
                                    raise ProviderFailure("timeout", "Provider execution timed out") from error
                                usage = result.usage.model_dump(exclude={'metadata_available'}) if result.usage.metadata_available else None
                            finally:
                                if reservation is not None:
                                    self.ledger.settlement({'id': reservation, 'usage': usage})
                            if schemas:
                                structured = result.structured if result.structured is not None else json.loads(result.text)
                                json.dumps(structured, allow_nan=False)
                                for schema in schemas:
                                    Draft202012Validator(schema, format_checker=FormatChecker()).validate(structured)
                                result.structured = structured
                            break
                        except (ValidationError, ValueError, TypeError):
                            trace[-1] = RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="failed", reason="structured_output_invalid", duration_ms=(time.perf_counter() - started) * 1000)
                            if attempt == 1:
                                raise ProviderFailure("structured_output_invalid", "Output does not satisfy the declared schema")
                            trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="attempted"))
                    self.breakers.success(path_id)
                    trace[-1] = RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="selected", duration_ms=(time.perf_counter() - started) * 1000)
                    return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, text=result.text, structured=result.structured, provider=path.provider, model=path.model, usage=result.usage, routing_trace=trace)
                except (ProviderFailure, ValidationError) as error:
                    category = str(getattr(error, "category", "malformed_output"))
                    admission_failure = category.startswith(("budget_admission_", "hard_budget_exhausted:"))
                    if not admission_failure:
                        self.breakers.failure(path_id)
                    trace[-1] = RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped" if admission_failure else "failed", reason=category, duration_ms=(time.perf_counter() - started) * 1000)
            error = NormalizedError.BUDGET_EXHAUSTED if trace and all((event.reason or "").startswith("hard_budget_exhausted:") for event in trace) else (NormalizedError.STRUCTURED_OUTPUT_INVALID if any(event.reason == "structured_output_invalid" for event in trace) else NormalizedError.PROVIDER_UNAVAILABLE)
            return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, routing_trace=trace, degraded=True, status="failure", error_code=error, error_message="No eligible inference path succeeded")
