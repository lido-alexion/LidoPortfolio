import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import KnowledgeNoteGrid from '../components/knowledgeBoard/KnowledgeNoteGrid';
import KnowledgeNoteEditorModal from '../components/knowledgeBoard/KnowledgeNoteEditorModal';
import KnowledgeExportDialog from '../components/knowledgeBoard/KnowledgeExportDialog';
import KnowledgeImageLightbox from '../components/knowledgeBoard/KnowledgeImageLightbox';
import { IconChevronDown } from '../components/knowledgeBoard/KnowledgeCardIcons';
import SegmentToggle from '../components/SegmentToggle';
import { showToast } from '../toast';
import { notePreviewText } from '../utils/knowledgeBoardPreview';
import {
    applyManualOrder,
    loadManageModePreference,
    loadManualOrder,
    loadSortPreference,
    saveManageModePreference,
    saveManualOrder,
    saveSortPreference,
} from '../utils/knowledgeBoardOrder';

const SORT_OPTIONS = [
    { value: 'manual', label: 'Sort manually' },
    { value: 'updated_at', label: 'Sort by date updated' },
    { value: 'created_at', label: 'Sort by date created' },
    { value: 'title', label: 'Sort by title' },
    { value: 'pinned_first', label: 'Sort pinned first' },
];

const TAG_MATCH_OPTIONS = [
    { value: 'any', label: 'Match any tags' },
    { value: 'all', label: 'Match all tags' },
    { value: 'exclude', label: 'Exclude tags' },
];

function useDebouncedValue(value, delayMs = 300) {
    const [debounced, setDebounced] = useState(value);
    useEffect(() => {
        const timer = setTimeout(() => setDebounced(value), delayMs);
        return () => clearTimeout(timer);
    }, [value, delayMs]);
    return debounced;
}

