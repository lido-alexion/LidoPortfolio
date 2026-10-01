import pytest

from stox_ai.budgets import BudgetProjection, BudgetLimit
from stox_ai.circuit_breaker import CircuitBreaker
from stox_ai.providers import DeterministicAdapter, ProviderFailure
from stox_ai.registry import Capability, CapabilityRegistry, ProviderPath
from stox_ai.router import InferenceRouter
from stox_ai.schemas import FailureCategory, InferenceRequest, ProviderResponse, Usage
from stox_ai.retrieval import DocumentationRetriever


def request() -> InferenceRequest:
    return InferenceRequest(request_id="r1", capability_id="test.capability", user_prompt="hello")


@pytest.mark.asyncio
async def test_router_fails_over_in_canonical_order_and_records_trace():
    registry = CapabilityRegistry()
    registry.register_path(ProviderPath("first", "one", "m1", DeterministicAdapter("one", "m1", failure=ProviderFailure(FailureCategory.TIMEOUT, "timeout"))))
    registry.register_path(ProviderPath("second", "two", "m2", DeterministicAdapter("two", "m2", ProviderResponse(text="ok", usage=Usage(estimated_cost=0.2))), budget_scopes=("overall",)))
    registry.register(Capability("test.capability", "test", ("first", "second")))
    result = await InferenceRouter(registry).infer(request())
    assert result.text == "ok"
    assert [item.state for item in result.routing_trace] == ["failed", "selected"]


@pytest.mark.asyncio
async def test_hard_budget_skips_a_path_without_reordering_the_definition():
    registry = CapabilityRegistry()
    registry.register_path(ProviderPath("first", "one", "m1", DeterministicAdapter("one", "m1", ProviderResponse(text="wrong")), budget_scopes=("path:first",)))
    registry.register_path(ProviderPath("second", "two", "m2", DeterministicAdapter("two", "m2", ProviderResponse(text="ok"))))
    registry.register(Capability("test.capability", "test", ("first", "second")))
    budgets = BudgetProjection({"path:first": BudgetLimit(hard=1, spent=1)})
    result = await InferenceRouter(registry, budgets).infer(request())
    assert result.provider == "two"
    assert result.routing_trace[0].reason == "hard_budget_exhausted:path:first"


@pytest.mark.asyncio
async def test_circuit_breaker_skips_repeatedly_failing_path():
    registry = CapabilityRegistry()
    registry.register_path(ProviderPath("first", "one", "m1", DeterministicAdapter("one", "m1", failure=ProviderFailure(FailureCategory.TIMEOUT, "timeout"))))
    registry.register_path(ProviderPath("second", "two", "m2", DeterministicAdapter("two", "m2", ProviderResponse(text="ok"))))
    registry.register(Capability("test.capability", "test", ("first", "second")))
    breakers = CircuitBreaker(failure_threshold=1)
    router = InferenceRouter(registry, breakers=breakers)
    await router.infer(request())
    result = await router.infer(request())
    assert result.routing_trace[0].reason == "circuit_open"


def test_documentation_retrieval_has_stable_citations():
    results = DocumentationRetriever().search("How do I export portfolio data?")
    assert results
    assert results[0]["source_id"].startswith("journey:")
    assert results[0]["path"].startswith("docs/user-journeys/")
    assert results[0]["snippet"]
