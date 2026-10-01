from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(frozen=True)
class PromptVersion:
    prompt_id: str
    version: int
    template: str
    active: bool = False


@dataclass
class PromptRegistry:
    versions: dict[str, list[PromptVersion]] = field(default_factory=dict)

    def publish(self, prompt: PromptVersion) -> None:
        versions = self.versions.setdefault(prompt.prompt_id, [])
        if any(item.version == prompt.version for item in versions):
            raise ValueError("Prompt version already exists")
        if prompt.active:
            versions[:] = [item.__class__(item.prompt_id, item.version, item.template, False) for item in versions]
        versions.append(prompt)

    def active(self, prompt_id: str) -> PromptVersion | None:
        return next((item for item in reversed(self.versions.get(prompt_id, [])) if item.active), None)
