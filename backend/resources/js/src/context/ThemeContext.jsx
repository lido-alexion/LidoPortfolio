import React, { createContext, useContext, useEffect, useMemo, useState } from 'react';
import {
    STORAGE_KEY,
    applyResolvedTheme,
    readStoredThemePreference,
    resolveThemePreference,
} from '../themeInit';

const ThemeContext = createContext(null);

export function ThemeProvider({ children }) {
    const [theme, setThemeState] = useState(readStoredThemePreference);
    const [resolvedTheme, setResolvedTheme] = useState(() => resolveThemePreference(readStoredThemePreference()));

    useEffect(() => {
        const resolved = resolveThemePreference(theme);
        setResolvedTheme(resolved);
        applyResolvedTheme(resolved);
    }, [theme]);

    useEffect(() => {
        if (theme !== 'system') {
            return undefined;
        }

        const media = window.matchMedia('(prefers-color-scheme: dark)');
        const onChange = () => {
            const resolved = resolveThemePreference('system');
            setResolvedTheme(resolved);
            applyResolvedTheme(resolved);
        };

        media.addEventListener('change', onChange);
        return () => media.removeEventListener('change', onChange);
    }, [theme]);

    const setTheme = (next) => {
        const value = next === 'light' || next === 'dark' || next === 'system' ? next : 'dark';
        localStorage.setItem(STORAGE_KEY, value);
        setThemeState(value);
    };

    const value = useMemo(
        () => ({ theme, resolvedTheme, setTheme }),
        [theme, resolvedTheme],
    );

    return (
        <ThemeContext.Provider value={value}>
            {children}
        </ThemeContext.Provider>
    );
}

export function useTheme() {
    const context = useContext(ThemeContext);
    if (!context) {
        throw new Error('useTheme must be used within ThemeProvider');
    }
    return context;
}
