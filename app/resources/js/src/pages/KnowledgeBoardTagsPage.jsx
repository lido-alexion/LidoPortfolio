import React, { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { showToast } from '../toast';

export default function KnowledgeBoardTagsPage() {
    const [tags, setTags] = useState([]);
    const [loading, setLoading] = useState(true);
    const [editingId, setEditingId] = useState(null);
    const [editName, setEditName] = useState('');
    const [editColor, setEditColor] = useState('#0d6efd');
    const [mergeSourceId, setMergeSourceId] = useState('');
    const [mergeTargetId, setMergeTargetId] = useState('');
    const [newName, setNewName] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/knowledge-board/tags');
            setTags(res.data.data || []);
        } catch {
            showToast('Failed to load tags.', 'danger');
            setTags([]);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => { load(); }, [load]);
    usePortfolioChanged(load);

    const startEdit = (tag) => {
        setEditingId(tag.id);
        setEditName(tag.name);
        setEditColor(tag.color || '#6c757d');
    };

    const saveEdit = async () => {
        if (!editingId) {
            return;
        }
        try {
            const res = await api.put(`/knowledge-board/tags/${editingId}`, {
                name: editName,
                color: editColor,
            });
            setTags((prev) => prev.map((tag) => (tag.id === editingId ? res.data.data : tag)));
            setEditingId(null);
            showToast('Tag updated.');
        } catch (err) {
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not update tag.';
            showToast(message, 'danger');
        }
    };

    const deleteTag = async (tag) => {
        if (!window.confirm(`Delete tag "${tag.name}"? It will be removed from all notes.`)) {
            return;
        }
        try {
            await api.delete(`/knowledge-board/tags/${tag.id}`);
            setTags((prev) => prev.filter((row) => row.id !== tag.id));
            showToast('Tag deleted.');
        } catch {
            showToast('Could not delete tag.', 'danger');
        }
    };

    const createTag = async () => {
        const name = newName.trim();
        if (!name) {
            return;
        }
        try {
            const res = await api.post('/knowledge-board/tags', { name });
            setTags((prev) => [...prev, res.data.data].sort((a, b) => a.name.localeCompare(b.name)));
            setNewName('');
            showToast('Tag created.');
        } catch (err) {
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not create tag.';
            showToast(message, 'danger');
        }
    };

    const mergeTags = async () => {
        if (!mergeSourceId || !mergeTargetId || mergeSourceId === mergeTargetId) {
            showToast('Pick two different tags to merge.', 'danger');
            return;
        }
        try {
            await api.post('/knowledge-board/tags/merge', {
                source_id: Number(mergeSourceId),
                target_id: Number(mergeTargetId),
            });
            await load();
            setMergeSourceId('');
            setMergeTargetId('');
            showToast('Tags merged.');
        } catch {
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
                    {loading ? (
                        <div className="p-3 text-muted small">Loading…</div>
                    ) : tags.length === 0 ? (
                        <div className="p-3 text-muted small">No tags yet.</div>
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
