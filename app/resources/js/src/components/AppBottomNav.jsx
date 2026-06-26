import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { FOOTER_NAV_ITEMS } from '../config/mainNav';

export default function AppBottomNav() {
    const { pathname } = useLocation();

    return (
        <nav className="lido-bottom-nav" aria-label="Footer navigation">
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
        </nav>
    );
}
