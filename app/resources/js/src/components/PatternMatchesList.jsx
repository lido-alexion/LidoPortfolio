import React from 'react';
import { Link } from 'react-router-dom';
import { categoryClassName, categoryLabel } from '../utils/patternDetection';
import { formatTransactionDateDisplay } from '../utils/transactionDate';

/**
 * Compact list of pattern matches (chart window or scan results).
 */
export default function PatternMatchesList({
    matches = [],
    title = 'Possible patterns on this window',
    emptyMessage = 'No actionable patterns detected on the latest bar.',
    linkToGuide = true,
    compact = false,
}) {
    if (!matches.length) {
        return (
            <div className="lido-pattern-matches small text-muted">
                {emptyMessage}
            </div>
        );
    }

    return (
        <div className="lido-pattern-matches">
            {title ? (
                <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                    <span className="small fw-semibold">{title}</span>
                    {linkToGuide ? (
                        <Link to="/patterns" className="small">
                            Pattern guide
                        </Link>
                    ) : null}
                </div>
            ) : null}
            <ul className={`list-unstyled mb-0 ${compact ? 'd-flex flex-wrap gap-2' : 'd-grid gap-1'}`}>
                {matches.map((match) => (
                    <li
                        key={`${match.id}-${match.barDate || ''}`}
                        className={compact ? 'badge bg-light text-dark border' : 'small'}
                    >
                        <span className="fw-medium">{match.name}</span>
                        {' '}
                        <span className={categoryClassName(match.category)}>
                            ({categoryLabel(match.category)})
                        </span>
                        {!compact && match.barDate ? (
                            <span className="text-muted">
                                {' '}
                                · as of {formatTransactionDateDisplay(match.barDate)}
                            </span>
                        ) : null}
                    </li>
                ))}
            </ul>
        </div>
    );
}
