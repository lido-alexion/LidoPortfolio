import React, { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FOOTER_NAV_ITEMS } from '../config/mainNav';

const BOTTOM_ZONE_PX = 48;
const HIDE_DELAY_MS = 350;

export default function AppBottomNav() {
    const { pathname } = useLocation();
    const [pinned, setPinned] = useState(false);
    const [hoverOpen, setHoverOpen] = useState(false);
    const hideTimer = useRef(null);
    const hoveringNav = useRef(false);
    const hoveringZone = useRef(false);

    const visible = pinned || hoverOpen;

    const clearHideTimer = useCallback(() => {
        if (hideTimer.current !== null) {
            window.clearTimeout(hideTimer.current);
            hideTimer.current = null;
        }
    }, []);

    const recomputeOpen = useCallback(() => {
        if (pinned) {
            setHoverOpen(true);
            return;
        }
        setHoverOpen(hoveringNav.current || hoveringZone.current);
    }, [pinned]);

    const scheduleHide = useCallback(() => {
        if (pinned) {
            return;
        }
        clearHideTimer();
        hideTimer.current = window.setTimeout(() => {
            if (!hoveringNav.current && !hoveringZone.current) {
                setHoverOpen(false);
            }
            hideTimer.current = null;
        }, HIDE_DELAY_MS);
    }, [clearHideTimer, pinned]);

    useEffect(() => {
        document.documentElement.classList.toggle('lido-footer-visible', pinned);

        return () => {
            document.documentElement.classList.remove('lido-footer-visible');
        };
    }, [pinned]);

    useEffect(() => {
        const onMouseMove = (event) => {
            if (pinned) {
                return;
            }
            const inZone = event.clientY >= window.innerHeight - BOTTOM_ZONE_PX;
            if (inZone !== hoveringZone.current) {
                hoveringZone.current = inZone;
                if (inZone) {
                    clearHideTimer();
                    recomputeOpen();
                } else {
                    scheduleHide();
                }
            }
        };

        window.addEventListener('mousemove', onMouseMove, { passive: true });
        return () => window.removeEventListener('mousemove', onMouseMove);
    }, [clearHideTimer, pinned, recomputeOpen, scheduleHide]);

    useEffect(() => () => clearHideTimer(), [clearHideTimer]);

    useEffect(() => {
        setPinned(false);
        setHoverOpen(false);
        hoveringNav.current = false;
        hoveringZone.current = false;
        clearHideTimer();
    }, [pathname, clearHideTimer]);

    const onNavEnter = () => {
        hoveringNav.current = true;
        clearHideTimer();
        recomputeOpen();
    };

    const onNavLeave = () => {
        hoveringNav.current = false;
        scheduleHide();
        recomputeOpen();
    };

    const togglePin = () => {
        setPinned((current) => {
            const next = !current;
            if (next) {
                clearHideTimer();
                setHoverOpen(true);
            } else {
                scheduleHide();
            }
            return next;
        });
    };

    return (
        <>
            <nav
                id="lido-bottom-nav"
                className={`lido-bottom-nav${visible ? ' lido-bottom-nav--visible' : ''}`}
                aria-label="Footer navigation"
                aria-hidden={!visible}
                onMouseEnter={onNavEnter}
                onMouseLeave={onNavLeave}
            >
                {FOOTER_NAV_ITEMS.map((tab) => {
                    const isActive = tab.match(pathname);
                    return (
                        <NavLink
                            key={tab.to}
                            to={tab.to}
                            end={tab.end}
                            className={`nav-link${isActive ? ' active' : ''}`}
                            tabIndex={visible ? 0 : -1}
                        >
                            {tab.label}
                        </NavLink>
                    );
                })}
            </nav>
            <button
                type="button"
                className={`lido-bottom-nav-reveal${visible ? ' lido-bottom-nav-reveal--active' : ''}${pinned ? ' lido-bottom-nav-reveal--pinned' : ''}`}
                aria-label={pinned ? 'Unpin footer navigation' : 'Pin footer navigation open'}
                aria-expanded={visible}
                aria-controls="lido-bottom-nav"
                id="lido-bottom-nav-reveal"
                onClick={togglePin}
            />
        </>
    );
}
