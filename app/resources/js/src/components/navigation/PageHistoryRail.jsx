import React from 'react';
import { Link } from 'react-router-dom';

export default function PageHistoryRail({ visits, activeVisitKey }) {
    return (
        <nav className="lido-page-history-rail" aria-label="Page visit history">
            <ol className="lido-page-history-list">
                {visits.map((visit, index) => {
                    const active = visit.visitKey === activeVisitKey
                        && visits.findIndex((candidate) => candidate.visitKey === activeVisitKey) === index;
                    return (
                        <li key={`${visit.visitKey}-${index}`} className="lido-page-history-item">
                            <Link
                                to={visit.destination}
                                className={`lido-page-history-link${active ? ' is-active' : ''}`}
                                aria-current={active ? 'page' : undefined}
                                aria-label={visit.label}
                                title={visit.label}
                            >
                                <span className="lido-page-history-label">{visit.label}</span>
                                <span className="lido-page-history-bar" aria-hidden="true" />
                            </Link>
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
