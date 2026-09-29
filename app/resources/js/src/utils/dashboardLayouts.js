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
            ? { ...defaultCard, ...saved, size: defaultCard.sizes?.includes(saved.size) ? saved.size : defaultCard.size }
            : defaultCard;
    }).filter((card) => knownIds.has(card.id));
    if (!cards.some((card) => card.visible !== false)) cards[0].visible = true;
    const fieldsById = new Map(Array.isArray(source.summaryFields) ? source.summaryFields.map((field) => [field?.id, field]) : []);
    const summaryFields = defaults.summaryFields.map((defaultField) => ({ ...defaultField, ...(fieldsById.get(defaultField.id) || {}) }));
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
        locked: Boolean(source.locked),
        desktop: migrateVariant(source.desktop, defaults.desktop, knownIds),
        mobile: migrateVariant(source.mobile, defaults.mobile, knownIds),
    };
}

export function accountLayoutStorageKey(userId) {
    return `stox_dashboard_layout_v${DASHBOARD_LAYOUT_VERSION}_${String(userId || 'unknown')}`;
}

export function readLocalDashboardLayout(userId) {
    try { return migrateDashboardLayout(JSON.parse(localStorage.getItem(accountLayoutStorageKey(userId)) || 'null')); } catch { return factoryDashboardLayout(); }
}

export function hasLocalDashboardLayout(userId) {
    try { return localStorage.getItem(accountLayoutStorageKey(userId)) !== null; } catch { return false; }
}

export function writeLocalDashboardLayout(userId, layout) {
    const migrated = migrateDashboardLayout(layout);
    localStorage.setItem(accountLayoutStorageKey(userId), JSON.stringify(migrated));
    return migrated;
}

export function toggleCard(layout, variant, cardId, visible) {
    const next = migrateDashboardLayout(layout);
    const cards = next[variant].cards;
    const card = cards.find((item) => item.id === cardId);
    if (!card) return next;
    if (!visible && cards.filter((item) => item.visible !== false).length <= 1) return next;
    card.visible = visible;
    return next;
}
