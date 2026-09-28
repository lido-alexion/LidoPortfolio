/**
 * Investor guided tour — configuration-driven steps (FEAT-061).
 */
export const INVESTOR_TOUR_VERSION = 'investor-core-v1';

export const INVESTOR_TOUR_STEPS = [
    {
        id: 'navigation',
        route: '/',
        target: '#lido-primary-sidebar',
        titleKey: 'guidedTour.step.navigation.title',
        bodyKey: 'guidedTour.step.navigation.body',
        placement: 'right',
        openSidebar: true,
    },
    {
        id: 'dashboard',
        route: '/',
        target: '[data-tour="page-chrome"]',
        titleKey: 'guidedTour.step.dashboard.title',
        bodyKey: 'guidedTour.step.dashboard.body',
        placement: 'bottom',
    },
    {
        id: 'holdings',
        route: '/holdings',
        target: '[data-tour="holdings-main"]',
        titleKey: 'guidedTour.step.holdings.title',
        bodyKey: 'guidedTour.step.holdings.body',
        placement: 'top',
    },
    {
        id: 'screeners',
        route: '/screeners',
        target: '[data-tour="screeners-main"]',
        titleKey: 'guidedTour.step.screeners.title',
        bodyKey: 'guidedTour.step.screeners.body',
        placement: 'top',
    },
    {
        id: 'recommendations',
        route: '/recommendations',
        target: '[data-tour="page-chrome"]',
        titleKey: 'guidedTour.step.recommendations.title',
        bodyKey: 'guidedTour.step.recommendations.body',
        placement: 'bottom',
    },
    {
        id: 'notifications',
        route: '/',
        target: '[data-tour="header-notifications"]',
        titleKey: 'guidedTour.step.notifications.title',
        bodyKey: 'guidedTour.step.notifications.body',
        placement: 'bottom',
    },
    {
        id: 'help',
        route: '/',
        target: '[data-tour="header-help"]',
        titleKey: 'guidedTour.step.help.title',
        bodyKey: 'guidedTour.step.help.body',
        placement: 'bottom',
    },
    {
        id: 'profile',
        route: '/profile',
        target: '[data-tour="profile-tour-launch"]',
        titleKey: 'guidedTour.step.profile.title',
        bodyKey: 'guidedTour.step.profile.body',
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
