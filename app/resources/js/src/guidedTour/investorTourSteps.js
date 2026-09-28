/**
 * Investor guided tour — configuration-driven steps (FEAT-061).
 */
export const INVESTOR_TOUR_VERSION = 'investor-core-v1';

export const INVESTOR_TOUR_STEPS = [
    {
        id: 'navigation',
        route: '/',
        target: '#lido-primary-sidebar',
        title: 'Navigation',
        body: 'Use the sidebar to move between portfolio, screeners, recommendations, and other areas.',
        placement: 'right',
        openSidebar: true,
    },
    {
        id: 'dashboard',
        route: '/',
        target: '[data-tour="page-chrome"]',
        title: 'Your dashboard',
        body: 'The header shows where you are. The home dashboard summarizes portfolio health and recent activity.',
        placement: 'bottom',
    },
    {
        id: 'holdings',
        route: '/holdings',
        target: '[data-tour="holdings-main"]',
        title: 'Holdings',
        body: 'Review positions, cost, and performance. Open a stock for prices, depth, and analysis.',
        placement: 'top',
    },
    {
        id: 'screeners',
        route: '/screeners',
        target: '[data-tour="screeners-main"]',
        title: 'Screeners',
        body: 'Build and run screens to discover candidates that match your rules.',
        placement: 'top',
    },
    {
        id: 'recommendations',
        route: '/recommendations',
        target: '[data-tour="page-chrome"]',
        title: 'Recommendations',
        body: 'Track strategy recommendations and decisions tied to your portfolio workflow.',
        placement: 'bottom',
    },
    {
        id: 'notifications',
        route: '/',
        target: '[data-tour="header-notifications"]',
        title: 'Notifications',
        body: 'Alerts and updates appear here. Tune delivery in notification settings.',
        placement: 'bottom',
    },
    {
        id: 'help',
        route: '/',
        target: '[data-tour="header-help"]',
        title: 'Help & documentation',
        body: 'Open contextual help for the current page, or browse the full documentation library.',
        placement: 'bottom',
    },
    {
        id: 'profile',
        route: '/profile',
        target: '[data-tour="profile-tour-launch"]',
        title: 'Profile & tour',
        body: 'Update your account here. You can relaunch this guided tour anytime from Profile.',
        placement: 'top',
    },
];

export function resolveTourStep(stepId) {
    if (!stepId) {
        return INVESTOR_TOUR_STEPS[0] ?? null;
    }
    return INVESTOR_TOUR_STEPS.find((step) => step.id === stepId)
        ?? INVESTOR_TOUR_STEPS[0]
        ?? null;
}

export function stepIndex(stepId) {
    const idx = INVESTOR_TOUR_STEPS.findIndex((step) => step.id === stepId);
    return idx >= 0 ? idx : 0;
}

export function nextTourStep(stepId) {
    const next = INVESTOR_TOUR_STEPS[stepIndex(stepId) + 1];
    return next ?? null;
}
