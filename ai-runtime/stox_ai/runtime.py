from __future__ import annotations

import os

from .budgets import BudgetLedger
from .circuit_breaker import CircuitBreaker
from .providers import DeterministicAdapter
from .prompts import PromptRegistry
from .registry import Capability, CapabilityRegistry, ProviderPath
from .router import InferenceRouter
from .schemas import ProviderResponse


class Runtime:
    def __init__(self) -> None:
        self.registry = CapabilityRegistry()
        self.budgets = BudgetLedger()
        self.breakers = CircuitBreaker(
            failure_threshold=max(1, int(os.getenv("STOX_AI_CIRCUIT_FAILURE_THRESHOLD", "3"))),
            cooldown_seconds=max(1, int(os.getenv("STOX_AI_CIRCUIT_COOLDOWN_SECONDS", "60"))),
        )
        self.prompts = PromptRegistry()
        if os.getenv("STOX_AI_ENABLE_LOCAL_ADAPTER", "false").lower() == "true":
            self.registry.register_path(ProviderPath("local-default", "local", "deterministic", DeterministicAdapter("local", "deterministic", ProviderResponse(text="Local development adapter")), budget_scopes=("overall",)))
            self.registry.register(Capability("runtime.healthcheck", "platform", ("local-default",), streaming=True))
        self.router = InferenceRouter(self.registry, self.budgets, self.breakers)

    def capability_catalog(self) -> list[dict]:
        return [
            {
                "id": item.capability_id,
                "owner": item.owning_feature,
                "paths": list(item.path_order),
                "structured_output": item.structured_output,
                "streaming": item.streaming,
                "service_class": item.service_class,
            }
            for item in self.registry.capabilities.values()
        ]
