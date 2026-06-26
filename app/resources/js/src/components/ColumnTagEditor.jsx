import React, { useMemo } from 'react';

const TAG_PATTERN = /\{\{\s*([a-z0-9_]+)\s*\}\}/gi;

/**
 * @returns {Array<{ key: string, index: number, length: number }>}
 */
export function extractColumnTagMatches(text) {
    if (!text) {
        return [];
    }
    const matches = [];
    TAG_PATTERN.lastIndex = 0;
    let match = TAG_PATTERN.exec(text);
    while (match) {
        matches.push({
            key: match[1],
            index: match.index,
            length: match[0].length,
        });
        match = TAG_PATTERN.exec(text);
    }
    TAG_PATTERN.lastIndex = 0;
    return matches;
}

export function extractColumnTags(text) {
    return extractColumnTagMatches(text).map((item) => item.key);
}

export function removeColumnTagAt(text, occurrenceIndex) {
    const matches = extractColumnTagMatches(text);
    const target = matches[occurrenceIndex];
    if (!target) {
        return text;
    }
    const before = text.slice(0, target.index);
    const after = text.slice(target.index + target.length);
    return (before + after).replace(/\s{2,}/g, ' ').trim();
}

export default function ColumnTagEditor({
    id,
    label,
    value,
    onChange,
    columns = [],
    showColumnPicker = true,
    multiline = false,
    rows = 3,
    placeholder = '',
    disabled = false,
    invalid = false,
    errorMessage = '',
    hint = null,
}) {
    const columnMap = useMemo(() => {
        const map = new Map();
        columns.forEach((col) => map.set(col.key, col.label));
        return map;
    }, [columns]);

    const tagMatches = useMemo(() => extractColumnTagMatches(value), [value]);

    const addColumn = (columnKey) => {
        if (!columnKey) {
            return;
        }
        const token = `{{${columnKey}}}`;
        const next = value?.trim() ? `${value.trim()} ${token}` : token;
        onChange(next);
    };

    const InputComponent = multiline ? 'textarea' : 'input';

    return (
        <div>
            {label && <label className="form-label" htmlFor={id}>{label}</label>}
            {tagMatches.length > 0 && (
                <div className="d-flex flex-wrap gap-1 mb-2">
                    {tagMatches.map((tag, index) => (
                        <span
                            key={`${tag.key}-${tag.index}-${index}`}
                            className="badge text-bg-secondary d-inline-flex align-items-center gap-1"
                        >
                            {columnMap.get(tag.key) || tag.key}
                            {!disabled && (
                                <button
                                    type="button"
                                    className="btn-close btn-close-white"
                                    style={{ fontSize: '0.55rem' }}
                                    aria-label={`Remove ${tag.key}`}
                                    onClick={() => onChange(removeColumnTagAt(value, index))}
                                />
                            )}
                        </span>
                    ))}
                </div>
            )}
            <InputComponent
                id={id}
                className={`form-control form-control-sm${invalid ? ' is-invalid' : ''}`}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                rows={multiline ? rows : undefined}
                placeholder={placeholder}
                disabled={disabled}
            />
            {invalid && errorMessage && (
                <div className="invalid-feedback d-block">{errorMessage}</div>
            )}
            {hint && !invalid && (
                <div className="form-text small mb-0">{hint}</div>
            )}
            {showColumnPicker && columns.length > 0 && (
                <div className="d-flex flex-wrap gap-2 align-items-center mt-2">
                    <select
                        className="form-select form-select-sm column-tag-picker"
                        style={{ maxWidth: 220 }}
                        defaultValue=""
                        disabled={disabled}
                        onChange={(e) => {
                            addColumn(e.target.value);
                            e.target.value = '';
                        }}
                    >
                        <option value="">Add column…</option>
                        {columns.map((col) => (
                            <option key={col.key} value={col.key}>{col.label}</option>
                        ))}
                    </select>
                </div>
            )}
        </div>
    );
}

export function ContextColumnList({ columns, selected, onChange, disabled = false }) {
    const columnMap = useMemo(() => {
        const map = new Map();
        columns.forEach((col) => map.set(col.key, col.label));
        return map;
    }, [columns]);

    const addColumn = (columnKey) => {
        if (!columnKey) {
            return;
        }
        onChange([...selected, columnKey]);
    };

    return (
        <div>
            <label className="form-label">Context information</label>
            <p className="text-muted small mb-2">
                Columns included as key-value context when an alert is generated.
            </p>
            {selected.length > 0 && (
                <div className="d-flex flex-wrap gap-1 mb-2">
                    {selected.map((key, index) => (
                        <span
                            key={`${key}-${index}`}
                            className="badge text-bg-info d-inline-flex align-items-center gap-1"
                        >
                            {columnMap.get(key) || key}
                            {!disabled && (
                                <button
                                    type="button"
                                    className="btn-close"
                                    style={{ fontSize: '0.55rem' }}
                                    aria-label={`Remove ${key}`}
                                    onClick={() => onChange(selected.filter((_, i) => i !== index))}
                                />
                            )}
                        </span>
                    ))}
                </div>
            )}
            <div className="d-flex flex-wrap gap-2">
                <select
                    className="form-select form-select-sm column-tag-picker"
                    style={{ maxWidth: 220 }}
                    defaultValue=""
                    disabled={disabled}
                    onChange={(e) => {
                        addColumn(e.target.value);
                        e.target.value = '';
                    }}
                >
                    <option value="">Select column…</option>
                    {columns.map((col) => (
                        <option key={col.key} value={col.key}>{col.label}</option>
                    ))}
                </select>
            </div>
        </div>
    );
}
