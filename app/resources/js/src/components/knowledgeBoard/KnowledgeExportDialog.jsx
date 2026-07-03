import React, { useMemo, useState } from 'react';
import { createPortal } from 'react-dom';
import SegmentToggle from '../SegmentToggle';
import {
    buildKnowledgeExport,
    loadExportFormatPreference,
    saveExportFormatPreference,
} from '../../utils/knowledgeBoardExport';
import { showToast } from '../../toast';

const FORMAT_OPTIONS = [
    { value: 'text', label: 'Plain Text' },
    { value: 'markdown', label: 'Markdown' },
    { value: 'ai', label: 'AI Friendly' },
];

export default function KnowledgeExportDialog({ notes, onClose }) {
    const [format, setFormat] = useState(() => loadExportFormatPreference());

    const preview = useMemo(
        () => buildKnowledgeExport(notes, { format }),
        [notes, format],
    );

    const handleFormatChange = (next) => {
        setFormat(next);
        saveExportFormatPreference(next);
    };

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
                        <SegmentToggle
                            label="Format"
                            value={format}
                            onChange={handleFormatChange}
                            options={FORMAT_OPTIONS}
                            ariaLabel="Export format"
                            compact
                            className="mb-3"
                        />
                        <label className="form-label small" htmlFor="kb-export-preview">Preview</label>
                        <textarea
                            id="kb-export-preview"
                            className="form-control font-monospace small lido-knowledge-export-preview"
                            rows={1}
                            readOnly
                            value={preview}
                        />
                    </div>
                    <div className="modal-footer lido-knowledge-export-modal-footer">
                        <button type="button" className="btn btn-outline-secondary" onClick={onClose}>Close</button>
                        <button type="button" className="btn btn-primary" onClick={copyToClipboard}>Copy to Clipboard</button>
                    </div>
                </div>
            </div>
        </div>,
        document.body,
    );
}
