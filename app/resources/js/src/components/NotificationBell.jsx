import React, { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { useNotifications } from '../context/NotificationContext';

export default function NotificationBell() {
    const { items, meta, refresh } = useNotifications();
    const [open, setOpen] = useState(false);
    const rootRef = useRef(null);

    useEffect(() => {
        const close = (event) => {
            if (!rootRef.current?.contains(event.target)) setOpen(false);
        };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, []);

    const openNotification = async (id) => {
        try {
            await api.post(`/notification-center/${id}/read`, null, { skipErrorToast: true });
            await refresh();
        } catch {
            // Navigation remains available if the attention update fails.
        }
        setOpen(false);
    };

    return (
        <div className="position-relative" ref={rootRef}>
            <button
                type="button"
                className="btn btn-link text-reset position-relative p-2"
                aria-label={`${meta.unread_count || 0} unread notifications`}
                aria-expanded={open}
                onClick={() => setOpen((value) => !value)}
            >
                <i className="bi bi-bell" aria-hidden="true" />
                {meta.unread_count > 0 && (
                    <span className="position-absolute top-0 start-100 translate-middle badge rounded-pill text-bg-danger">
                        {meta.unread_count > 99 ? '99+' : meta.unread_count}
                    </span>
                )}
            </button>
            {open && (
                <div className="dropdown-menu dropdown-menu-end show p-0 shadow" style={{ width: 'min(24rem, calc(100vw - 2rem))' }}>
                    <div className="px-3 py-2 border-bottom fw-semibold">Notifications</div>
                    {items.length === 0 ? (
                        <div className="px-3 py-4 text-muted small">No notifications yet.</div>
                    ) : items.map((item) => (
                        <Link
                            key={item.id}
                            to={`/notification-history?notification=${item.id}`}
                            className={`dropdown-item text-wrap py-2 border-bottom${item.attention_state === 'unread' ? ' fw-semibold' : ''}`}
                            onClick={() => openNotification(item.id)}
                        >
                            <span className={`badge me-2 ${item.severity === 'critical' ? 'text-bg-danger' : item.severity === 'action_required' ? 'text-bg-warning' : 'text-bg-secondary'}`}>
                                {item.severity.replace('_', ' ')}
                            </span>
                            {item.title}
                        </Link>
                    ))}
                    <Link to="/notification-history" className="dropdown-item text-center py-2" onClick={() => setOpen(false)}>
                        View all notifications
                    </Link>
                </div>
            )}
        </div>
    );
}
