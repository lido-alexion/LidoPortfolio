import React, { useEffect } from 'react';
import { NavLink, useLocation } from 'react-router-dom';
import { PRIMARY_NAV_SECTIONS } from '../config/mainNav';
import { useSidebar } from '../context/SidebarContext';

export default function AppSidebar() {
    const { pathname } = useLocation();
    const { isDesktop, isOpen, mobileOpen, closeMobile } = useSidebar();

    useEffect(() => {
        closeMobile();
    }, [pathname, closeMobile]);

    useEffect(() => {
        if (!mobileOpen) {
            return undefined;
        }
        const onKey = (event) => {
            if (event.key === 'Escape') {
                closeMobile();
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
    }, [mobileOpen, closeMobile]);

    const shellClass = [
        'lido-sidebar',
        isOpen ? 'is-open' : 'is-closed',
        isDesktop ? 'lido-sidebar--desktop' : 'lido-sidebar--mobile',
        mobileOpen ? 'is-mobile-open' : '',
    ].filter(Boolean).join(' ');

    return (
        <>
            {!isDesktop && mobileOpen && (
                <button
                    type="button"
                    className="lido-sidebar-backdrop"
                    aria-label="Close navigation"
                    onClick={closeMobile}
                />
            )}
            <nav id="lido-primary-sidebar" className={shellClass} aria-label="Primary">
                <div className="lido-sidebar-scroll">
                    {PRIMARY_NAV_SECTIONS.map((section) => (
                        <div className="lido-sidebar-section" key={section.id}>
                            <div className="lido-sidebar-section-label">{section.label}</div>
                            <ul className="lido-sidebar-list">
                                {section.items.map((item) => {
                                    const isActive = item.match(pathname);
                                    return (
                                        <li key={item.to}>
                                            <NavLink
                                                to={item.to}
                                                end={item.end}
                                                className={`lido-sidebar-link${isActive ? ' active' : ''}`}
                                                onClick={closeMobile}
                                            >
                                                {item.label}
                                            </NavLink>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ))}
                </div>
            </nav>
        </>
    );
}
