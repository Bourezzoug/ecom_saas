import { Form } from '@inertiajs/react';
import type { RouteFormDefinition } from '@/wayfinder';
import { Plus, X } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import type { ProjectDetail, ProjectFormOptions } from '@/types';

type Props = ProjectFormOptions & {
    action: RouteFormDefinition<'post'> | RouteFormDefinition<'put'>;
    project?: ProjectDetail;
    submitLabel: string;
};

const MAX_BRAND_COLORS = 3;

export default function ProjectForm({
    action,
    project,
    languages,
    tones,
    creationModes,
    submitLabel,
}: Props) {
    const [creationMode, setCreationMode] = useState(
        project?.creationMode ?? 'describe',
    );
    const [colors, setColors] = useState<string[]>(
        project?.brief.brand_colors ?? [],
    );

    return (
        <Form {...action} className="space-y-8">
            {({ errors, processing }) => (
                <>
                    <fieldset className="space-y-3">
                        <legend className="text-sm font-medium">
                            How do you want to start?
                        </legend>
                        <input
                            type="hidden"
                            name="creation_mode"
                            value={creationMode}
                        />
                        <div className="grid gap-3 sm:grid-cols-3">
                            {creationModes.map((mode) => (
                                <button
                                    key={mode.value}
                                    type="button"
                                    disabled={!mode.available}
                                    aria-pressed={creationMode === mode.value}
                                    onClick={() => setCreationMode(mode.value)}
                                    className={cn(
                                        'rounded-lg border p-4 text-left text-sm transition-colors',
                                        creationMode === mode.value
                                            ? 'border-primary ring-2 ring-primary/20'
                                            : 'hover:bg-accent',
                                        !mode.available &&
                                            'cursor-not-allowed opacity-50 hover:bg-transparent',
                                    )}
                                >
                                    <span className="font-medium">
                                        {mode.label}
                                    </span>
                                    {!mode.available ? (
                                        <span className="mt-1 block text-xs text-muted-foreground">
                                            Coming soon
                                        </span>
                                    ) : null}
                                </button>
                            ))}
                        </div>
                        <InputError message={errors.creation_mode} />
                    </fieldset>

                    <div className="grid gap-6 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Store name</Label>
                            <Input
                                id="name"
                                name="name"
                                defaultValue={project?.name}
                                placeholder="Clay & Co"
                                maxLength={120}
                                required
                            />
                            <InputError message={errors.name} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="niche">Niche</Label>
                            <Input
                                id="niche"
                                name="brief[niche]"
                                defaultValue={project?.brief.niche}
                                placeholder="Handmade ceramic mugs"
                                maxLength={160}
                                required
                            />
                            <InputError message={errors['brief.niche']} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="audience">Target audience</Label>
                            <Input
                                id="audience"
                                name="brief[audience]"
                                defaultValue={project?.brief.audience ?? ''}
                                placeholder="Coffee lovers aged 25–45 who buy gifts online"
                                maxLength={300}
                            />
                            <InputError message={errors['brief.audience']} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="language">Store language</Label>
                            <NativeSelect
                                id="language"
                                name="language"
                                defaultValue={project?.language ?? 'en'}
                            >
                                {languages.map((language) => (
                                    <option
                                        key={language.value}
                                        value={language.value}
                                    >
                                        {language.label}
                                        {language.dir === 'rtl' ? ' (RTL)' : ''}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors.language} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="tone">Tone of voice</Label>
                            <NativeSelect
                                id="tone"
                                name="brief[tone]"
                                defaultValue={project?.brief.tone ?? tones[0]}
                            >
                                {tones.map((tone) => (
                                    <option key={tone} value={tone}>
                                        {tone.charAt(0).toUpperCase() +
                                            tone.slice(1)}
                                    </option>
                                ))}
                            </NativeSelect>
                            <InputError message={errors['brief.tone']} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="currency">
                                Currency{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Input
                                id="currency"
                                name="currency"
                                defaultValue={project?.currency ?? ''}
                                placeholder="USD"
                                maxLength={3}
                                className="uppercase"
                            />
                            <InputError message={errors.currency} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="style">
                                Style preferences{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </Label>
                            <Textarea
                                id="style"
                                name="brief[style]"
                                defaultValue={project?.brief.style ?? ''}
                                placeholder="Earthy and minimal, lots of white space, warm photography"
                                maxLength={500}
                                rows={3}
                            />
                            <InputError message={errors['brief.style']} />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label>
                                Brand colours{' '}
                                <span className="text-muted-foreground">
                                    (optional, up to {MAX_BRAND_COLORS})
                                </span>
                            </Label>
                            <div className="flex flex-wrap items-center gap-3">
                                {colors.map((color, index) => (
                                    <div
                                        key={index}
                                        className="flex items-center gap-1 rounded-md border p-1"
                                    >
                                        <input
                                            type="color"
                                            name="brief[brand_colors][]"
                                            aria-label={`Brand colour ${index + 1}`}
                                            value={color}
                                            onChange={(event) =>
                                                setColors(
                                                    colors.map((c, i) =>
                                                        i === index
                                                            ? event.target.value
                                                            : c,
                                                    ),
                                                )
                                            }
                                            className="h-7 w-10 cursor-pointer rounded bg-transparent"
                                        />
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {color}
                                        </span>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="size-6"
                                            aria-label="Remove colour"
                                            onClick={() =>
                                                setColors(
                                                    colors.filter(
                                                        (_, i) => i !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <X className="size-3" />
                                        </Button>
                                    </div>
                                ))}
                                {colors.length < MAX_BRAND_COLORS ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setColors([...colors, '#4f46e5'])
                                        }
                                    >
                                        <Plus /> Add colour
                                    </Button>
                                ) : null}
                            </div>
                            <InputError
                                message={
                                    errors['brief.brand_colors'] ??
                                    Object.entries(errors).find(([key]) =>
                                        key.startsWith('brief.brand_colors.'),
                                    )?.[1]
                                }
                            />
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            {submitLabel}
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}
