import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const telemetry = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/telemetry/lidoTelemetry.js'),
    'utf8',
);

test('route view telemetry flushes durations to API', () => {
    assert.match(telemetry, /flushRouteView/);
    assert.match(telemetry, /\/api\/telemetry\/route-view/);
    assert.match(telemetry, /active_duration_ms/);
    assert.match(telemetry, /visibilitychange/);
});
