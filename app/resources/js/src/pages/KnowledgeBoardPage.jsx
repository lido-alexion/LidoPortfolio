import React, { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import api from '../api';
import { usePortfolio } from '../context/PortfolioContext';
import usePortfolioChanged from '../hooks/usePortfolioChanged';
import { getActivePortfolioId } from '../portfolio/activePortfolioStorage';
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
    const ownershipRef = useRef({ profileId: profileId == null ? null : String(profileId), generation: 0, mounted: false, editorGeneration: 0 });
    const pendingPortfolioRef = useRef({ active: false, profileId: null });
    const ownership = () => ({ ...ownershipRef.current });
    const owns = (owner) => ownershipRef.current.mounted
        && ownershipRef.current.profileId === owner.profileId
        && ownershipRef.current.generation === owner.generation;
    const ownsEditor = (owner) => owns(owner) && owner.editorGeneration === ownershipRef.current.editorGeneration;

    const [notes, setNotes] = useState([]);
    const [tags, setTags] = useState([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState('');
    const [tagsError, setTagsError] = useState('');
    const notesRequestRef = useRef(0);
    const searchRequestRef = useRef(0);
    const [search, setSearch] = useState('');
    const [wikiSearchResults, setWikiSearchResults] = useState([]);
    const [sort, setSort] = useState(() => loadSortPreference());
    const [tagMatch, setTagMatch] = useState('any');
    const [filterTagIds, setFilterTagIds] = useState([]);
    const [showArchived, setShowArchived] = useState(false);
    const [filtersExpanded, setFiltersExpanded] = useState(false);
    const [manageMode, setManageMode] = useState(() => loadManageModePreference());
    const [selectedIds, setSelectedIds] = useState(() => new Set());
    const [manualOrder, setManualOrder] = useState([]);
    const [editorOpen, setEditorOpen] = useState(false);
    const [draftPortfolioIds, setDraftPortfolioIds] = useState([]);
    const [editorSessionKey, setEditorSessionKey] = useState(0);
    const editorOwnerRef = useRef(null);
    const editorSessionsRef = useRef(new Map());
    const editorSessionIdRef = useRef(0);
    const [editingNote, setEditingNote] = useState(null);
    const [saving, setSaving] = useState(false);
    const [exportOpen, setExportOpen] = useState(false);
    const searchInputRef = useRef(null);

    const debouncedSearch = useDebouncedValue(search, 300);

    const resetForPortfolio = useCallback((nextProfileId) => {
        const next = nextProfileId == null ? null : String(nextProfileId);
        if (ownershipRef.current.profileId === next) return false;
        if (editorOpen && ownershipRef.current.profileId != null) {
            const session = editorOwnerRef.current?.session;
            if (session && session.profileId === ownershipRef.current.profileId) {
                session.note = editingNote ?? session.note;
                editorSessionsRef.current.set(session.profileId, session);
                setDraftPortfolioIds(current => current.includes(session.profileId) ? current : [...current, session.profileId]);
            }
        }
        ownershipRef.current = { profileId: next, generation: ownershipRef.current.generation + 1, mounted: ownershipRef.current.mounted, editorGeneration: ownershipRef.current.editorGeneration + 1 };
        setNotes([]); setTags([]); setLoadError(''); setTagsError(''); setWikiSearchResults([]);
        setLoading(Boolean(next)); setSaving(false); setSelectedIds(new Set());
        setFilterTagIds([]); setExportOpen(false);
        setEditorOpen(false);
        return true;
    }, [editorOpen, editingNote]);

    useLayoutEffect(() => {
        const committed = profileId == null ? null : String(profileId);
        if (pendingPortfolioRef.current.active) {
            if (pendingPortfolioRef.current.profileId !== committed) return;
            pendingPortfolioRef.current = { active: false, profileId: null };
        }
        resetForPortfolio(profileId);
    }, [profileId, resetForPortfolio]);

    const loadTags = useCallback(async () => {
        if (profileId == null) return;
        const owner = ownership();
        if (owner.profileId !== String(profileId)) return;
        setTagsError('');
        try {
            const res = await api.get('/knowledge-board/tags');
            if (!owns(owner)) return;
            setTags(res.data.data || []);
        } catch {
            if (!owns(owner)) return;
            setTagsError('Knowledge tags could not be loaded.');
        }
    }, [profileId]);

    const loadNotes = useCallback(async () => {
        if (profileId == null) {
            setLoading(false);
            return;
        }
        const owner = ownership();
        if (owner.profileId !== String(profileId)) return;
        const requestId = ++notesRequestRef.current;
        setLoading(true);
        setLoadError('');
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
            if (owns(owner) && requestId === notesRequestRef.current) setNotes(res.data.data || []);
        } catch {
            if (owns(owner) && requestId === notesRequestRef.current) {
                setLoadError('Knowledge notes could not be loaded.');
                showToast('Failed to load knowledge notes.', 'danger');
            }
        } finally {
            if (owns(owner) && requestId === notesRequestRef.current) setLoading(false);
        }
    }, [debouncedSearch, showArchived, tagMatch, filterTagIds, sort, profileId]);

    useEffect(() => {
        loadTags().catch(() => setTags([]));
    }, [loadTags]);

    useEffect(() => {
        loadNotes();
    }, [loadNotes]);

    useEffect(() => {
        const owner = ownership();
        if (owner.profileId !== (profileId == null ? null : String(profileId))) return;
        const requestId = ++searchRequestRef.current;
        if (!debouncedSearch.trim()) { setWikiSearchResults([]); return; }
        api.get('/knowledge-board/search', { params: { q: debouncedSearch } })
            .then((response) => { if (owns(owner) && requestId === searchRequestRef.current) setWikiSearchResults((response.data?.data || []).filter((item) => item.type === 'wiki_page')); })
            .catch(() => { if (owns(owner) && requestId === searchRequestRef.current) setWikiSearchResults([]); });
    }, [debouncedSearch, profileId]);

    useEffect(() => {
        if (profileId) {
            setManualOrder(loadManualOrder(profileId));
        }
    }, [profileId]);

    usePortfolioChanged(() => {
        const selectedProfileId = getActivePortfolioId();
        pendingPortfolioRef.current = { active: true, profileId: selectedProfileId == null ? null : String(selectedProfileId) };
        resetForPortfolio(selectedProfileId);
    });

    useEffect(() => {
        ownershipRef.current.mounted = true;
        return () => { ownershipRef.current.mounted = false; ownershipRef.current.generation += 1; ownershipRef.current.editorGeneration += 1; };
    }, []);

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
        if (profileId == null) return;
        const owner = ownership();
        const nextSessionKey = editorSessionKey + 1;
        const session = { id: ++editorSessionIdRef.current, profileId: owner.profileId, sessionKey: nextSessionKey, note: null };
        editorSessionsRef.current.set(owner.profileId, session);
        setDraftPortfolioIds(current => current.filter(id => id !== owner.profileId));
        setEditingNote(null);
        setEditorSessionKey(nextSessionKey);
        editorOwnerRef.current = { ...owner, editorGeneration: ownershipRef.current.editorGeneration + 1, session };
        ownershipRef.current.editorGeneration += 1;
        setEditorOpen(true);
    };

    const openEditNote = (note) => {
        const owner = ownership();
        const nextSessionKey = editorSessionKey + 1;
        const session = { id: ++editorSessionIdRef.current, profileId: owner.profileId, sessionKey: nextSessionKey, note };
        editorSessionsRef.current.set(owner.profileId, session);
        setDraftPortfolioIds(current => current.filter(id => id !== owner.profileId));
        setEditingNote(note);
        setEditorSessionKey(nextSessionKey);
        editorOwnerRef.current = { ...owner, editorGeneration: ownershipRef.current.editorGeneration + 1, session };
        ownershipRef.current.editorGeneration += 1;
        setEditorOpen(true);
    };

    const createTag = async (name, owner = ownership()) => {
        try {
            const res = await api.post('/knowledge-board/tags', { name });
            if (!owns(owner)) return null;
            const created = res.data.data;
            setTags((prev) => [...prev, created].sort((a, b) => a.name.localeCompare(b.name)));
            return created;
        } catch (err) {
            if (!owns(owner)) return null;
            const message = err?.response?.data?.errors?.name?.[0] || 'Could not create tag.';
            showToast(message, 'danger');
            return null;
        }
    };

    const saveNote = async (payload, { isAutosave = false } = {}, owner = editorOwnerRef.current) => {
        const session = owner?.session;
        const editorKey = session?.sessionKey;
        if (!session || !editorOpen || !ownsEditor(owner)) return false;
        const isSessionCurrent = () => ownershipRef.current.mounted && editorSessionsRef.current.get(session.profileId) === session;
        const isCurrentEditor = () => editorOpen && ownsEditor(owner) && editorSessionKey === editorKey && isSessionCurrent() && editorOwnerRef.current?.session === session;
        const isVisibleSession = () => ownershipRef.current.mounted && editorOpen && ownershipRef.current.profileId === session.profileId && editorOwnerRef.current?.session === session;
        // A suspended session can be resumed before its request settles. Keep the
        // operation on the session itself so the resumed editor cannot duplicate it.
        if (session.pendingWrite) return false;
        const operation = {};
        session.pendingWrite = operation;
        if (!isAutosave) {
            setSaving(true);
        }
        try {
            if (session.note?.id) {
                const res = await api.put(`/knowledge-board/notes/${session.note.id}`, payload);
                const updated = res.data.data;
                if (isSessionCurrent()) session.note = updated;
                if (isVisibleSession() && isSessionCurrent()) setEditingNote(updated);
                if (!isCurrentEditor()) return false;
                setNotes((prev) => prev.map((row) => (row.id === updated.id ? updated : row)));
                if (!isAutosave) {
                    setEditingNote(updated);
                }
            } else {
                const res = await api.post('/knowledge-board/notes', payload);
                const created = res.data.data;
                if (isSessionCurrent()) session.note = created;
                if (isVisibleSession()) setEditingNote(created);
                if (!isCurrentEditor()) return false;
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
            if (!isCurrentEditor()) return false;
            const message = err?.response?.data?.errors?.content_html?.[0]
                || err?.response?.data?.errors?.title?.[0]
                || err?.response?.data?.message
                || 'Could not save note.';
            if (!isAutosave) {
                showToast(message, 'danger');
            }
            return false;
        } finally {
            if (session.pendingWrite === operation) session.pendingWrite = null;
            if (!isAutosave) {
                if (isCurrentEditor()) setSaving(false);
            }
        }
    };

    const boundEditorOwner = editorOwnerRef.current;
    const saveEditorNote = (payload, options) => saveNote(payload, options, boundEditorOwner);
    const createEditorTag = (name) => (boundEditorOwner && ownsEditor(boundEditorOwner)
        ? createTag(name, boundEditorOwner)
        : Promise.resolve(null));
    const deleteEditorNote = (note) => (boundEditorOwner && ownsEditor(boundEditorOwner)
        ? deleteNote(note, { closeEditor: true }, boundEditorOwner)
        : Promise.resolve());
    const closeEditorSession = () => {
        if (!boundEditorOwner || !ownsEditor(boundEditorOwner)) return;
        if (boundEditorOwner.session.pendingWrite) {
            showToast('A note save is still pending. Keep the editor open until it finishes.');
            return;
        }
        if (editorSessionsRef.current.get(boundEditorOwner.profileId) === boundEditorOwner.session) {
            editorSessionsRef.current.delete(boundEditorOwner.profileId);
        }
        ownershipRef.current.editorGeneration += 1;
        setEditorOpen(false);
        setEditingNote(null);
    };

    const resumeNoteDraft = () => {
        const profileKey = ownershipRef.current.profileId;
        const session = editorSessionsRef.current.get(profileKey);
        if (!session) return;
        ownershipRef.current.editorGeneration += 1;
        editorOwnerRef.current = { ...ownership(), editorGeneration: ownershipRef.current.editorGeneration, session };
        setEditingNote(session.note);
        setEditorSessionKey(session.sessionKey);
        setDraftPortfolioIds(current => current.filter(id => id !== profileKey));
        setEditorOpen(true);
    };

    const patchNote = async (note, changes, owner = ownership()) => {
        try {
            const res = await api.put(`/knowledge-board/notes/${note.id}`, changes);
            if (!owns(owner)) return false;
            const updated = res.data.data;
            setNotes((prev) => prev.map((row) => (row.id === updated.id ? updated : row)));
            return true;
        } catch {
            if (!owns(owner)) return false;
            showToast('Could not update note.', 'danger');
            return false;
        }
    };

    const duplicateNote = async (note) => {
        const owner = ownership();
        try {
            const res = await api.post(`/knowledge-board/notes/${note.id}/duplicate`);
            if (!owns(owner)) return;
            const copy = res.data.data;
            setNotes((prev) => [copy, ...prev]);
            showToast('Note duplicated.');
        } catch {
            if (!owns(owner)) return;
            showToast('Could not duplicate note.', 'danger');
        }
    };

    const archiveNote = async (note) => {
        const owner = ownership();
        if (await patchNote(note, { is_archived: true }, owner) && owns(owner)) {
            setNotes((prev) => prev.filter((row) => row.id !== note.id));
            showToast('Note archived.');
        }
    };

    const deleteNote = async (note, { closeEditor = false } = {}, owner = ownership()) => {
        const label = notePreviewText(note.content_html, 60) || 'this note';
        if (!window.confirm(`Delete "${label}"? This cannot be undone.`)) {
            return;
        }
        try {
            await api.delete(`/knowledge-board/notes/${note.id}`);
            if (!owns(owner)) return;
            const ownsEditorSession = () => closeEditor
                && ownsEditor(owner)
                && editorOwnerRef.current?.session === owner.session
                && editorSessionsRef.current.get(owner.profileId) === owner.session;
            const closeOriginatingEditor = ownsEditorSession();
            setNotes((prev) => prev.filter((row) => row.id !== note.id));
            setSelectedIds((prev) => {
                const next = new Set(prev);
                next.delete(String(note.id));
                return next;
            });
            if (closeOriginatingEditor) {
                if (owner.session && editorSessionsRef.current.get(owner.profileId) === owner.session) {
                    editorSessionsRef.current.delete(owner.profileId);
                }
                ownershipRef.current.editorGeneration += 1;
                setEditorOpen(false);
                setEditingNote(null);
            }
            if (!closeEditor || closeOriginatingEditor) showToast('Note deleted.');
        } catch {
            if (!owns(owner) || (closeEditor && (!ownsEditor(owner) || editorOwnerRef.current?.session !== owner.session))) return;
            showToast('Could not delete note.', 'danger');
        }
    };

    const bulkAction = async (action) => {
        const owner = ownership();
        const ids = [...selectedIds];
        if (!ids.length) {
            return;
        }
        if (action === 'delete' && !window.confirm(`Delete ${ids.length} selected note(s)?`)) {
            return;
        }
        try {
            await api.post('/knowledge-board/notes/bulk', { action, note_ids: ids.map(Number) });
            if (!owns(owner)) return;
            await loadNotes();
            if (!owns(owner)) return;
            clearSelection();
            showToast(action === 'delete' ? 'Notes deleted.' : 'Notes archived.');
        } catch {
            if (!owns(owner)) return;
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
                            <Link to="/knowledge-board/wiki" className="btn btn-sm btn-outline-primary">Open Wiki</Link>
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

            {draftPortfolioIds.includes(String(profileId)) && !editorOpen ? (
                <button type="button" className="btn btn-sm btn-outline-primary justify-self-start" onClick={resumeNoteDraft}>Resume note draft</button>
            ) : null}

            {wikiSearchResults.length ? <div className="card"><div className="card-header">Wiki Page results</div><div className="list-group list-group-flush">{wikiSearchResults.map((result) => <Link key={result.id} to={`/knowledge-board/wiki/${result.id}`} className="list-group-item list-group-item-action"><strong>{result.title}</strong><div className="small text-muted">Wiki Page · {result.context}</div><div className="small">{result.excerpt}</div></Link>)}</div></div> : null}

            {profileId == null ? <div className="text-muted small px-1" role="status">Waiting for a selected portfolio</div> : tagsError && <div className="alert alert-danger" role="alert"><p>{tagsError}</p><button type="button" className="btn btn-sm btn-outline-primary" onClick={loadTags}>Retry knowledge tags</button></div>}
            {profileId != null && (loadError ? <div className="alert alert-danger" role="alert"><p>{loadError}</p><button type="button" className="btn btn-sm btn-outline-primary" disabled={loading} onClick={loadNotes}>Retry knowledge notes</button></div> : loading ? (
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
                    onChangePalette={(note, paletteId) => patchNote(note, { color_palette: paletteId })}
                />
            ))}

            <KnowledgeNoteEditorModal
                open={editorOpen}
                sessionKey={editorSessionKey}
                sessionId={editorOwnerRef.current?.session?.id ?? null}
                portfolioId={profileId}
                note={editingNote}
                allTags={tags}
                saving={saving}
                onClose={closeEditorSession}
                onSave={saveEditorNote}
                onDelete={deleteEditorNote}
                onCreateTag={createEditorTag}
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
