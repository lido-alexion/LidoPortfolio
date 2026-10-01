from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone


@dataclass
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
class BudgetLedger:
    limits: dict[str, BudgetLimit] = field(default_factory=dict)
    warnings_sent: set[str] = field(default_factory=set)

    def eligible(self, scopes: list[str]) -> tuple[bool, str | None]:
        for scope in scopes:
            limit = self.limits.get(scope)
            if limit and limit.exhausted:
                return False, f"hard_budget_exhausted:{scope}"
        return True, None

    def record(self, scopes: list[str], cost: float) -> list[str]:
        warnings = []
        for scope in scopes:
            limit = self.limits.get(scope)
            if not limit:
                continue
            limit.spent += max(0.0, cost)
            if limit.spent >= limit.soft and scope not in self.warnings_sent:
                self.warnings_sent.add(scope)
                warnings.append(scope)
        return warnings

    def reset(self) -> None:
        for limit in self.limits.values():
            limit.spent = 0.0
        self.warnings_sent.clear()
