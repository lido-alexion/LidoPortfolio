import React, { useEffect, useMemo, useState } from 'react';
import ColumnTagEditor, { MESSAGE_FORMAT_HINT } from './ColumnTagEditor';
import NumberInput from './NumberInput';

const EMPTY_FORM = {
    name: '',
    stock_universe: 'holdings',
    alert_definition: '',
    condition_column: '',
    condition_operator: 'lt',
    compare_type: 'column',
    compare_column: '',
    compare_formula: '',
    compare_constant: '',
    message_template: '',
    context_template: '',
    action_type: 'sell',
    action_custom: '',
    is_enabled: true,
};

export function policyToForm(policy) {
    if (!policy) {
        return { ...EMPTY_FORM };
    }
    return {
        name: policy.name || '',
        stock_universe: policy.stock_universe || 'holdings',
        alert_definition: policy.alert_definition || '',
        condition_column: policy.condition_column || '',
        condition_operator: policy.condition_operator || 'lt',
        compare_type: policy.compare_type || 'column',
        compare_column: policy.compare_column || '',
        compare_formula: policy.compare_formula || '',
        compare_constant: policy.compare_constant != null && policy.compare_constant !== ''
            ? Number(policy.compare_constant).toFixed(2)
            : '',
        message_template: policy.message_template || '',
        context_template: policy.context_template || '',
        action_type: policy.action_type || 'sell',
        action_custom: policy.action_custom || '',
        is_enabled: policy.is_enabled !== false,
    };
}

