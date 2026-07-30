import React, { useRef, useState } from 'react';
import { MAX_NAV_FAVOURITES } from '../../navigation/constants';
import { showToast } from '../../toast';
import NavMenuItem from './NavMenuItem';
import { GripVertical, Star } from './NavIcon';

function FavouriteLink({
    item,
    collapsed,
    onNavigate,
    onUnpin,
    draggable,
    onDragStart,
    onDragOver,
    onDrop,
    onDragEnd,
    isDragging,
    activePageId,
}) {
    return (
        <div
            className={[
                'lido-sidebar-fav-row',
                isDragging ? 'is-dragging' : '',
            ].filter(Boolean).join(' ')}
            draggable={draggable}
            onDragStart={onDragStart}
            onDragOver={onDragOver}
            onDrop={onDrop}
            onDragEnd={onDragEnd}
        >
            {!collapsed && (
                <button
                    type="button"
                    className="lido-sidebar-fav-grip"
                    aria-label={`Reorder ${item.title}`}
                    title="Drag to reorder"
                    tabIndex={-1}
                >
                    <GripVertical size={14} strokeWidth={1.75} aria-hidden="true" />
                </button>
            )}
            <NavMenuItem
                title={item.title}
                icon={item.icon}
                route={item.route}
                active={activePageId === item.id}
                collapsed={collapsed}
                variant="favourite"
                onClick={onNavigate}
                showActiveBar
            />
            {!collapsed && (
                <button
                    type="button"
                    className="lido-sidebar-fav-unpin"
                    aria-label={`Unpin ${item.title}`}
                    title="Unpin"
                    onClick={() => onUnpin(item.id)}
                >
                    <Star size={14} strokeWidth={1.75} fill="currentColor" aria-hidden="true" />
                </button>
            )}
        </div>
    );
}

export default function SidebarFavourites({
    favourites,
    collapsed,
    onNavigate,
    onUnpin,
    onReorder,
    max,
    activePageId,
}) {
    const dragIndex = useRef(null);
    const [draggingIndex, setDraggingIndex] = useState(null);

    if (!favourites.length && collapsed) {
        return null;
    }

    return (
        <div className="lido-sidebar-block lido-sidebar-block--favourites">
            {!collapsed && (
                <div className="lido-sidebar-block-header">
                    <span className="lido-sidebar-block-title">Favourites</span>
                    <span className="lido-sidebar-block-meta">
                        {favourites.length}/{max}
                    </span>
                </div>
            )}
            {collapsed && favourites.length > 0 && (
                <div className="lido-sidebar-section-divider" aria-hidden="true" />
            )}
            {favourites.length === 0 ? (
                <p className="lido-sidebar-block-empty">
                    Pin up to {max} pages with the star on any eligible link.
                </p>
            ) : (
                <ul className="lido-sidebar-list lido-sidebar-list--favourites">
                    {favourites.map((item, index) => (
                        <li key={item.id}>
                            <FavouriteLink
                                item={item}
                                collapsed={collapsed}
                                onNavigate={onNavigate}
                                onUnpin={onUnpin}
                                activePageId={activePageId}
                                draggable={!collapsed}
                                isDragging={draggingIndex === index}
                                onDragStart={(event) => {
                                    dragIndex.current = index;
                                    setDraggingIndex(index);
                                    event.dataTransfer.effectAllowed = 'move';
                                    try {
                                        event.dataTransfer.setData('text/plain', String(index));
                                    } catch {
                                        /* ignore */
                                    }
                                }}
                                onDragOver={(event) => {
                                    event.preventDefault();
                                    event.dataTransfer.dropEffect = 'move';
                                }}
                                onDrop={(event) => {
                                    event.preventDefault();
                                    const from = dragIndex.current;
                                    if (from == null || from === index) {
                                        return;
                                    }
                                    onReorder(from, index);
                                    dragIndex.current = null;
                                    setDraggingIndex(null);
                                }}
                                onDragEnd={() => {
                                    dragIndex.current = null;
                                    setDraggingIndex(null);
                                }}
                            />
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export function FavouritePinButton({
    navId,
    eligible,
    isFavourite,
    canPinMore,
    onToggle,
}) {
    if (!eligible) {
        return null;
    }

    const disabled = !isFavourite && !canPinMore;

    return (
        <button
            type="button"
            className={`lido-sidebar-pin${isFavourite ? ' is-pinned' : ''}`}
            aria-label={isFavourite ? 'Unpin from favourites' : 'Pin to favourites'}
            aria-pressed={isFavourite}
            title={
                disabled
                    ? `Favourites full (max ${MAX_NAV_FAVOURITES})`
                    : (isFavourite ? 'Unpin' : 'Pin to favourites')
            }
            disabled={disabled}
            onClick={(event) => {
                event.preventDefault();
                event.stopPropagation();
                if (disabled) {
                    showToast(`Favourites are full (maximum ${MAX_NAV_FAVOURITES}).`, 'warning');
                    return;
                }
                onToggle(navId);
            }}
        >
            <Star
                size={13}
                strokeWidth={1.75}
                fill={isFavourite ? 'currentColor' : 'none'}
                aria-hidden="true"
            />
        </button>
    );
}
