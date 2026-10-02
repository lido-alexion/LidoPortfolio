from __future__ import annotations

import json

from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from typing import Any

import httpx

from .schemas import FailureCategory, InferenceRequest, ProviderResponse, StreamEvent, Usage


class ProviderFailure(Exception):
    def __init__(self, category: FailureCategory, message: str):
        super().__init__(message)
        self.category = category


class ProviderAdapter(ABC):
    provider: str
    model: str

    @abstractmethod
    async def infer(self, request: InferenceRequest) -> ProviderResponse:
        raise NotImplementedError

    async def stream(self, request: InferenceRequest) -> AsyncIterator[StreamEvent]:
        result = await self.infer(request)
        yield StreamEvent(type="message.start", data={})
        if result.text:
            yield StreamEvent(type="message.delta", data={"text": result.text})
        yield StreamEvent(type="usage", data=result.usage.model_dump())
        yield StreamEvent(type="message.completed", data={"structured": result.structured})


class DeterministicAdapter(ProviderAdapter):
    """A safe local adapter used for tests and controlled development only."""

    def __init__(self, provider: str, model: str, response: ProviderResponse | None = None, failure: ProviderFailure | None = None):
        self.provider = provider
        self.model = model
        self.response = response or ProviderResponse(text="Local adapter response")
        self.failure = failure

    async def infer(self, request: InferenceRequest) -> ProviderResponse:
        if self.failure:
            raise self.failure
        return self.response


class OpenAICompatibleAdapter(ProviderAdapter):
    """Small OpenAI-compatible adapter; credentials stay on the private service link."""

    def __init__(self, provider: str, model: str, transport: Any | None = None, config: dict[str, Any] | None = None):
        self.provider = provider
        self.model = model
        self.transport = transport
        self.config = config or {}

    async def infer(self, request: InferenceRequest) -> ProviderResponse:
        try:
            if self.transport:
                return await self.transport(request, self.provider, self.model)
            endpoint = str(self.config.get("endpoint", "")).rstrip("/")
            api_key = str(self.config.get("api_key", ""))
            if not endpoint or not api_key:
                raise ProviderFailure(FailureCategory.AUTHENTICATION, "Provider path is not configured")
            payload = {"model": self.model, "messages": [{"role": "system", "content": request.system_prompt}, {"role": "user", "content": request.user_prompt or str(request.input)}], "stream": False, "max_tokens": request.max_output_tokens}
            async with httpx.AsyncClient(timeout=float(self.config.get("timeout_seconds", 20))) as client:
                if request.stream and self.config.get("streaming", False):
                    payload["stream"] = True
                    payload["stream_options"] = {"include_usage": True}
                    text = ""
                    usage = {}
                    async with client.stream("POST", f"{endpoint}/chat/completions", headers={"Authorization": f"Bearer {api_key}"}, json=payload) as streamed:
                        if streamed.status_code == 429:
                            raise ProviderFailure(FailureCategory.RATE_LIMIT, "Provider rate limit")
                        streamed.raise_for_status()
                        finished = False
                        async for line in streamed.aiter_lines():
                            if not line.startswith("data:"):
                                continue
                            data = line[5:].strip()
                            if data == "[DONE]":
                                finished = True
                                break
                            event = json.loads(data)
                            choices = event.get("choices") or []
                            if choices:
                                text += (choices[0].get("delta") or {}).get("content") or ""
                            if event.get("usage"):
                                usage = event["usage"]
                            if len(text) > 100_000:
                                raise ProviderFailure(FailureCategory.MALFORMED_OUTPUT, "Response too large")
                        if not finished:
                            raise ProviderFailure(FailureCategory.TRANSIENT, "Provider stream interrupted")
                    # Buffer until the documentation contract validates; unvalidated
                    # model tokens must never escape as browser answer deltas.
                    return ProviderResponse(text=text, usage=Usage(metadata_available="prompt_tokens" in usage and "completion_tokens" in usage, input_tokens=int(usage.get("prompt_tokens", 0)), output_tokens=int(usage.get("completion_tokens", 0)), estimated_cost=float(usage.get("estimated_cost", 0))))
                response = await client.post(f"{endpoint}/chat/completions", headers={"Authorization": f"Bearer {api_key}"}, json=payload)
                if response.status_code == 429:
                    raise ProviderFailure(FailureCategory.RATE_LIMIT, "Provider rate limit")
                response.raise_for_status()
                body = response.json()
            usage = body.get("usage", {})
            return ProviderResponse(text=str(((body.get("choices") or [{}])[0].get("message") or {}).get("content", "")), usage=Usage(metadata_available="prompt_tokens" in usage and "completion_tokens" in usage, input_tokens=int(usage.get("prompt_tokens", 0)), output_tokens=int(usage.get("completion_tokens", 0)), estimated_cost=float(usage.get("estimated_cost", 0))))
        except ProviderFailure:
            raise
        except TimeoutError as error:
            raise ProviderFailure(FailureCategory.TIMEOUT, "Provider timed out") from error
        except Exception as error:
            raise ProviderFailure(FailureCategory.TRANSIENT, "Provider request failed") from error
