export function buildAdminOperationalAlertsToastMessage(count) {
    const label = count === 1 ? 'alert' : 'alerts';
    return `You have ${count} operational ${label}. Open Settings → Admin alerts to review.`;
}
