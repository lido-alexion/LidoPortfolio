import React from 'react';

const TONES = {
    allow: { className: 'lido-docs-decision--allow', icon: 'bi-check-circle', badge: 'Allowed' },
    caution: { className: 'lido-docs-decision--caution', icon: 'bi-exclamation-circle', badge: 'Caution' },
    block: { className: 'lido-docs-decision--block', icon: 'bi-x-circle', badge: 'Blocked' },
};

/**
 * Coloured decision / threshold cards.
 * @param {{ items: Array<{ tone: 'allow'|'caution'|'block', title: string, body: string }> }} props
 */
export default function DocDecisionCards({ items = [] }) {
    if (!items.length) {
        return null;
    }

    return (
        <div className="row g-2 mb-3">
            {items.map((item) => {
                const tone = TONES[item.tone] || TONES.caution;
                return (
                    <div key={item.title} className="col-md-4">
                        <div className={`card h-100 lido-docs-decision ${tone.className}`}>
                            <div className="card-body p-3">
                                <div className="d-flex align-items-center gap-2 mb-2">
                                    <i className={`bi ${tone.icon}`} aria-hidden="true" />
                                    <span className="badge text-bg-light">{tone.badge}</span>
                                </div>
                                <h3 className="h6 mb-1">{item.title}</h3>
                                <p className="small mb-0">{item.body}</p>
                            </div>
                        </div>
                    </div>
                );
            })}
        </div>
    );
}
