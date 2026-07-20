import React, { useEffect, useState } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FOOTER_NAV_ITEMS } from '../config/mainNav';

export default function AppBottomNav() {
    const { pathname } = useLocation();
    const [pinned, setPinned] = useState(false);

    useEffect(() => {
        document.documentElement.classList.toggle('lido-footer-visible', pinned);

        return () => {
            document.documentElement.classList.remove('lido-footer-visible');
        };
    }, [pinned]);

    useEffect(() => {
        setPinned(false);
    }, [pathname]);

    return (
        <nav
            id="lido-bottom-nav"
            className={`lido-bottom-nav${pinned ? ' is-pinned' : ''}`}
            aria-label="Footer navigation"
        >
            <div className="lido-bottom-nav-links">
                {FOOTER_NAV_ITEMS.map((tab) => {
                    const isActive = tab.match(pathname);
                    return (
                        <NavLink
                            key={tab.to}
                            to={tab.to}
                            end={tab.end}
                            className={`nav-link${isActive ? ' active' : ''}`}
                        >
                            {tab.label}
                        </NavLink>
                    );
                })}
            </div>
            <button
                type="button"
                className="lido-bottom-nav-reveal"
                aria-label={pinned ? 'Unpin footer navigation' : 'Pin footer navigation open'}
                aria-pressed={pinned}
                onClick={() => setPinned((current) => !current)}
            />
        </nav>
    );
}
