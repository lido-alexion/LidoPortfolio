import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import api from '../api';
import { ensureCsrfCookie, resetCsrfCookie } from '../auth/csrf';
import { consumeRedirectPath, saveRedirectPath } from '../auth/redirect';
import { clearDashboardCachesForUser } from '../utils/dashboardCache';
import { setAuthSessionUserId } from '../auth/sessionContext';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [sessionExpired, setSessionExpired] = useState(false);
    const userRef = useRef(null);
    const identityGeneration = useRef(0);
    userRef.current = user;

    const refreshUser = useCallback(async () => {
        const generation = identityGeneration.current;
        try {
            const res = await api.get('/auth/me');
            if (generation !== identityGeneration.current) return null;
            const observedUser = res.data.user || null;
            const previousUserId = userRef.current?.id;
            if (previousUserId != null && String(observedUser?.id ?? '') !== String(previousUserId)) {
                // The shared role cookie was replaced in another tab. Do not
                // adopt that principal into this tab's existing page state.
                identityGeneration.current += 1;
                setUser(null);
                setAuthSessionUserId(null);
                setSessionExpired(true);
                return null;
            }
            setUser(observedUser);
            setAuthSessionUserId(observedUser?.id);
            setSessionExpired(false);
            return observedUser;
        } catch (error) {
            if (generation !== identityGeneration.current) return null;
            if (error?.response?.status === 401) {
                setUser(null);
                setAuthSessionUserId(null);
            }
            return null;
        }
    }, []);

    useEffect(() => {
        localStorage.removeItem('portfolio_token');

        let cancelled = false;

        (async () => {
            try {
                await ensureCsrfCookie();
            } catch {
                // Login will retry with force=true
            }
            await refreshUser();
            if (!cancelled) {
                setLoading(false);
            }
        })();

        const onUnauthorized = () => {
            identityGeneration.current += 1;
            setAuthSessionUserId(null);
            const wasLoggedIn = Boolean(userRef.current);
            setUser(null);
            if (wasLoggedIn) {
                setSessionExpired(true);
                saveRedirectPath(window.location.pathname);
            }
        };

        window.addEventListener('portfolio-unauthorized', onUnauthorized);

        return () => {
            cancelled = true;
            window.removeEventListener('portfolio-unauthorized', onUnauthorized);
        };
    }, [refreshUser]);

    const login = useCallback(async ({ email, password, remember = false }) => {
        identityGeneration.current += 1;
        setSessionExpired(false);

        const attempt = async () => {
            await ensureCsrfCookie({ force: true });
            return api.post('/auth/login', { email, password, remember });
        };

        try {
            const res = await attempt();
            identityGeneration.current += 1;
            setUser(res.data.user);
            setAuthSessionUserId(res.data.user?.id);
            return res.data.user;
        } catch (error) {
            if (error?.response?.status === 419) {
                resetCsrfCookie();
                const res = await attempt();
                identityGeneration.current += 1;
                setUser(res.data.user);
                setAuthSessionUserId(res.data.user?.id);
                return res.data.user;
            }
            throw error;
        }
    }, []);

    const register = useCallback(async () => {
        throw new Error('Registration is invite-only. Use the link from your administrator.');
    }, []);

    const logout = useCallback(async () => {
        identityGeneration.current += 1;
        const previousUserId = userRef.current?.id;
        try {
            await api.post('/auth/logout', {}, { skipErrorToast: true });
        } catch (error) {
            // A 401 means there is no authenticated session left to end.
            if (error?.response?.status !== 401) {
                throw error;
            }
        }
        resetCsrfCookie();
        clearDashboardCachesForUser(previousUserId);
        setAuthSessionUserId(null);
        setUser(null);
        setSessionExpired(false);
        return true;
    }, []);

    const value = useMemo(() => ({
        user,
        loading,
        isAuthenticated: Boolean(user),
        sessionExpired,
        login,
        register,
        logout,
        refreshUser,
        consumeRedirectPath,
    }), [user, loading, sessionExpired, login, register, logout, refreshUser]);

    return (
        <AuthContext.Provider value={value}>
            {children}
        </AuthContext.Provider>
    );
}

export function useAuth() {
    const ctx = useContext(AuthContext);
    if (!ctx) {
        throw new Error('useAuth must be used within AuthProvider');
    }
    return ctx;
}

export default AuthContext;
