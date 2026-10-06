export const DASHBOARD_LAYOUT_SCHEMA = 'stox.dashboard.layout';
export const DASHBOARD_LAYOUT_VERSION = 1;

export const DASHBOARD_SUMMARY_FIELDS = Object.freeze([
    { id: 'portfolio_value', label: 'Portfolio Value', core: true },
    { id: 'invested_value', label: 'Invested Value', core: true },
    { id: 'total_gain_loss', label: 'Total Gain/Loss', core: true },
    { id: 'xirr', label: 'XIRR', core: false },
    { id: 'cash_available', label: 'Cash available', core: false },
]);

export const DASHBOARD_CARDS = Object.freeze([
    { id: 'portfolio-summary', label: 'Portfolio summary', sizes: ['small', 'medium', 'large'], defaultSize: 'large' },
    { id: 'top-movers', label: 'Top movers', sizes: ['small', 'medium'], defaultSize: 'medium' },
    { id: 'market-diagnostics', label: 'Market diagnostics', sizes: ['medium', 'large'], defaultSize: 'large' },
    { id: 'alerts', label: 'Alerts', sizes: ['medium', 'large'], defaultSize: 'large' },
    { id: 'calendar', label: 'Calendar', sizes: ['medium', 'large'], defaultSize: 'medium' },
    { id: 'patterns', label: 'Pattern signals', sizes: ['medium', 'large'], defaultSize: 'large' },
    { id: 'relative-strength', label: 'Relative strength', sizes: ['medium', 'large'], defaultSize: 'medium' },
    { id: 'allocation', label: 'Allocation', sizes: ['medium', 'large'], defaultSize: 'medium' },
    { id: 'portfolio-growth', label: 'Portfolio growth', sizes: ['large'], defaultSize: 'large' },
]);

export function factoryDashboardLayout() {
    const cards = DASHBOARD_CARDS.map((card, index) => ({ id: card.id, order: index, size: card.defaultSize, visible: true }));
    const fields = DASHBOARD_SUMMARY_FIELDS.map((field, index) => ({ id: field.id, order: index, visible: true }));
    return {
        schema: DASHBOARD_LAYOUT_SCHEMA,
        version: DASHBOARD_LAYOUT_VERSION,
        locked: false,
        desktop: { cards, summaryFields: fields },
        mobile: { cards: cards.map((card, index) => ({ ...card, order: index })), summaryFields: fields.map((field, index) => ({ ...field, order: index })) },
    };
}

function migrateVariant(variant, defaults, knownIds) {
    const source = variant && typeof variant === 'object' ? variant : {};
    const byId = new Map(Array.isArray(source.cards) ? source.cards.map((card) => [card?.id, card]) : []);
    const cards = defaults.cards.map((defaultCard) => {
        const saved = byId.get(defaultCard.id);
        return saved && knownIds.has(saved.id)
            ? { id: defaultCard.id, order: Number.isFinite(saved.order) ? Math.max(0, Math.min(100, saved.order)) : defaultCard.order, size: defaultCard.sizes?.includes(saved.size) ? saved.size : defaultCard.size, visible: defaultCard.id === 'portfolio-summary' || saved.visible !== false }
            : { id: defaultCard.id, order: defaultCard.order, size: defaultCard.size, visible: defaultCard.visible };
    }).filter((card) => knownIds.has(card.id));
    if (!cards.some((card) => card.visible !== false)) cards[0].visible = true;
    const fieldsById = new Map(Array.isArray(source.summaryFields) ? source.summaryFields.map((field) => [field?.id, field]) : []);
    const summaryFields = defaults.summaryFields.map((defaultField) => {
        const saved = fieldsById.get(defaultField.id) || {};
        return { id: defaultField.id, order: Number.isFinite(saved.order) ? Math.max(0, Math.min(100, saved.order)) : defaultField.order, visible: saved.visible !== false };
    });
    for (const field of summaryFields) if (DASHBOARD_SUMMARY_FIELDS.find((item) => item.id === field.id)?.core) field.visible = true;
    return { cards, summaryFields };
}

