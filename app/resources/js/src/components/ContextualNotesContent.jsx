import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Save, Trash2, X } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import { showToast } from '../toast';

export function contextFromPath(pathname) {
    const trimmed = pathname.replace(/^\/+/, '').replace(/\/+$/, '');
    return trimmed === '' ? 'dashboard' : trimmed.replace(/[^A-Za-z0-9_.:-]+/g, ':').slice(0, 120);
}

export default function ContextualNotesContent({ onClose, headingId = 'lido-context-notes-title' }) {
    const { pathname } = useLocation();
    const { activePortfolio } = usePortfolio();
    const [note, setNote] = useState(null);
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const [loading, setLoading] = useState(false);
    const [loadError, setLoadError] = useState('');
    const requestIdRef = useRef(0);
    const contextKey = useMemo(() => contextFromPath(pathname), [pathname]);
    const scopeIdentity = JSON.stringify([contextKey, activePortfolio?.id ?? null]);
    const scopeStateRef = useRef({ identity: null, epoch: 0, mounted: false });
    const operationSequenceRef = useRef(0);
    const activeOperationRef = useRef(null);

    // Replace the committed scope object so A→B→A and unmount invalidate old writes.
    useLayoutEffect(() => {
        const previousScope = scopeStateRef.current;
        const currentScope = {
            identity: scopeIdentity,
            epoch: previousScope.epoch + 1,
            mounted: true,
        };
        scopeStateRef.current = currentScope;
        activeOperationRef.current = null;
        if (previousScope.identity !== null && previousScope.identity !== scopeIdentity) {
            setBusy(false);
        }

        return () => {
            if (scopeStateRef.current === currentScope) {
                currentScope.mounted = false;
                currentScope.epoch += 1;
                activeOperationRef.current = null;
            }
        };
    }, [scopeIdentity]);

    const beginOperation = () => {
        const scope = scopeStateRef.current;
        if (!scope.mounted || scope.identity !== scopeIdentity) return null;

        const operation = {
            scope,
            epoch: scope.epoch,
            token: ++operationSequenceRef.current,
        };
        activeOperationRef.current = operation.token;
        setBusy(true);
        return operation;
    };

    const isCurrentOperation = (operation) => Boolean(
        operation
        && operation.scope.mounted
        && operation.scope.epoch === operation.epoch
        && scopeStateRef.current === operation.scope
        && operation.scope.identity === scopeIdentity
        && activeOperationRef.current === operation.token
    );

    const load = useCallback(async () => {
        const requestId = ++requestIdRef.current;
        setLoading(true);
        setLoadError('');
        setNote(null);
        setBody('');
        try {
            const response = await api.get('/contextual-notes', {
                params: {
                    context_key: contextKey,
                    profile_id: activePortfolio?.id || undefined,
                },
            });
            if (requestId !== requestIdRef.current) return;
            const current = response.data?.data?.[0] || null;
            setNote(current);
            setBody(current?.body || '');
        } catch {
            if (requestId !== requestIdRef.current) return;
            setLoadError('Contextual note could not be loaded.');
            setNote(null);
            setBody('');
        } finally {
            if (requestId === requestIdRef.current) setLoading(false);
        }
    }, [activePortfolio?.id, contextKey]);

    useEffect(() => {
        load();
    }, [load]);

    const save = async () => {
        const operation = beginOperation();
        if (!operation) return;
        try {
            const response = note
                ? await api.put(`/contextual-notes/${note.id}`, { body })
                : await api.post('/contextual-notes', {
                    context_key: contextKey,
                    profile_id: activePortfolio?.id || null,
                    body,
                });
            if (isCurrentOperation(operation)) {
                setNote(response.data?.data || null);
                showToast('Note saved');
            }
        } catch (error) {
            if (isCurrentOperation(operation)) {
                showToast(getApiErrorMessage(error, 'Could not save note'), 'danger');
            }
        } finally {
            if (isCurrentOperation(operation)) {
                activeOperationRef.current = null;
                setBusy(false);
            }
        }
    };

    const remove = async () => {
        if (!note || !window.confirm('Delete this contextual note?')) return;
        const operation = beginOperation();
        if (!operation) return;
        try {
            await api.delete(`/contextual-notes/${note.id}`);
            if (isCurrentOperation(operation)) {
                setNote(null);
                setBody('');
                showToast('Note deleted');
            }
        } catch (error) {
            if (isCurrentOperation(operation)) {
                showToast(getApiErrorMessage(error, 'Could not delete note'), 'danger');
            }
        } finally {
            if (isCurrentOperation(operation)) {
                activeOperationRef.current = null;
                setBusy(false);
            }
        }
    };

    return (
        <>
            <div className="lido-context-notes-header">
                <div>
                    <h2 id={headingId} className="fw-semibold">Notes</h2>
                    <div className="text-muted small">{contextKey}</div>
                </div>
                <button
                    type="button"
                    className="lido-icon-action"
                    aria-label="Close notes"
                    title="Close notes"
                    onClick={onClose}
                >
                    <X size={16} />
                </button>
            </div>
            {loadError && <div className="alert alert-danger" role="alert"><p>{loadError}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading || busy} onClick={load}>Retry contextual note</button></div>}
            <label className="visually-hidden" htmlFor={`${headingId}-body`}>Contextual note</label>
            <textarea
                id={`${headingId}-body`}
                className="form-control lido-context-notes-textarea"
                value={body}
                onChange={(event) => setBody(event.target.value)}
                maxLength={10000}
                aria-busy={loading}
                disabled={loading || Boolean(loadError)}
            />
            <div className="lido-context-notes-actions">
                <button type="button" className="btn btn-primary btn-sm" disabled={busy || loading || Boolean(loadError)} onClick={save}>
                    <Save size={14} /> Save
                </button>
                {note && (
                    <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy || loading || Boolean(loadError)} onClick={remove}>
                        <Trash2 size={14} /> Delete
                    </button>
                )}
            </div>
        </>
    );
}
