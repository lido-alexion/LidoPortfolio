import React, { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import { buildKnowledgeExport } from '../../utils/knowledgeBoardExport';
import { showToast } from '../../toast';

const FORMAT_OPTIONS = [
    { value: 'text', label: 'Plain Text' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'ai', label: 'AI Friendly' },
];

export default function KnowledgeExportDialog({ notes, onClose }) {
    const [format, setFormat] = useState('text');
    const [includeTitle, setIncludeTitle] = useState(true);
    const [includeTags, setIncludeTags] = useState(true);
    const [includeCreated, setIncludeCreated] = useState(true);
    const [includeUpdated, setIncludeUpdated] = useState(true);
    const [includeDivider, setIncludeDivider] = useState(true);

    const preview = useMemo(() => buildKnowledgeExport(notes, {
        format,
        includeTitle,
        includeTags,
        includeCreated,
        includeUpdated,
        includeDivider,
    }), [notes, format, includeTitle, includeTags, includeCreated, includeUpdated, includeDivider]);

    const copyToClipboard = async () => {
        try {
            await navigator.clipboard.writeText(preview);
            showToast('Copied to clipboard.');
        } catch {
            showToast('Could not copy to clipboard.', 'danger');
        }
    };

    return createPortal(
        <div className="lido-knowledge-modal-root lido-knowledge-export-modal">
            <div
                className="lido-knowledge-modal-backdrop"
                aria-hidden="true"
                onClick={onClose}
            />
            <div
                className="modal-dialog modal-lg modal-dialog-scrollable"
                role="dialog"
                aria-modal="true"
                aria-labelledby="kb-export-title"
            >
                <div className="modal-content">
                    <div className="modal-header">
                        <h2 className="modal-title h5" id="kb-export-title">Export {notes.length} note{notes.length === 1 ? '' : 's'}</h2>
                        <button type="button" className="btn-close" aria-label="Close" onClick={onClose} />
                    </div>
                    <div className="modal-body">
                        <div className="row g-3 mb-3">
                            <div className="col-md-4">
                                <label className="form-label small" htmlFor="kb-export-format">Format</label>
                                <select
                                    id="kb-export-format"
                                    className="form-select form-select-sm"
                                    value={format}
                                    onChange={(e) => setFormat(e.target.value)}
                                >
                                    {FORMAT_OPTIONS.map((option) => (
                                        <option key={option.value} value={option.value}>{option.label}</option>
                                    ))}
                                </select>
                            </div>
                            <div className="col-md-8">
                                <div className="small fw-semibold mb-1">Include</div>
                                <div className="d-flex flex-wrap gap-3">
                                    <label className="form-check small">
                                        <input className="form-check-input" type="checkbox" checked={includeTitle} onChange={(e) => setIncludeTitle(e.target.checked)} />
                                        Title
                                    </label>
                                    <label className="form-check small">
                                        <input className="form-check-input" type="checkbox" checked={includeTags} onChange={(e) => setIncludeTags(e.target.checked)} />
                                        Tags
                                    </label>
                                    <label className="form-check small">
                                        <input className="form-check-input" type="checkbox" checked={includeCreated} onChange={(e) => setIncludeCreated(e.target.checked)} />
                                        Created Date
                                    </label>
                                    <label className="form-check small">
                                        <input className="form-check-input" type="checkbox" checked={includeUpdated} onChange={(e) => setIncludeUpdated(e.target.checked)} />
                                        Updated Date
                                    </label>
                                    <label className="form-check small">
                                        <input className="form-check-input" type="checkbox" checked={includeDivider} onChange={(e) => setIncludeDivider(e.target.checked)} />
                                        Divider
                                    </label>
                                </div>
                            </div>
                        </div>
                        <label className="form-label small" htmlFor="kb-export-preview">Preview</label>
                        <textarea
                            id="kb-export-preview"
                            className="form-control font-monospace small"
                            rows={14}
                            readOnly
                            value={preview}
                        />
                    </div>
                    <div className="modal-footer">
                        <button type="button" className="btn btn-outline-secondary" onClick={onClose}>Close</button>
                        <button type="button" className="btn btn-primary" onClick={copyToClipboard}>Copy to Clipboard</button>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
