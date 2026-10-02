from __future__ import annotations

from pydantic import BaseModel, ConfigDict

class ToolError(BaseModel):
    model_config = ConfigDict(extra="forbid")
    code: str
    message: str
    retryable: bool = False


def create_mcp(gateway, allowed):
    """A scoped in-process facade; never an externally listening MCP server."""
    from .agent import tool_server
    return tool_server(gateway, set(allowed))
