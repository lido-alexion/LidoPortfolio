"""OPS-003 is a schema-governed background consumer of the shared router."""
import json
from pathlib import Path
from unittest.mock import AsyncMock
import pytest
import stox_ai.app as service
from stox_ai.runtime import Runtime
from stox_ai.schemas import InferenceRequest, ProviderResponse
from stox_ai.providers import DeterministicAdapter
from stox_ai.outbox import DeliveryOutbox
from stox_ai.circuit_breaker import CircuitBreaker

SCHEMA = json.loads((Path(__file__).resolve().parents[2] / 'app/config/ai-schemas/ops.log_error_triage.v1.json').read_text())
CAPABILITY = 'ops.log_error_triage'

@pytest.mark.asyncio
@pytest.mark.parametrize('invalid', [False, True])
async def test_shared_schema_prompt_admission_and_audit(monkeypatch, tmp_path, invalid):
    projection = {'provider_paths': [{'path_id': 'triage-test', 'provider': 'deterministic', 'model': 'test'}],
        'capabilities': [{'capability_id': CAPABILITY, 'owner': 'V9-OPS-003', 'enabled': True, 'path_order': ['triage-test'], 'output_schema': SCHEMA, 'service_class': 'background', 'max_concurrency': 1}],
        'prompts': {CAPABILITY: {'id': CAPABILITY, 'version': 2, 'template': 'Classify supplied safe evidence conservatively.'}}}
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value=projection))
    monkeypatch.setattr(service.configuration, 'record', AsyncMock())
    monkeypatch.setattr(service.configuration, 'reserve', AsyncMock(return_value={'max_output_tokens': 1024}))
    monkeypatch.setattr(service.configuration, 'outbox', DeliveryOutbox(tmp_path / 'outbox.sqlite3'))
    monkeypatch.setattr(service.runtime, 'breakers', CircuitBreaker())
    output = {'classification': 'invented' if invalid else 'code_bug', 'confidence': .94, 'summary': 'Null access', 'evidence': ['Application frame: app/Services/PortfolioService.php:123'], 'suspected_component': 'app/Services/PortfolioService.php', 'bug_kind': 'null_handling', 'actionability': 'actionable', 'safe_issue_title': 'Null access', 'security_sensitive': False}
    calls = []
    async def infer(self, request):
        assert request.system_prompt == projection['prompts'][CAPABILITY]['template']
        payload = json.loads(request.user_prompt)
        assert payload['response_schema'] == SCHEMA
        assert payload['evidence']['evidence_candidates'] == output['evidence']
        assert request.context.get('user_id') is None
        assert request.max_output_tokens == 1024
        calls.append(request)
        return ProviderResponse(text=json.dumps(output))
    monkeypatch.setattr(DeterministicAdapter, 'infer', infer)
    runtime = Runtime(); runtime.apply_projection(projection)
    assert runtime.registry.get(CAPABILITY).service_class == 'background'
    assert runtime.registry.get(CAPABILITY).max_concurrency == 1
    result = await service.execute(InferenceRequest(request_id='triage-test', capability_id=CAPABILITY, input={'evidence_candidates': output['evidence']}, output_schema=SCHEMA))
    assert result.prompt == {'id': CAPABILITY, 'version': 2}
    assert (result.status == 'success') is not invalid
    assert len(calls) == (2 if invalid else 1)
    assert service.configuration.reserve.await_count == len(calls)
    if invalid:
        assert result.error_code == 'structured_output_invalid'
        assert result.structured is None
    else:
        assert result.structured == output

@pytest.mark.asyncio
async def test_missing_governed_prompt_fails_closed(monkeypatch):
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value={'capabilities': [], 'prompts': {}}))
    monkeypatch.setattr(service.configuration, 'record', AsyncMock())
    result = await service.execute(InferenceRequest(request_id='missing-prompt', capability_id=CAPABILITY))
    assert result.status == 'failure'
    assert result.error_code == 'configuration_invalid'

@pytest.mark.asyncio
async def test_unconfigured_capability_routes_fail_safe(monkeypatch):
    monkeypatch.setattr(service.configuration, 'get', AsyncMock(return_value={'capabilities': [], 'prompts': {CAPABILITY: {'id': CAPABILITY, 'version': 2, 'template': 'Use safe evidence.'}}}))
    monkeypatch.setattr(service.configuration, 'record', AsyncMock())
    result = await service.execute(InferenceRequest(request_id='no-routes', capability_id=CAPABILITY))
    assert result.status == 'failure'
    assert result.error_code == 'capability_disabled'
