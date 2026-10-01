from __future__ import annotations

import hmac
import json
import os
from collections.abc import AsyncIterator

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import StreamingResponse

from .configuration import ConfigurationUnavailable, LaravelConfigurationClient
from .retrieval import DocumentationRetriever
from .runtime import Runtime
from .schemas import InferenceRequest

runtime = Runtime()
configuration = LaravelConfigurationClient()
retriever = DocumentationRetriever(os.getenv("STOX_DOCUMENTATION_ROOT") or None)
app = FastAPI(title="StoX AI Runtime", version="0.1.0", docs_url=None, redoc_url=None)


def require_service_key(key: str | None = Header(default=None, alias="X-StoX-AI-Service-Key")) -> None:
    configured = os.getenv("STOX_AI_SERVICE_KEY", "")
    if not configured or not key or not hmac.compare_digest(key, configured):
        raise HTTPException(status_code=401, detail="AI runtime service authentication failed")


@app.get("/health")
async def health() -> dict:
    return {"status": "ok", "service": "stox-ai-runtime", "configuration_version": os.getenv("STOX_AI_CONFIG_VERSION", "0")}


@app.get("/internal/v1/capabilities", dependencies=[Depends(require_service_key)])
async def capabilities() -> dict:
    return {"data": runtime.capability_catalog()}


@app.post("/internal/v1/inference", dependencies=[Depends(require_service_key)])
async def inference(request: InferenceRequest):
    return {"success": True, "data": (await execute(request)).model_dump(mode="json")}


async def execute(request: InferenceRequest):
    try:
        projection = await configuration.get()
        runtime.apply_projection(projection)
    except ConfigurationUnavailable:
        from .schemas import InferenceResult, NormalizedError
        return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.CONFIGURATION_INVALID, error_message="AI configuration is unavailable")
    provenance = []
    if request.capability_id == "documentation_chat":
        question = str(request.input.get("question", ""))
        provenance = retriever.search(question)
        if not provenance:
            from .schemas import InferenceResult, NormalizedError
            return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, degraded=True, status="failure", error_code=NormalizedError.GROUNDING_INSUFFICIENT, error_message="No maintained StoX documentation supports this answer")
        request.input["grounding"] = provenance
    prompt = projection.get("prompts", {}).get(request.capability_id)
    if prompt:
        request.system_prompt = str(prompt.get("template", ""))
        request.user_prompt = str(request.input.get("question", request.user_prompt))
    result = await runtime.router.infer(request)
    result.provenance = provenance
    result.prompt = {"id": prompt.get("id"), "version": prompt.get("version")} if prompt else None
    await configuration.record({"request_id": request.request_id, "capability": request.capability_id, "status": result.status, "trace_id": request.trace_id, "routing_trace": [event.model_dump(mode="json") for event in result.routing_trace], "usage": result.usage.model_dump(), "selected_path": {"provider": result.provider, "model": result.model}, "prompt": result.prompt or {}, "context": request.context, "provenance": provenance, "error": {"code": str(result.error_code)} if result.error_code else {}, "latency_ms": None, "budget_scopes": ["overall", f"capability:{request.capability_id}"]})
    return result


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
