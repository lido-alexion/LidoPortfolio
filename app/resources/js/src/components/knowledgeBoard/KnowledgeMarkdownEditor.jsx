import React, { useCallback, useMemo, useRef, useState } from 'react';
import SegmentToggle from '../SegmentToggle';
import { markdownToHtml } from '../../utils/knowledgeBoardPreview';
import { enhanceImageHtml, KNOWLEDGE_IMAGE_ACCEPT, uploadKnowledgeImage } from '../../utils/knowledgeImageUpload';
import { showToast } from '../../toast';

export default function KnowledgeMarkdownEditor({
    value = '',
    onChange,
    placeholder = 'Write your market notes in Markdown…',
}) {
    const [view, setView] = useState('edit');
    const [uploading, setUploading] = useState(false);
    const fileInputRef = useRef(null);
    const textareaRef = useRef(null);

    const previewHtml = useMemo(
        () => enhanceImageHtml(markdownToHtml(value)),
        [value],
    );

    const insertAtCursor = useCallback((snippet) => {
        const textarea = textareaRef.current;
        const current = value || '';
        if (!textarea) {
            onChange?.(`${current}${current && !current.endsWith('\n') ? '\n\n' : ''}${snippet}\n\n`);
            return;
        }
        const start = textarea.selectionStart ?? current.length;
        const end = textarea.selectionEnd ?? current.length;
        const before = current.slice(0, start);
        const after = current.slice(end);
        const needsLead = before.length > 0 && !before.endsWith('\n\n') && !before.endsWith('\n');
        const lead = needsLead ? '\n\n' : (before.endsWith('\n') && !before.endsWith('\n\n') ? '\n' : '');
        const next = `${before}${lead}${snippet}\n\n${after}`;
        onChange?.(next);
        requestAnimationFrame(() => {
            const pos = (before + lead + snippet + '\n\n').length;
            textarea.focus();
            textarea.setSelectionRange(pos, pos);
        });
    }, [value, onChange]);

    const handleInsertImage = useCallback(async (file) => {
        if (!file) {
            return;
        }
        setUploading(true);
        try {
            const uploaded = await uploadKnowledgeImage(file);
            const alt = (uploaded.original_name || 'image').replace(/[[\]]/g, '');
            insertAtCursor(
                `![${alt}](${uploaded.display_url} "${uploaded.full_url}")`,
            );
        } catch (err) {
            showToast(err?.response?.data?.message || err?.message || 'Image upload failed.', 'danger');
        } finally {
            setUploading(false);
            if (fileInputRef.current) {
                fileInputRef.current.value = '';
            }
        }
    }, [insertAtCursor]);

    return (
        <div className="lido-knowledge-markdown-editor">
            <div className="lido-knowledge-markdown-editor-bar d-flex flex-wrap align-items-center gap-2">
                <SegmentToggle
                    compact
                    ariaLabel="Markdown view"
                    value={view}
                    onChange={setView}
                    options={[
                        { value: 'edit', label: 'Edit' },
                        { value: 'preview', label: 'Preview' },
                    ]}
                />
                {view === 'edit' ? (
                    <>
                        <button
                            type="button"
                            className="btn btn-sm btn-outline-secondary"
                            disabled={uploading}
                            onClick={() => fileInputRef.current?.click()}
                        >
                            {uploading ? 'Uploading…' : 'Insert image'}
                        </button>
                        <input
                            ref={fileInputRef}
                            type="file"
                            accept={KNOWLEDGE_IMAGE_ACCEPT}
                            className="d-none"
                            aria-hidden="true"
                            tabIndex={-1}
                            onChange={(event) => handleInsertImage(event.target.files?.[0])}
                        />
                    </>
                ) : null}
            </div>
            {view === 'edit' ? (
                <textarea
                    ref={textareaRef}
                    className="form-control form-control-sm lido-knowledge-markdown-editor-input"
                    rows={10}
                    value={value}
                    placeholder={placeholder}
                    onChange={(e) => onChange?.(e.target.value)}
                    aria-label="Note content (Markdown)"
                />
            ) : (
                <div
                    className="lido-knowledge-markdown-preview"
                    dangerouslySetInnerHTML={{ __html: previewHtml || '<p class="lido-knowledge-markdown-preview-empty">Nothing to preview yet.</p>' }}
                />
            )}
        </div>
    );
}
