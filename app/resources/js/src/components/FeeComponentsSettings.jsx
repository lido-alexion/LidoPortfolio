import React from 'react';
import NumberInput from './NumberInput';
import SegmentToggle from './SegmentToggle';
import FeeSideToggle from './FeeSideToggle';
import {
    DEFAULT_FEE_COMPONENTS,
    FEE_EXCHANGES,
    FEE_MODES,
    normalizeFeeComponents,
} from '../utils/feeCalculator';

const FEE_CONTROL_HEIGHT = 'var(--lido-fee-control-height)';

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

function FeeField({ label, htmlFor, className = '', children }) {
    return (
        <div className={['lido-fee-field', className].filter(Boolean).join(' ')}>
            {label && (
                <label className="form-label small mb-0" htmlFor={htmlFor}>
                    {label}
                </label>
            )}
            {children}
        </div>
    );
}

function newFeeComponent(index) {
    return {
        id: `custom_${Date.now()}_${index}`,
        label: 'Custom fee',
        value: '0',
        mode: FEE_MODES.PERCENTAGE,
        applies_buy: true,
        applies_sell: true,
        exchange: FEE_EXCHANGES.BOTH,
        gst_percent: '0',
    };
}

export default function FeeComponentsSettings({ components, onChange }) {
    const rows = normalizeFeeComponents(components);

    const updateRow = (index, patch) => {
        const next = rows.map((row, i) => (i === index ? { ...row, ...patch } : row));
        onChange(next);
    };

    const removeRow = (index) => {
        onChange(rows.filter((_, i) => i !== index));
    };

    const resetDefaults = () => {
        onChange(DEFAULT_FEE_COMPONENTS.map((c) => ({ ...c })));
    };

    return (
        <div className="col-12">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                <div>
                    <label className="form-label mb-0">Transaction fee components</label>
                    <p className="text-muted small mb-0">
                        Equity delivery defaults (Zerodha-style). Each line can be % of turnover or a fixed ₹
                        amount. GST is per line. Exchange filter applies NSE/BSE-specific charges.
                    </p>
                </div>
                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={resetDefaults}>
                    Reset defaults
                </button>
            </div>

            <div className="d-flex flex-column gap-2">
                {rows.map((row, index) => (
                    <div key={row.id} className="lido-fee-component-row border rounded p-2 p-lg-3">
                        <div className="lido-fee-component-grid">
                            <FeeField label="Label" htmlFor={`fee-label-${row.id}`}>
                                <input
                                    id={`fee-label-${row.id}`}
                                    className="form-control form-control-sm lido-fee-row-control"
                                    value={row.label}
                                    onChange={(e) => updateRow(index, { label: e.target.value })}
                                />
                            </FeeField>

                            <div className="lido-fee-field">
                                <SegmentToggle
                                    label="Type"
                                    value={row.mode}
                                    onChange={(mode) => updateRow(index, { mode })}
                                    options={[
                                        { value: FEE_MODES.PERCENTAGE, label: '%' },
                                        { value: FEE_MODES.FIXED, label: '₹' },
                                    ]}
                                    ariaLabel={`Fee type for ${row.label}`}
                                    compact
                                />
                            </div>

                            <FeeField
                                label={row.mode === FEE_MODES.FIXED ? 'Amount (₹)' : 'Rate (%)'}
                                htmlFor={`fee-value-${row.id}`}
                            >
                                <NumberInput
                                    id={`fee-value-${row.id}`}
                                    compact
                                    height={FEE_CONTROL_HEIGHT}
                                    min="0"
                                    step={row.mode === FEE_MODES.FIXED ? '0.01' : '0.00001'}
                                    fixedDecimals={row.mode === FEE_MODES.FIXED ? 2 : null}
                                    value={row.value}
                                    onChange={(e) => updateRow(index, { value: e.target.value })}
                                />
                            </FeeField>

                            <div className="lido-fee-field">
                                <span className="form-label small d-block mb-0">Applies to</span>
                                <FeeSideToggle
                                    appliesBuy={row.applies_buy}
                                    appliesSell={row.applies_sell}
                                    onChange={(patch) => updateRow(index, patch)}
                                    ariaLabel={`Buy sell applicability for ${row.label}`}
                                    compact
                                />
                            </div>

                            <div className="lido-fee-field">
                                <SegmentToggle
                                    label="Exchange"
                                    value={row.exchange}
                                    onChange={(exchange) => updateRow(index, { exchange })}
                                    options={[
                                        { value: FEE_EXCHANGES.BOTH, label: 'Both' },
                                        { value: FEE_EXCHANGES.NSE, label: 'NSE' },
                                        { value: FEE_EXCHANGES.BSE, label: 'BSE' },
                                    ]}
                                    ariaLabel={`Exchange filter for ${row.label}`}
                                    compact
                                />
                            </div>

                            <FeeField label="GST (%)" htmlFor={`fee-gst-${row.id}`} className="lido-fee-field--gst">
                                <NumberInput
                                    id={`fee-gst-${row.id}`}
                                    compact
                                    height={FEE_CONTROL_HEIGHT}
                                    min="0"
                                    max="100"
                                    step="0.01"
                                    value={row.gst_percent}
                                    onChange={(e) => updateRow(index, { gst_percent: e.target.value })}
                                />
                            </FeeField>

                            <div className="lido-fee-field lido-fee-field--delete">
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-danger lido-fee-delete-btn"
                                    onClick={() => removeRow(index)}
                                    disabled={rows.length <= 1}
                                    aria-label={`Remove ${row.label}`}
                                    title="Remove fee component"
                                >
                                    <TrashIcon />
                                </button>
                            </div>
                        </div>
                    </div>
                ))}
            </div>

            <button
                type="button"
                className="btn btn-sm btn-outline-secondary mt-2"
                onClick={() => onChange([...rows, newFeeComponent(rows.length)])}
            >
                Add fee component
            </button>
        </div>
    );
}
