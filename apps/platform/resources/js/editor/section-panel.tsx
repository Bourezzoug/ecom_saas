import { AlertTriangle, Loader2, Sparkles, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { Textarea } from '@/components/ui/textarea';
import { FieldControl, type FieldContext } from './fields';
import type { Editor } from './use-editor';

type Props = {
    editor: Editor;
    context: Omit<FieldContext, 'errors'>;
    regenerateCost: number;
    credits: number;
};

export default function SectionPanel({
    editor,
    context,
    regenerateCost,
    credits,
}: Props) {
    const section = editor.selected;
    const [instruction, setInstruction] = useState('');

    if (!section) {
        return (
            <p className="p-4 text-sm text-muted-foreground">
                Click a section in the preview or in the list to edit it.
            </p>
        );
    }

    const definition = editor.library[section.key];

    if (!definition) {
        return null;
    }

    const generating = section.status === 'generating';
    const errors = editor.errors[section.id] ?? {};
    const isLayoutPart = definition.placement !== 'body';
    const hasAiFields = definition.fields.some((f) => f.ai?.generate);

    return (
        <div className="flex flex-col gap-4 p-4" data-test="section-panel">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <h2 className="font-semibold">{definition.name}</h2>
                    <p className="text-xs text-muted-foreground">
                        Changes save automatically.
                    </p>
                </div>
                {!isLayoutPart ? (
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Remove section"
                        onClick={() => void editor.removeSection(section.id)}
                    >
                        <Trash2 className="size-4" />
                    </Button>
                ) : null}
            </div>

            {definition.reviewRequired ? (
                <div className="flex gap-2 rounded-md border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                    <AlertTriangle className="size-4 shrink-0" />
                    <span>
                        This section contains AI example content (reviews,
                        promises or comparisons). Replace it with real, accurate
                        information before publishing.
                    </span>
                </div>
            ) : null}

            {hasAiFields ? (
                <div className="grid grid-cols-1 gap-2 rounded-lg border bg-muted/40 p-3">
                    <p className="flex items-center gap-2 text-sm font-medium">
                        <Sparkles className="size-4" /> Rewrite with AI
                    </p>
                    <Textarea
                        rows={2}
                        maxLength={300}
                        value={instruction}
                        disabled={generating}
                        placeholder="Optional: e.g. more playful, mention free gift wrapping"
                        onChange={(e) => setInstruction(e.target.value)}
                    />
                    <Button
                        size="sm"
                        disabled={generating || credits < regenerateCost}
                        onClick={() => {
                            void editor.regenerate(section.id, instruction);
                            setInstruction('');
                        }}
                        data-test="regenerate-button"
                    >
                        {generating ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Sparkles />
                        )}
                        {generating
                            ? 'Rewriting…'
                            : `Rewrite · ${regenerateCost} credits`}
                    </Button>
                </div>
            ) : null}

            <fieldset
                disabled={generating}
                className="grid min-w-0 grid-cols-1 gap-4 disabled:opacity-50"
            >
                {definition.fields.map((field) => (
                    <FieldControl
                        key={field.name}
                        field={field}
                        value={section.content[field.name]}
                        path={`content.${field.name}`}
                        context={{ ...context, errors }}
                        onChange={(value) =>
                            editor.updateSection(section.id, {
                                content: {
                                    ...section.content,
                                    [field.name]: value,
                                },
                            })
                        }
                    />
                ))}

                {definition.style.length > 0 ? (
                    <>
                        <Separator />
                        <p className="text-sm font-semibold">Style</p>
                        {definition.style.map((field) => (
                            <FieldControl
                                key={field.name}
                                field={field}
                                value={section.style[field.name]}
                                path={`style.${field.name}`}
                                context={{ ...context, errors }}
                                onChange={(value) =>
                                    editor.updateSection(section.id, {
                                        style: {
                                            ...section.style,
                                            [field.name]: value,
                                        },
                                    })
                                }
                            />
                        ))}
                    </>
                ) : null}
            </fieldset>
        </div>
    );
}
