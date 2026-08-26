import React, { useState } from 'react';
import api from '../../api';
import { showToast } from '../../toast';

/**
 * Name + optional description → POST /v1/strategy-registry (factory/default config, stored as draft).
 */
export default function CreateStrategyPanel({ open, onClose, onCreated, disabled = false }) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    if (!open) {
        return null;
    }

    const reset = () => {
        setName('');
        setDescription('');
        setError('');
    };

    const submit = async (event) => {
        event.preventDefault();
        const trimmed = name.trim();
        if (!trimmed) {
            setError('Enter a strategy name.');
            return;
        }
        setBusy(true);
        setError('');
        try {
            const res = await api.post('/v1/strategy-registry', {
                name: trimmed,
                description: description.trim(),
            });
            const created = res.data?.data;
            showToast(
                `Created “${created?.name || trimmed}” as a draft. Enable it when you want it to generate recommendations.`,
            );
            reset();
            onCreated?.(created);
        } catch (err) {
            const msg = err?.response?.data?.error?.message || err.message || 'Could not create strategy';
            setError(msg);
            showToast(msg, 'danger');
        } finally {
            setBusy(false);
        }
    };

    const locked = busy || disabled;

    return (
        <form id="create-strategy-form" className="card border-primary mb-0" onSubmit={submit}>
            <div className="card-body d-grid gap-2">
                <h3 className="h6 mb-0">New Strategy</h3>
                <p className="text-muted small mb-0">
                    Creates a new strategy from the default factory configuration. You can edit it next,
                    then Enable it — other enabled strategies stay enabled.
                </p>
                {error ? <div className="alert alert-danger py-2 mb-0">{error}</div> : null}
                <div>
                    <label className="form-label small mb-1" htmlFor="create-strategy-name">Name</label>
                    <input
                        id="create-strategy-name"
                        className="form-control"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        maxLength={120}
                        required
                        disabled={locked}
                        placeholder="e.g. Swing momentum"
                        autoComplete="off"
                    />
                </div>
                <div>
                    <label className="form-label small mb-1" htmlFor="create-strategy-description">
                        Description
                        {' '}
                        <span className="text-muted">(optional)</span>
                    </label>
                    <textarea
                        id="create-strategy-description"
                        className="form-control"
                        rows={2}
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        maxLength={2000}
                        disabled={locked}
                    />
                </div>
                <div className="d-flex flex-wrap gap-2">
                    <button
                        type="submit"
                        id="create-strategy-submit"
                        className="btn btn-primary btn-sm"
                        disabled={locked}
                    >
                        {busy ? 'Creating…' : 'Create Strategy'}
                    </button>
                    <button
                        type="button"
                        className="btn btn-outline-secondary btn-sm"
                        disabled={locked}
                        onClick={() => {
                            reset();
                            onClose?.();
                        }}
                    >
                        Cancel
                    </button>
                </div>
            </div>
        </form>
    );
}

export function createdStrategyId(created) {
    const raw = created?.artifact_id ?? created?.metadata?.legacy_id ?? created?.id;
    const n = Number(raw);
    return Number.isFinite(n) && n > 0 ? String(n) : '';
}
