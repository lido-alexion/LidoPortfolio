import React, { useEffect, useRef } from 'react';
import { X } from 'lucide-react';
import { Link } from 'react-router-dom';

const FOCUSABLE_SELECTOR = [
    'a[href]:not([disabled])',
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export default function PageHistorySheet({ visits, activeVisitKey, onClose }) {
    const sheetRef = useRef(null);
    const closeRef = useRef(null);

    useEffect(() => {
        closeRef.current?.focus();
        const handleKeyDown = (event) => {
            if (event.key === 'Escape') onClose();
            if (event.key !== 'Tab') return;

            const sheet = sheetRef.current;
            if (!sheet) return;
            const focusables = Array.from(sheet.querySelectorAll(FOCUSABLE_SELECTOR));
            if (!focusables.length) {
                event.preventDefault();
                return;
            }

            const first = focusables[0];
            const last = focusables[focusables.length - 1];
            if (event.shiftKey) {
                if (document.activeElement === first || !sheet.contains(document.activeElement)) {
                    event.preventDefault();
                    last.focus();
                }
            } else if (document.activeElement === last || !sheet.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        };
        document.addEventListener('keydown', handleKeyDown);
        return () => document.removeEventListener('keydown', handleKeyDown);
    }, [onClose]);

    return (
        <div className="lido-page-history-overlay" role="presentation" onMouseDown={(event) => {
            if (event.target === event.currentTarget) onClose();
        }}>
            <section
                ref={sheetRef}
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
