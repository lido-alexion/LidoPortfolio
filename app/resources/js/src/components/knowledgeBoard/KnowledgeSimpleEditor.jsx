import React from 'react';

export default function KnowledgeSimpleEditor({
    value = '',
    onChange,
    placeholder = 'Write your market notes, research, and ideas…',
}) {
    return (
        <textarea
            className="form-control form-control-sm lido-knowledge-simple-editor"
            rows={10}
            value={value}
            placeholder={placeholder}
            onChange={(e) => onChange?.(e.target.value)}
            aria-label="Note content"
        />
    );
}
