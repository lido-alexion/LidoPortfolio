import React, { useCallback, useEffect, useState } from 'react';
import { KeyRound, Trash2 } from 'lucide-react';
import api, { getApiErrorMessage } from '../api';
import { showToast } from '../toast';

const SCOPES = [
    'portfolio:read',
    'portfolio:write',
    'execution:read',
    'execution:submit',
    'notes:read',
    'notes:write',
];

export default function PersonalApiTokensPanel() {
    const [tokens, setTokens] = useState([]);
    const [name, setName] = useState('');
    const [abilities, setAbilities] = useState(['portfolio:read']);
    const [plainToken, setPlainToken] = useState('');
    const [busy, setBusy] = useState(false);

    const load = useCallback(async () => {
        const response = await api.get('/personal-api-tokens');
        setTokens(response.data?.data || []);
    }, []);

    useEffect(() => {
        load().catch(() => {});
    }, [load]);

    const toggle = (scope) => {
        setAbilities((current) => current.includes(scope)
            ? current.filter((item) => item !== scope)
            : [...current, scope]);
    };

    const create = async (event) => {
        event.preventDefault();
        if (!name.trim() || abilities.length === 0) return;
        setBusy(true);
        try {
            const response = await api.post('/personal-api-tokens', { name: name.trim(), abilities });
            setPlainToken(response.data?.data?.token || '');
            setName('');
            setAbilities(['portfolio:read']);
            await load();
            showToast('API token created');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not create token'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const revoke = async (token) => {
        if (!window.confirm(`Revoke "${token.name}"?`)) return;
        await api.delete(`/personal-api-tokens/${token.id}`);
        await load();
        showToast('API token revoked');
    };

    return (
        <div className="card">
            <div className="card-header d-flex align-items-center gap-2">
                <KeyRound size={16} />
                <span>Personal API tokens</span>
            </div>
            <div className="card-body d-grid gap-3">
                {plainToken && (
                    <div className="alert alert-warning mb-0">
                        <div className="fw-semibold">Token created</div>
                        <code className="d-block text-break">{plainToken}</code>
                    </div>
                )}
                <form className="d-grid gap-2" onSubmit={create}>
                    <input className="form-control form-control-sm" placeholder="Token name" value={name} onChange={(event) => setName(event.target.value)} maxLength={80} />
                    <div className="d-flex flex-wrap gap-2">
                        {SCOPES.map((scope) => (
                            <label key={scope} className="form-check form-check-inline small">
                                <input className="form-check-input" type="checkbox" checked={abilities.includes(scope)} onChange={() => toggle(scope)} />
                                <span className="form-check-label">{scope}</span>
                            </label>
                        ))}
                    </div>
                    <div>
                        <button type="submit" className="btn btn-primary btn-sm" disabled={busy || !name.trim() || abilities.length === 0}>
                            Create token
                        </button>
                    </div>
                </form>
                <div className="table-responsive">
                    <table className="table table-sm align-middle mb-0">
                        <thead><tr><th>Name</th><th>Scopes</th><th>Last used</th><th /></tr></thead>
                        <tbody>
                            {tokens.map((token) => (
                                <tr key={token.id}>
                                    <td>{token.name}</td>
                                    <td>{(token.abilities || []).join(', ')}</td>
                                    <td>{token.last_used_at ? new Date(token.last_used_at).toLocaleString() : 'Never'}</td>
                                    <td className="text-end">
                                        <button type="button" className="btn btn-outline-danger btn-sm" title="Revoke token" onClick={() => revoke(token)}>
                                            <Trash2 size={14} />
                                        </button>
                                    </td>
                                </tr>
                            ))}
                            {tokens.length === 0 && <tr><td colSpan="4" className="text-muted">No personal API tokens.</td></tr>}
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    );
}
