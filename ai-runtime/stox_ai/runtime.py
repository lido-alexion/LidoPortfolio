from __future__ import annotations

import os
from typing import Any

from .budgets import BudgetProjection, BudgetLimit
from .circuit_breaker import CircuitBreaker
from .providers import DeterministicAdapter, OpenAICompatibleAdapter
from .prompts import PromptRegistry
from .registry import Capability, CapabilityRegistry, ProviderPath
from .router import InferenceRouter
from .schemas import ProviderResponse


class Runtime:
    def __init__(self) -> None:
        self.registry = CapabilityRegistry()
        self.budgets = BudgetProjection()
        self.breakers = CircuitBreaker(
            failure_threshold=max(1, int(os.getenv("STOX_AI_CIRCUIT_FAILURE_THRESHOLD", "3"))),
            cooldown_seconds=max(1, int(os.getenv("STOX_AI_CIRCUIT_COOLDOWN_SECONDS", "60"))),
        )
        self.prompts = PromptRegistry()
        self.router = InferenceRouter(self.registry, self.budgets, self.breakers)

    def apply_projection(self, projection: dict[str, Any]) -> None:
        """Replace the ephemeral execution projection. Laravel remains authoritative."""
        self.budgets = BudgetProjection({item["scope"]: BudgetLimit(hard=float(item["hard_limit"]), spent=float(item["spent"])) for item in projection.get("budgets", [])})
        registry = CapabilityRegistry()
        for item in projection.get("provider_paths", []):
            config = item.get("config") or {}
            # Deterministic is deliberately explicit and supports CI without external AI access.
            adapter = (DeterministicAdapter(item["provider"], item["model"], ProviderResponse(text=str(config.get("deterministic_response", "Configured deterministic response"))))
                       if item["provider"] == "deterministic" else OpenAICompatibleAdapter(item["provider"], item["model"], config=config))
            registry.register_path(ProviderPath(item["path_id"], item["provider"], item["model"], adapter, bool(item.get("enabled", True)), tuple(config.get("budget_scopes", ["overall", f"path:{item['path_id']}"]))))
        for item in projection.get("capabilities", []):
            if item.get("enabled") and item.get("path_order"):
                registry.register(Capability(item["capability_id"], item["owner"], tuple(item["path_order"]), bool(item.get("output_schema")), bool(item.get("streaming")), max_concurrency=int(item.get("max_concurrency", 4)), prompt_id=item.get("prompt_id")))
        self.registry = registry
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
