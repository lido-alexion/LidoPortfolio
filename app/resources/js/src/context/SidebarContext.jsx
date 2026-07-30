import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';

const STORAGE_KEY = 'lido-sidebar-collapsed';
const MQ_DESKTOP = '(min-width: 992px)';

const SidebarContext = createContext(null);

function readCollapsedPreference() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

export function SidebarProvider({ children }) {
    const [isDesktop, setIsDesktop] = useState(() => (
        typeof window !== 'undefined' ? window.matchMedia(MQ_DESKTOP).matches : true
    ));
    const [collapsedDesktop, setCollapsedDesktop] = useState(readCollapsedPreference);
    const [mobileOpen, setMobileOpen] = useState(false);

    useEffect(() => {
        const mq = window.matchMedia(MQ_DESKTOP);
        const onChange = () => {
            setIsDesktop(mq.matches);
            if (mq.matches) {
                setMobileOpen(false);
            }
        };
        onChange();
        mq.addEventListener('change', onChange);
        return () => mq.removeEventListener('change', onChange);
    }, []);

    useEffect(() => {
        try {
            window.localStorage.setItem(STORAGE_KEY, collapsedDesktop ? '1' : '0');
        } catch {
            /* ignore */
        }
    }, [collapsedDesktop]);

    const isOpen = isDesktop ? !collapsedDesktop : mobileOpen;

    const toggle = useCallback(() => {
        if (isDesktop) {
            setCollapsedDesktop((prev) => !prev);
        } else {
            setMobileOpen((prev) => !prev);
        }
    }, [isDesktop]);

    const closeMobile = useCallback(() => {
        setMobileOpen(false);
    }, []);

    const value = useMemo(() => ({
        isDesktop,
        isOpen,
        mobileOpen,
        collapsedDesktop,
        toggle,
        closeMobile,
    }), [isDesktop, isOpen, mobileOpen, collapsedDesktop, toggle, closeMobile]);

    return (
        <SidebarContext.Provider value={value}>
            {children}
        </SidebarContext.Provider>
    );
}

export function useSidebar() {
    const ctx = useContext(SidebarContext);
    if (!ctx) {
        throw new Error('useSidebar must be used within SidebarProvider');
    }
    return ctx;
}
