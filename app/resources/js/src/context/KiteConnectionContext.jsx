import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import api from '../api';
import { tosData } from '../utils/tosEnvelope';
import { PORTFOLIO_CHANGED } from '../utils/portfolioEvents';
import { useLocation } from 'react-router-dom';

const KiteConnectionContext = createContext(null);

function validStatus(value) {
    return value && typeof value.configured === 'boolean'
        && typeof value.connected === 'boolean' && typeof value.usable === 'boolean';
}

export function KiteConnectionProvider({ user, children }) {
    const { pathname } = useLocation();
    const [status, setStatus] = useState(null);
    const [state, setState] = useState('loading');

    const refresh = useCallback(async () => {
        setState('loading');
        try {
            const response = await api.get('/v1/broker/status', { skipErrorToast: true });
            const next = tosData(response);
            if (!validStatus(next)) throw new Error('Invalid broker status');
            setStatus(next);
            setState('confirmed');
            return next;
        } catch {
            setStatus(null);
            setState('unavailable');
            return null;
        }
    }, []);

    useEffect(() => {
        if (user && !user.is_admin && (pathname === '/' || pathname.startsWith('/settings/account'))) refresh();
    }, [pathname, refresh, user?.id, user?.is_admin]);

    useEffect(() => {
        if (!user || user.is_admin) {
            setStatus(null);
            setState('unavailable');
            return undefined;
        }
        refresh();
        const onVisible = () => { if (document.visibilityState === 'visible') refresh(); };
        const onPortfolioChanged = () => refresh();
        const timer = window.setInterval(refresh, 60_000);
        window.addEventListener('focus', onVisible);
        document.addEventListener('visibilitychange', onVisible);
        window.addEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
        window.addEventListener('kite-connection-refresh', refresh);
        return () => {
            window.clearInterval(timer);
            window.removeEventListener('focus', onVisible);
            document.removeEventListener('visibilitychange', onVisible);
            window.removeEventListener(PORTFOLIO_CHANGED, onPortfolioChanged);
            window.removeEventListener('kite-connection-refresh', refresh);
        };
    }, [user?.id, user?.is_admin, refresh]);

    const value = useMemo(() => ({ status, state, refresh }), [status, state, refresh]);
    return <KiteConnectionContext.Provider value={value}>{children}</KiteConnectionContext.Provider>;
}

export function useKiteConnection() {
    const value = useContext(KiteConnectionContext);
    if (!value) throw new Error('useKiteConnection must be used within KiteConnectionProvider');
    return value;
}