export default function KnowledgeBoardPage() {
    const { activePortfolio } = usePortfolio();
    const profileId = activePortfolio?.id;

    const [notes, setNotes] = useState([]);
    const [tags, setTags] = useState([]);
    const [loading, setLoading] = useState(true);
    const [search, setSearch] = useState('');
    const [sort, setSort] = useState(() => loadSortPreference());
    const [tagMatch, setTagMatch] = useState('any');
    const [filterTagIds, setFilterTagIds] = useState([]);
    const [showArchived, setShowArchived] = useState(false);
    const [filtersExpanded, setFiltersExpanded] = useState(false);
    const [manageMode, setManageMode] = useState(() => loadManageModePreference());
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [manualOrder, setManualOrder] = useState([]);
    const [editorOpen, setEditorOpen] = useState(false);
    const [editorSessionKey, setEditorSessionKey] = useState(0);
    const [editingNote, setEditingNote] = useState(null);
    const [saving, setSaving] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const searchInputRef = useRef(null);

    const debouncedSearch = useDebouncedValue(search, 300);

    const loadTags = useCallback(async () => {
        const res = await api.get('/knowledge-board/tags');
        setTags(res.data.data || []);
    }, []);

    const loadNotes = useCallback(async () => {
        setLoading(true);
        try {
            const res = await api.get('/knowledge-board/notes', {
                params: {
                    q: debouncedSearch || undefined,
                    archived: showArchived,
                    tag_match: filterTagIds.length ? tagMatch : undefined,
                    tag_ids: filterTagIds.length ? filterTagIds : undefined,
                    sort: sort === 'manual' ? 'updated_at' : sort,
                },
            });
            setNotes(res.data.data || []);
        } catch {
            showToast('Failed to load knowledge notes.', 'danger');
            setNotes([]);
        } finally {
            setLoading(false);
        }
    }, [debouncedSearch, showArchived, tagMatch, filterTagIds, sort]);

    useEffect(() => {
        loadTags().catch(() => setTags([]));
    }, [loadTags]);

    useEffect(() => {
        loadNotes();
    }, [loadNotes]);

    useEffect(() => {
        if (profileId) {
            setManualOrder(loadManualOrder(profileId));
        }
    }, [profileId]);

    usePortfolioChanged(() => {
        setSelectedIds(new Set());
        loadTags();
        loadNotes();
    });

    const displayNotes = useMemo(() => {
        if (sort !== 'manual') {
            return notes;
        }
        return applyManualOrder(notes, manualOrder);
    }, [notes, sort, manualOrder]);

    const selectedNotes = useMemo(
        () => displayNotes.filter((note) => selectedIds.has(String(note.id))),
        [displayNotes, selectedIds],
    );

    const toggleSelect = (noteId) => {
        setSelectedIds((prev) => {
            const next = new Set(prev);
            const key = String(noteId);
            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }
            return next;
        });
    };

    const selectAllVisible = () => {
        setSelectedIds(new Set(displayNotes.map((note) => String(note.id))));
    };

    const clearSelection = () => setSelectedIds(new Set());

    const openNewNote = () => {
        setEditingNote(null);
        setEditorSessionKey((key) => key + 1);
        setEditorOpen(true);
    };

    const openEditNote = (note) => {
        setEditingNote(note);
        setEditorSessionKey((key) => key + 1);
        setEditorOpen(true);
    };

    const createTag = async (name) => {
        try {
            const res = await api.post('/knowledge-board/tags', { name });
            const created = res.data.data;
            setTags((prev) => [...prev, created].sort((a, b) => a.name.localeCompare(b.name)));
            return created;
        } catch (err) {
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not create tag.';
            showToast(message, 'danger');
            return null;
        }
    };

    const saveNote = async (payload, { isAutosave = false } = {}) => {
        if (!isAutosave) {
            setSaving(true);
        }
        try {
            if (editingNote?.id) {
                const res = await api.put(`/knowledge-board/notes/${editingNote.id}`, payload);
                const updated = res.data.data;
                setNotes((prev) => prev.map((row) => (row.id === updated.id ? updated : row)));
                if (!isAutosave) {
                    setEditingNote(updated);
                }
            } else {
                const res = await api.post('/knowledge-board/notes', payload);
                const created = res.data.data;
                setNotes((prev) => [created, ...prev]);
                setEditingNote(created);
                if (profileId) {
                    const nextOrder = [String(created.id), ...manualOrder];
                    setManualOrder(nextOrder);
                    saveManualOrder(profileId, nextOrder);
                }
            }
            if (!isAutosave) {
                showToast('Note saved.');
            }
            return true;
        } catch (err) {
            const message = err?.response?.data?.errors?.content_html?.[0]
                || err?.response?.data?.errors?.title?.[0]
                || err?.response?.data?.message
                || 'Could not save note.';
            if (!isAutosave) {
                showToast(message, 'danger');
            }
            return false;
        } finally {
            if (!isAutosave) {
                setSaving(false);
            }
        }
    };

    const patchNote = async (note, changes) => {
        try {
            const res = await api.put(`/knowledge-board/notes/${note.id}`, changes);
            const updated = res.data.data;
            setNotes((prev) => prev.map((row) => (row.id === updated.id ? updated : row)));
        } catch {
            showToast('Could not update note.', 'danger');
        }
    };

    const duplicateNote = async (note) => {
        try {
            const res = await api.post(`/knowledge-board/notes/${note.id}/duplicate`);
            const copy = res.data.data;
            setNotes((prev) => [copy, ...prev]);
            showToast('Note duplicated.');
        } catch {
            showToast('Could not duplicate note.', 'danger');
        }
    };

    const archiveNote = async (note) => {
        await patchNote(note, { is_archived: true });
        setNotes((prev) => prev.filter((row) => row.id !== note.id));
        showToast('Note archived.');
    };

    const deleteNote = async (note, { closeEditor = false } = {}) => {
        const label = notePreviewText(note.content_html, 60) || 'this note';
        if (!window.confirm(`Delete "${label}"? This cannot be undone.`)) {
            return;
        }
        try {
            await api.delete(`/knowledge-board/notes/${note.id}`);
            setNotes((prev) => prev.filter((row) => row.id !== note.id));
            setSelectedIds((prev) => {
                const next = new Set(prev);
                next.delete(String(note.id));
                return next;
            });
            if (closeEditor) {
                setEditorOpen(false);
                setEditingNote(null);
            }
            showToast('Note deleted.');
        } catch {
            showToast('Could not delete note.', 'danger');
        }
    };

    const bulkAction = async (action) => {
        const ids = [...selectedIds];
        if (!ids.length) {
            return;
        }
        if (action === 'delete' && !window.confirm(`Delete ${ids.length} selected note(s)?`)) {
            return;
        }
        try {
            await api.post('/knowledge-board/notes/bulk', { action, note_ids: ids.map(Number) });
            await loadNotes();
            clearSelection();
            showToast(action === 'delete' ? 'Notes deleted.' : 'Notes archived.');
        } catch {
            showToast('Bulk action failed.', 'danger');
        }
    };

    const handleReorder = (orderedIds) => {
        setManualOrder(orderedIds);
        if (profileId) {
            saveManualOrder(profileId, orderedIds);
        }
    };

    const toggleFilterTag = (tagId) => {
        setFilterTagIds((prev) => (
            prev.includes(tagId) ? prev.filter((id) => id !== tagId) : [...prev, tagId]
        ));
    };

    useEffect(() => {
        const onKeyDown = (event) => {
            if (editorOpen) {
                return;
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'n') {
                event.preventDefault();
                openNewNote();
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') {
                event.preventDefault();
                setFiltersExpanded(true);
                setTimeout(() => searchInputRef.current?.focus(), 0);
            }
            if (event.key === 'Delete' && manageMode && selectedIds.size > 0) {
                event.preventDefault();
                bulkAction('delete');
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    });

    const setManageModePreference = useCallback((enabled) => {
        setManageMode(enabled);
        saveManageModePreference(enabled);
        if (!enabled) {
            setSelectedIds(new Set());
        }
    }, []);

    const manageModeToggle = (
        <SegmentToggle
            compact
            className="lido-knowledge-manage-toggle"
            ariaLabel="Note viewing mode"
            value={manageMode ? 'manage' : 'read'}
            onChange={(value) => setManageModePreference(value === 'manage')}
            options={[
                { value: 'read', label: 'Read' },
                { value: 'manage', label: 'Manage' },
            ]}
        />
    );

    return (
        <div className="d-grid gap-2 lido-knowledge-board-page">
            {!filtersExpanded ? (
                <div className="lido-knowledge-toolbar-collapsed-row">
                    <button
                        type="button"
                        className="btn btn-sm btn-primary lido-knowledge-toolbar-new-collapsed"
                        onClick={openNewNote}
                    >
                        + New
                    </button>
                    {manageModeToggle}
                    <div className="card lido-knowledge-toolbar-card lido-knowledge-toolbar-card--collapsed">
                        <button
                            type="button"
                            className="lido-knowledge-toolbar-toggle"
                            onClick={() => setFiltersExpanded(true)}
                            aria-expanded={false}
                            aria-controls="kb-toolbar-panel"
                            aria-label="Expand Knowledge Board filters"
                        >
                            <span className="lido-knowledge-toolbar-expand-icon" aria-hidden="true">
                                <IconChevronDown />
                            </span>
                        </button>
                    </div>
                </div>
            ) : (
            <div className="card lido-knowledge-toolbar-card lido-knowledge-toolbar-card--expanded">
                    <>
                        <button
                            type="button"
                            className="lido-knowledge-toolbar-toggle"
                            onClick={() => setFiltersExpanded(false)}
                            aria-expanded
                            aria-controls="kb-toolbar-panel"
                            aria-label="Collapse Knowledge Board filters"
                        >
                            <span className="lido-knowledge-toolbar-toggle-label">Knowledge Board</span>
                            <span className="lido-collapsible-card-chevron lido-knowledge-toolbar-chevron-expanded" aria-hidden="true">▾</span>
                        </button>

                        <div id="kb-toolbar-panel" className="card-body pt-0 pb-2 px-2 px-md-3">
                        <div className="d-flex flex-wrap lido-knowledge-toolbar-actions mb-2 align-items-center">
                            <button type="button" className="btn btn-sm btn-primary" onClick={openNewNote}>New Note</button>
                            {manageModeToggle}
                            {manageMode ? (
                                <>
                                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={selectAllVisible}>Select All</button>
                                    <button type="button" className="btn btn-sm btn-outline-secondary" onClick={clearSelection} disabled={!selectedIds.size}>Clear</button>
                                    <button type="button" className="btn btn-sm btn-outline-secondary" disabled={!selectedIds.size} onClick={() => bulkAction('archive')}>Archive</button>
                                    <button type="button" className="btn btn-sm btn-outline-danger" disabled={!selectedIds.size} onClick={() => bulkAction('delete')}>Delete</button>
                                    <button type="button" className="btn btn-sm btn-outline-primary" disabled={!selectedIds.size} onClick={() => setExportOpen(true)}>Export</button>
                                </>
                            ) : null}
                            <Link to="/knowledge-board/tags" className="btn btn-sm btn-outline-secondary">Manage tags</Link>
                            {manageMode && selectedIds.size ? (
                                <span className="small text-muted align-self-center ms-1">{selectedIds.size} selected</span>
                            ) : null}
                        </div>

                        <div className="row g-2 align-items-center">
                            <div className="col-12 col-lg-5">
                                <input
                                    id="kb-search"
                                    ref={searchInputRef}
                                    type="search"
                                    className="form-control form-control-sm"
                                    placeholder="Search text, tags"
                                    aria-label="Search text and tags"
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                />
                            </div>
                            <div className="col-6 col-md-4 col-lg-3">
                                <select
                                    id="kb-sort"
                                    className="form-select form-select-sm"
                                    value={sort}
                                    onChange={(e) => {
                                        const next = e.target.value;
                                        setSort(next);
                                        saveSortPreference(next);
                                    }}
                                    aria-label="Sort notes"
                                >
                                    {SORT_OPTIONS.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-6 col-md-4 col-lg-4 d-flex align-items-center">
                                <label className="form-check small mb-0">
                                    <input className="form-check-input" type="checkbox" checked={showArchived} onChange={(e) => setShowArchived(e.target.checked)} />
                                    Show archived
                                </label>
                            </div>
                        </div>

                        {tags.length ? (
                            <div className="lido-knowledge-filter-tags-row mt-2">
                                <select
                                    id="kb-tag-match"
                                    className="form-select form-select-sm lido-knowledge-tag-match-select"
                                    value={tagMatch}
                                    onChange={(e) => setTagMatch(e.target.value)}
                                    aria-label="Tag filter mode"
                                >
                                    {TAG_MATCH_OPTIONS.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                                {tags.map((tag) => {
                                    const active = filterTagIds.includes(tag.id);
                                    return (
                                        <button
                                            key={tag.id}
                                            type="button"
                                            className={[
                                                'lido-knowledge-filter-tag',
                                                active ? 'lido-knowledge-filter-tag--active' : '',
                                            ].join(' ')}
                                            onClick={() => toggleFilterTag(tag.id)}
                                        >
                                            {tag.name}
                                        </button>
                                    );
                                })}
                            </div>
                        ) : null}
                        </div>
                    </>
            </div>
            )}

            {loading ? (
                <div className="text-muted small px-1">Loading notes…</div>
            ) : displayNotes.length === 0 ? (
                <div className="card">
                    <div className="card-body text-muted small py-2 px-3">
                        {showArchived ? 'No archived notes.' : 'No notes yet. Create your first market learning note.'}
                    </div>
                </div>
            ) : (
                <KnowledgeNoteGrid
                    notes={displayNotes}
                    sortMode={sort}
                    showControls={manageMode}
                    selectedIds={selectedIds}
                    onToggleSelect={toggleSelect}
                    onReorder={handleReorder}
                    onEdit={openEditNote}
                    onTogglePin={(note) => patchNote(note, { is_pinned: !note.is_pinned })}
                    onDuplicate={duplicateNote}
                    onArchive={archiveNote}
                    onDelete={deleteNote}
                />
            )}

            <KnowledgeNoteEditorModal
                open={editorOpen}
                sessionKey={editorSessionKey}
                note={editingNote}
                allTags={tags}
                saving={saving}
                onClose={() => setEditorOpen(false)}
                onSave={saveNote}
                onDelete={(note) => deleteNote(note, { closeEditor: true })}
                onCreateTag={createTag}
            />

            {exportOpen ? (
                <KnowledgeExportDialog
                    notes={selectedNotes}
                    onClose={() => setExportOpen(false)}
                />
            ) : null}

            <KnowledgeImageLightbox />
        </div>
    );
}
