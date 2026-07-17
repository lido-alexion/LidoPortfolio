import React, { useEffect, useState } from 'react';
import { createPortal } from 'react-dom';
import api from '../api';
import { showToast } from '../toast';
import { validateWatchlistName } from '../utils/watchlistName';

function validationMessage(error) {
    const errors = error?.response?.data?.errors;
    if (errors) {
        const first = Object.values(errors).flat()[0];
        if (first) {
            return first;
        }
    }

    return error?.response?.data?.message || 'Something went wrong. Please try again.';
}

export default function ManageWatchlistsModal({
    show,
    watchlists,
    activeWatchlistId,
    onClose,
    onChanged,
}) {
    const [newName, setNewName] = useState('');
    const [newNameTouched, setNewNameTouched] = useState(false);
    const [creating, setCreating] = useState(false);
    const [renameId, setRenameId] = useState(null);
    const [renameName, setRenameName] = useState('');
    const [renameTouched, setRenameTouched] = useState(false);
    const [savingRename, setSavingRename] = useState(false);
    const [deletingId, setDeletingId] = useState(null);

    useEffect(() => {
        if (!show) {
            setNewName('');
            setNewNameTouched(false);
            setRenameId(null);
            setRenameName('');
            setRenameTouched(false);
        }
    }, [show]);

    if (!show) {
        return null;
    }

    const newNameError = newNameTouched ? validateWatchlistName(newName) : null;
    const renameNameError = renameTouched ? validateWatchlistName(renameName) : null;

    const createWatchlist = async (event) => {
        event.preventDefault();
        setNewNameTouched(true);
        const name = newName.trim();
        const error = validateWatchlistName(name);
        if (error) {
            return;
        }

        setCreating(true);
        try {
            await api.post('/watchlists', { name });
            setNewName('');
            setNewNameTouched(false);
            await onChanged?.();
            showToast('Watchlist created.');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setCreating(false);
        }
    };

    const startRename = (watchlist) => {
        setRenameId(watchlist.id);
        setRenameName(watchlist.name);
        setRenameTouched(false);
    };

    const cancelRename = () => {
        setRenameId(null);
        setRenameName('');
        setRenameTouched(false);
    };

    const saveRename = async (watchlistId) => {
        setRenameTouched(true);
        const name = renameName.trim();
        const error = validateWatchlistName(name);
        if (error) {
            return;
        }

        setSavingRename(true);
        try {
            await api.put(`/watchlists/${watchlistId}`, { name });
            cancelRename();
            await onChanged?.();
            showToast('Watchlist renamed.');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setSavingRename(false);
        }
    };

    const deleteWatchlist = async (watchlist) => {
        const confirmed = window.confirm(
            `Delete watchlist "${watchlist.name}" and all ${watchlist.item_count} stock(s) on it? This cannot be undone.`,
        );
        if (!confirmed) {
            return;
        }

        setDeletingId(watchlist.id);
        try {
            await api.delete(`/watchlists/${watchlist.id}`);
            await onChanged?.({ deletedId: watchlist.id });
            showToast('Watchlist deleted.');
        } catch (error) {
            showToast(validationMessage(error), 'danger');
        } finally {
            setDeletingId(null);
        }
    };

    return createPortal(
        <div className="lido-knowledge-modal-root lido-manage-watchlists-modal">
            <div
                className="lido-knowledge-modal-backdrop"
                aria-hidden="true"
                onClick={onClose}
            />
            <div
                className="modal-dialog modal-dialog-scrollable modal-lg"
                role="dialog"
                aria-modal="true"
                aria-labelledby="manage-watchlists-title"
            >
                <div className="modal-content">
                    <div className="modal-header">
                        <h2 className="modal-title h5 mb-0" id="manage-watchlists-title">
                            Manage watchlists
                        </h2>
                        <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                    </div>
                    <div className="modal-body d-grid gap-4">
                        <form onSubmit={createWatchlist} className="d-grid gap-2">
                            <label className="form-label small text-muted mb-0" htmlFor="new-watchlist-name">
                                New watchlist
                            </label>
                            <div className="input-group input-group-sm">
                                <input
                                    id="new-watchlist-name"
                                    type="text"
                                    className={`form-control ${newNameError ? 'is-invalid' : ''}`}
                                    value={newName}
                                    onChange={(event) => setNewName(event.target.value)}
                                    onBlur={() => setNewNameTouched(true)}
                                    placeholder="e.g. Breakout candidates"
                                    maxLength={80}
                                    disabled={creating}
                                />
                                <button type="submit" className="btn btn-primary" disabled={creating}>
                                    {creating ? 'Creating…' : 'Create'}
                                </button>
                            </div>
                            {newNameError ? <div className="invalid-feedback d-block">{newNameError}</div> : null}
                        </form>

                        <div>
                            <div className="small text-muted mb-2">Your watchlists</div>
                            <ul className="list-group">
                                {watchlists.map((watchlist) => {
                                    const isActive = watchlist.id === activeWatchlistId;
                                    const isRenaming = renameId === watchlist.id;

                                    return (
                                        <li
                                            key={watchlist.id}
                                            className="list-group-item d-flex flex-wrap align-items-center gap-2 justify-content-between"
                                        >
                                            {isRenaming ? (
                                                <div className="flex-grow-1 d-grid gap-2">
                                                    <input
                                                        type="text"
                                                        className={`form-control form-control-sm ${renameNameError ? 'is-invalid' : ''}`}
                                                        value={renameName}
                                                        onChange={(event) => setRenameName(event.target.value)}
                                                        onBlur={() => setRenameTouched(true)}
                                                        maxLength={80}
                                                        disabled={savingRename}
                                                    />
                                                    {renameNameError ? (
                                                        <div className="invalid-feedback d-block">{renameNameError}</div>
                                                    ) : null}
                                                    <div className="d-flex gap-2">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-primary"
                                                            onClick={() => saveRename(watchlist.id)}
                                                            disabled={savingRename}
                                                        >
                                                            {savingRename ? 'Saving…' : 'Save'}
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-secondary"
                                                            onClick={cancelRename}
                                                            disabled={savingRename}
                                                        >
                                                            Cancel
                                                        </button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <>
                                                    <div>
                                                        <strong>{watchlist.name}</strong>
                                                        {isActive ? (
                                                            <span className="badge text-bg-secondary ms-2">Active</span>
                                                        ) : null}
                                                        <div className="small text-muted">
                                                            {watchlist.item_count} stock{watchlist.item_count === 1 ? '' : 's'}
                                                        </div>
                                                    </div>
                                                    <div className="d-flex flex-wrap gap-2">
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-primary"
                                                            onClick={() => startRename(watchlist)}
                                                            disabled={deletingId === watchlist.id}
                                                        >
                                                            Rename
                                                        </button>
                                                        <button
                                                            type="button"
                                                            className="btn btn-sm btn-outline-danger"
                                                            onClick={() => deleteWatchlist(watchlist)}
                                                            disabled={watchlists.length <= 1 || deletingId === watchlist.id}
                                                            title={watchlists.length <= 1 ? 'Cannot delete your only watchlist' : undefined}
                                                        >
                                                            {deletingId === watchlist.id ? 'Deleting…' : 'Delete'}
                                                        </button>
                                                    </div>
                                                </>
                                            )}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-outline-secondary" onClick={onClose}>
                            Close
                        </button>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
