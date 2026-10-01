from __future__ import annotations

from dataclasses import dataclass, field

from .providers import ProviderAdapter


@dataclass(frozen=True)
class ProviderPath:
    path_id: str
    provider: str
    model: str
    adapter: ProviderAdapter
    enabled: bool = True
    budget_scopes: tuple[str, ...] = ()


@dataclass(frozen=True)
class Capability:
    capability_id: str
    owning_feature: str
    path_order: tuple[str, ...]
    structured_output: bool = False
    streaming: bool = False
    service_class: str = "interactive"
    prompt_id: str | None = None
    max_concurrency: int = 4


@dataclass
class CapabilityRegistry:
    capabilities: dict[str, Capability] = field(default_factory=dict)
    paths: dict[str, ProviderPath] = field(default_factory=dict)

    def register_path(self, path: ProviderPath) -> None:
        self.paths[path.path_id] = path

    def register(self, capability: Capability) -> None:
        if not capability.path_order:
            raise ValueError("Capability must have an ordered path")
        if any(path not in self.paths for path in capability.path_order):
            raise ValueError("Capability references an unknown provider path")
        self.capabilities[capability.capability_id] = capability

    def get(self, capability_id: str) -> Capability:
        try:
            return self.capabilities[capability_id]
        except KeyError as error:
            raise KeyError(f"Unknown AI capability: {capability_id}") from error
