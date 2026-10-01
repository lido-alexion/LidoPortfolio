from __future__ import annotations

from dataclasses import dataclass
from datetime import datetime, timedelta, timezone


@dataclass
class CircuitState:
    failures: int = 0
    open_until: datetime | None = None
    manual_hold: bool = False


class CircuitBreaker:
    def __init__(self, failure_threshold: int = 3, cooldown_seconds: int = 60):
        self.failure_threshold = failure_threshold
        self.cooldown_seconds = cooldown_seconds
        self.states: dict[str, CircuitState] = {}

    def eligible(self, path_id: str) -> tuple[bool, str | None]:
        state = self.states.setdefault(path_id, CircuitState())
        now = datetime.now(timezone.utc)
        if state.manual_hold:
            return False, "manual_hold"
        if state.open_until and state.open_until > now:
            return False, "circuit_open"
        if state.open_until:
            state.open_until = None
            state.failures = 0
        return True, None

    def success(self, path_id: str) -> None:
        self.states.setdefault(path_id, CircuitState()).failures = 0
        self.states[path_id].open_until = None

    def failure(self, path_id: str) -> None:
        state = self.states.setdefault(path_id, CircuitState())
        state.failures += 1
        if state.failures >= self.failure_threshold:
            state.open_until = datetime.now(timezone.utc) + timedelta(seconds=self.cooldown_seconds)

    def set_hold(self, path_id: str, held: bool) -> None:
        self.states.setdefault(path_id, CircuitState()).manual_hold = held
