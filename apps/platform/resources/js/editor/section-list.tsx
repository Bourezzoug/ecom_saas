import {
    DndContext,
    KeyboardSensor,
    PointerSensor,
    closestCenter,
    useSensor,
    useSensors,
    type DragEndEvent,
} from '@dnd-kit/core';
import {
    SortableContext,
    arrayMove,
    sortableKeyboardCoordinates,
    useSortable,
    verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import {
    AlertTriangle,
    GripVertical,
    Loader2,
    Lock,
    Plus,
    Trash2,
} from 'lucide-react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import AddSectionDialog from './add-section-dialog';
import type { Editor } from './use-editor';
import type { EditorSection } from './types';

type RowProps = {
    section: EditorSection;
    name: string;
    reviewRequired: boolean;
    selected: boolean;
    locked?: boolean;
    onSelect: () => void;
    onRemove?: () => void;
};

function SectionRow({
    section,
    name,
    reviewRequired,
    selected,
    locked,
    onSelect,
    onRemove,
}: RowProps) {
    const sortable = useSortable({ id: section.id, disabled: locked });
    const style = {
        transform: CSS.Transform.toString(sortable.transform),
        transition: sortable.transition,
    };

    return (
        <li
            ref={sortable.setNodeRef}
            style={style}
            className={cn(
                'group flex items-center gap-1 rounded-md border bg-background text-sm',
                selected && 'border-primary ring-1 ring-primary',
                sortable.isDragging && 'z-10 opacity-70 shadow-md',
            )}
            data-test="editor-section-row"
        >
            {locked ? (
                <span className="p-2 text-muted-foreground">
                    <Lock className="size-3.5" />
                </span>
            ) : (
                <button
                    type="button"
                    className="cursor-grab touch-none p-2 text-muted-foreground active:cursor-grabbing"
                    aria-label={`Drag ${name}`}
                    {...sortable.attributes}
                    {...sortable.listeners}
                >
                    <GripVertical className="size-4" />
                </button>
            )}
            <button
                type="button"
                className="flex min-w-0 flex-1 items-center gap-2 py-2 text-start"
                onClick={onSelect}
            >
                <span className="truncate">{name}</span>
                {section.status === 'generating' ? (
                    <Loader2 className="size-3.5 animate-spin text-primary" />
                ) : null}
                {reviewRequired ? (
                    <AlertTriangle
                        className="size-3.5 shrink-0 text-amber-500"
                        aria-label="Contains example claims to replace"
                    />
                ) : null}
            </button>
            {onRemove ? (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-7 opacity-0 group-hover:opacity-100 focus:opacity-100"
                    onClick={onRemove}
                    aria-label={`Remove ${name}`}
                >
                    <Trash2 className="size-3.5" />
                </Button>
            ) : null}
        </li>
    );
}

export default function SectionList({ editor }: { editor: Editor }) {
    const { page, header, footer, library, selectedId } = editor;
    const sensors = useSensors(
        useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
        useSensor(KeyboardSensor, {
            coordinateGetter: sortableKeyboardCoordinates,
        }),
    );

    if (!page) {
        return null;
    }

    const onDragEnd = (event: DragEndEvent) => {
        const { active, over } = event;

        if (!over || active.id === over.id) {
            return;
        }

        const ids = page.sections.map((s) => s.id);
        const next = arrayMove(
            ids,
            ids.indexOf(String(active.id)),
            ids.indexOf(String(over.id)),
        );
        void editor.reorder(page.id, next);
    };

    const row = (section: EditorSection, locked = false) => (
        <SectionRow
            key={section.id}
            section={section}
            name={library[section.key]?.name ?? section.key}
            reviewRequired={library[section.key]?.reviewRequired ?? false}
            selected={section.id === selectedId}
            locked={locked}
            onSelect={() => editor.setSelectedId(section.id)}
            onRemove={
                locked ? undefined : () => void editor.removeSection(section.id)
            }
        />
    );

    return (
        <div className="flex flex-col gap-2">
            <ul className="flex flex-col gap-1.5">
                {header?.sections.map((s) => row(s, true))}
            </ul>

            <DndContext
                sensors={sensors}
                collisionDetection={closestCenter}
                onDragEnd={onDragEnd}
            >
                <SortableContext
                    items={page.sections.map((s) => s.id)}
                    strategy={verticalListSortingStrategy}
                >
                    <ul className="flex flex-col gap-1.5">
                        {page.sections.map((s) => row(s))}
                    </ul>
                </SortableContext>
            </DndContext>

            <AddSectionDialog
                library={Object.values(library)}
                pageType={page.type}
                onAdd={(key) =>
                    void editor.addSection(key, page.sections.length)
                }
            >
                <Button variant="outline" size="sm" className="w-full">
                    <Plus /> Add section
                </Button>
            </AddSectionDialog>

            <ul className="flex flex-col gap-1.5">
                {footer?.sections.map((s) => row(s, true))}
            </ul>
        </div>
    );
}
