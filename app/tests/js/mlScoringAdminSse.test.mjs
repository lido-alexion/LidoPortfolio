import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const page = readFileSync(
    join(dirname(fileURLToPath(import.meta.url)), '../../resources/js/src/pages/MlScoringAdminPage.jsx'),
    'utf8',
);

test('ML admin page queues retrain and uses EventSource progress stream', () => {
    assert.match(page, /retrain-queue/);
    assert.match(page, /EventSource/);
    assert.match(page, /\/runs\/\$\{runId\}\/stream/);
    assert.match(page, /completed_eligible/);
    assert.match(page, /completed_rejected/);
    assert.match(page, /admin\/ml\/schedules/);
    assert.match(page, /admin\/ml\/rollback/);
    assert.match(page, /next_scheduled_at/);
    assert.match(page, /cancellation requested/);
});
