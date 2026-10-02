"""Process-wide admission, independent of request-local provider routing."""
from __future__ import annotations

import asyncio
from contextlib import asynccontextmanager
from threading import Lock


class AdmissionController:
    def __init__(self):
        self._lock = Lock()
        self._active = 0
        self._capabilities: dict[str, int] = {}
        self._global_limit = 16
        self._limits: dict[str, int] = {}
        self._version = -1

    def configure(self, version: int, global_limit: int, limits: dict[str, int]):
        if global_limit < 1 or any(limit < 1 for limit in limits.values()):
            raise ValueError("Concurrency limits must be positive")
        with self._lock:
            if version < self._version:
                return
            self._version = version
            self._global_limit = global_limit
            self._limits = dict(limits)
            # Never replace counters: reduced limits drain existing executions.

    @asynccontextmanager
    async def acquire(self, capability: str, default_limit: int = 4):
        while True:
            with self._lock:
                active = self._capabilities.get(capability, 0)
                if self._active < self._global_limit and active < self._limits.get(capability, default_limit):
                    self._active += 1
                    self._capabilities[capability] = active + 1
                    break
            # No loop-bound primitives; cancellation while queued owns no permit.
            await asyncio.sleep(0.01)
        try:
            yield
        finally:
            with self._lock:
                self._active -= 1
                self._capabilities[capability] -= 1


admission = AdmissionController()
