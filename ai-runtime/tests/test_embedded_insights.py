"""AI-003 consumes the shared structured router; partial streams never validate."""
import json
from pathlib import Path
from unittest.mock import AsyncMock
import pytest
import stox_ai.app as service
from stox_ai.schemas import InferenceRequest, ProviderResponse
from stox_ai.providers import DeterministicAdapter, ProviderFailure
from stox_ai.outbox import DeliveryOutbox

ROOT = Path(__file__).resolve().parents[2]

@pytest.mark.asyncio
@pytest.mark.parametrize('capability', ['stock_analysis_insight', 'strategy_designer'])
@pytest.mark.parametrize('failure', [False, True])
async def test_embedded_schema_prompt_stream_and_failure(monkeypatch, tmp_path, capability, failure):
    schema = json.loads((ROOT / 'docs/architecture/ai-schemas' / f'{capability}.v1.json').read_text())
    output = {key: 'Only supplied evidence is interpreted.' for key in schema['required']}
    if capability == 'strategy_designer':
        output['draft_envelope'] = {'schema_version': '1.0', 'artifact_type': 'strategy', 'name': 'Draft', 'slug': 'draft', 'metadata': {}, 'definition': {}}
    projection = {'provider_paths': [{'path_id': 'embedded-test', 'provider': 'deterministic', 'model': 'test'}],
        'capabilities': [{'capability_id': capability, 'owner': 'V9-AI-003', 'enabled': True, 'path_order': ['embedded-test'], 'output_schema': schema, 'streaming': True}],
        'prompts': {capability: {'id': capability, 'version': 1, 'template': 'Analytical only.'}}}
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value=projection))
    monkeypatch.setattr(service.configuration, 'record', AsyncMock())
    monkeypatch.setattr(service.configuration, 'reserve', AsyncMock(return_value={'max_output_tokens': 4096}))
    monkeypatch.setattr(service.configuration, 'outbox', DeliveryOutbox(tmp_path / 'outbox.sqlite3'))
    from stox_ai.circuit_breaker import CircuitBreaker
    monkeypatch.setattr(service.runtime, 'breakers', CircuitBreaker())
    async def infer(self, request):
        assert request.stream is True
        assert json.loads(request.user_prompt)['evidence'] == {'source': 'Laravel'}
        assert request.system_prompt == 'Analytical only.'
        if failure:
            return ProviderResponse(text='{"summary":"interrupted')
        return ProviderResponse(text=json.dumps(output))
    monkeypatch.setattr(DeterministicAdapter, 'infer', infer)
    request = InferenceRequest(request_id='embedded', capability_id=capability, input={'evidence': {'source': 'Laravel'}}, stream=True)
    result = await service.execute(request)
    assert (result.status == 'success') is not failure
    assert result.prompt == {'id': capability, 'version': 1}
    if failure:
        assert result.structured is None
        assert result.text == ''
        assert result.error_code == 'structured_output_invalid'
    else:
        assert result.structured == output
