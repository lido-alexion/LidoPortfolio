import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';
import api from '../api';
import { useAuth } from './AuthContext';
import {
    clearActivePortfolioId,
    getActivePortfolioId,
    setActivePortfolioId,
} from '../portfolio/activePortfolioStorage';
import { notifyPortfolioChanged } from '../utils/portfolioEvents';

const PortfolioContext = createContext(null);

export function PortfolioProvider({ children }) {
    const { isAuthenticated, user } = useAuth();
    const [portfolios, setPortfolios] = useState([]);
    const [activePortfolioId, setActivePortfolioIdState] = useState(() => getActivePortfolioId());
    const [loading, setLoading] = useState(false);

    const resolveActiveId = useCallback((list, preferredId) => {
        if (!list.length) {
            return null;
        }
        const preferred = preferredId != null ? String(preferredId) : null;
        if (preferred && list.some((p) => String(p.id) === preferred)) {
            return preferred;
        }
        const defaultPortfolio = list.find((p) => p.is_default) ?? list[0];
        return defaultPortfolio ? String(defaultPortfolio.id) : null;
    }, []);

    const refreshPortfolios = useCallback(async () => {
        const res = await api.get('/portfolios', { skipErrorToast: true });
        const list = res.data?.data ?? [];
        setPortfolios(list);
        return list;
    }, []);

    const bootstrap = useCallback(async () => {
        setLoading(true);
        try {
            const list = await refreshPortfolios();
            const stored = getActivePortfolioId();
            const fallback = user?.default_portfolio_id ?? null;
            const nextId = resolveActiveId(list, stored ?? fallback);
            if (nextId) {
                setActivePortfolioId(nextId);
                setActivePortfolioIdState(nextId);
            } else {
                clearActivePortfolioId();
                setActivePortfolioIdState(null);
            }
        } catch {
            setPortfolios([]);
        } finally {
            setLoading(false);
        }
    }, [refreshPortfolios, resolveActiveId, user?.default_portfolio_id]);

    useEffect(() => {
        if (!isAuthenticated) {
            setPortfolios([]);
            clearActivePortfolioId();
            setActivePortfolioIdState(null);
            return;
        }
        bootstrap();
    }, [isAuthenticated, user?.id, bootstrap]);

    const setActivePortfolio = useCallback((portfolioId) => {
        const id = portfolioId == null ? null : String(portfolioId);
        if (!id) {
            clearActivePortfolioId();
            setActivePortfolioIdState(null);
            return;
        }
        setActivePortfolioId(id);
        setActivePortfolioIdState(id);
        notifyPortfolioChanged(id);
    }, []);

    const activePortfolio = useMemo(
        () => portfolios.find((p) => String(p.id) === String(activePortfolioId)) ?? null,
        [portfolios, activePortfolioId],
    );

    const value = useMemo(() => ({
        portfolios,
        activePortfolio,
        activePortfolioId: activePortfolioId ? Number(activePortfolioId) : null,
        loading,
        refreshPortfolios,
        setActivePortfolio,
        bootstrap,
    }), [
        portfolios,
        activePortfolio,
        activePortfolioId,
        loading,
        refreshPortfolios,
        setActivePortfolio,
        bootstrap,
    ]);

    return (
        <PortfolioContext.Provider value={value}>
            {children}
        </PortfolioContext.Provider>
    );
}

export function usePortfolio() {
    const ctx = useContext(PortfolioContext);
    if (!ctx) {
        throw new Error('usePortfolio must be used within PortfolioProvider');
    }
    return ctx;
}

export default PortfolioContext;
