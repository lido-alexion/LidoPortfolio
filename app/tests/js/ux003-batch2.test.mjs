import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const marketDepth = readFileSync(new URL('../../resources/js/src/pages/MarketDepthPage.jsx', import.meta.url), 'utf8');
const notificationHistory = readFileSync(new URL('../../resources/js/src/pages/NotificationHistoryPage.jsx', import.meta.url), 'utf8');

test('Batch 2 keeps PageChrome authoritative and uses the shared local-mode control', () => {
    assert.match(marketDepth, /import SegmentToggle from ['"]\.\.\/components\/SegmentToggle['"]/);
    assert.match(marketDepth, /value: 'pct'/);
    assert.match(marketDepth, /value: 'count'/);
    assert.doesNotMatch(marketDepth, /function SegmentToggle/);
    assert.match(marketDepth, /<h2 className="h4 mb-0">Market Breadth<\/h2>/);
    assert.match(notificationHistory, /<h2 className="h3 mb-1">Notification Center<\/h2>/);
});

test('Notification History retains its five URL-backed quick views', () => {
    for (const view of ['all', 'needs_attention', 'unread', 'critical', 'resolved']) {
        assert.match(notificationHistory, new RegExp(`\\['${view}',`));
    }
    assert.match(notificationHistory, /useSearchParams/);
});
