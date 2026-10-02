import asyncio

import pytest

from stox_ai.admission import AdmissionController
from stox_ai.providers import DeterministicAdapter
from stox_ai.registry import Capability, CapabilityRegistry, ProviderPath
from stox_ai.router import InferenceRouter
from stox_ai.schemas import InferenceRequest, ProviderResponse


def router(adapter, admission, capability='test', schema=None):
    registry = CapabilityRegistry()
    registry.register_path(ProviderPath('first', 'local', 'test', adapter))
    registry.register(Capability(capability, 'test', ('first',), output_schema=schema))
    result = InferenceRouter(registry)
    result.admission = admission
    return result


@pytest.mark.parametrize('global_limit,cap_limit,capabilities,expected', [(16, 4, ['a'] * 8, 4), (2, 4, ['a', 'b'] * 4, 2)])
async def test_request_isolated_routers_share_admission(global_limit, cap_limit, capabilities, expected):
    admission = AdmissionController()
    admission.configure(1, global_limit, {'a': cap_limit, 'b': cap_limit})
    active = peak = 0

    class Observed(DeterministicAdapter):
        async def infer(self, request):
            nonlocal active, peak
            active += 1
            peak = max(peak, active)
            try:
                await asyncio.sleep(.025)
                return ProviderResponse(text='ok')
            finally:
                active -= 1

    results = await asyncio.gather(*(router(Observed('local', 'test'), admission, cap).infer(InferenceRequest(request_id=str(i), capability_id=cap)) for i, cap in enumerate(capabilities)))
    assert all(result.status == 'success' for result in results)
    assert peak == expected


async def test_cancel_failure_and_reconfiguration_preserve_permits():
    admission = AdmissionController()
    admission.configure(1, 2, {'a': 2})
    started = asyncio.Event()
    async def hold():
        async with admission.acquire('a'):
            started.set()
            await asyncio.Event().wait()
    task = asyncio.create_task(hold())
    await started.wait()
    admission.configure(2, 1, {'a': 1})
    admission.configure(1, 10, {'a': 10})  # stale configuration cannot raise limits
    waiter = asyncio.create_task(hold())
    await asyncio.sleep(.02)
    assert admission._active == 1
    task.cancel()
    with pytest.raises(asyncio.CancelledError):
        await task
    waiter.cancel()
    with pytest.raises(asyncio.CancelledError):
        await waiter
    with pytest.raises(RuntimeError):
        async with admission.acquire('a'):
            raise RuntimeError('provider failure')
    async with asyncio.timeout(.2):
        async with admission.acquire('a'):
            assert admission._active == 1
    assert admission._active == 0


SCHEMA = {'type': 'object', 'required': ['count'], 'properties': {'count': {'type': 'integer'}}, 'additionalProperties': False}


@pytest.mark.parametrize('value,valid', [({}, False), ({'count': '1'}, False), ({'count': True}, False), ({'count': 1}, True)])
async def test_declared_schema_is_enforced(value, valid):
    execution = router(DeterministicAdapter('local', 'test', ProviderResponse(structured=value)), AdmissionController(), schema=SCHEMA)
    result = await execution.infer(InferenceRequest(request_id='schema', capability_id='test'))
    assert (result.status == 'success') is valid
    if not valid:
        assert result.error_code == 'structured_output_invalid'
        assert [e.state for e in result.routing_trace] == ['failed', 'failed']


async def test_schema_failure_retries_once_then_uses_canonical_fallback():
    execution = router(DeterministicAdapter('local', 'test', ProviderResponse(structured={})), AdmissionController(), schema=SCHEMA)
    execution.registry.register_path(ProviderPath('second', 'fallback', 'test', DeterministicAdapter('fallback', 'test', ProviderResponse(text='{"count": 2}'))))
    execution.registry.register(Capability('test', 'test', ('first', 'second'), output_schema=SCHEMA))
    result = await execution.infer(InferenceRequest(request_id='schema', capability_id='test'))
    assert result.structured == {'count': 2}
    assert [event.path_id for event in result.routing_trace] == ['first', 'first', 'second']


async def test_cancelled_provider_durably_enqueues_unknown_usage(tmp_path):
    from stox_ai.configuration import LaravelConfigurationClient
    from stox_ai.outbox import DeliveryOutbox
    from unittest.mock import AsyncMock
    started = asyncio.Event()
    class Waiting(DeterministicAdapter):
        async def infer(self, request):
            started.set()
            await asyncio.Event().wait()
    execution = router(Waiting('local', 'test'), AdmissionController())
    client = LaravelConfigurationClient()
    client.outbox = DeliveryOutbox(tmp_path / 'delivery.sqlite3')
    client.reserve = AsyncMock(return_value={'max_output_tokens': 100})
    execution.ledger = client
    task = asyncio.create_task(execution.infer(InferenceRequest(request_id='r', capability_id='test')))
    await started.wait()
    task.cancel()
    with pytest.raises(asyncio.CancelledError):
        await task
    assert execution.admission._active == 0
    assert client.outbox.status()['pending'] == 1
    import json
    with client.outbox.connect() as db:
        endpoint, payload = db.execute('SELECT endpoint,payload FROM deliveries').fetchone()
    assert endpoint == 'settlements'
    assert json.loads(payload)['usage'] is None


async def test_admission_outage_prevents_provider_execution():
    from unittest.mock import AsyncMock
    adapter = DeterministicAdapter('local', 'test')
    adapter.infer = AsyncMock()
    execution = router(adapter, AdmissionController())
    execution.ledger = AsyncMock()
    execution.ledger.reserve.side_effect = ConnectionError('Laravel unavailable')
    result = await execution.infer(InferenceRequest(request_id='r', capability_id='test'))
    assert result.status == 'failure'
    adapter.infer.assert_not_awaited()


async def test_remote_schema_references_are_rejected_before_provider():
    from unittest.mock import AsyncMock
    adapter = DeterministicAdapter('local', 'test')
    adapter.infer = AsyncMock()
    execution = router(adapter, AdmissionController(), schema={'$ref': 'https://untrusted/schema'})
    result = await execution.infer(InferenceRequest(request_id='r', capability_id='test'))
    assert result.error_code == 'configuration_invalid'
    adapter.infer.assert_not_awaited()


async def test_request_schema_cannot_weaken_capability_schema():
    execution = router(DeterministicAdapter('local', 'test', ProviderResponse(structured={})), AdmissionController(), schema=SCHEMA)
    result = await execution.infer(InferenceRequest(request_id='schema', capability_id='test', output_schema={'type': 'object'}))
    assert result.error_code == 'structured_output_invalid'


async def test_timeout_releases_shared_admission_for_next_request():
    admission = AdmissionController()
    admission.configure(1, 1, {'a': 1})
    with pytest.raises(TimeoutError):
        async with asyncio.timeout(.02):
            async with admission.acquire('a'):
                await asyncio.Event().wait()
    async with asyncio.timeout(.1):
        async with admission.acquire('a'):
            assert admission._active == 1
    assert admission._active == 0
