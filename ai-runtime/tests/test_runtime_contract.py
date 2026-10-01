import json
from unittest.mock import AsyncMock
import pytest
import stox_ai.app as service
from stox_ai.runtime import Runtime
from stox_ai.schemas import InferenceRequest, ProviderResponse


def projection(budgets=None):
    return {'provider_paths': [{'path_id': name, 'provider': 'deterministic', 'model': name} for name in ['first', 'second']],
            'capabilities': [{'capability_id': 'documentation_chat', 'owner': 'V9-AI-001', 'enabled': True, 'path_order': ['first', 'second']}],
            'prompts': {'documentation_chat': {'id': 'documentation_chat', 'version': 1, 'template': 'Use maintained evidence only.'}},
            'budgets': budgets or []}


@pytest.mark.asyncio
@pytest.mark.parametrize('scope', ['overall', 'capability:documentation_chat', 'user:42'])
async def test_projected_hard_budget_prevents_every_provider_call(scope):
    runtime = Runtime()
    runtime.apply_projection(projection([{'scope': scope, 'hard_limit': 1, 'spent': 1}]))
    calls = []
    for path in runtime.registry.paths.values():
        path.adapter.infer = AsyncMock()
        calls.append(path.adapter.infer)
    result = await runtime.router.infer(InferenceRequest(request_id='r', capability_id='documentation_chat', context={'user_id': 42}))
    assert result.error_code == 'budget_exhausted'
    assert all(call.await_count == 0 for call in calls)


@pytest.mark.asyncio
async def test_projected_path_budget_preserves_fallback_and_does_not_update_ledger():
    runtime = Runtime()
    runtime.apply_projection(projection([{'scope': 'path:first', 'hard_limit': 1, 'spent': 1}]))
    first = runtime.registry.paths['first'].adapter.infer = AsyncMock()
    result = await runtime.router.infer(InferenceRequest(request_id='r', capability_id='documentation_chat'))
    first.assert_not_awaited()
    assert result.model == 'second'
    assert [e.state for e in result.routing_trace] == ['skipped', 'selected']
    assert runtime.budgets.limits['path:first'].spent == 1


@pytest.mark.asyncio
@pytest.mark.parametrize('output,accepted', [
    ('general model knowledge', False),
    (json.dumps({'extracts': [{'source_id': 'invented', 'quote': 'Export from the portfolio export menu.'}]}), False),
    (json.dumps({'extracts': [{'source_id': 'journey:export', 'quote': 'Buy any stock to guarantee a profit.'}]}), False),
    (json.dumps({'extracts': [{'source_id': 'journey:export', 'quote': 'Export from the portfolio export menu.'}]}), True),
])
async def test_evidence_reaches_provider_and_output_is_validated(monkeypatch, output, accepted):
    evidence = {'source_id': 'journey:export', 'title': 'Export', 'path': 'docs/user-journeys/export.md', 'snippet': 'Export from the portfolio export menu.', 'relevance': 3}
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value=projection()))
    monkeypatch.setattr(service.configuration, 'record', AsyncMock())
    monkeypatch.setattr(service.retriever, 'search', lambda _: [evidence])
    from stox_ai.providers import DeterministicAdapter
    async def infer(self, request):
        payload = json.loads(request.user_prompt)
        assert payload['evidence'] == [evidence]
        assert request.system_prompt == 'Use maintained evidence only.'
        return ProviderResponse(text=output)
    monkeypatch.setattr(DeterministicAdapter, 'infer', infer)
    result = await service.execute(InferenceRequest(request_id='r', capability_id='documentation_chat', input={'question': 'export'}))
    assert (result.status == 'success') == accepted
    if not accepted:
        assert result.error_code == 'grounding_insufficient'
        assert result.text == ''

@pytest.mark.asyncio
async def test_invalid_answer_never_escapes_as_stream_delta(monkeypatch):
    from stox_ai.schemas import InferenceResult
    monkeypatch.setattr(service, 'execute', AsyncMock(return_value=InferenceResult(request_id='r', capability_id='documentation_chat', status='failure', degraded=True, error_code='grounding_insufficient')))
    frames = [frame async for frame in service.event_stream(InferenceRequest(request_id='r', capability_id='documentation_chat'))]
    assert not any('event: message.delta' in frame for frame in frames)
    assert 'grounding_insufficient' in frames[-1]


