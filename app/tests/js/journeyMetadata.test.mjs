import assert from 'node:assert/strict';
import test from 'node:test';
import { JOURNEY_TOPICS, normalizeHelpQuery, searchJourneyTopics } from '../../resources/js/src/data/journeyMetadata.js';

test('journey metadata has stable IDs and actionable contracts', () => {
    assert.equal(JOURNEY_TOPICS.length, 71);
    assert.equal(new Set(JOURNEY_TOPICS.map((topic) => topic.id)).size, JOURNEY_TOPICS.length);
    assert.deepEqual(JOURNEY_TOPICS.filter((topic) => topic.id.startsWith('AUTH-')).map((topic) => topic.id), ['AUTH-01', 'AUTH-02']);
    assert.equal(JOURNEY_TOPICS.find((topic) => topic.id === 'AUTH-01').route, '/screeners');
    for (const topic of JOURNEY_TOPICS) {
        assert.match(topic.id, /^(AUTH|SCR|STR|REC|EXE|E2E|AI)-\d+$/);
        assert.ok(topic.route && topic.guide && Array.isArray(topic.steps));
    }
});

test('journey ranking is deterministic and uses aliases', () => {
    const first = searchJourneyTopics('new screener').map((topic) => topic.id);
    const second = searchJourneyTopics('new screener').map((topic) => topic.id);
    assert.deepEqual(first, second);
    assert.equal(first[0], 'SCR-01');
});

test('assistant journeys are discoverable through deterministic help', () => {
    assert.deepEqual(JOURNEY_TOPICS.filter(topic => topic.id.startsWith('AI-')).map(topic => topic.id), ['AI-01', 'AI-02', 'AI-03', 'AI-04', 'AI-05', 'AI-06', 'AI-07', 'AI-08', 'AI-09']);
    assert.ok(searchJourneyTopics('grounded product question').some(topic => topic.id === 'AI-01'));
});

test('exact titles, keywords, normalization, typo tolerance, current page and bounded history rank deterministically', () => {
    assert.equal(searchJourneyTopics('How do I create a screener?')[0].id, 'SCR-01');
    assert.equal(searchJourneyTopics('nested logic')[0].id, 'SCR-03');
    assert.equal(searchJourneyTopics('screening rule')[0].id, 'SCR-01');
    assert.ok(searchJourneyTopics('stock filter').some((topic) => topic.id === 'SCR-01'));
    assert.equal(searchJourneyTopics('create strategy using existing screener')[0].id, 'STR-01');
    assert.equal(searchJourneyTopics('screner').some((topic) => topic.id.startsWith('SCR-')), true);
    assert.equal(normalizeHelpQuery('  CrÉate—Screener! '), 'create screener');
    const textual = searchJourneyTopics('create screener')[0];
    const contextual = searchJourneyTopics('create screener', { currentPath: '/screeners', history: ['STR-01'] })[0];
    assert.equal(contextual.textScore, textual.textScore);
    assert.deepEqual(searchJourneyTopics('create screener'), searchJourneyTopics('create screener'));
});

test('metadata is generated only from current journey sources and has no favorites contract', () => {
    assert.ok(JOURNEY_TOPICS.every((topic) => !('favorite' in topic) && !('pinned' in topic)));
    assert.equal(JOURNEY_TOPICS.some((topic) => topic.id === 'DELETED-999'), false);
    assert.equal(JOURNEY_TOPICS.find((topic) => topic.id === 'SCR-01').steps.length, 9);
    assert.ok(JOURNEY_TOPICS.find((topic) => topic.id === 'REC-07').prerequisites.length > 0);
    assert.ok(JOURNEY_TOPICS.find((topic) => topic.id === 'EXE-03').warnings.length > 0);
});
