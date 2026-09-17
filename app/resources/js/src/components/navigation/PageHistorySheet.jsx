import React, { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { Link } from 'react-router-dom';

export default function PageHistorySheet({ visits, activeVisitKey, onClose }) {
    const closeRef = useRef(null);

    useEffect(() => {
        closeRef.current?.focus();
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
        };
        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    return (
        <div className="lido-page-history-overlay" role="presentation" onMouseDown={(event) => {
            if (event.target === event.currentTarget) onClose();
        }}>
            <section
                className="lido-page-history-sheet"
                role="dialog"
                aria-modal="true"
                aria-labelledby="lido-page-history-title"
            >
                <header className="lido-page-history-sheet-header">
                    <h2 id="lido-page-history-title">History</h2>
                    <button ref={closeRef} type="button" className="lido-icon-action" aria-label="Close history" title="Close history" onClick={onClose}>
                        <X size={18} />
                    </button>
                </header>
                <ol className="lido-page-history-sheet-list">
                    {visits.map((visit, index) => {
                        const active = visit.visitKey === activeVisitKey
                            && visits.findIndex((candidate) => candidate.visitKey === activeVisitKey) === index;
                        return (
                            <li key={`${visit.visitKey}-${index}`}>
                                <Link
                                    to={visit.destination}
                                    className={`lido-page-history-sheet-link${active ? ' is-active' : ''}`}
                                    aria-current={active ? 'page' : undefined}
                                    onClick={onClose}
                                >
                                    <span>{visit.label}</span>
                                    {active && <span className="visually-hidden">Current page</span>}
                                </Link>
                            </li>
                        );
                    })}
                    {!visits.length && <li className="text-muted small">No pages visited yet.</li>}
                </ol>
            </section>
        </div>
    );
}
