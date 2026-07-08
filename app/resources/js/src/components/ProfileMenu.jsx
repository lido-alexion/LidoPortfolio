import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { useAuth } from '../context/AuthContext';
import { userDisplayName } from '../utils/userDisplay';
import ProfileAvatar from './ProfileAvatar';
import ThemeToggle from './ThemeToggle';

export default function ProfileMenu({ user }) {
    const { logout } = useAuth();
    const [isOpen, setIsOpen] = useState(false);

    const displayName = userDisplayName(user);
    const hasName = Boolean(user?.name?.trim());

    const handleLogout = async () => {
        setIsOpen(false);
        await logout();
        window.location.href = '/';
    };

    return (
        <div className="dropdown" style={{ position: 'relative', zIndex: 2000 }}>
            <button
                type="button"
                className="lido-profile-toggle dropdown-toggle"
                onClick={(e) => {
                    e.stopPropagation();
                    setIsOpen(!isOpen);
                }}
                aria-expanded={isOpen}
                aria-haspopup="true"
            >
                <ProfileAvatar user={user} size={28} />
                <span className="lido-profile-name">{displayName}</span>
            </button>

            {isOpen && (
                <>
                    <div
                        style={{
                            position: 'fixed',
                            top: 0,
                            left: 0,
                            right: 0,
                            bottom: 0,
                            zIndex: 1999,
                        }}
                        onClick={() => setIsOpen(false)}
                        aria-hidden="true"
                    />
                    <div
                        className="dropdown-menu lido-profile-menu show border shadow-lg py-2 rounded-3"
                        style={{
                            display: 'block',
                            position: 'absolute',
                            right: 0,
                            top: '100%',
                            marginTop: 8,
                            zIndex: 2000,
                            minWidth: 220,
                        }}
                    >
                        <div className="d-flex align-items-center mb-2 border-bottom lido-profile-menu-header">
                            <div className="ms-3">
                                <ProfileAvatar user={user} size={40} menu />
                            </div>
                            <div className="px-3 py-2">
                                <Link
                                    to="/profile"
                                    className="small fw-bold mb-0 d-block text-decoration-none lido-profile-account-link"
                                    onClick={() => setIsOpen(false)}
                                >
                                    {displayName}
                                </Link>
                                {hasName && (
                                    <div className="small mb-0 lido-profile-account-email">{user.email}</div>
                                )}
                            </div>
                        </div>
                        <ThemeToggle />
                        <Link
                            to="/portfolios"
                            className="dropdown-item py-2 small d-flex align-items-center"
                            onClick={() => setIsOpen(false)}
                        >
                            <span className="me-2">📁</span>
                            Portfolios
                        </Link>
                        <Link
                            to="/settings/portfolio"
                            className="dropdown-item py-2 small d-flex align-items-center"
                            onClick={() => setIsOpen(false)}
                        >
                            <span className="me-2">⚙️</span>
                            Settings
                        </Link>
                        <hr className="dropdown-divider border-secondary my-1 opacity-25" />
                        <button
                            type="button"
                            className="dropdown-item text-danger py-2 small d-flex align-items-center lido-profile-menu-action"
                            onClick={handleLogout}
                        >
                            <span className="me-2">🚪</span>
                            Logout
                        </button>
                    </div>
                </>
            )}
        </div>
    );
}