def test_retrieval_excludes_engineering_and_refreshes_removed_sources(tmp_path):
    from stox_ai.retrieval import DocumentationRetriever
    root = tmp_path / 'docs' / 'user-journeys'
    root.mkdir(parents=True)
    approved = root / '01-screeners.md'
    approved.write_text('# Screeners\n## Create\nCreate a screener from the Screeners page.')
    (root / 'automation-contract.md').write_text('# Internal\nScreener private automation details.')
    retriever = DocumentationRetriever(str(tmp_path))
    sources = retriever.search('screener')
    assert sources and all('automation' not in item['path'] for item in sources)
    assert sources[0]['url'].startswith('/docs/journeys/')
    approved.unlink()
    assert retriever.search('screener') == []

@pytest.mark.asyncio
async def test_http_adapter_carries_evidence_on_the_wire(monkeypatch):
    import httpx
    from stox_ai.providers import OpenAICompatibleAdapter
    original_client = httpx.AsyncClient
    async def handler(request):
        payload = json.loads(request.content)
        assert 'source_id' in payload['messages'][1]['content']
        assert 'Export from the portfolio export menu.' in payload['messages'][1]['content']
        return httpx.Response(200, json={'choices': [{'message': {'content': 'answer'}}]})
    monkeypatch.setattr(httpx, 'AsyncClient', lambda **kwargs: original_client(transport=httpx.MockTransport(handler), **kwargs))
    adapter = OpenAICompatibleAdapter('test', 'test', config={'endpoint': 'http://provider', 'api_key': 'test'})
    result = await adapter.infer(InferenceRequest(request_id='r', capability_id='documentation_chat', user_prompt=json.dumps({'evidence': [{'source_id': 'journey:export', 'snippet': 'Export from the portfolio export menu.'}]})))
    assert result.text == 'answer'


@pytest.mark.asyncio
async def test_provider_stream_collects_usage_and_rejects_interruption(monkeypatch):
    import httpx
    from stox_ai.providers import OpenAICompatibleAdapter, ProviderFailure
    original_client = httpx.AsyncClient
    complete = True
    async def handler(request):
        assert json.loads(request.content)['stream'] is True
        body = 'data: {"choices":[{"delta":{"content":"evidence"}}]}\n\ndata: {"choices":[],"usage":{"prompt_tokens":10,"completion_tokens":2}}\n\n'
        if complete:
            body += 'data: [DONE]\n\n'
        return httpx.Response(200, text=body)
    monkeypatch.setattr(httpx, 'AsyncClient', lambda **kwargs: original_client(transport=httpx.MockTransport(handler), **kwargs))
    adapter = OpenAICompatibleAdapter('test', 'test', config={'endpoint': 'http://provider', 'api_key': 'test', 'streaming': True})
    request = InferenceRequest(request_id='r', capability_id='documentation_chat', stream=True)
    result = await adapter.infer(request)
    assert result.text == 'evidence' and result.usage.input_tokens == 10
    complete = False
    with pytest.raises(ProviderFailure):
        await adapter.infer(request)

@pytest.mark.asyncio
async def test_action_request_is_refused_before_provider(monkeypatch):
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value=projection()))
    from stox_ai.providers import DeterministicAdapter
    called = AsyncMock()
    monkeypatch.setattr(DeterministicAdapter, 'infer', called)
    result = await service.execute(InferenceRequest(request_id='r', capability_id='documentation_chat', input={'question': 'Approve this recommendation'}))
    assert result.error_code == 'read_only_scope'
    called.assert_not_awaited()

@pytest.mark.asyncio
async def test_refusal_is_audited_for_answer_feedback(monkeypatch):
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value=projection()))
    recorded = AsyncMock()
    monkeypatch.setattr(service.configuration, 'record', recorded)
    result = await service.execute(InferenceRequest(request_id='r', capability_id='documentation_chat', context={'user_id': 42}, input={'question': 'Place a trade'}))
    event = recorded.call_args.args[0]
    assert result.error_code == 'read_only_scope'
    assert event['context']['user_id'] == 42
    assert event['error']['code'] == 'read_only_scope'
    assert event['routing_trace'] == []
