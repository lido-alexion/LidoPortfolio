import assert from 'node:assert/strict';
import test from 'node:test';
import { JOURNEY_TOPICS, searchJourneyTopics } from '../../resources/js/src/data/journeyMetadata.js';

test('journey metadata has stable IDs and actionable contracts', () => {
    assert.equal(JOURNEY_TOPICS.length, 59);
    assert.equal(new Set(JOURNEY_TOPICS.map((topic) => topic.id)).size, JOURNEY_TOPICS.length);
    for (const topic of JOURNEY_TOPICS) {
        assert.match(topic.id, /^(SCR|STR|REC|EXE|E2E)-\d+$/);
        assert.ok(topic.route && topic.guide && topic.steps.length > 0);
    }
});

test('journey ranking is deterministic and uses aliases', () => {
    const first = searchJourneyTopics('new screener').map((topic) => topic.id);
    const second = searchJourneyTopics('new screener').map((topic) => topic.id);
    assert.deepEqual(first, second);
    assert.equal(first[0], 'SCR-01');
});
