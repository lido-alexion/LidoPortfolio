import React, {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
} from 'react';
import api from '../api';
import { ensureCsrfCookie, resetCsrfCookie } from '../auth/csrf';
import { consumeRedirectPath, saveRedirectPath } from '../auth/redirect';

const AuthContext = createContext(null);

export function AuthProvider({ children }) {
    const [user, setUser] = useState(null);
    const [loading, setLoading] = useState(true);
    const [sessionExpired, setSessionExpired] = useState(false);

    const refreshUser = useCallback(async () => {
        try {
            const res = await api.get('/auth/me');
            setUser(res.data.user || null);
            setSessionExpired(false);
            return res.data.user;
        } catch (error) {
            if (error?.response?.status === 401) {
                setUser(null);
                setSessionExpired(true);
            }
            return null;
        }
    }, []);

    useEffect(() => {
        localStorage.removeItem('portfolio_token');

        let cancelled = false;

        (async () => {
            await refreshUser();
            if (!cancelled) {
                setLoading(false);
            }
        })();

        const onUnauthorized = () => {
            setUser(null);
            setSessionExpired(true);
            saveRedirectPath(window.location.pathname);
        };

        window.addEventListener('portfolio-unauthorized', onUnauthorized);

        return () => {
            cancelled = true;
            window.removeEventListener('portfolio-unauthorized', onUnauthorized);
        };
    }, [refreshUser]);

    const login = useCallback(async ({ email, password, remember = false }) => {
        setSessionExpired(false);
        await ensureCsrfCookie();
        const res = await api.post('/auth/login', { email, password, remember });
        setUser(res.data.user);
        return res.data.user;
    }, []);

    const register = useCallback(async ({ name, email, password, password_confirmation, remember = false }) => {
        setSessionExpired(false);
        await ensureCsrfCookie();
        await api.post('/auth/register', {
            name,
            email,
            password,
            password_confirmation,
            remember,
        });
        const res = await api.post('/auth/login', { email, password, remember });
        setUser(res.data.user);
        return res.data.user;
    }, []);

    const logout = useCallback(async () => {
        try {
            await api.post('/auth/logout');
        } catch {
            // Session may already be invalid.
        }
        resetCsrfCookie();
        setUser(null);
        setSessionExpired(false);
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
