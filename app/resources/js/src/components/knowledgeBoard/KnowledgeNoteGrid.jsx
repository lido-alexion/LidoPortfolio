import React from 'react';
import {
    DndContext,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
} from '@dnd-kit/core';
import {
    SortableContext,
    rectSortingStrategy,
} from '@dnd-kit/sortable';
import KnowledgeNoteCard from './KnowledgeNoteCard';

export default function KnowledgeNoteGrid({
    notes,
    sortMode,
    showControls = true,
    selectedIds,
    onToggleSelect,
    onReorder,
    onEdit,
    onTogglePin,
    onDuplicate,
    onArchive,
    onDelete,
    onChangePalette,
}) {
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    );
    const draggable = showControls && sortMode === 'manual';

    const handleDragEnd = (event) => {
        const { active, over } = event;
        if (!over || active.id === over.id) {
            return;
        }
        const ids = notes.map((note) => String(note.id));
        const oldIndex = ids.indexOf(String(active.id));
        const newIndex = ids.indexOf(String(over.id));
        if (oldIndex < 0 || newIndex < 0) {
            return;
        }
        const next = [...ids];
        const [moved] = next.splice(oldIndex, 1);
        next.splice(newIndex, 0, moved);
        onReorder?.(next);
    };

    const grid = (
        <div className="lido-knowledge-grid">
            {notes.map((note) => (
                <KnowledgeNoteCard
                    key={note.id}
                    note={note}
                    draggable={draggable}
                    showControls={showControls}
                    selected={selectedIds.has(String(note.id))}
                    onToggleSelect={onToggleSelect}
                    onEdit={onEdit}
                    onTogglePin={onTogglePin}
                    onDuplicate={onDuplicate}
                    onArchive={onArchive}
                    onDelete={onDelete}
                    onChangePalette={onChangePalette}
                />
            ))}
        </div>
    );

    if (!draggable) {
        return grid;
    }

    return (
        <DndContext sensors={sensors} collisionDetection={closestCenter} onDragEnd={handleDragEnd}>
            <SortableContext items={notes.map((note) => String(note.id))} strategy={rectSortingStrategy}>
                {grid}
            </SortableContext>
        </DndContext>
    );
}
