import React from 'react';
import { Link } from 'react-router-dom';

/**
 * Vertical "Where am I?" workflow strip.
 * @param {{
 *   steps: Array<{ label: string, keyword?: string, current?: boolean }>,
 *   title?: string,
 * }} props
 */
export default function DocWorkflow({ steps = [], title = 'Where am I?' }) {
    if (!steps.length) {
        return null;
    }

    return (
        <nav className="lido-docs-workflow mb-4" aria-label={title}>
            <div className="lido-docs-workflow-title small fw-semibold text-muted mb-2">
                <i className="bi bi-signpost-2 me-1" aria-hidden="true" />
                {title}
            </div>
            <ol className="lido-docs-workflow-list list-unstyled mb-0">
                {steps.map((step, index) => {
                    const isCurrent = Boolean(step.current);
                    const body = (
                        <span className={`lido-docs-workflow-step${isCurrent ? ' is-current' : ''}`}>
                            <span className="lido-docs-workflow-dot" aria-hidden="true" />
                            <span className="lido-docs-workflow-label">
                                {step.label}
                                {isCurrent ? (
                                    <span className="badge text-bg-info ms-2">You are here</span>
                                ) : null}
                            </span>
                        </span>
                    );
                    return (
                        <li key={`${step.label}-${index}`} className="lido-docs-workflow-item">
                            {step.keyword && !isCurrent ? (
                                <Link
                                    to={`/documentation?q=${encodeURIComponent(step.keyword)}`}
                                    className="lido-docs-workflow-link"
                                >
                                    {body}
                                </Link>
                            ) : (
                                body
                            )}
                            {index < steps.length - 1 ? (
                                <div className="lido-docs-workflow-connector" aria-hidden="true" />
                            ) : null}
                        </li>
                    );
                })}
            </ol>
        </nav>
    );
}
