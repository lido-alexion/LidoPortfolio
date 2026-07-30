import React, { useEffect, useMemo, useRef } from 'react';
import { useLocation } from 'react-router-dom';
import { useAuth } from '../../context/AuthContext';
import { useSidebar } from '../../context/SidebarContext';
import useNavFavourites from '../../hooks/useNavFavourites';
import {
    createNavAccessContext,
    ensureNavigationBootstrapped,
    STORAGE_KEYS,
} from '../../navigation';
import {
    buildSidebarNavigation,
    findActiveGroupId,
    findActiveSidebarPageId,
} from '../../utils/navigationTree';
import NavGroup from './NavGroup';
import SidebarFavourites from './SidebarFavourites';
import SidebarQuickActions from './SidebarQuickActions';

ensureNavigationBootstrapped();

const FOCUSABLE_SELECTOR = [
    'a[href]:not([disabled])',
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

function useFocusTrap(containerRef, active) {
    useEffect(() => {
        if (!active) {
            return undefined;
        }

        const container = containerRef.current;
        if (!container) {
            return undefined;
        }

        const previouslyFocused = document.activeElement;
        const focusables = () => Array.from(container.querySelectorAll(FOCUSABLE_SELECTOR))
            .filter((el) => el.offsetParent !== null || el === document.activeElement);

        const initial = focusables();
        if (initial[0]) {
            initial[0].focus();
        }

        const onKeyDown = (event) => {
            if (event.key !== 'Tab') {
                return;
            }
            const items = focusables();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey) {
                if (document.activeElement === first || !container.contains(document.activeElement)) {
                    event.preventDefault();
                    last.focus();
                }
            } else if (document.activeElement === last || !container.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        };

        document.addEventListener('keydown', onKeyDown);
        return () => {
            document.removeEventListener('keydown', onKeyDown);
            if (previouslyFocused && typeof previouslyFocused.focus === 'function') {
                previouslyFocused.focus();
            }
        };
    }, [active, containerRef]);
}

/**
 * Production sidebar — Favourites → Quick Actions → config-driven Navigation.
 */
export default function Sidebar() {
    const { user } = useAuth();
    const { pathname } = useLocation();
    const {
        isLayoutMode,
        isCollapsed,
        isOverlay,
        isOverlayOpen,
        closeOverlay,
        isGroupExpanded,
        toggleGroup,
        expandGroup,
    } = useSidebar();
    const navRef = useRef(null);
    const scrollRef = useRef(null);

    const accessCtx = useMemo(() => createNavAccessContext(user), [user]);
    const fav = useNavFavourites(user?.id, accessCtx);

    const tree = useMemo(
        () => buildSidebarNavigation(undefined, accessCtx),
        [accessCtx],
    );

    const activePageId = useMemo(
        () => findActiveSidebarPageId(pathname, accessCtx),
        [pathname, accessCtx],
    );

    useFocusTrap(navRef, isOverlayOpen);

    useEffect(() => {
        const groupId = findActiveGroupId(pathname, accessCtx);
        if (groupId) {
            expandGroup(groupId);
        }
    }, [pathname, accessCtx, expandGroup]);

    useEffect(() => {
        const el = scrollRef.current;
        if (!el) {
            return undefined;
        }
        try {
            const saved = sessionStorage.getItem(STORAGE_KEYS.SIDEBAR_SCROLL);
            if (saved != null) {
                el.scrollTop = Number(saved) || 0;
            }
        } catch {
            /* ignore */
        }
        const onScroll = () => {
            try {
                sessionStorage.setItem(STORAGE_KEYS.SIDEBAR_SCROLL, String(el.scrollTop));
            } catch {
                /* ignore */
            }
        };
        el.addEventListener('scroll', onScroll, { passive: true });
        return () => el.removeEventListener('scroll', onScroll);
    }, []);

    useEffect(() => {
        if (!isOverlayOpen) {
            return undefined;
        }
        const onKey = (event) => {
            if (event.key === 'Escape') {
                closeOverlay();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [isOverlayOpen, closeOverlay]);

    useEffect(() => {
        if (!isOverlayOpen) {
            return undefined;
        }
        const prev = document.body.style.overflow;
        document.body.style.overflow = 'hidden';
        return () => {
            document.body.style.overflow = prev;
        };
    }, [isOverlayOpen]);

    const onNavigate = isOverlay ? closeOverlay : undefined;

    const shellClass = [
        'lido-sidebar',
        isCollapsed ? 'is-collapsed' : 'is-expanded',
        isLayoutMode ? 'lido-sidebar--layout' : 'lido-sidebar--overlay',
        isOverlayOpen ? 'is-overlay-open' : '',
    ].filter(Boolean).join(' ');

    return (
        <>
            {isOverlay && isOverlayOpen && (
                <button
                    type="button"
                    className="lido-sidebar-backdrop"
                    aria-label="Close navigation"
                    onClick={closeOverlay}
                />
            )}
            <nav
                id="lido-primary-sidebar"
                ref={navRef}
                className={shellClass}
                aria-label="Primary"
                aria-hidden={isOverlay && !isOverlayOpen ? true : undefined}
                {...(isOverlayOpen ? { role: 'dialog', 'aria-modal': true } : {})}
            >
                <div className="lido-sidebar-scroll" ref={scrollRef}>
                    <SidebarFavourites
                        favourites={fav.favourites}
                        collapsed={isCollapsed}
                        onNavigate={onNavigate}
                        onUnpin={fav.unpin}
                        onReorder={fav.reorder}
                        max={fav.max}
                        activePageId={activePageId}
                    />

                    <SidebarQuickActions
                        collapsed={isCollapsed}
                        accessCtx={accessCtx}
                        onDone={onNavigate}
                    />

                    <div className="lido-sidebar-nav-region" aria-label="Navigation">
                        {!isCollapsed && (
                            <div className="lido-sidebar-block-header lido-sidebar-block-header--nav">
                                <span className="lido-sidebar-block-title">Navigation</span>
                            </div>
                        )}
                        {isCollapsed && (
                            <div className="lido-sidebar-section-divider" aria-hidden="true" />
                        )}
                        {tree.map((group) => {
                            const expanded = isCollapsed || isGroupExpanded(group.id);
                            return (
                                <NavGroup
                                    key={group.id}
                                    group={group}
                                    collapsed={isCollapsed}
                                    expanded={expanded}
                                    onNavigate={onNavigate}
                                    onToggle={() => toggleGroup(group.id)}
                                    activePageId={activePageId}
                                    isFavourite={fav.isFavourite}
                                    canPinMore={fav.canPinMore}
                                    onToggleFavourite={fav.toggle}
                                />
                            );
                        })}
                    </div>
                </div>
            </nav>
        </>
    );
}
