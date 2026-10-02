"""Public operational contracts only; no model reasoning fields are retained."""
from __future__ import annotations

from typing import Literal, Annotated, Union
from uuid import UUID
from pydantic import BaseModel, ConfigDict, Field, JsonValue, model_validator, create_model


class Contract(BaseModel):
    model_config = ConfigDict(extra='forbid', strict=True)


class EmptyInput(Contract):
    pass


class ObjectInput(Contract):
    id: int = Field(gt=0)


class NameInput(Contract):
    name: str = Field(min_length=1, max_length=120)


class RenameInput(ObjectInput, NameInput):
    pass


class StockInput(ObjectInput):
    stock_id: int = Field(gt=0)


class AddStockInput(StockInput):
    note: str | None = Field(default=None, max_length=500)


class EnvelopeInput(Contract):
    envelope: dict[str, JsonValue]


class UpdateEnvelopeInput(ObjectInput, EnvelopeInput):
    pass


class DraftInput(ObjectInput):
    content: dict[str, JsonValue]


INPUTS = {name: EmptyInput for name in (
    'portfolio.summary', 'portfolio.holdings', 'portfolio.analytics', 'cash.summary',
    'watchlist.list', 'strategy.list', 'screener.list', 'recommendations.list',
    'artifact.list', 'preferences.read', 'dashboard.list', 'workflow.prepare')}
INPUTS.update({'watchlist.items': ObjectInput, 'screener.runs': ObjectInput,
    'watchlist.create': NameInput, 'watchlist.rename': RenameInput,
    'watchlist.add_stock': AddStockInput, 'watchlist.remove_stock': StockInput,
    'watchlist.delete': ObjectInput, 'strategy.create': EnvelopeInput,
    'screener.create': EnvelopeInput, 'strategy.update': UpdateEnvelopeInput,
    'screener.update': UpdateEnvelopeInput, 'artifact.update_draft': DraftInput})
READ_TOOLS = frozenset(list(INPUTS)[:14])


# Discriminated contracts also expose exact tool/argument combinations to JSON Schema,
# so the shared router retries/fails over malformed plans before orchestration sees them.
CALL_MODELS = {
    name: create_model(name.replace('.', '_') + '_Call', __base__=Contract,
        tool=(Literal[name], ...), arguments=(model, ...),
        reason=(str, Field(min_length=1, max_length=300, description='Brief public purpose, never private reasoning.')))
    for name, model in INPUTS.items()
}
ReadCall = Annotated[Union[tuple(model for name, model in CALL_MODELS.items() if name in READ_TOOLS)], Field(discriminator='tool')]
ActionCall = Annotated[Union[tuple(model for name, model in CALL_MODELS.items() if name not in READ_TOOLS)], Field(discriminator='tool')]


class PlannerResult(Contract):
    read_tools: list[ReadCall] = Field(default_factory=list, max_length=4)
    actions: list[ActionCall] = Field(default_factory=list, max_length=5)
    ready: bool

    @model_validator(mode='after')
    def classifications(self):
        if any(call.tool not in READ_TOOLS for call in self.read_tools):
            raise ValueError('read_tool_required')
        if any(call.tool in READ_TOOLS for call in self.actions):
            raise ValueError('mutation_tool_required')
        if self.actions and self.read_tools:
            raise ValueError('read_current_state_before_proposing_actions')
        return self


class Synthesis(Contract):
    answer: str = Field(min_length=1, max_length=5000)
    missing_evidence: list[str] = Field(default_factory=list, max_length=20)


class Projection(Contract):
    availability: Literal['available', 'not_initialized', 'unavailable', 'incomplete', 'not_supported']
    data: JsonValue = None
    reason: str | None = None


class InvestigationRequest(Contract):
    run_id: str = Field(pattern=r'^[0-9a-fA-F-]{36}$')
    delegation: str = Field(pattern=r'^[0-9a-f]{64}$')
    objective: str = Field(min_length=1, max_length=4000)
    user_id: int = Field(gt=0)


class MutationPreview(BaseModel):
    """Laravel authorizes execution separately after the user approves this scope."""
    id: str
    status: Literal['awaiting_approval']
    plan_hash: str = Field(pattern=r'^[0-9a-f]{64}$')
    preview: list[dict[str, JsonValue]]
    approval_expires_at: str