export function migrateDashboardLayout(input) {
    const defaults = factoryDashboardLayout();
    const source = input && typeof input === 'object' ? input : {};
    const knownIds = new Set(DASHBOARD_CARDS.map((card) => card.id));
    return {
        schema: DASHBOARD_LAYOUT_SCHEMA,
        version: DASHBOARD_LAYOUT_VERSION,
        locked: source.locked === true,
        desktop: migrateVariant(source.desktop, defaults.desktop, knownIds),
        mobile: migrateVariant(source.mobile, defaults.mobile, knownIds),
    };
}

export function inspectDashboardLayoutCompatibility(input) {
    const warnings = [];
    if (!input || typeof input !== 'object' || input.schema !== DASHBOARD_LAYOUT_SCHEMA) warnings.push('Unrecognized dashboard schema; compatible settings were recovered where possible.');
    const knownCards = new Set(DASHBOARD_CARDS.map(({ id }) => id));
    const knownFields = new Set(DASHBOARD_SUMMARY_FIELDS.map(({ id }) => id));
    for (const variant of ['desktop', 'mobile']) {
        for (const card of input?.[variant]?.cards || []) if (!knownCards.has(card?.id)) warnings.push(`Unsupported ${variant} card skipped: ${String(card?.id || 'unknown')}.`);
        for (const field of input?.[variant]?.summaryFields || []) if (!knownFields.has(field?.id)) warnings.push(`Unsupported ${variant} summary field skipped: ${String(field?.id || 'unknown')}.`);
    }
    return [...new Set(warnings)];
}

export function accountLayoutStorageKey(userId) {
    return `stox_dashboard_layout_${String(userId || 'unknown')}`;
}

export function readLocalDashboardLayout(userId) {
    try {
        const suffix = String(userId || 'unknown');
        let stored = localStorage.getItem(accountLayoutStorageKey(userId));
        let legacyKey = null;
        for (let version = DASHBOARD_LAYOUT_VERSION; stored === null && version >= 1; version -= 1) {
            legacyKey = `stox_dashboard_layout_v${version}_${suffix}`;
            stored = localStorage.getItem(legacyKey);
        }
        const parsed = JSON.parse(stored || 'null');
        const migrated = migrateDashboardLayout(parsed);
        if (stored !== null && (legacyKey || JSON.stringify(parsed) !== JSON.stringify(migrated))) writeLocalDashboardLayout(userId, migrated);
        return migrated;
    } catch { return factoryDashboardLayout(); }
}

export function hasLocalDashboardLayout(userId) {
    try {
        const suffix = String(userId || 'unknown');
        if (localStorage.getItem(accountLayoutStorageKey(userId)) !== null) return true;
        for (let version = DASHBOARD_LAYOUT_VERSION; version >= 1; version -= 1) if (localStorage.getItem(`stox_dashboard_layout_v${version}_${suffix}`) !== null) return true;
        return false;
    } catch { return false; }
}

export function writeLocalDashboardLayout(userId, layout) {
    const migrated = migrateDashboardLayout(layout);
    localStorage.setItem(accountLayoutStorageKey(userId), JSON.stringify(migrated));
    for (let version = DASHBOARD_LAYOUT_VERSION; version >= 1; version -= 1) localStorage.removeItem(`stox_dashboard_layout_v${version}_${String(userId || 'unknown')}`);
    return migrated;
}

export function toggleCard(layout, variant, cardId, visible) {
    const next = migrateDashboardLayout(layout);
    const cards = next[variant].cards;
    const card = cards.find((item) => item.id === cardId);
    if (!card || (cardId === 'portfolio-summary' && !visible)) return next;
    if (!visible && cards.filter((item) => item.visible !== false).length <= 1) return next;
    card.visible = visible;
    return next;
}
