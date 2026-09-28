const ADVANCED_PREFERENCE_PREFIX = 'lido.fundamentals.advancedExpanded.user.';

export function advancedFundamentalsPreferenceKey(userId) {
    const normalized = Number.isFinite(Number(userId)) && Number(userId) > 0 ? String(Number(userId)) : 'anonymous';
    return `${ADVANCED_PREFERENCE_PREFIX}${normalized}`;
}
