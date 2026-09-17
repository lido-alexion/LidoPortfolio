import React from 'react';

const VARIANT_COPY = {
    loading: { title: 'Loading…', role: 'status' },
    error: { title: 'Unable to load this data', role: 'alert' },
    empty: { title: 'Nothing to show', role: 'status' },
    unavailable: { title: 'Data unavailable', role: 'status' },
    incomplete: { title: 'Data incomplete', role: 'status' },
};

export default function DataState({
    variant = 'empty',
    title,
    message,
    action = null,
    className = '',
    testId,
}) {
    const copy = VARIANT_COPY[variant] || VARIANT_COPY.empty;

    return (
        <section
            className={`lido-data-state border rounded p-4 ${className}`.trim()}
            role={copy.role}
            aria-live={copy.role === 'status' ? 'polite' : 'assertive'}
            data-testid={testId}
        >
            <h2 className="h6 mb-2">{title || copy.title}</h2>
            {message ? <p className="text-muted mb-3">{message}</p> : null}
            {action}
        </section>
    );
}
