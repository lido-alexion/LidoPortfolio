import json
from unittest.mock import AsyncMock

import pytest
from fastmcp import Client
from pydantic import ValidationError
from stox_ai.agent import investigate, tool_server, AgentFailure
from stox_ai.agent_contracts import InvestigationRequest, PlannerResult, READ_TOOLS
from stox_ai.schemas import InferenceResult

REQUEST = InvestigationRequest(run_id='00000000-0000-0000-0000-000000000001', delegation='a' * 64, objective='Read my watchlists', user_id=1)


class Gateway:
    def __init__(self, tools=('watchlist.list', 'watchlist.create')):
        self.tools = tools
        self.calls = []
        self.failure = None
    async def call(self, operation, **payload):
        self.calls.append((operation, payload))
        if operation == 'catalog':
            return {'tools': [{'id': tool} for tool in self.tools]}
        if operation == 'read':
            if self.failure:
                raise self.failure
            return {'availability': 'not_initialized', 'data': []}
        if operation == 'preview':
            return {'id': REQUEST.run_id, 'status': 'awaiting_approval', 'plan_hash': 'b' * 64,
                'preview': payload['plan'], 'approval_expires_at': '2026-10-02T12:00:00Z'}
        return payload


def fake_infer(plans):
    async def infer(request):
        assert request.capability_id in {'agent_planning', 'agent_synthesis'}
        assert request.context == {'user_id': 1}
        assert request.output_schema
        value = plans.pop(0) if request.capability_id == 'agent_planning' else {'answer': 'No initialized evidence is available.', 'missing_evidence': []}
        return InferenceResult(request_id=request.request_id, capability_id=request.capability_id, structured=value)
    return infer


def read_plan(tool='watchlist.list', arguments=None, ready=True):
    return {'read_tools': [{'tool': tool, 'arguments': arguments or {}, 'reason': 'Read requested state'}], 'actions': [], 'ready': ready}


async def test_fastmcp_exposes_only_scoped_typed_tools_and_mutations_only_preview():
    gateway = Gateway()
    async with Client(tool_server(gateway, set(gateway.tools))) as client:
        tools = await client.list_tools()
        assert {tool.name for tool in tools} == set(gateway.tools)
        response = await client.call_tool('watchlist.list', {'arguments': {}})
        assert response.structured_content['availability'] == 'not_initialized'
        preview = await client.call_tool('watchlist.create', {'arguments': {'name': 'Requested'}})
        assert preview.structured_content['status'] == 'awaiting_approval'
        with pytest.raises(Exception):
            await client.call_tool('watchlist.create', {'arguments': {'name': 'Requested', 'shell': 'anything'}})
    assert all(operation != 'execute' for operation, _ in gateway.calls)


async def test_minimum_reads_and_shared_synthesis_disclose_missing_evidence():
    gateway = Gateway()
    result = await investigate(REQUEST, fake_infer([read_plan()]), gateway)
    assert len([call for call in gateway.calls if call[0] == 'read']) == 1
    assert 'watchlist.list' in result['answer']
    assert 'Unavailable or incomplete evidence' in result['answer']


async def test_mutation_reads_current_state_then_returns_grouped_preview():
    gateway = Gateway()
    plans = [read_plan(ready=False), {'read_tools': [], 'actions': [{'tool': 'watchlist.create', 'arguments': {'name': 'New'}, 'reason': 'Requested list'}], 'ready': True}]
    result = await investigate(REQUEST, fake_infer(plans), gateway)
    assert result['status'] == 'awaiting_approval'
    assert [operation for operation, _ in gateway.calls] == ['catalog', 'read', 'preview']


async def test_unexposed_tools_and_mutations_without_state_fail_closed():
    with pytest.raises(AgentFailure, match='tool_not_allowed'):
        await investigate(REQUEST, fake_infer([read_plan('cash.summary')]), Gateway())
    with pytest.raises(AgentFailure, match='current_state_required'):
        await investigate(REQUEST, fake_infer([{'read_tools': [], 'actions': [{'tool': 'watchlist.create', 'arguments': {'name': 'New'}, 'reason': 'Requested'}], 'ready': True}]), Gateway())


