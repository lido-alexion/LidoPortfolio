from __future__ import annotations

import hmac
import json
import os
from collections.abc import AsyncIterator

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.responses import StreamingResponse

from .runtime import Runtime
from .schemas import InferenceRequest

runtime = Runtime()
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
    return {"data": (await runtime.router.infer(request)).model_dump(mode="json")}


async def event_stream(request: InferenceRequest) -> AsyncIterator[str]:
    result = await runtime.router.infer(request)
    yield "event: message.start\ndata: {}\n\n"
    if result.text:
        yield "event: message.delta\ndata: "+json.dumps({"text": result.text})+"\n\n"
    yield "event: usage\ndata: "+json.dumps(result.usage.model_dump())+"\n\n"
    event = "error" if result.degraded else "message.completed"
    yield f"event: {event}\ndata: "+json.dumps(result.model_dump(mode="json"))+"\n\n"


@app.post("/internal/v1/inference/stream", dependencies=[Depends(require_service_key)])
async def inference_stream(request: InferenceRequest):
    return StreamingResponse(event_stream(request), media_type="text/event-stream", headers={"Cache-Control": "no-cache", "X-Accel-Buffering": "no"})
