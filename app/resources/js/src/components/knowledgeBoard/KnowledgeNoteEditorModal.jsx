import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import SegmentToggle from '../SegmentToggle';
import KnowledgeEditor from './KnowledgeEditor';
import KnowledgeMarkdownEditor from './KnowledgeMarkdownEditor';
import KnowledgeSimpleEditor from './KnowledgeSimpleEditor';
import TagInput from './TagInput';
import {
    deriveNoteTitle,
    htmlToMarkdownLite,
    htmlToPlainText,
    markdownToHtml,
    plainTextToHtml,
    plainTextToJson,
} from '../../utils/knowledgeBoardPreview';
import { IconDelete } from './KnowledgeCardIcons';

const EMPTY_DOC = { type: 'doc', content: [{ type: 'paragraph' }] };

export default function KnowledgeNoteEditorModal({
    open,
    sessionKey,
    note,
    allTags,
    saving = false,
    onClose,
    onSave,
    onDelete,
    onCreateTag,
}) {
    const [tags, setTags] = useState([]);
    const [contentJson, setContentJson] = useState(EMPTY_DOC);
    const [contentHtml, setContentHtml] = useState('');
    const [plainText, setPlainText] = useState('');
    const [markdownText, setMarkdownText] = useState('');
    const [editorMode, setEditorMode] = useState('simple');
    const [dirty, setDirty] = useState(false);
    const autosaveTimer = useRef(null);
    const lastSavedSnapshot = useRef('');

    const snapshot = useCallback(() => JSON.stringify({
        tags: tags.map((tag) => tag.id).sort(),
        contentJson,
        plainText,
        markdownText,
        editorMode,
    }), [tags, contentJson, plainText, markdownText, editorMode]);

    useEffect(() => {
        if (!open) {
            return;
        }
        const initialTags = note?.tags || [];
        const initialJson = note?.content_json || EMPTY_DOC;
        const initialHtml = note?.content_html || '';
        const initialPlain = htmlToPlainText(initialHtml);
        const initialMarkdown = htmlToMarkdownLite(initialHtml);
        setTags(initialTags);
        setContentJson(initialJson);
        setContentHtml(initialHtml);
        setPlainText(initialPlain);
        setMarkdownText(initialMarkdown);
        setEditorMode('simple');
        setDirty(false);
        lastSavedSnapshot.current = JSON.stringify({
            tags: initialTags.map((tag) => tag.id).sort(),
            contentJson: initialJson,
            plainText: initialPlain,
            markdownText: initialMarkdown,
            editorMode: 'simple',
        });
    }, [open, sessionKey]);

    const buildPayload = useCallback(() => {
        let html;
        let json;
        if (editorMode === 'simple') {
            html = plainTextToHtml(plainText);
            json = plainTextToJson(plainText);
        } else if (editorMode === 'markdown') {
            html = markdownToHtml(markdownText);
            json = plainTextToJson(htmlToPlainText(html));
        } else {
            html = contentHtml;
            json = contentJson;
        }
        return {
            title: deriveNoteTitle(html) || 'Untitled note',
            content_json: json,
            content_html: html,
            tag_ids: tags.map((tag) => tag.id),
        };
    }, [editorMode, plainText, markdownText, contentHtml, contentJson, tags]);

    const hasContent = useCallback(() => {
        if (editorMode === 'simple') {
            return plainText.trim().length > 0;
        }
        if (editorMode === 'markdown') {
            return markdownText.trim().length > 0;
        }
        return htmlToPlainText(contentHtml).trim().length > 0;
    }, [editorMode, plainText, markdownText, contentHtml]);

    const save = useCallback(async (isAutosave = false) => {
        if (!hasContent()) {
            if (!isAutosave) {
                window.alert('Write something before saving.');
            }
            return false;
        }
        const ok = await onSave?.(buildPayload(), { isAutosave });
        if (ok !== false) {
            setDirty(false);
            lastSavedSnapshot.current = snapshot();
        }
        return ok !== false;
    }, [hasContent, buildPayload, onSave, snapshot]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        if (!hasContent()) {
            return undefined;
        }
        const isDirty = snapshot() !== lastSavedSnapshot.current;
        setDirty(isDirty);
        if (!isDirty) {
            return undefined;
        }
        if (autosaveTimer.current) {
            clearTimeout(autosaveTimer.current);
        }
        autosaveTimer.current = setTimeout(() => {
            save(true);
        }, 2500);
        return () => {
            if (autosaveTimer.current) {
                clearTimeout(autosaveTimer.current);
            }
        };
    }, [open, snapshot, save, hasContent, tags, contentJson, contentHtml, plainText, markdownText, editorMode]);

    const handleModeChange = (nextMode) => {
        if (nextMode === editorMode) {
            return;
        }
        if (editorMode === 'simple') {
            const html = plainTextToHtml(plainText);
            if (nextMode === 'formatted') {
                setContentHtml(html);
                setContentJson(plainTextToJson(plainText));
            } else if (nextMode === 'markdown') {
                setMarkdownText(htmlToMarkdownLite(html));
            }
        } else if (editorMode === 'formatted') {
            if (nextMode === 'simple') {
                setPlainText(htmlToPlainText(contentHtml));
            } else if (nextMode === 'markdown') {
                setMarkdownText(htmlToMarkdownLite(contentHtml));
            }
        } else if (editorMode === 'markdown') {
            const html = markdownToHtml(markdownText);
            if (nextMode === 'simple') {
                setPlainText(htmlToPlainText(html));
            } else if (nextMode === 'formatted') {
                setContentHtml(html);
                setContentJson(plainTextToJson(htmlToPlainText(html)));
            }
        }
        setEditorMode(nextMode);
    };

    const handleClose = useCallback(() => {
        if (dirty && !window.confirm('You have unsaved changes. Close anyway?')) {
            return;
        }
        onClose?.();
    }, [dirty, onClose]);

    useEffect(() => {
        if (!open) {
            return undefined;
        }
        const onKeyDown = (event) => {
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
                event.preventDefault();
                save(false);
            }
            if (event.key === 'Escape') {
                event.preventDefault();
                handleClose();
            }
        };
        window.addEventListener('keydown', onKeyDown);
        return () => window.removeEventListener('keydown', onKeyDown);
    }, [open, save, handleClose]);

    const saveStatusClass = [
        'small lido-knowledge-editor-save-status',
        saving ? 'is-saving' : dirty ? 'is-unsaved' : 'is-saved',
    ].join(' ');

    if (!open) {
        return null;
    }

    return createPortal(
        <div className="lido-knowledge-modal-root lido-knowledge-editor-modal">
            <div
                className="lido-knowledge-modal-backdrop"
                aria-hidden="true"
                onClick={handleClose}
            />
            <div
                className="modal-dialog modal-xl modal-dialog-scrollable"
                role="dialog"
                aria-modal="true"
                aria-labelledby="kb-editor-title"
            >
                <div className="modal-content">
                    <div className="modal-header py-2">
                        <h2 className="modal-title h6 mb-0" id="kb-editor-title">{note?.id ? 'Edit note' : 'New note'}</h2>
                        <button type="button" className="btn-close" aria-label="Close editor" onClick={handleClose} />
                    </div>
                    <div className="modal-body d-grid gap-2">
                        {editorMode === 'simple' ? (
                            <KnowledgeSimpleEditor
                                key={`${sessionKey}-simple`}
                                value={plainText}
                                onChange={setPlainText}
                            />
                        ) : editorMode === 'markdown' ? (
                            <KnowledgeMarkdownEditor
                                key={`${sessionKey}-markdown`}
                                value={markdownText}
                                onChange={setMarkdownText}
                            />
                        ) : (
                            <KnowledgeEditor
                                key={`${sessionKey}-formatted`}
                                initialJson={contentJson}
                                initialHtml={contentHtml}
                                showToolbar
                                onChange={({ content_json, content_html }) => {
                                    setContentJson(content_json);
                                    setContentHtml(content_html);
                                }}
                            />
                        )}
                    </div>
                    <div className="modal-footer py-2 lido-knowledge-editor-footer">
                        <div className="lido-knowledge-editor-footer-group">
                            <SegmentToggle
                                compact
                                ariaLabel="Editor mode"
                                value={editorMode}
                                onChange={handleModeChange}
                                options={[
                                    { value: 'simple', label: 'Simple' },
                                    { value: 'formatted', label: 'Formatted' },
                                    { value: 'markdown', label: 'Markdown' },
                                ]}
                            />
                            <span className={saveStatusClass}>
                                {saving ? 'Saving…' : dirty ? 'Unsaved' : 'Saved'}
                            </span>
                        </div>
                        <div className="lido-knowledge-editor-footer-sep" aria-hidden="true" />
                        <div className="lido-knowledge-editor-footer-tags">
                            <TagInput
                                tags={tags}
                                allTags={allTags}
                                onChange={setTags}
                                onCreateTag={onCreateTag}
                                footer
                            />
                        </div>
                        <div className="lido-knowledge-editor-footer-sep" aria-hidden="true" />
                        <div className="lido-knowledge-editor-footer-group lido-knowledge-editor-footer-group--actions">
                            {note?.id && onDelete ? (
                                <button
                                    type="button"
                                    className="btn btn-sm btn-outline-danger lido-knowledge-editor-footer-icon-btn"
                                    aria-label="Delete note"
                                    title="Delete note"
                                    onClick={() => onDelete(note)}
                                    disabled={saving}
                                >
                                    <IconDelete />
                                </button>
                            ) : null}
                            <button type="button" className="btn btn-sm btn-outline-secondary" onClick={handleClose}>Close</button>
                            <button type="button" className="btn btn-sm btn-primary" onClick={() => save(false)} disabled={saving}>Save (Ctrl/Cmd + S)</button>
                        </div>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
