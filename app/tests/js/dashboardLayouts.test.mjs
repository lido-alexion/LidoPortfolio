import assert from 'node:assert/strict';
import test from 'node:test';
import { factoryDashboardLayout, migrateDashboardLayout, toggleCard } from '../../resources/js/src/utils/dashboardLayouts.js';

test('layout migration preserves variants and adds new cards', () => {
    const layout = migrateDashboardLayout({ desktop: { cards: [{ id: 'alerts', visible: false }] } });
    assert.equal(layout.schema, 'stox.dashboard.layout');
    assert.equal(layout.mobile.cards.length > 1, true);
    assert.equal(layout.desktop.cards.some((card) => card.id === 'portfolio-summary'), true);
});

test('layout migration keeps core summary fields and at least one card visible', () => {
    const layout = factoryDashboardLayout();
    layout.desktop.cards.forEach((card) => { card.visible = false; });
    layout.desktop.summaryFields[0].visible = false;
    const migrated = migrateDashboardLayout(layout);
    assert.equal(migrated.desktop.cards.some((card) => card.visible), true);
    assert.equal(migrated.desktop.summaryFields[0].visible, true);
    assert.equal(toggleCard(migrated, 'desktop', 'portfolio-summary', false).desktop.cards.find((card) => card.id === 'portfolio-summary').visible, true);
});
