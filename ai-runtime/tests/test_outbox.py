import pytest
from stox_ai.outbox import DeliveryOutbox


async def test_endpoint_outage_survives_restart_and_ack_removes_envelope(tmp_path):
    path = tmp_path / 'outbox.sqlite3'
    queue = DeliveryOutbox(path)
    queue.enqueue('settlements', {'id': 'reservation', 'usage': None})
    async def unavailable(endpoint, payload):
        raise ConnectionError('Laravel unavailable')
    await queue.drain(unavailable)
    assert queue.status() == {'pending': 1, 'max_attempts': 1}
    restarted = DeliveryOutbox(path)
    calls = []
    async def recovered(endpoint, payload):
        calls.append((endpoint, payload))
    await restarted.drain(recovered)
    assert calls == []  # bounded backoff, not a busy retry loop
    with restarted.connect() as db:
        db.execute('UPDATE deliveries SET next_attempt=0')
    await restarted.drain(recovered)
    assert calls == [('settlements', {'id': 'reservation', 'usage': None})]
    assert restarted.status()['pending'] == 0


@pytest.mark.parametrize('initial_status,initial_data', [(503, {'event_id': 1}), (200, {})])
async def test_http_error_and_invalid_ack_remain_durable(monkeypatch, tmp_path, initial_status, initial_data):
    import httpx
    from stox_ai.configuration import LaravelConfigurationClient
    client = LaravelConfigurationClient()
    client.outbox = DeliveryOutbox(tmp_path / 'outbox.sqlite3')
    monkeypatch.setenv('STOX_LARAVEL_INTERNAL_URL', 'http://laravel')
    monkeypatch.setenv('STOX_AI_SERVICE_KEY', 'test')
    original = httpx.AsyncClient
    status = initial_status
    data = initial_data
    async def handler(request):
        return httpx.Response(status, json={'success': status == 200, 'data': data})
    monkeypatch.setattr(httpx, 'AsyncClient', lambda **kwargs: original(transport=httpx.MockTransport(handler), **kwargs))
    await client.record({'request_id': 'audit'})
    assert client.outbox.status()['pending'] == 1
    status = 200
    data = {'event_id': 1}
    with client.outbox.connect() as db:
        db.execute('UPDATE deliveries SET next_attempt=0')
    await client.outbox.drain(client.send)
    assert client.outbox.status()['pending'] == 0
