from __future__ import annotations

from pydantic import BaseModel, ConfigDict

try:
    from fastmcp import FastMCP
except ImportError:  # pragma: no cover - optional until governed tools are registered
    FastMCP = None  # type: ignore[assignment,misc]


class ToolError(BaseModel):
    model_config = ConfigDict(extra="forbid")
    code: str
    message: str
    retryable: bool = False


def create_mcp():
    if FastMCP is None:
        raise RuntimeError("FastMCP 4.x is not installed")
    return FastMCP("stox-governed-tools")
