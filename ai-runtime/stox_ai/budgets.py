from __future__ import annotations

from dataclasses import dataclass, field


@dataclass(frozen=True)
class BudgetLimit:
    hard: float
    soft_percent: float = 80.0
    spent: float = 0.0
    period: str = "monthly"

    @property
    def soft(self) -> float:
        return self.hard * (self.soft_percent / 100.0)

    @property
    def exhausted(self) -> bool:
        return self.spent >= self.hard


@dataclass
class BudgetProjection:
    limits: dict[str, BudgetLimit] = field(default_factory=dict)

    def eligible(self, scopes: list[str]) -> tuple[bool, str | None]:
        for scope in scopes:
            limit = self.limits.get(scope)
            if limit and limit.exhausted:
                return False, f"hard_budget_exhausted:{scope}"
        return True, None
