import React from 'react';
import DocRichText from './DocRichText';

/**
 * Documentation section with optional subtitle and icon.
 */
export default function DocSection({ title, subtitle, icon, children, id }) {
    return (
        <section className="lido-docs-section mb-4" id={id}>
            <div className="mb-2">
                <h2 className="h5 mb-0 d-flex align-items-center gap-2">
                    {icon ? <i className={`bi ${icon}`} aria-hidden="true" /> : null}
                    <span>{title}</span>
                </h2>
                {subtitle ? <p className="small text-muted mb-0 mt-1">{subtitle}</p> : null}
            </div>
            {children}
        </section>
    );
}

/**
 * Controls / concepts as cards instead of a flat list.
 */
export function DocItemCards({ items, emptyLabel = 'None listed for this topic.' }) {
    if (!items?.length) {
        return <p className="small text-muted mb-0">{emptyLabel}</p>;
    }

    return (
        <div className="row g-2">
            {items.map((item) => (
                <div key={item.name} className="col-md-6">
                    <div className="card h-100 lido-docs-item-card">
                        <div className="card-body p-3">
                            <h3 className="h6 mb-2">{item.name}</h3>
                            <div className="small text-muted lido-docs-item-body mb-0">
                                <DocRichText text={item.description} />
                            </div>
                        </div>
                    </div>
                </div>
            ))}
        </div>
    );
}
