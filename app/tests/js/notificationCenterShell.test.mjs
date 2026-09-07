import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const app = readFileSync(new URL('../../resources/js/src/App.jsx', import.meta.url), 'utf8');
const header = readFileSync(new URL('../../resources/js/src/components/AppHeader.jsx', import.meta.url), 'utf8');
const bell = readFileSync(new URL('../../resources/js/src/components/NotificationBell.jsx', import.meta.url), 'utf8');
const banner = readFileSync(new URL('../../resources/js/src/components/CriticalNotificationBanner.jsx', import.meta.url), 'utf8');
const center = readFileSync(new URL('../../resources/js/src/pages/NotificationHistoryPage.jsx', import.meta.url), 'utf8');

test('both authenticated role shells share global notification chrome', () => {
    assert.match(app, /<NotificationProvider>/);
    assert.match(app, /<CriticalNotificationBanner\s*\/>/);
    assert.match(header, /user && <NotificationBell\s*\/>/);
});

test('bell shows unread count without marking items merely by opening the panel', () => {
    assert.match(bell, /meta\.unread_count/);
    assert.match(bell, /setOpen\(\(value\) => !value\)/);
    assert.match(bell, /notification-center\/\$\{id\}\/read/);
});

test('critical presentation depends on active condition count and has no dismiss control', () => {
    assert.match(banner, /meta\.active_critical_count/);
    assert.match(banner, /condition_state === 'active'/);
    assert.doesNotMatch(banner, /btn-close|dismiss/i);
});

test('Notification Center provides frozen quick views and attention-only actions', () => {
    for (const view of ['All', 'Needs attention', 'Unread', 'Critical', 'Resolved']) {
        assert.match(center, new RegExp(`'${view}'`));
    }
    assert.match(center, /mark-all-read/);
    assert.doesNotMatch(center, /Retry attempted|Trading OS Telegram deliveries/);
});
