import React, { createContext, useCallback, useContext, useEffect, useMemo, useState } from 'react';
import { STORAGE_KEYS } from '../navigation/constants';

const STORAGE_KEY = STORAGE_KEYS.SIDEBAR_COLLAPSED;
const GROUPS_STORAGE_KEY = STORAGE_KEYS.NAV_GROUPS;
/** Sidebar participates in layout (not overlay). */
const MQ_LAYOUT = '(min-width: 1200px)';
/** Always expanded; collapse control hidden. */
const MQ_ULTRA_WIDE = '(min-width: 1600px)';

export const SIDEBAR_WIDTH_EXPANDED_PX = 260;
export const SIDEBAR_WIDTH_COLLAPSED_PX = 64;

const SidebarContext = createContext(null);

function readCollapsedPreference() {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === '1';
    } catch {
        return false;
    }
}

function readGroupExpandedMap() {
    try {
        const raw = window.localStorage.getItem(GROUPS_STORAGE_KEY);
        if (!raw) {
            return {};
        }
        const parsed = JSON.parse(raw);
        return parsed && typeof parsed === 'object' ? parsed : {};
    } catch {
        return {};
    }
}

export function SidebarProvider({ children }) {
    const [isLayoutMode, setIsLayoutMode] = useState(() => (
        typeof window !== 'undefined' ? window.matchMedia(MQ_LAYOUT).matches : true
    ));
    const [isUltraWide, setIsUltraWide] = useState(() => (
        typeof window !== 'undefined' ? window.matchMedia(MQ_ULTRA_WIDE).matches : false
    ));
    const [collapsedDesktop, setCollapsedDesktop] = useState(readCollapsedPreference);
    const [overlayOpen, setOverlayOpen] = useState(false);
    const [groupExpanded, setGroupExpanded] = useState(readGroupExpandedMap);

    useEffect(() => {
        const mqLayout = window.matchMedia(MQ_LAYOUT);
        const mqUltra = window.matchMedia(MQ_ULTRA_WIDE);

        const sync = () => {
            const layout = mqLayout.matches;
            const ultra = mqUltra.matches;
            setIsLayoutMode(layout);
            setIsUltraWide(ultra);
            if (layout) {
                setOverlayOpen(false);
            }
        };

        sync();
        mqLayout.addEventListener('change', sync);
        mqUltra.addEventListener('change', sync);
        return () => {
            mqLayout.removeEventListener('change', sync);
            mqUltra.removeEventListener('change', sync);
        };
    }, []);

    useEffect(() => {
        try {
            window.localStorage.setItem(STORAGE_KEY, collapsedDesktop ? '1' : '0');
        } catch {
            /* ignore */
        }
    }, [collapsedDesktop]);

    useEffect(() => {
        try {
            window.localStorage.setItem(GROUPS_STORAGE_KEY, JSON.stringify(groupExpanded));
        } catch {
            /* ignore */
        }
    }, [groupExpanded]);

    const canCollapse = isLayoutMode && !isUltraWide;
    const isCollapsed = canCollapse && collapsedDesktop;
    const isOverlay = !isLayoutMode;
    const isOverlayOpen = isOverlay && overlayOpen;
    const isExpanded = isLayoutMode
        ? (!collapsedDesktop || isUltraWide)
        : overlayOpen;

    const toggle = useCallback(() => {
        if (!isLayoutMode) {
            setOverlayOpen((prev) => !prev);
            return;
        }
        if (!canCollapse) {
            return;
        }
        setCollapsedDesktop((prev) => !prev);
    }, [isLayoutMode, canCollapse]);

    const openOverlay = useCallback(() => {
        if (!isLayoutMode) {
            setOverlayOpen(true);
        }
    }, [isLayoutMode]);

    const closeOverlay = useCallback(() => {
        setOverlayOpen(false);
    }, []);

    useEffect(() => {
        const onKeyDown = (event) => {
            if (!(event.ctrlKey || event.metaKey) || event.key.toLowerCase() !== 'b') {
                return;
            }
            const target = event.target;
            const tag = target?.tagName;
            if (
                tag === 'INPUT'
                || tag === 'TEXTAREA'
                || tag === 'SELECT'
                || target?.isContentEditable
            ) {
                return;
            }
            event.preventDefault();
            if (!isLayoutMode) {
                setOverlayOpen((prev) => !prev);
                return;
            }
            if (!canCollapse) {
                return;
            }
            setCollapsedDesktop((prev) => !prev);
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [isLayoutMode, canCollapse]);

    const isGroupExpanded = useCallback((groupId) => {
        if (Object.prototype.hasOwnProperty.call(groupExpanded, groupId)) {
            return Boolean(groupExpanded[groupId]);
        }
        return true;
    }, [groupExpanded]);

    const toggleGroup = useCallback((groupId) => {
        setGroupExpanded((prev) => {
            const current = Object.prototype.hasOwnProperty.call(prev, groupId)
                ? Boolean(prev[groupId])
                : true;
            return { ...prev, [groupId]: !current };
        });
    }, []);

    const expandGroup = useCallback((groupId) => {
        if (!groupId) {
            return;
        }
        setGroupExpanded((prev) => {
            if (prev[groupId] === true) {
                return prev;
            }
            return { ...prev, [groupId]: true };
        });
    }, []);

    const value = useMemo(() => ({
        isLayoutMode,
        isUltraWide,
        isOverlay,
        isOverlayOpen,
        canCollapse,
        isCollapsed,
        isExpanded,
        collapsedDesktop,
        toggle,
        openOverlay,
        closeOverlay,
        isGroupExpanded,
        toggleGroup,
        expandGroup,
        widthPx: isCollapsed ? SIDEBAR_WIDTH_COLLAPSED_PX : SIDEBAR_WIDTH_EXPANDED_PX,
    }), [
        isLayoutMode,
        isUltraWide,
        isOverlay,
        isOverlayOpen,
        canCollapse,
        isCollapsed,
        isExpanded,
        collapsedDesktop,
        toggle,
        openOverlay,
        closeOverlay,
        isGroupExpanded,
        toggleGroup,
        expandGroup,
    ]);

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