export default function AlertPolicyForm({
    meta,
    initialPolicy = null,
    saving = false,
    fieldErrors = {},
    onSubmit,
    onCancel,
    onClearFieldError,
}) {
    const [form, setForm] = useState(() => policyToForm(initialPolicy));

    useEffect(() => {
        setForm(policyToForm(initialPolicy));
    }, [initialPolicy]);

    const columns = meta?.columns ?? [];
    const columnOptions = useMemo(
        () => columns.map((col) => (
            <option key={col.key} value={col.key}>{col.label}</option>
        )),
        [columns],
    );

    const update = (patch) => {
        Object.keys(patch).forEach((key) => {
            if (fieldErrors[key] && onClearFieldError) {
                onClearFieldError(key);
            }
        });
        setForm((prev) => ({ ...prev, ...patch }));
    };

    const firstError = (key) => {
        const messages = fieldErrors[key];
        return Array.isArray(messages) ? messages[0] : '';
    };

    const handleSubmit = (e) => {
        e.preventDefault();
        const payload = {
            ...form,
            compare_constant: form.compare_type === 'constant' && form.compare_constant !== ''
                ? Number(Number(form.compare_constant).toFixed(2))
                : null,
        };
        onSubmit(payload);
    };

    return (
        <form className="d-grid gap-3" onSubmit={handleSubmit}>
            <div className="row g-3">
                <div className="col-md-6">
                    <label className="form-label" htmlFor="alert-policy-name">Alert name</label>
                    <input
                        id="alert-policy-name"
                        type="text"
                        className="form-control form-control-sm"
                        value={form.name}
                        onChange={(e) => update({ name: e.target.value })}
                        maxLength={120}
                        required
                        disabled={initialPolicy?.is_system}
                    />
                </div>
                <div className="col-md-6">
                    <label className="form-label" htmlFor="alert-policy-universe">Stock universe</label>
                    <select
                        id="alert-policy-universe"
                        className="form-select form-select-sm"
                        value={form.stock_universe}
                        onChange={(e) => update({ stock_universe: e.target.value })}
                        disabled
                    >
                        {(meta?.stock_universes ?? [{ value: 'holdings', label: 'Holdings' }]).map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </div>
            </div>

            <div>
                <label className="form-label" htmlFor="alert-policy-definition">Alert definition</label>
                <textarea
                    id="alert-policy-definition"
                    className={`form-control form-control-sm${firstError('alert_definition') ? ' is-invalid' : ''}`}
                    value={form.alert_definition}
                    onChange={(e) => update({ alert_definition: e.target.value })}
                    rows={2}
                    maxLength={2000}
                    placeholder="Optional short description of when this alert should fire"
                />
                {firstError('alert_definition') && (
                    <div className="invalid-feedback d-block">{firstError('alert_definition')}</div>
                )}
            </div>

            <div className="card">
                <div className="card-header py-2">
                    <span className="small fw-semibold">Condition</span>
                </div>
                <div className="card-body">
                    <div className="row g-3 align-items-end">
                        <div className="col-md-4">
                            <label className="form-label" htmlFor="alert-condition-column">Column</label>
                            <select
                                id="alert-condition-column"
                                className="form-select form-select-sm"
                                value={form.condition_column}
                                onChange={(e) => update({ condition_column: e.target.value })}
                                required
                            >
                                <option value="">Select…</option>
                                {columnOptions}
                            </select>
                        </div>
                        <div className="col-md-3">
                            <label className="form-label" htmlFor="alert-condition-operator">Operator</label>
                            <select
                                id="alert-condition-operator"
                                className="form-select form-select-sm"
                                value={form.condition_operator}
                                onChange={(e) => update({ condition_operator: e.target.value })}
                            >
                                {(meta?.condition_operators ?? []).map((item) => (
                                    <option key={item.value} value={item.value}>{item.label}</option>
                                ))}
                            </select>
                        </div>
                        <div className="col-md-5">
                            <label className="form-label" htmlFor="alert-compare-type">Compare value type</label>
                            <select
                                id="alert-compare-type"
                                className="form-select form-select-sm"
                                value={form.compare_type}
                                onChange={(e) => update({ compare_type: e.target.value })}
                            >
                                {(meta?.compare_types ?? []).map((item) => (
                                    <option key={item.value} value={item.value}>{item.label}</option>
                                ))}
                            </select>
                        </div>
                        {form.compare_type === 'column' && (
                            <div className="col-md-6">
                                <label className="form-label" htmlFor="alert-compare-column">Compare column</label>
                                <select
                                    id="alert-compare-column"
                                    className="form-select form-select-sm"
                                    value={form.compare_column}
                                    onChange={(e) => update({ compare_column: e.target.value })}
                                    required
                                >
                                    <option value="">Select…</option>
                                    {columnOptions}
                                </select>
                            </div>
                        )}
                        {form.compare_type === 'derived' && (
                            <div className="col-12">
                                <ColumnTagEditor
                                    id="alert-compare-formula"
                                    label="Derived formula"
                                    value={form.compare_formula}
                                    onChange={(compare_formula) => update({ compare_formula })}
                                    columns={columns}
                                    showColumnPicker
                                    placeholder="e.g. {{highest_close_since_buy}} * 0.9"
                                    invalid={Boolean(firstError('compare_formula'))}
                                    errorMessage={firstError('compare_formula')}
                                />
                            </div>
                        )}
                        {form.compare_type === 'constant' && (
                            <div className="col-md-6">
                                <label className="form-label" htmlFor="alert-compare-constant">Constant value</label>
                                <NumberInput
                                    id="alert-compare-constant"
                                    value={form.compare_constant}
                                    onChange={(e) => update({ compare_constant: e.target.value })}
                                    step="0.01"
                                    fixedDecimals={2}
                                    required
                                />
                            </div>
                        )}
                    </div>
                </div>
            </div>

            <ColumnTagEditor
                id="alert-message-template"
                label="Alert message"
                value={form.message_template}
                onChange={(message_template) => update({ message_template })}
                columns={columns}
                showColumnPicker
                multiline
                rows={3}
                placeholder="e.g. {{symbol}}: latest close [[{{latest_close}}]] (stop <<{{latest_close}} * 0.9>>)"
                invalid={Boolean(firstError('message_template'))}
                errorMessage={firstError('message_template')}
                hint={MESSAGE_FORMAT_HINT}
            />

            <ColumnTagEditor
                id="alert-context-template"
                label="Context details"
                value={form.context_template}
                onChange={(context_template) => update({ context_template })}
                columns={columns}
                showColumnPicker
                multiline
                rows={4}
                columnInsertFormat="labeled"
                insertSeparator="\n"
                placeholder={'e.g.\nQuantity: {{quantity}}\nLatest close: [[{{latest_close}}]]'}
                invalid={Boolean(firstError('context_template'))}
                errorMessage={firstError('context_template')}
                hint={MESSAGE_FORMAT_HINT}
            />

            <div className="row g-3">
                <div className="col-md-4">
                    <label className="form-label" htmlFor="alert-action-type">Action suggested</label>
                    <select
                        id="alert-action-type"
                        className="form-select form-select-sm"
                        value={form.action_type}
                        onChange={(e) => update({ action_type: e.target.value })}
                    >
                        {(meta?.action_types ?? []).map((item) => (
                            <option key={item.value} value={item.value}>{item.label}</option>
                        ))}
                    </select>
                </div>
                {form.action_type === 'custom' && (
                    <div className="col-md-8">
                        <label className="form-label" htmlFor="alert-action-custom">Custom action</label>
                        <input
                            id="alert-action-custom"
                            type="text"
                            className="form-control form-control-sm"
                            value={form.action_custom}
                            onChange={(e) => update({ action_custom: e.target.value })}
                            maxLength={255}
                            required
                        />
                    </div>
                )}
            </div>

            <div className="form-check">
                <input
                    id="alert-policy-enabled"
                    type="checkbox"
                    className="form-check-input"
                    checked={form.is_enabled}
                    onChange={(e) => update({ is_enabled: e.target.checked })}
                />
                <label className="form-check-label" htmlFor="alert-policy-enabled">
                    Policy enabled
                </label>
            </div>

            <div className="d-flex flex-wrap gap-2 justify-content-end mt-3">
                {onCancel && (
                    <button type="button" className="btn btn-outline-secondary btn-sm" onClick={onCancel}>
                        Cancel
                    </button>
                )}
                <button type="submit" className="btn btn-primary btn-sm" disabled={saving}>
                    {saving ? 'Saving…' : (initialPolicy ? 'Update policy' : 'Create policy')}
                </button>
            </div>
        </form>
    );
}
