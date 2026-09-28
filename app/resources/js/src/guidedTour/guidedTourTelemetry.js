import api from '../api';

export function logGuidedTourEvent(event, extra = {}) {
    api.post('/logs/frontend', {
        level: 'info',
        message: `guided_tour:${event}`,
        url: typeof window !== 'undefined' ? window.location.href : null,
        extra: { category: 'guided_tour', event, ...extra },
    }).catch(() => {
        /* telemetry must not break onboarding */
    });
}
