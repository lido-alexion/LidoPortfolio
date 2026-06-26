import React from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { MAIN_TAB_ITEMS } from '../config/mainNav';

export default function AppTabs() {
    const { pathname } = useLocation();

    return (
        <ul className="nav nav-tabs mb-4" role="tablist">
            {MAIN_TAB_ITEMS.map((tab) => {
                const isActive = tab.match(pathname);
                return (
                    <li className="nav-item" key={tab.to} role="presentation">
                        <NavLink
                            to={tab.to}
                            end={tab.end}
                            className={`nav-link${isActive ? ' active' : ''}`}
                            role="tab"
                            aria-selected={isActive}
                        >
                            {tab.label}
                        </NavLink>
                    </li>
                );
            })}
        </ul>
    );
}
