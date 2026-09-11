import React, { useCallback, useEffect, useMemo, useState } from 'react';
import { NotebookPen, Save, Trash2, X } from 'lucide-react';
import { useLocation } from 'react-router-dom';
import api, { getApiErrorMessage } from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import { showToast } from '../toast';

function contextFromPath(pathname) {
    const trimmed = pathname.replace(/^\/+/, '').replace(/\/+$/, '');
    return trimmed === '' ? 'dashboard' : trimmed.replace(/[^A-Za-z0-9_.:-]+/g, ':').slice(0, 120);
}

export default function ContextualNotesPane({ user }) {
    const { pathname } = useLocation();
    const { activePortfolio } = usePortfolio();
    const [open, setOpen] = useState(false);
    const [note, setNote] = useState(null);
    const [body, setBody] = useState('');
    const [busy, setBusy] = useState(false);
    const contextKey = useMemo(() => contextFromPath(pathname), [pathname]);

    const load = useCallback(async () => {
        if (!user || user.is_admin) return;
        try {
            const response = await api.get('/contextual-notes', {
                params: {
                    context_key: contextKey,
                    profile_id: activePortfolio?.id || undefined,
                },
            });
            const current = response.data?.data?.[0] || null;
            setNote(current);
            setBody(current?.body || '');
        } catch {
            setNote(null);
            setBody('');
        }
    }, [activePortfolio?.id, contextKey, user]);

    useEffect(() => {
        if (open) load();
    }, [load, open]);

    if (!user || user.is_admin) return null;

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
            <button type="button" className="lido-notes-rail-button" title="Contextual notes" onClick={() => setOpen(true)}>
                <NotebookPen size={18} />
            </button>
            {open && (
                <aside className="lido-context-notes-pane" aria-label="Contextual notes">
                    <div className="lido-context-notes-header">
                        <div>
                            <div className="fw-semibold">Notes</div>
                            <div className="text-muted small">{contextKey}</div>
                        </div>
                        <button type="button" className="lido-icon-action" title="Close notes" onClick={() => setOpen(false)}>
                            <X size={16} />
                        </button>
                    </div>
                    <textarea
                        className="form-control lido-context-notes-textarea"
                        value={body}
                        onChange={(event) => setBody(event.target.value)}
                        maxLength={10000}
                    />
                    <div className="lido-context-notes-actions">
                        <button type="button" className="btn btn-primary btn-sm" disabled={busy} onClick={save}>
                            <Save size={14} /> Save
                        </button>
                        {note && (
                            <button type="button" className="btn btn-outline-danger btn-sm" disabled={busy} onClick={remove}>
                                <Trash2 size={14} /> Delete
                            </button>
                        )}
                    </div>
                </aside>
            )}
        </>
    );
}
