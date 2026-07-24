import React, { useId } from 'react';
import {
    KNOWLEDGE_NOTE_PALETTES,
    getKnowledgeNotePalette,
} from '../../utils/knowledgeNotePalettes';

/**
 * Compact swatch strip to pick a note color palette.
 */
export default function KnowledgeNotePalettePicker({
    value = 'default',
    onChange,
    disabled = false,
    compact = false,
    ariaLabel = 'Note color palette',
}) {
    const groupId = useId();
    const current = getKnowledgeNotePalette(value);

    return (
        <div
            className={[
                'lido-knowledge-palette-picker',
                compact ? 'lido-knowledge-palette-picker--compact' : '',
            ].filter(Boolean).join(' ')}
            role="radiogroup"
            aria-label={ariaLabel}
        >
            {!compact ? (
                <span className="lido-knowledge-palette-picker-label small text-muted">
                    Color
                    <span className="ms-1">{current.label}</span>
                </span>
            ) : null}
            <div className="lido-knowledge-palette-swatches">
                {KNOWLEDGE_NOTE_PALETTES.map((palette) => {
                    const selected = palette.id === current.id;
                    const isDefault = palette.id === 'default';
                    return (
                        <button
                            key={palette.id}
                            type="button"
                            id={`${groupId}-${palette.id}`}
                            role="radio"
                            aria-checked={selected}
                            aria-label={palette.label}
                            title={palette.label}
                            disabled={disabled}
                            className={[
                                'lido-knowledge-palette-swatch',
                                isDefault ? 'lido-knowledge-palette-swatch--default' : '',
                                selected ? 'is-selected' : '',
                            ].filter(Boolean).join(' ')}
                            style={isDefault ? undefined : {
                                backgroundColor: palette.background,
                                color: palette.text,
                            }}
                            onClick={(event) => {
                                event.stopPropagation();
                                if (!disabled && palette.id !== current.id) {
                                    onChange?.(palette.id);
                                }
                            }}
                        >
                            {isDefault ? 'A' : 'Aa'}
                        </button>
                    );
                })}
            </div>
        </div>
    );
}
