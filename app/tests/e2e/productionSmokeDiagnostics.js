const AUTHENTICATED_SMOKE_STAGES = new Set([
    'configuration',
    'browser_launch',
    'login',
    'review',
    'combo_navigation',
    'combo_preference_response',
    'combo_preference_scope',
    'combo_chart_attached',
    'combo_chart_visible',
    'combo_default_selection',
    'combo_default_options',
    'combo_default_resolution',
    'combo_default_precedence',
    'combo_chart_data_rendered',
    'combo_keyboard_navigation',
    'combo_legend_toggle',
    'combo_selection_restore',
    'request_safety',
]);

// Only fixed labels may cross the production-smoke logging boundary.
export function safeAuthenticatedSmokeStage(stage) {
    return AUTHENTICATED_SMOKE_STAGES.has(stage) ? stage : 'unknown';
}
