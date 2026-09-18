import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../api';

const NotificationContext = createContext(null);

export function NotificationProvider({ children }) {
    const [items, setItems] = useState([]);
    const [meta, setMeta] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    const refresh = useCallback(async () => {
        setLoading(true);
        setError(null);
        try {
            const response = await api.get('/notification-center?per_page=5', { skipErrorToast: true });
            setItems(Array.isArray(response.data?.data) ? response.data.data : []);
            setMeta(response.data?.meta || { unread_count: 0, active_critical_count: 0 });
        } catch (requestError) {
            setError(requestError);
            // Notifications must never prevent the application shell from loading.
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        refresh();
        const timer = window.setInterval(refresh, 60000);
        return () => window.clearInterval(timer);
    }, [refresh]);

    const value = useMemo(() => ({ items, meta, loading, error, refresh }), [items, meta, loading, error, refresh]);
    return <NotificationContext.Provider value={value}>{children}</NotificationContext.Provider>;
}

export function useNotifications() {
    const context = useContext(NotificationContext);
    if (!context) {
        throw new Error('useNotifications must be used inside NotificationProvider');
    }
    return context;
}
