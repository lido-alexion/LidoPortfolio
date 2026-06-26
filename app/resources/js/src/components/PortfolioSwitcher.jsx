import React, { useState } from 'react';
import { Link } from 'react-router-dom';
import { usePortfolio } from '../context/PortfolioContext';

export default function PortfolioSwitcher() {
    const {
        portfolios,
        activePortfolio,
        loading,
        setActivePortfolio,
    } = usePortfolio();
    const [isOpen, setIsOpen] = useState(false);

    if (loading || portfolios.length <= 1) {
        return null;
    }

    const activeName = activePortfolio?.name ?? 'Portfolio';

    return (
        <div className="dropdown lido-portfolio-switcher-wrap" style={{ position: 'relative', zIndex: 2001 }}>
            <button
                type="button"
                className="btn btn-sm btn-outline-light dropdown-toggle lido-portfolio-switcher"
                onClick={(e) => {
                    e.stopPropagation();
                    setIsOpen(!isOpen);
                }}
                aria-expanded={isOpen}
                aria-haspopup="true"
                title="Switch portfolio (per tab)"
            >
                {activeName}
            </button>

            {isOpen && (
                <>
                    <div
                        style={{
                            position: 'fixed',
                            inset: 0,
                            zIndex: 1998,
                        }}
                        onClick={() => setIsOpen(false)}
                        aria-hidden="true"
                    />
                    <div
                        className="dropdown-menu show border shadow-lg py-2 rounded-3 lido-portfolio-menu"
                        style={{
                            display: 'block',
                            position: 'absolute',
                            left: 0,
                            top: '100%',
                            marginTop: 8,
                            zIndex: 2001,
                            minWidth: 200,
                        }}
                    >
                        <div className="dropdown-header small text-muted">Portfolios</div>
                        {portfolios.map((portfolio) => {
                            const isActive = String(portfolio.id) === String(activePortfolio?.id);
                            return (
                                <button
                                    key={portfolio.id}
                                    type="button"
                                    className={`dropdown-item small d-flex align-items-center justify-content-between ${isActive ? 'active' : ''}`}
                                    onClick={() => {
                                        setActivePortfolio(portfolio.id);
                                        setIsOpen(false);
                                    }}
                                >
                                    <span>{portfolio.name}</span>
                                    {isActive && <span aria-hidden="true">✓</span>}
                                </button>
                            );
                        })}
                        <hr className="dropdown-divider my-1" />
                        <Link
                            to="/portfolios"
                            className="dropdown-item small"
                            onClick={() => setIsOpen(false)}
                        >
                            Manage portfolios…
                        </Link>
                    </div>
                </>
            )}
        </div>
    );
}
