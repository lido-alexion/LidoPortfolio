import assert from 'node:assert/strict';
import test from 'node:test';
import { JOURNEY_TOPICS, searchJourneyTopics } from '../../resources/js/src/data/journeyMetadata.js';

test('journey metadata has stable IDs and actionable contracts', () => {
    assert.equal(JOURNEY_TOPICS.length, 63);
    assert.equal(new Set(JOURNEY_TOPICS.map((topic) => topic.id)).size, JOURNEY_TOPICS.length);
    for (const topic of JOURNEY_TOPICS) {
        assert.match(topic.id, /^(SCR|STR|REC|EXE|E2E|AI)-\d+$/);
        assert.ok(topic.route && topic.guide && topic.steps.length > 0);
    }
});

test('journey ranking is deterministic and uses aliases', () => {
    const first = searchJourneyTopics('new screener').map((topic) => topic.id);
    const second = searchJourneyTopics('new screener').map((topic) => topic.id);
    assert.deepEqual(first, second);
    assert.equal(first[0], 'SCR-01');
});

test('assistant journeys are discoverable through deterministic help', () => {
    assert.deepEqual(JOURNEY_TOPICS.filter(topic => topic.id.startsWith('AI-')).map(topic => topic.id), ['AI-01', 'AI-02', 'AI-03']);
    assert.ok(searchJourneyTopics('grounded product question').some(topic => topic.id === 'AI-01'));
});
