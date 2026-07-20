import React, { useCallback, useEffect, useRef, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FOOTER_NAV_ITEMS } from '../config/mainNav';

const HIDE_DELAY_MS = 250;

export default function AppBottomNav() {
    const { pathname } = useLocation();
    const [pinned, setPinned] = useState(false);
    const [hovering, setHovering] = useState(false);
    const hideTimer = useRef(null);

    const visible = pinned || hovering;

    const clearHideTimer = useCallback(() => {
        if (hideTimer.current !== null) {
            window.clearTimeout(hideTimer.current);
            hideTimer.current = null;
        }
    }, []);

    const show = useCallback(() => {
        clearHideTimer();
        setHovering(true);
    }, [clearHideTimer]);

    const scheduleHide = useCallback(() => {
        if (pinned) {
            return;
        }
        clearHideTimer();
        hideTimer.current = window.setTimeout(() => {
            setHovering(false);
            hideTimer.current = null;
        }, HIDE_DELAY_MS);
    }, [clearHideTimer, pinned]);

    const togglePin = useCallback(() => {
        setPinned((current) => {
            const next = !current;
            if (next) {
                clearHideTimer();
                setHovering(true);
            }
            return next;
        });
    }, [clearHideTimer]);

    useEffect(() => {
        document.documentElement.classList.toggle('lido-footer-visible', pinned);

        return () => {
            document.documentElement.classList.remove('lido-footer-visible');
        };
    }, [pinned]);

    useEffect(() => () => clearHideTimer(), [clearHideTimer]);

    useEffect(() => {
        setPinned(false);
        setHovering(false);
        clearHideTimer();
    }, [pathname, clearHideTimer]);

    return (
        <div
            className={`lido-bottom-nav-shell${visible ? ' lido-bottom-nav-shell--open' : ''}${pinned ? ' lido-bottom-nav-shell--pinned' : ''}`}
            onMouseEnter={show}
            onMouseLeave={scheduleHide}
        >
            <nav
                id="lido-bottom-nav"
                className={`lido-bottom-nav${visible ? ' lido-bottom-nav--visible' : ''}`}
                aria-label="Footer navigation"
                aria-hidden={!visible}
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
        </div>
    );
}
