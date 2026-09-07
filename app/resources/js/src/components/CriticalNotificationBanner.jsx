import React from 'react';
import { Link } from 'react-router-dom';
import { useNotifications } from '../context/NotificationContext';

export default function CriticalNotificationBanner() {
    const { items, meta } = useNotifications();
    const count = Number(meta.active_critical_count || 0);
    if (count === 0) return null;

    const critical = items.find((item) => item.severity === 'critical' && item.condition_state === 'active');
    const destination = count === 1 && critical
        ? `/notification-history?notification=${critical.id}`
        : '/notification-history?view=critical';

    return (
        <div className="alert alert-danger rounded-0 border-start-0 border-end-0 mb-0 py-2" role="alert">
            <div className="container-fluid d-flex align-items-center justify-content-between gap-3">
                <span>
                    <i className="bi bi-exclamation-octagon-fill me-2" aria-hidden="true" />
                    {count === 1 ? (critical?.title || 'A critical condition needs attention.') : `${count} critical conditions need attention.`}
                </span>
                <Link className="alert-link text-nowrap" to={destination}>View details</Link>
            </div>
        </div>
    );
}
