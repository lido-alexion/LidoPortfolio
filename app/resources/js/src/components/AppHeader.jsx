import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import HeaderHelpButton from './HeaderHelpButton';
import ProfileMenu from './ProfileMenu';
import PortfolioSwitcher from './PortfolioSwitcher';
import { useSidebar } from '../context/SidebarContext';

function SidebarToggle() {
    const { toggle, isLayoutMode, canCollapse, isCollapsed, isOverlayOpen } = useSidebar();

    if (isLayoutMode && !canCollapse) {
        return null;
    }

    const label = isLayoutMode
        ? (isCollapsed ? 'Expand sidebar' : 'Collapse sidebar')
        : (isOverlayOpen ? 'Close navigation' : 'Open navigation');

    return (
        <button
            type="button"
            className="lido-sidebar-toggle"
            aria-label={label}
            title={`${label} (Ctrl+B)`}
            aria-keyshortcuts="Control+B Meta+B"
            aria-expanded={isLayoutMode ? !isCollapsed : isOverlayOpen}
            aria-controls="lido-primary-sidebar"
            onClick={toggle}
        >
            <span className="lido-sidebar-toggle-bars" aria-hidden="true">
                <span />
                <span />
                <span />
            </span>
        </button>
    );
}

export default function AppHeader({ user, showSidebarToggle = false }) {
    const [isHeaderVisible, setIsHeaderVisible] = useState(true);
    const hideOnScroll = Boolean(user);

    useEffect(() => {
        if (!hideOnScroll) {
            return undefined;
        }

        const handleScroll = () => {
            setIsHeaderVisible(window.pageYOffset < 50);
        };

        window.addEventListener('scroll', handleScroll);
        return () => window.removeEventListener('scroll', handleScroll);
    }, [hideOnScroll]);

    return (
        <header className={`lido-header${isHeaderVisible ? '' : ' covered'}`}>
            <div className="lido-header-bar">
                <div className="lido-header-side lido-header-side--brand">
                    {showSidebarToggle && <SidebarToggle />}
                    <Link to="/" className="lido-header-title-link" aria-label="StoX by Lido Alexion">
                        <h1 className="lido-header-title">
                            <span className="lido-header-title-mark">StoX</span>
                            <span className="lido-header-title-by">by Lido Alexion</span>
                        </h1>
                    </Link>
                </div>
                <div className="lido-header-actions">
                    {user && <PortfolioSwitcher />}
                    <HeaderHelpButton />
                    {user && <ProfileMenu user={user} />}
                </div>
            </div>
        </header>
    );
}
