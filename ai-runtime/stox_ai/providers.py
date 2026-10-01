from __future__ import annotations

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
            payload = {"model": self.model, "messages": [{"role": "system", "content": request.system_prompt}, {"role": "user", "content": request.user_prompt or str(request.input)}], "stream": False}
            async with httpx.AsyncClient(timeout=float(self.config.get("timeout_seconds", 20))) as client:
                response = await client.post(f"{endpoint}/chat/completions", headers={"Authorization": f"Bearer {api_key}"}, json=payload)
                if response.status_code == 429:
                    raise ProviderFailure(FailureCategory.RATE_LIMIT, "Provider rate limit")
                response.raise_for_status()
                body = response.json()
            usage = body.get("usage", {})
            return ProviderResponse(text=str(((body.get("choices") or [{}])[0].get("message") or {}).get("content", "")), usage=Usage(input_tokens=int(usage.get("prompt_tokens", 0)), output_tokens=int(usage.get("completion_tokens", 0)), estimated_cost=float(usage.get("estimated_cost", 0))))
        except ProviderFailure:
            raise
        except TimeoutError as error:
            raise ProviderFailure(FailureCategory.TIMEOUT, "Provider timed out") from error
        except Exception as error:
            raise ProviderFailure(FailureCategory.TRANSIENT, "Provider request failed") from error
