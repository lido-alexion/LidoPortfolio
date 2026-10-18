import React, { useCallback, useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
import { showToast } from '../toast';

export default function KnowledgeBoardTagsPage() {
    const { activePortfolioId, loading: portfoliosLoading, portfolios } = usePortfolio();
    const [tags, setTags] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');
    const [editColor, setEditColor] = useState('#0d6efd');
    const [mergeSourceId, setMergeSourceId] = useState('');
    const [mergeTargetId, setMergeTargetId] = useState('');
    const [newName, setNewName] = useState('');
    const mountedRef = useRef(false);
    const scopeRef = useRef(getActivePortfolioId());
    const scopeEpochRef = useRef(0);
    const loadGenerationRef = useRef(0);
    const startupScopePendingRef = useRef(getActivePortfolioId() == null);
    const initialLoadDispatchedRef = useRef(false);
    const hasConfirmedScopeRef = useRef(getActivePortfolioId() != null);
    const [waitingForPortfolio, setWaitingForPortfolio] = useState(() => getActivePortfolioId() == null);

    const captureScope = () => ({ scope: getActivePortfolioId(), epoch: scopeEpochRef.current });
    const isCurrentScope = ({ scope, epoch }) => (
        mountedRef.current
        && scopeRef.current === scope
        && scopeEpochRef.current === epoch
        && getActivePortfolioId() === scope
    );

    const load = useCallback(async () => {
        const scope = getActivePortfolioId();
        if (!scope) {
            loadGenerationRef.current += 1;
            if (scopeRef.current !== null) {
                scopeRef.current = null;
                scopeEpochRef.current += 1;
                setTags([]);
                setEditingId(null);
                setEditName('');
                setEditColor('#0d6efd');
                setMergeSourceId('');
                setMergeTargetId('');
            }
            setWaitingForPortfolio(true);
            setLoading(false);
            setLoadError('');
            return;
        }
        if (scope !== scopeRef.current) {
            const firstConfirmedScope = scopeRef.current === null && !hasConfirmedScopeRef.current;
            scopeRef.current = scope;
            scopeEpochRef.current += 1;
            loadGenerationRef.current += 1;
            if (!firstConfirmedScope) {
                setTags([]);
                setEditingId(null);
                setEditName('');
                setEditColor('#0d6efd');
                setMergeSourceId('');
                setMergeTargetId('');
                setNewName('');
            }
            setLoadError('');
        }
        hasConfirmedScopeRef.current = true;
        setWaitingForPortfolio(false);
        const requestEpoch = scopeEpochRef.current;
        const requestGeneration = ++loadGenerationRef.current;
        const isCurrentRequest = () => (
            mountedRef.current
            && requestGeneration === loadGenerationRef.current
            && scopeRef.current === scope
            && scopeEpochRef.current === requestEpoch
            && getActivePortfolioId() === scope
        );

        setLoading(true);
        setLoadError('');
        try {
            const res = await api.get('/knowledge-board/tags');
            if (!Array.isArray(res?.data?.data)) {
                throw new Error('Invalid knowledge tag response.');
            }
            if (isCurrentRequest()) setTags(res.data.data);
        } catch {
            if (isCurrentRequest()) {
                setLoadError('Knowledge tags could not be loaded.');
                showToast('Failed to load tags.', 'danger');
            }
        } finally {
            if (isCurrentRequest()) setLoading(false);
        }
    }, []);

    useEffect(() => {
        mountedRef.current = true;
        return () => {
            mountedRef.current = false;
        };
    }, []);

    useEffect(() => {
        const activeId = activePortfolioId == null ? null : String(activePortfolioId);
        const canonicalId = getActivePortfolioId();

        if (startupScopePendingRef.current) {
            if (!activeId || canonicalId !== activeId) {
                setWaitingForPortfolio(true);
                setLoading(false);
                return;
            }
            startupScopePendingRef.current = false;
        }

        if (!activeId || canonicalId !== activeId) {
            if (scopeRef.current !== null) {
                loadGenerationRef.current += 1;
                scopeEpochRef.current += 1;
                scopeRef.current = null;
                setTags([]);
                setEditingId(null);
                setEditName('');
                setEditColor('#0d6efd');
                setMergeSourceId('');
                setMergeTargetId('');
                setNewName('');
                setLoadError('');
            }
            setWaitingForPortfolio(true);
            setLoading(false);
            return;
        }

        if (initialLoadDispatchedRef.current) return;
        initialLoadDispatchedRef.current = true;
        load();
    }, [activePortfolioId, load]);

    const handlePortfolioChanged = useCallback(() => {
        const scope = getActivePortfolioId();
        if (!scope) {
            load();
            return;
        }
        if (startupScopePendingRef.current) {
            startupScopePendingRef.current = false;
            initialLoadDispatchedRef.current = true;
        }
        load();
    }, [load]);

    usePortfolioChanged(handlePortfolioChanged);

    const startEdit = (tag) => {
        setEditingId(tag.id);
        setEditName(tag.name);
        setEditColor(tag.color || '#6c757d');
    };

    const saveEdit = async () => {
        if (!editingId || !getActivePortfolioId()) {
            return;
        }
        const scopeToken = captureScope();
        const editingTagId = editingId;
        try {
            const res = await api.put(`/knowledge-board/tags/${editingTagId}`, {
                name: editName,
                color: editColor,
            });
            if (!isCurrentScope(scopeToken)) return;
            setTags((prev) => prev.map((tag) => (tag.id === editingTagId ? res.data.data : tag)));
            setEditingId(null);
            showToast('Tag updated.');
        } catch (err) {
            if (!isCurrentScope(scopeToken)) return;
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not update tag.';
            showToast(message, 'danger');
        }
    };

    const deleteTag = async (tag) => {
        if (!getActivePortfolioId()) return;
        if (!window.confirm(`Delete tag "${tag.name}"? It will be removed from all notes.`)) {
            return;
        }
        const scopeToken = captureScope();
        try {
            await api.delete(`/knowledge-board/tags/${tag.id}`);
            if (!isCurrentScope(scopeToken)) return;
            setTags((prev) => prev.filter((row) => row.id !== tag.id));
            showToast('Tag deleted.');
        } catch {
            if (!isCurrentScope(scopeToken)) return;
            showToast('Could not delete tag.', 'danger');
        }
    };

    const createTag = async () => {
        const name = newName.trim();
        if (!name || !getActivePortfolioId()) {
            return;
        }
        const scopeToken = captureScope();
        try {
            const res = await api.post('/knowledge-board/tags', { name });
            if (!isCurrentScope(scopeToken)) return;
            setTags((prev) => [...prev, res.data.data].sort((a, b) => a.name.localeCompare(b.name)));
            setNewName('');
            showToast('Tag created.');
        } catch (err) {
            if (!isCurrentScope(scopeToken)) return;
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not create tag.';
            showToast(message, 'danger');
        }
    };

    const mergeTags = async () => {
        if (!getActivePortfolioId()) return;
        if (!mergeSourceId || !mergeTargetId || mergeSourceId === mergeTargetId) {
            showToast('Pick two different tags to merge.', 'danger');
            return;
        }
        const scopeToken = captureScope();
        try {
            await api.post('/knowledge-board/tags/merge', {
                source_id: Number(mergeSourceId),
                target_id: Number(mergeTargetId),
            });
            if (!isCurrentScope(scopeToken)) return;
            await load();
            if (!isCurrentScope(scopeToken)) return;
            setMergeSourceId('');
            setMergeTargetId('');
            showToast('Tags merged.');
        } catch {
            if (!isCurrentScope(scopeToken)) return;
            showToast('Could not merge tags.', 'danger');
        }
    };

    return (
        <div className="d-grid gap-3">
            <div className="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h1 className="h5 mb-1">Knowledge Board — Tags</h1>
                    <p className="text-muted small mb-0">Rename, delete, or merge tags used on your notes.</p>
                </div>
                <Link to="/knowledge-board" className="btn btn-sm btn-outline-secondary">← Knowledge Board</Link>
            </div>

            <div className="card">
                <div className="card-body">
                    <h2 className="h6">Create tag</h2>
                    <div className="d-flex flex-wrap gap-2">
                        <input
                            type="text"
                            className="form-control form-control-sm"
                            style={{ maxWidth: '16rem' }}
                            placeholder="Tag name"
                            aria-label="New tag name"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            onKeyDown={(e) => e.key === 'Enter' && createTag()}
                        />
                        <button type="button" className="btn btn-sm btn-primary" onClick={createTag}>Add</button>
                    </div>
                </div>
            </div>

            <div className="card">
                <div className="card-header">Merge tags</div>
                <div className="card-body d-flex flex-wrap gap-2 align-items-end">
                    <div>
                        <label className="form-label small" htmlFor="merge-source">Source (removed)</label>
                        <select id="merge-source" className="form-select form-select-sm" value={mergeSourceId} onChange={(e) => setMergeSourceId(e.target.value)}>
                            <option value="">Select…</option>
                            {tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}
                        </select>
                    </div>
                    <div>
                        <label className="form-label small" htmlFor="merge-target">Target (kept)</label>
                        <select id="merge-target" className="form-select form-select-sm" value={mergeTargetId} onChange={(e) => setMergeTargetId(e.target.value)}>
                            <option value="">Select…</option>
                            {tags.map((tag) => <option key={tag.id} value={tag.id}>{tag.name}</option>)}
                        </select>
                    </div>
                    <button type="button" className="btn btn-sm btn-outline-primary" onClick={mergeTags}>Merge</button>
                </div>
            </div>

            <div className="card">
                <div className="card-header">All tags</div>
                <div className="card-body p-0">
                    {loadError && <div className="alert alert-danger" role="alert"><p>{loadError}{tags.length > 0 ? ' Showing the last successfully loaded tags; they may be out of date.' : ''}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={load}>Retry knowledge tags</button></div>}
                    {waitingForPortfolio ? (
                        <div className="p-3 text-muted small" role="status">
                            {portfoliosLoading ? 'Waiting for a selected portfolio to load tags…' : portfolios.length === 0 ? 'Tags will load after a portfolio is selected.' : 'Waiting for a selected portfolio to load tags…'}
                        </div>
                    ) : loading && tags.length === 0 ? (
                        <div className="p-3 text-muted small">Loading…</div>
                    ) : tags.length === 0 ? (
                        loadError ? null : <div className="p-3 text-muted small">No tags yet.</div>
                    ) : (
                        <ul className="list-group list-group-flush">
                            {tags.map((tag) => (
                                <li key={tag.id} className="list-group-item d-flex flex-wrap justify-content-between align-items-center gap-2">
                                    {editingId === tag.id ? (
                                        <div className="d-flex flex-wrap gap-2 align-items-center flex-grow-1">
                                            <input
                                                type="text"
                                                className="form-control form-control-sm"
                                                style={{ maxWidth: '12rem' }}
                                                aria-label="Rename tag"
                                                value={editName}
                                                onChange={(e) => setEditName(e.target.value)}
                                            />
                                            <input
                                                type="color"
                                                className="form-control form-control-color form-control-sm"
                                                value={editColor}
                                                onChange={(e) => setEditColor(e.target.value)}
                                                aria-label="Tag color"
                                            />
                                            <button type="button" className="btn btn-sm btn-primary" onClick={saveEdit}>Save</button>
                                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => setEditingId(null)}>Cancel</button>
                                        </div>
                                    ) : (
                                        <>
                                            <span>
                                                <span className="badge me-2" style={{ backgroundColor: tag.color }}>{tag.name}</span>
                                            </span>
                                            <div className="d-flex gap-1">
                                                <button type="button" className="btn btn-sm btn-outline-secondary" onClick={() => startEdit(tag)}>Rename</button>
                                                <button type="button" className="btn btn-sm btn-outline-danger" onClick={() => deleteTag(tag)}>Delete</button>
                                            </div>
                                        </>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </div>
    );
}
