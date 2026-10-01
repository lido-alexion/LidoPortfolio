from __future__ import annotations

import asyncio
import time
from collections import defaultdict

from pydantic import TypeAdapter, ValidationError

from .budgets import BudgetLedger
from .circuit_breaker import CircuitBreaker
from .registry import CapabilityRegistry
from .schemas import InferenceRequest, InferenceResult, RoutingEvent, Usage
from .providers import ProviderFailure


class InferenceRouter:
    def __init__(self, registry: CapabilityRegistry, budgets: BudgetLedger | None = None, breakers: CircuitBreaker | None = None):
        self.registry = registry
        self.budgets = budgets or BudgetLedger()
        self.breakers = breakers or CircuitBreaker()
        self.semaphores: dict[str, asyncio.Semaphore] = defaultdict(lambda: asyncio.Semaphore(4))

    async def infer(self, request: InferenceRequest) -> InferenceResult:
        capability = self.registry.get(request.capability_id)
        semaphore = self.semaphores[capability.capability_id]
        if semaphore._value > capability.max_concurrency:
            self.semaphores[capability.capability_id] = semaphore = asyncio.Semaphore(capability.max_concurrency)
        trace: list[RoutingEvent] = []
        async with semaphore:
            for path_id in capability.path_order:
                path = self.registry.paths[path_id]
                if not path.enabled:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason="disabled"))
                    continue
                eligible, reason = self.breakers.eligible(path_id)
                if not eligible:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason=reason))
                    continue
                eligible, reason = self.budgets.eligible(list(path.budget_scopes))
                if not eligible:
                    trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="skipped", reason=reason))
                    continue
                started = time.perf_counter()
                trace.append(RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="attempted"))
                try:
                    result = await path.adapter.infer(request)
                    if capability.structured_output and request.output_schema:
                        result.structured = TypeAdapter(dict).validate_python(result.structured or {})
                    self.breakers.success(path_id)
                    self.budgets.record(list(path.budget_scopes), result.usage.estimated_cost)
                    trace[-1] = RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="selected", duration_ms=(time.perf_counter() - started) * 1000)
                    return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, text=result.text, structured=result.structured, provider=path.provider, model=path.model, usage=result.usage, routing_trace=trace)
                except (ProviderFailure, ValidationError) as error:
                    self.breakers.failure(path_id)
                    category = getattr(error, "category", "malformed_output")
                    trace[-1] = RoutingEvent(path_id=path_id, provider=path.provider, model=path.model, state="failed", reason=str(category), duration_ms=(time.perf_counter() - started) * 1000)
            return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, routing_trace=trace, degraded=True, error_code="AI_RUNTIME_UNAVAILABLE", error_message="No eligible inference path succeeded")