@pytest.mark.parametrize('failure', [TimeoutError(), ConnectionError()])
async def test_tool_failure_is_disclosed_and_no_raw_error_leaks(failure):
    gateway = Gateway()
    gateway.failure = failure
    result = await investigate(REQUEST, fake_infer([read_plan()]), gateway)
    assert 'Unavailable or incomplete evidence: watchlist.list' in result['answer']


async def test_iteration_and_call_limits_are_deterministic():
    tools = list(sorted(READ_TOOLS - {'watchlist.items', 'screener.runs'}))
    plans = [{'read_tools': [{'tool': tool, 'arguments': {}, 'reason': 'Requested evidence'} for tool in tools[i:i+4]], 'actions': [], 'ready': False} for i in range(0, 12, 4)]
    gateway = Gateway(tools)
    with pytest.raises(AgentFailure, match='max_steps_exceeded'):
        await investigate(REQUEST, fake_infer(plans), gateway)
    assert len([call for call in gateway.calls if call[0] == 'read']) == 8


@pytest.mark.parametrize('plan', [
    {'read_tools': [{'tool': 'broker.place', 'arguments': {}, 'reason': 'No'}], 'ready': True},
    {'read_tools': [{'tool': 'watchlist.items', 'arguments': {'id': '1'}, 'reason': 'No'}], 'ready': True},
    {'read_tools': [], 'ready': True, 'chain_of_thought': 'private'},
    {'read_tools': [{'tool': 'watchlist.delete', 'arguments': {'id': 1}, 'reason': 'No'}], 'ready': True},
])
def test_planner_contract_rejects_unsafe_or_untyped_output(plan):
    with pytest.raises(ValidationError):
        PlannerResult.model_validate(plan)


async def test_agent_private_endpoint_rejects_unauthenticated_calls(monkeypatch):
    import httpx
    from stox_ai.app import app
    monkeypatch.setenv('STOX_AI_SERVICE_KEY', 'private')
    async with httpx.AsyncClient(transport=httpx.ASGITransport(app=app), base_url='http://runtime') as client:
        response = await client.post('/internal/v1/agent/investigate', json=REQUEST.model_dump())
    assert response.status_code == 401


def test_domain_tool_modules_have_no_database_shell_or_external_mcp_path():
    import ast
    from pathlib import Path
    root = Path(__file__).parents[1] / 'stox_ai'
    for name in ['agent.py', 'agent_contracts.py', 'mcp.py']:
        tree = ast.parse((root / name).read_text())
        imports = {node.module for node in ast.walk(tree) if isinstance(node, ast.ImportFrom)}
        imports |= {alias.name for node in ast.walk(tree) if isinstance(node, ast.Import) for alias in node.names}
        assert not {'sqlite3', 'sqlalchemy', 'pymysql', 'mysql', 'subprocess'} & imports
    source = (root / 'agent.py').read_text()
    assert 'Client(server)' in source
    assert '/api/internal/v1/ai-tools/call' in source
    assert 'shell=True' not in source


async def test_agent_inference_requires_an_active_governed_prompt(monkeypatch):
    from stox_ai import app as runtime_app
    from stox_ai.schemas import InferenceRequest
    monkeypatch.setattr(runtime_app.configuration, 'get', AsyncMock(return_value={'version': '0', 'provider_paths': [], 'capabilities': [], 'prompts': {}}))
    monkeypatch.setattr(runtime_app.configuration, 'record', AsyncMock())
    result = await runtime_app.execute(InferenceRequest(request_id='missing-prompt', capability_id='agent_planning'))
    assert result.error_code == 'configuration_invalid'
    runtime_app.configuration.record.assert_awaited_once()
