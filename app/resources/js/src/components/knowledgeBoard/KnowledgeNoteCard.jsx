import React, { useMemo } from 'react';
import { useSortable } from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { formatTransactionDateDisplay } from '../../utils/transactionDate';
import { noteCardText } from '../../utils/knowledgeBoardPreview';
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
    dragHandleProps = null,
    cardRef = null,
    cardStyle = undefined,
    onToggleSelect,
    onEdit,
    onTogglePin,
    onDuplicate,
    onArchive,
    onDelete,
}) {
    const createdLabel = formatTransactionDateDisplay(note.created_at) || '—';
    const updatedLabel = formatTransactionDateDisplay(note.updated_at) || '—';
    const dateTooltip = `Created: ${createdLabel}\nUpdated: ${updatedLabel}`;
    const bodyText = useMemo(() => noteCardText(note.content_html), [note.content_html]);

    const runAction = (event, action) => {
        event.stopPropagation();
        action?.(note);
    };

    return (
        <article
            ref={cardRef}
            style={cardStyle}
            className={[
                'card lido-knowledge-card',
                dragHandleProps ? 'lido-knowledge-card--draggable' : '',
                selected ? 'lido-knowledge-card--selected' : '',
            ].filter(Boolean).join(' ')}
        >
            <div className="card-body lido-knowledge-card-body p-2 p-md-3">
                <div className="lido-knowledge-card-preview">
                    {bodyText || 'Empty note'}
                </div>

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

                        {note.tags?.length ? (
                            <div className="lido-knowledge-card-tags">
                                {note.tags.map((tag) => (
                                    <span key={tag.id} className="lido-knowledge-card-tag">
                                        {tag.name}
                                    </span>
                                ))}
                            </div>
                        ) : null}
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
