from __future__ import annotations

from enum import StrEnum
from typing import Any, Literal

from pydantic import BaseModel, ConfigDict, Field


class ServiceClass(StrEnum):
    INTERACTIVE = "interactive"
    BACKGROUND = "background"
    CRITICAL_AGENTIC = "critical_agentic"


class FailureCategory(StrEnum):
    AUTHENTICATION = "authentication_or_configuration"
    TIMEOUT = "timeout"
    RATE_LIMIT = "rate_limit"
    TRANSIENT = "transient_provider_failure"
    NETWORK = "network_failure"
    MALFORMED_OUTPUT = "malformed_output"
    UNSUPPORTED = "unsupported_capability"
    CANCELLATION = "cancelled"


class NormalizedError(StrEnum):
    CAPABILITY_DISABLED = "capability_disabled"
    PROVIDER_UNAVAILABLE = "provider_unavailable"
    PROVIDER_TIMEOUT = "provider_timeout"
    BUDGET_EXHAUSTED = "budget_exhausted"
    STRUCTURED_OUTPUT_INVALID = "structured_output_invalid"
    GROUNDING_INSUFFICIENT = "grounding_insufficient"
    RUNTIME_UNAVAILABLE = "runtime_unavailable"
    CONFIGURATION_INVALID = "configuration_invalid"
    INTERNAL_ERROR = "internal_error"


class InferenceRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    request_id: str = Field(min_length=1, max_length=128)
    capability_id: str = Field(min_length=1, max_length=160)
    trace_id: str | None = Field(default=None, max_length=160)
    context: dict[str, Any] = Field(default_factory=dict)
    calling_feature: str = Field(default="unknown", max_length=160)
    service_class: ServiceClass = ServiceClass.INTERACTIVE
    system_prompt: str = Field(default="", max_length=100_000)
    user_prompt: str = Field(default="", max_length=100_000)
    input: dict[str, Any] = Field(default_factory=dict)
    output_schema: dict[str, Any] | None = None
    stream: bool = False
    idempotency_key: str | None = Field(default=None, max_length=160)


class Usage(BaseModel):
    input_tokens: int = Field(default=0, ge=0)
    output_tokens: int = Field(default=0, ge=0)
    estimated_cost: float = Field(default=0.0, ge=0)


class RoutingEvent(BaseModel):
    path_id: str
    provider: str
    model: str
    state: Literal["skipped", "attempted", "selected", "failed"]
    reason: str | None = None
    duration_ms: float | None = None


class InferenceResult(BaseModel):
    request_id: str
    capability_id: str
    text: str = ""
    structured: dict[str, Any] | None = None
    provider: str | None = None
    model: str | None = None
    usage: Usage = Field(default_factory=Usage)
    routing_trace: list[RoutingEvent] = Field(default_factory=list)
    degraded: bool = False
    error_code: str | None = None
    error_message: str | None = None
    prompt: dict[str, Any] | None = None
    provenance: list[dict[str, Any]] = Field(default_factory=list)
    status: Literal["success", "failure"] = "success"


class StreamEvent(BaseModel):
    type: Literal["message.start", "message.delta", "message.completed", "usage", "error"]
    data: dict[str, Any] = Field(default_factory=dict)


class ProviderResponse(BaseModel):
    text: str = ""
    structured: dict[str, Any] | None = None
    usage: Usage = Field(default_factory=Usage)
