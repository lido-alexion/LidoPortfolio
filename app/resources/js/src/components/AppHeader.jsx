import React, { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import ProfileMenu from './ProfileMenu';
import PortfolioSwitcher from './PortfolioSwitcher';

export default function AppHeader({ user }) {
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
                <div className="lido-header-side">
                    {user && <PortfolioSwitcher />}
                </div>
                <div className="lido-header-center">
                    <Link to="/" className="lido-header-title-link">
                        <h1 className="lido-header-title">Lido Alexion</h1>
                    </Link>
                </div>
                <div className="lido-header-actions">
                    {user && <ProfileMenu user={user} />}
                </div>
            </div>
        </header>
    );
}
