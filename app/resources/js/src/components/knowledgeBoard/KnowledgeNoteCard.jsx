import React, { useMemo } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { formatTransactionDateDisplay } from '../../utils/transactionDate';
import { enhanceImageHtml } from '../../utils/knowledgeImageUpload';
import { knowledgeNotePaletteStyle } from '../../utils/knowledgeNotePalettes';
import KnowledgeNotePalettePicker from './KnowledgeNotePalettePicker';
import {
    IconArchive,
    IconClock,
    IconDelete,
    IconDrag,
    IconDuplicate,
    IconEdit,
    IconMenu,
    IconPin,
} from './KnowledgeCardIcons';

function IconButton({ label, onClick, className = '', children }) {
    return (
        <button
            type="button"
            className={['lido-knowledge-card-icon-btn', className].filter(Boolean).join(' ')}
            onClick={onClick}
            aria-label={label}
            title={label}
        >
            {children}
        </button>
    );
}

function KnowledgeNoteCardBody({
    note,
    selected = false,
    showControls = true,
    dragHandleProps = null,
    cardRef = null,
    cardStyle = undefined,
    onToggleSelect,
    onEdit,
    onTogglePin,
    onDuplicate,
    onArchive,
    onDelete,
    onChangePalette,
}) {
    const createdLabel = formatTransactionDateDisplay(note.created_at) || '—';
    const updatedLabel = formatTransactionDateDisplay(note.updated_at) || '—';
    const dateTooltip = `Created: ${createdLabel}\nUpdated: ${updatedLabel}`;
    const bodyHtml = useMemo(
        () => enhanceImageHtml(note.content_html || ''),
        [note.content_html],
    );
    const paletteStyle = knowledgeNotePaletteStyle(note.color_palette);
    const mergedStyle = {
        ...paletteStyle,
        ...cardStyle,
    };

    const runAction = (event, action) => {
        event.stopPropagation();
        action?.(note);
    };

    const tags = note.tags?.length ? (
        <div className="lido-knowledge-card-tags">
            {note.tags.map((tag) => (
                <span key={tag.id} className="lido-knowledge-card-tag">
                    {tag.name}
                </span>
            ))}
        </div>
    ) : null;

    return (
        <article
            ref={cardRef}
            style={mergedStyle}
            className={[
                'card lido-knowledge-card',
                paletteStyle ? 'lido-knowledge-card--palette' : '',
                showControls && dragHandleProps ? 'lido-knowledge-card--draggable' : '',
                showControls && selected ? 'lido-knowledge-card--selected' : '',
                showControls ? 'lido-knowledge-card--manage' : 'lido-knowledge-card--read',
            ].filter(Boolean).join(' ')}
        >
            <div className="card-body lido-knowledge-card-body p-2 p-md-3">
                {!showControls && tags ? (
                    <div className="lido-knowledge-card-tags-row mb-2">
                        {tags}
                    </div>
                ) : null}

                <div
                    className="lido-knowledge-card-preview"
                    dangerouslySetInnerHTML={{
                        __html: bodyHtml || '<span class="text-muted">Empty note</span>',
                    }}
                />

                {showControls ? (
                    <div
                        className="lido-knowledge-card-palette-row mt-2"
                        onClick={(event) => event.stopPropagation()}
                        onPointerDown={(event) => event.stopPropagation()}
                    >
                        <KnowledgeNotePalettePicker
                            compact
                            value={note.color_palette || 'default'}
                            onChange={(paletteId) => onChangePalette?.(note, paletteId)}
                            ariaLabel={`Color palette for note ${note.id}`}
                        />
                    </div>
                ) : null}

                {showControls ? (
                    <div className="lido-knowledge-card-overlay">
                        <div className="lido-knowledge-card-header-main">
                            {dragHandleProps ? (
                                <button
                                    type="button"
                                    className="lido-knowledge-card-drag-handle"
                                    aria-label="Drag to reorder"
                                    title="Drag to reorder"
                                    onClick={(e) => e.stopPropagation()}
                                    {...dragHandleProps}
                                >
                                    <IconDrag />
                                </button>
                            ) : null}
                            <div className="form-check lido-knowledge-card-checkbox">
                                <input
                                    className="form-check-input"
                                    type="checkbox"
                                    checked={selected}
                                    onChange={() => onToggleSelect?.(note.id)}
                                    aria-label="Select note"
                                    onClick={(e) => e.stopPropagation()}
                                />
                            </div>

                            {tags}
                        </div>

                        <div className="lido-knowledge-card-toolbar d-none d-md-flex">
                            <span
                                className="lido-knowledge-card-clock"
                                title={dateTooltip}
                                aria-label={dateTooltip}
                                role="img"
                            >
                                <IconClock />
                            </span>
                            <IconButton
                                label={note.is_pinned ? 'Unpin note' : 'Pin note'}
                                className={note.is_pinned ? 'lido-knowledge-card-icon-btn--active' : ''}
                                onClick={(e) => runAction(e, onTogglePin)}
                            >
                                <IconPin filled={note.is_pinned} />
                            </IconButton>
                            <IconButton label="Edit note" onClick={(e) => runAction(e, onEdit)}>
                                <IconEdit />
                            </IconButton>
                            <IconButton label="Duplicate note" onClick={(e) => runAction(e, onDuplicate)}>
                                <IconDuplicate />
                            </IconButton>
                            <IconButton label="Archive note" onClick={(e) => runAction(e, onArchive)}>
                                <IconArchive />
                            </IconButton>
                            <IconButton
                                label="Delete note"
                                className="lido-knowledge-card-icon-btn--danger"
                                onClick={(e) => runAction(e, onDelete)}
                            >
                                <IconDelete />
                            </IconButton>
                        </div>

                        <div className="dropdown d-md-none lido-knowledge-card-mobile-menu">
                            <button
                                type="button"
                                className="lido-knowledge-card-icon-btn lido-knowledge-card-icon-btn--menu"
                                data-bs-toggle="dropdown"
                                aria-label="Note actions"
                                onClick={(e) => e.stopPropagation()}
                            >
                                <IconMenu />
                            </button>
                            <ul className="dropdown-menu dropdown-menu-end shadow-sm">
                                <li><span className="dropdown-item-text small text-muted">Created {createdLabel}</span></li>
                                <li><span className="dropdown-item-text small text-muted">Updated {updatedLabel}</span></li>
                                <li><hr className="dropdown-divider" /></li>
                                <li>
                                    <button type="button" className="dropdown-item" onClick={(e) => runAction(e, onEdit)}>Edit</button>
                                </li>
                                <li>
                                    <button type="button" className="dropdown-item" onClick={(e) => runAction(e, onDuplicate)}>Duplicate</button>
                                </li>
                                <li>
                                    <button type="button" className="dropdown-item" onClick={(e) => runAction(e, onArchive)}>Archive</button>
                                </li>
                                <li>
                                    <button type="button" className="dropdown-item text-danger" onClick={(e) => runAction(e, onDelete)}>Delete</button>
                                </li>
                                <li><hr className="dropdown-divider" /></li>
                                <li>
                                    <button type="button" className="dropdown-item" onClick={(e) => runAction(e, onTogglePin)}>
                                        {note.is_pinned ? 'Unpin' : 'Pin'}
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </div>
                ) : null}
            </div>
        </article>
    );
}

function SortableKnowledgeNoteCard(props) {
    const {
        attributes,
        listeners,
        setNodeRef,
        transform,
        transition,
        isDragging,
    } = useSortable({ id: String(props.note.id) });

    return (
        <KnowledgeNoteCardBody
            {...props}
            cardRef={setNodeRef}
            cardStyle={{
                transform: CSS.Transform.toString(transform),
                transition,
                opacity: isDragging ? 0.85 : 1,
            }}
            dragHandleProps={{ ...attributes, ...listeners }}
        />
    );
}

export default function KnowledgeNoteCard({ draggable = false, ...props }) {
    if (draggable) {
        return <SortableKnowledgeNoteCard {...props} />;
    }
    return <KnowledgeNoteCardBody {...props} />;
}
