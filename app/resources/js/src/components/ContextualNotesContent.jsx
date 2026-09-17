import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
    const requestIdRef = useRef(0);
    const contextKey = useMemo(() => contextFromPath(pathname), [pathname]);

    const load = useCallback(async () => {
        const requestId = ++requestIdRef.current;
        setLoading(true);
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
        setBusy(true);
        try {
            const response = note
                ? await api.put(`/contextual-notes/${note.id}`, { body })
                : await api.post('/contextual-notes', {
                    context_key: contextKey,
                    profile_id: activePortfolio?.id || null,
                    body,
                });
            setNote(response.data?.data || null);
            showToast('Note saved');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not save note'), 'danger');
        } finally {
            setBusy(false);
        }
    };

    const remove = async () => {
        if (!note || !window.confirm('Delete this contextual note?')) return;
        setBusy(true);
        try {
            await api.delete(`/contextual-notes/${note.id}`);
            setNote(null);
            setBody('');
            showToast('Note deleted');
        } catch (error) {
            showToast(getApiErrorMessage(error, 'Could not delete note'), 'danger');
        } finally {
            setBusy(false);
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
            <label className="visually-hidden" htmlFor={`${headingId}-body`}>Contextual note</label>
            <textarea
                id={`${headingId}-body`}
                className="form-control lido-context-notes-textarea"
                value={body}
                onChange={(event) => setBody(event.target.value)}
                maxLength={10000}
                aria-busy={loading}
                disabled={loading}
            />
            <div className="lido-context-notes-actions">
                <button type="button" className="btn btn-primary btn-sm" disabled={busy || loading} onClick={save}>
                    <Save size={14} /> Save
                </button>
                {note && (
                    <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy || loading} onClick={remove}>
                        <Trash2 size={14} /> Delete
                    </button>
                )}
            </div>
        </>
    );
}
