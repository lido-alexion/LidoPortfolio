import React, { useMemo, useState } from 'react';
import SegmentToggle from '../SegmentToggle';
import { markdownToHtml } from '../../utils/knowledgeBoardPreview';

export default function KnowledgeMarkdownEditor({
    value = '',
    onChange,
    placeholder = 'Write your market notes in Markdown…',
}) {
    const [view, setView] = useState('edit');

    const previewHtml = useMemo(() => markdownToHtml(value), [value]);

    return (
        <div className="lido-knowledge-markdown-editor">
            <div className="lido-knowledge-markdown-editor-bar">
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
            </div>
            {view === 'edit' ? (
                <textarea
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
