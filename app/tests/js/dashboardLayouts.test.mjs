import assert from 'node:assert/strict';
import test from 'node:test';
import { accountLayoutStorageKey, factoryDashboardLayout, hasLocalDashboardLayout, inspectDashboardLayoutCompatibility, migrateDashboardLayout, readLocalDashboardLayout, toggleCard } from '../../resources/js/src/utils/dashboardLayouts.js';

test('layout migration preserves variants and adds new cards', () => {
    const layout = migrateDashboardLayout({ desktop: { cards: [{ id: 'alerts', visible: false }] } });
    assert.equal(layout.schema, 'stox.dashboard.layout');
    assert.equal(layout.mobile.cards.length > 1, true);
    assert.equal(layout.desktop.cards.some((card) => card.id === 'portfolio-summary'), true);
});

test('migration drops retired cards and strips unsupported properties while retaining preferences', () => {
    const layout = migrateDashboardLayout({
        schema: 'stox.dashboard.layout', version: 1,
        desktop: { cards: [{ id: 'alerts', order: 3, size: 'medium', visible: false, account_data: 'must not persist' }, { id: 'retired' }], summaryFields: [] },
        mobile: {},
    });
    const alerts = layout.desktop.cards.find((card) => card.id === 'alerts');
    assert.equal(alerts.visible, false);
    assert.equal(alerts.order, 3);
    assert.equal('account_data' in alerts, false);
    assert.equal(layout.desktop.cards.some((card) => card.id === 'retired'), false);
    assert.deepEqual(inspectDashboardLayoutCompatibility({ desktop: { cards: [{ id: 'retired' }] } }), [
        'Unrecognized dashboard schema; compatible settings were recovered where possible.',
        'Unsupported desktop card skipped: retired.',
    ]);
});

test('local layout keys are separated by account', () => {
    assert.notEqual(accountLayoutStorageKey('one'), accountLayoutStorageKey('two'));
});

test('legacy account-scoped keys migrate into the current key without crossing accounts', () => {
    const values = new Map();
    globalThis.localStorage = {
        getItem: (key) => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    const legacy = factoryDashboardLayout();
    legacy.desktop.cards.find((card) => card.id === 'alerts').visible = false;
    values.set('stox_dashboard_layout_v1_user-1', JSON.stringify(legacy));

    assert.equal(hasLocalDashboardLayout('user-1'), true);
    assert.equal(readLocalDashboardLayout('user-1').desktop.cards.find((card) => card.id === 'alerts').visible, false);
    assert.equal(values.has(accountLayoutStorageKey('user-1')), true);
    assert.equal(values.has('stox_dashboard_layout_v1_user-1'), false);
    assert.equal(hasLocalDashboardLayout('user-2'), false);
    assert.equal(readLocalDashboardLayout('user-2').desktop.cards.find((card) => card.id === 'alerts').visible, true);
});

test('layout migration keeps core summary fields and at least one card visible', () => {
    const layout = factoryDashboardLayout();
    layout.desktop.cards.forEach((card) => { card.visible = false; });
    layout.desktop.summaryFields[0].visible = false;
    const migrated = migrateDashboardLayout(layout);
    assert.equal(migrated.desktop.cards.some((card) => card.visible), true);
    assert.equal(migrated.desktop.cards.find((card) => card.id === 'portfolio-summary').visible, true);
    assert.equal(migrated.desktop.summaryFields[0].visible, true);
    assert.equal(toggleCard(migrated, 'desktop', 'portfolio-summary', false).desktop.cards.find((card) => card.id === 'portfolio-summary').visible, true);
});
