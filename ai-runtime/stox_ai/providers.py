from __future__ import annotations

from abc import ABC, abstractmethod
from collections.abc import AsyncIterator
from typing import Any

from .schemas import FailureCategory, InferenceRequest, ProviderResponse, StreamEvent


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
    """Provider-neutral contract placeholder; concrete transport is injected by deployment."""

    def __init__(self, provider: str, model: str, transport: Any):
        self.provider = provider
        self.model = model
        self.transport = transport

    async def infer(self, request: InferenceRequest) -> ProviderResponse:
        try:
            return await self.transport(request, self.provider, self.model)
        except ProviderFailure:
            raise
        except TimeoutError as error:
            raise ProviderFailure(FailureCategory.TIMEOUT, "Provider timed out") from error
        except Exception as error:
            raise ProviderFailure(FailureCategory.TRANSIENT, "Provider request failed") from error
