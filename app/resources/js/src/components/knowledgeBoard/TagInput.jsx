import React, { useMemo, useState } from 'react';

export default function TagInput({
    tags = [],
    allTags = [],
    onChange,
    onCreateTag,
    disabled = false,
    inline = false,
    footer = false,
}) {
    const [query, setQuery] = useState('');

    const selectedIds = useMemo(() => new Set(tags.map((tag) => String(tag.id))), [tags]);

    const suggestions = useMemo(() => {
        const q = query.trim().toLowerCase();
        return allTags
            .filter((tag) => !selectedIds.has(String(tag.id)))
            .filter((tag) => !q || tag.name.toLowerCase().includes(q))
            .slice(0, 8);
    }, [allTags, query, selectedIds]);

    const addTag = (tag) => {
        if (selectedIds.has(String(tag.id))) {
            return;
        }
        onChange?.([...tags, tag]);
        setQuery('');
    };

    const removeTag = (tagId) => {
        onChange?.(tags.filter((tag) => String(tag.id) !== String(tagId)));
    };

    const handleKeyDown = async (event) => {
        if (event.key !== 'Enter' || !query.trim()) {
            return;
        }
        event.preventDefault();
        const existing = allTags.find((tag) => tag.name.toLowerCase() === query.trim().toLowerCase());
        if (existing) {
            addTag(existing);
            return;
        }
        if (onCreateTag) {
            const created = await onCreateTag(query.trim());
            if (created) {
                addTag(created);
            }
        }
    };

    const tagBadges = tags.map((tag) => (
        <span key={tag.id} className="badge lido-knowledge-editor-tag">
            {tag.name}
            <button
                type="button"
                className="btn-close btn-close-white btn-sm ms-1"
                aria-label={`Remove tag ${tag.name}`}
                onClick={() => removeTag(tag.id)}
                disabled={disabled}
            />
        </span>
    ));

    const input = (
        <div className="position-relative lido-tag-input-field-wrap">
            <input
                type="text"
                className="form-control form-control-sm"
                placeholder="Add tag…"
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={handleKeyDown}
                disabled={disabled}
                aria-label="Tag search"
            />
            {query && suggestions.length > 0 ? (
                <ul className="list-group lido-tag-suggestions shadow-sm">
                    {suggestions.map((tag) => (
                        <li key={tag.id}>
                            <button
                                type="button"
                                className="list-group-item list-group-item-action py-1 small"
                                onClick={() => addTag(tag)}
                            >
                                <span
                                    className="d-inline-block rounded-circle me-2"
                                    style={{ width: 10, height: 10, backgroundColor: tag.color }}
                                />
                                {tag.name}
                            </button>
                        </li>
                    ))}
                </ul>
            ) : null}
        </div>
    );

    if (footer) {
        return (
            <div className="lido-knowledge-tag-row lido-knowledge-tag-row--footer">
                <div className="lido-knowledge-tag-row-content">
                    {tagBadges}
                    {input}
                </div>
            </div>
        );
    }

    if (inline) {
        return (
            <div className="lido-knowledge-tag-row">
                <span className="lido-knowledge-tag-row-label">Tags</span>
                <div className="lido-knowledge-tag-row-content">
                    {tagBadges}
                    {input}
                </div>
            </div>
        );
    }

    return (
        <div className="lido-tag-input">
            <div className="d-flex flex-wrap gap-1 mb-2">{tagBadges}</div>
            {input}
        </div>
    );
}
