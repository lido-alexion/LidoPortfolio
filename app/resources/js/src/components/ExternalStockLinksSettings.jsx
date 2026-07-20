import React from 'react';
import {
    DEFAULT_EXTERNAL_STOCK_LINKS,
    normalizeExternalStockLinks,
} from '../utils/externalStockLinks';

function TrashIcon() {
    return (
        <svg
            xmlns="http://www.w3.org/2000/svg"
            width="16"
            height="16"
            fill="currentColor"
            viewBox="0 0 16 16"
            aria-hidden="true"
        >
            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z" />
            <path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM2.5 3h11V2h-11z" />
        </svg>
    );
}

function newLinkRow(index) {
    return {
        id: `custom_${Date.now()}_${index}`,
        label: 'New site',
        url: 'https://example.com/{SYMBOL}',
        enabled: true,
    };
}

/**
 * Admin editor for external stock research URL templates.
 */
export default function ExternalStockLinksSettings({ links, onChange }) {
    const rows = normalizeExternalStockLinks(links);

    const updateRow = (index, patch) => {
        onChange(rows.map((row, i) => (i === index ? { ...row, ...patch } : row)));
    };

    const removeRow = (index) => {
        onChange(rows.filter((_, i) => i !== index));
    };

    return (
        <div>
            <p className="text-muted small mb-2">
                Shown on Screener hit rows (open in a new tab). Placeholders:
                {' '}
                <code>{'{SYMBOL}'}</code>
                ,
                {' '}
                <code>{'{EXCHANGE}'}</code>
                {' '}
                (NSE/BSE),
                {' '}
                <code>{'{YAHOO_SUFFIX}'}</code>
                {' '}
                (NS/BO). Uncheck Enabled or clear the URL to hide a link. Use Reset defaults to restore the built-in Chartink / TradingView / Yahoo / Zerodha / Screener.in / StockScans templates.
            </p>
            <div className="d-flex flex-wrap gap-2 mb-2">
                <button
                    type="button"
                    className="btn btn-sm btn-outline-primary"
                    onClick={() => onChange([...rows, newLinkRow(rows.length)])}
                    disabled={rows.length >= 20}
                >
                    Add link
                </button>
                <button
                    type="button"
                    className="btn btn-sm btn-outline-secondary"
                    onClick={() => onChange(DEFAULT_EXTERNAL_STOCK_LINKS.map((row) => ({ ...row })))}
                >
                    Reset defaults
                </button>
            </div>
            <div className="d-grid gap-2">
                {rows.map((row, index) => (
                    <div
                        key={row.id || index}
                        className="border rounded p-2 d-grid gap-2"
                        style={{ gridTemplateColumns: 'auto 1fr auto' }}
                    >
                        <div className="form-check align-self-center">
                            <input
                                id={`external-link-enabled-${index}`}
                                type="checkbox"
                                className="form-check-input"
                                checked={row.enabled}
                                onChange={(e) => updateRow(index, { enabled: e.target.checked })}
                            />
                            <label className="form-check-label visually-hidden" htmlFor={`external-link-enabled-${index}`}>
                                Enabled
                            </label>
                        </div>
                        <div className="d-grid gap-2">
                            <input
                                type="text"
                                className="form-control form-control-sm"
                                value={row.label}
                                onChange={(e) => updateRow(index, { label: e.target.value })}
                                placeholder="Label"
                                aria-label={`Link ${index + 1} label`}
                            />
                            <input
                                type="text"
                                className="form-control form-control-sm font-monospace"
                                value={row.url}
                                onChange={(e) => updateRow(index, { url: e.target.value })}
                                placeholder="https://…/{SYMBOL}"
                                aria-label={`Link ${index + 1} URL template`}
                            />
                        </div>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-danger align-self-start"
                            onClick={() => removeRow(index)}
                            title="Remove link"
                            aria-label={`Remove ${row.label || 'link'}`}
                        >
                            <TrashIcon />
                        </button>
                    </div>
                ))}
            </div>
        </div>
    );
}
