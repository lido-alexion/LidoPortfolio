from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager, suppress
import hmac
import json
import os
import re
from collections.abc import AsyncIterator

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import StreamingResponse

from .configuration import ConfigurationUnavailable, LaravelConfigurationClient
from .retrieval import DocumentationRetriever
from .runtime import Runtime
from .schemas import InferenceRequest, InferenceResult, NormalizedError

runtime = Runtime()
configuration = LaravelConfigurationClient()
retriever = DocumentationRetriever(os.getenv("STOX_DOCUMENTATION_ROOT") or None)
@asynccontextmanager
async def lifespan(app):
    async def deliver():
        while True:
            await configuration.outbox.drain(configuration.send)
            await asyncio.sleep(1)
    worker = asyncio.create_task(deliver())
    try:
        yield
    finally:
        worker.cancel()
        with suppress(asyncio.CancelledError):
            await worker


app = FastAPI(lifespan=lifespan, title="StoX AI Runtime", version="0.1.0", docs_url=None, redoc_url=None)


def require_service_key(key: str | None = Header(default=None, alias="X-StoX-AI-Service-Key")) -> None:
    configured = os.getenv("STOX_AI_SERVICE_KEY", "")
    if not configured or not key or not hmac.compare_digest(key, configured):
        raise HTTPException(status_code=401, detail="AI runtime service authentication failed")


@app.get("/health")
async def health() -> dict:
    return {"status": "ok", "service": "stox-ai-runtime", "configuration_version": os.getenv("STOX_AI_CONFIG_VERSION", "0"), "delivery": configuration.outbox.status()}


@app.get("/internal/v1/capabilities", dependencies=[Depends(require_service_key)])
async def capabilities() -> dict:
    return {"data": runtime.capability_catalog()}


@app.post("/internal/v1/inference", dependencies=[Depends(require_service_key)])
async def inference(request: InferenceRequest):
    return {"success": True, "data": (await execute(request)).model_dump(mode="json")}


async def record_result(request: InferenceRequest, result: InferenceResult, paths=None):
    paths = paths or {}
    budget_scopes = ["overall", f"capability:{request.capability_id}"]
    if request.context.get("user_id") is not None:
        budget_scopes.append(f"user:{request.context['user_id']}")
    for event in result.routing_trace:
        if event.state == "selected":
            budget_scopes.extend([f"path:{event.path_id}", *paths[event.path_id].budget_scopes])
    await configuration.record({"request_id": request.request_id, "capability": request.capability_id, "status": result.status, "trace_id": request.trace_id, "routing_trace": [event.model_dump(mode="json") for event in result.routing_trace], "usage": result.usage.model_dump(), "selected_path": {"provider": result.provider, "model": result.model}, "prompt": result.prompt or {}, "context": request.context, "provenance": result.provenance, "error": {"code": str(result.error_code)} if result.error_code else {}, "latency_ms": None, "budget_scopes": list(dict.fromkeys(budget_scopes))})
    return result

async def execute(request: InferenceRequest):
    try:
        projection = await configuration.get()
        execution = Runtime()
        execution.breakers = runtime.breakers
        execution.apply_projection(projection)
        execution.router.ledger = configuration
        runtime.registry = execution.registry  # Catalog only; request execution stays isolated.
    except ConfigurationUnavailable:
        return await record_result(request, InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.CONFIGURATION_INVALID, error_message="AI configuration is unavailable"))
    provenance = []
    if request.capability_id == "documentation_chat":
        question = str(request.input.get("question", ""))
        if re.search(r"^(?:please\s+)?(?:approve|reject|place|execute|cancel|buy|sell)\b|\b(?:guaranteed? profit|investment advice)\b", question.strip(), re.I):
            return await record_result(request, InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code="read_only_scope", error_message="The assistant provides read-only StoX documentation help"))
        history = request.input.get("conversation", [])[-6:]
        query = question
        if len(question.split()) < 9:
            query += " " + " ".join(str(turn.get("question", "")) for turn in history[-2:])
        page = request.input.get("page_context") or {}
        query += " " + str(page.get("topic", ""))
        provenance = retriever.search(query)
        if not provenance:
            return await record_result(request, InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.GROUNDING_INSUFFICIENT, error_message="No maintained StoX documentation supports this answer"))
        request.input["grounding"] = provenance
    prompt = projection.get("prompts", {}).get(request.capability_id)
    if request.capability_id in {"agent_planning", "agent_synthesis"} and not prompt:
        return await record_result(request, InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.CONFIGURATION_INVALID))
    if prompt:
        request.system_prompt = str(prompt.get("template", ""))
        request.user_prompt = str(request.input.get("question", request.user_prompt))
    if request.capability_id == "documentation_chat":
        if not prompt:
            return await record_result(request, InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.CONFIGURATION_INVALID))
        # A deterministic extractive contract prevents unsupported prose from being
        # accepted merely because a model supplied a valid citation identifier.
        request.user_prompt = json.dumps({"question": question, "evidence": provenance,
            "page_context": request.input.get("page_context", {}),
            "conversation": request.input.get("conversation", []),
            "response_contract": "Return JSON with an extracts array. Each entry must contain source_id and quote, an exact nonempty excerpt from that source snippet. No other answer text is accepted. Return an empty extracts array if evidence is insufficient."})
    result = await execution.router.infer(request)
    if request.capability_id == "documentation_chat" and result.status == "success":
        try:
            extracts = json.loads(result.text)["extracts"]
            sources = {source["source_id"]: source for source in provenance}
            if not isinstance(extracts, list) or not 1 <= len(extracts) <= 5:
                raise ValueError("Missing evidence")
            for extract in extracts:
                quote = extract["quote"]
                if not isinstance(quote, str) or len(quote.strip()) < 20 or quote not in sources[extract["source_id"]]["snippet"]:
                    raise ValueError("Unsupported excerpt")
            result.text = "\n\n".join(extract["quote"] for extract in extracts)
            provenance = list({extract["source_id"]: sources[extract["source_id"]] for extract in extracts}.values())
        except (ValueError, KeyError, TypeError):
            result.text = ""
            result.structured = None
            result.status = "failure"
            result.degraded = True
            result.error_code = "grounding_insufficient"
            result.error_message = "Maintained StoX documentation does not support an answer"

    result.provenance = provenance
    result.prompt = {"id": prompt.get("id"), "version": prompt.get("version")} if prompt else None
    return await record_result(request, result, execution.registry.paths)


async def event_stream(request: InferenceRequest) -> AsyncIterator[str]:
    result = await execute(request)
    yield "event: message.start\ndata: {}\n\n"
    if result.text:
        yield "event: message.delta\ndata: "+json.dumps({"text": result.text})+"\n\n"
    yield "event: usage\ndata: "+json.dumps(result.usage.model_dump())+"\n\n"
    event = "error" if result.degraded else "message.completed"
    yield f"event: {event}\ndata: "+json.dumps(result.model_dump(mode="json"))+"\n\n"


@app.post("/internal/v1/inference/stream", dependencies=[Depends(require_service_key)])
async def inference_stream(request: InferenceRequest):
    return StreamingResponse(event_stream(request), media_type="text/event-stream", headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"})


from .agent_contracts import InvestigationRequest
from .agent import investigate


@app.post("/internal/v1/agent/investigate", dependencies=[Depends(require_service_key)])
async def agent_investigate(request: InvestigationRequest):
    try:
        return {"success": True, "data": await investigate(request, execute)}
    except Exception:
        # Laravel records failed/interrupted run state; no raw model output crosses this boundary.
        raise HTTPException(status_code=503, detail="agent_run_unavailable")
