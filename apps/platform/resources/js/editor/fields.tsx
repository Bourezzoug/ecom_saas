import { defaultFor, repeaterItem, type Field } from '@aisg/renderer';
import {
    ChevronDown,
    ChevronUp,
    ImageUp,
    Loader2,
    Plus,
    Trash2,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, api } from './api';
import type { EditorPage } from './types';

/** Text value of a field that may hold anything (schema defaults, stale data). */
function asText(value: unknown): string {
    return typeof value === 'string' || typeof value === 'number'
        ? String(value)
        : '';
}

export type FieldContext = {
    pages: EditorPage[];
    /** Real catalog entries (empty while the project uses sample data). */
    products: { ref: string; name: string }[];
    categories: { ref: string; name: string }[];
    icons: string[];
    uploadUrl: string;
    /** Validation errors keyed like "content.items.0.question". */
    errors: Record<string, string>;
};

type Props = {
    field: Field;
    value: unknown;
    onChange: (value: unknown) => void;
    path: string;
    context: FieldContext;
};

const SYSTEM_TARGETS = [
    { value: 'home', label: 'Home page' },
    { value: 'shop', label: 'Shop' },
    { value: 'cart', label: 'Cart' },
    { value: 'checkout', label: 'Checkout' },
    { value: 'account', label: 'My account' },
];

function Counter({ value, max }: { value: string; max?: number }) {
    if (!max) {
        return null;
    }

    return (
        <span
            className={
                value.length > max
                    ? 'text-xs text-destructive'
                    : 'text-xs text-muted-foreground'
            }
        >
            {value.length}/{max}
        </span>
    );
}

function LinkControl({ value, onChange, field, context, path }: Props) {
    const link = (value ?? {
        label: '',
        target: { kind: 'url', value: '#' },
    }) as {
        label: string;
        target: { kind: string; value: string | null };
    };
    const set = (patch: Partial<typeof link>) =>
        onChange({ ...link, ...patch });
    const setTarget = (kind: string, targetValue: string | null) =>
        set({ target: { kind, value: targetValue } });
    const contentPages = context.pages.filter((p) => p.kind === 'page');

    return (
        <div className="grid grid-cols-1 gap-2 rounded-md border p-2">
            <Input
                aria-label={`${field.label} text`}
                value={link.label}
                maxLength={field.constraints?.maxLength ?? 60}
                placeholder="Button text (empty hides it)"
                onChange={(e) => set({ label: e.target.value })}
            />
            <div className="grid grid-cols-[7rem_1fr] gap-2">
                <NativeSelect
                    aria-label={`${field.label} destination type`}
                    value={link.target.kind}
                    onChange={(e) => {
                        const kind = e.target.value;
                        setTarget(
                            kind,
                            kind === 'system'
                                ? 'shop'
                                : kind === 'page'
                                  ? (contentPages[0]?.slug ?? null)
                                  : '',
                        );
                    }}
                >
                    <option value="system">Store</option>
                    <option value="page">Page</option>
                    <option value="url">URL</option>
                </NativeSelect>
                {link.target.kind === 'system' ? (
                    <NativeSelect
                        aria-label={`${field.label} destination`}
                        value={link.target.value ?? 'shop'}
                        onChange={(e) => setTarget('system', e.target.value)}
                    >
                        {SYSTEM_TARGETS.map((t) => (
                            <option key={t.value} value={t.value}>
                                {t.label}
                            </option>
                        ))}
                    </NativeSelect>
                ) : link.target.kind === 'page' ? (
                    <NativeSelect
                        aria-label={`${field.label} page`}
                        value={link.target.value ?? ''}
                        onChange={(e) => setTarget('page', e.target.value)}
                    >
                        {contentPages.map((p) => (
                            <option key={p.id} value={p.slug}>
                                {p.title}
                            </option>
                        ))}
                    </NativeSelect>
                ) : (
                    <Input
                        aria-label={`${field.label} URL`}
                        value={link.target.value ?? ''}
                        placeholder="https://…"
                        onChange={(e) => setTarget('url', e.target.value)}
                    />
                )}
            </div>
            <InputError
                message={
                    context.errors[path + '.label'] ?? context.errors[path]
                }
            />
        </div>
    );
}

function ImageControl({ value, onChange, field, context }: Props) {
    const image = (value ?? { asset_id: null, url: null, alt: '' }) as {
        asset_id: string | null;
        url: string | null;
        alt: string;
    };
    const input = useRef<HTMLInputElement>(null);
    const [uploading, setUploading] = useState(false);

    const upload = async (file: File) => {
        const form = new FormData();
        form.append('file', file);
        setUploading(true);

        try {
            const { asset } = await api<{ asset: { id: string; url: string } }>(
                'POST',
                context.uploadUrl,
                form,
            );
            onChange({ ...image, asset_id: asset.id, url: asset.url });
        } catch (error) {
            toast.error(
                error instanceof ApiError
                    ? error.firstError()
                    : 'Upload failed.',
            );
        } finally {
            setUploading(false);
        }
    };

    return (
        <div className="grid grid-cols-1 gap-2 rounded-md border p-2">
            {image.url ? (
                <div className="relative">
                    <img
                        src={image.url}
                        alt=""
                        className="aspect-video w-full rounded object-cover"
                    />
                    <Button
                        type="button"
                        variant="secondary"
                        size="icon"
                        className="absolute end-1 top-1 size-7"
                        aria-label={`Remove ${field.label}`}
                        onClick={() =>
                            onChange({ ...image, asset_id: null, url: null })
                        }
                    >
                        <X className="size-3.5" />
                    </Button>
                </div>
            ) : null}
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                className="hidden"
                onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) {
                        void upload(file);
                    }
                    e.target.value = '';
                }}
            />
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={uploading}
                onClick={() => input.current?.click()}
            >
                {uploading ? <Loader2 className="animate-spin" /> : <ImageUp />}
                {image.url ? 'Replace image' : 'Upload image'}
            </Button>
            <Input
                aria-label={`${field.label} description`}
                value={image.alt}
                maxLength={200}
                placeholder="Describe the image (accessibility & SEO)"
                onChange={(e) => onChange({ ...image, alt: e.target.value })}
            />
        </div>
    );
}

function RepeaterControl({ field, value, onChange, path, context }: Props) {
    const items = (Array.isArray(value) ? value : []) as Record<
        string,
        unknown
    >[];
    const min = field.constraints?.minItems ?? 0;
    const max = field.constraints?.maxItems ?? 20;
    const [open, setOpen] = useState<number | null>(null);

    const update = (index: number, item: Record<string, unknown>) =>
        onChange(items.map((it, i) => (i === index ? item : it)));
    const move = (index: number, delta: number) => {
        const next = [...items];
        const [moved] = next.splice(index, 1);
        next.splice(index + delta, 0, moved);
        onChange(next);
        setOpen(index + delta);
    };

    const itemLabel = (item: Record<string, unknown>, index: number) => {
        const template = field.item_label ?? '';
        const label = template.replace(
            /\{\{\s*(\w+)\s*\}\}/g,
            (_, key: string) => asText(item[key]),
        );

        return label.trim() || `Item ${index + 1}`;
    };

    return (
        <div className="grid grid-cols-1 gap-2">
            {items.map((item, index) => (
                <div key={index} className="rounded-md border">
                    <div className="flex items-center gap-1 px-2 py-1">
                        <button
                            type="button"
                            className="flex-1 truncate py-1 text-start text-sm font-medium"
                            onClick={() =>
                                setOpen(open === index ? null : index)
                            }
                        >
                            {itemLabel(item, index)}
                        </button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            disabled={index === 0}
                            onClick={() => move(index, -1)}
                            aria-label="Move up"
                        >
                            <ChevronUp className="size-3.5" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            disabled={index === items.length - 1}
                            onClick={() => move(index, 1)}
                            aria-label="Move down"
                        >
                            <ChevronDown className="size-3.5" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            className="size-7"
                            disabled={items.length <= min}
                            onClick={() =>
                                onChange(items.filter((_, i) => i !== index))
                            }
                            aria-label="Remove item"
                        >
                            <Trash2 className="size-3.5" />
                        </Button>
                    </div>
                    {open === index ? (
                        <div className="grid grid-cols-1 gap-3 border-t p-2">
                            {(field.fields ?? []).map((child) => (
                                <FieldControl
                                    key={child.name}
                                    field={child}
                                    value={item[child.name]}
                                    path={`${path}.${index}.${child.name}`}
                                    context={context}
                                    onChange={(v) =>
                                        update(index, {
                                            ...item,
                                            [child.name]: v,
                                        })
                                    }
                                />
                            ))}
                        </div>
                    ) : null}
                </div>
            ))}
            <Button
                type="button"
                variant="outline"
                size="sm"
                disabled={items.length >= max}
                onClick={() => {
                    onChange([...items, repeaterItem(field)]);
                    setOpen(items.length);
                }}
            >
                <Plus /> Add item
            </Button>
            <InputError message={context.errors[path]} />
        </div>
    );
}

/**
 * One form control per schema field type (docs/ARCHITECTURE.md §4.1).
 */
export function FieldControl(props: Props) {
    const { field, value, onChange, path, context } = props;
    const id = `f-${path}`;
    const error = context.errors[path];
    const max = field.constraints?.maxLength;

    const control = (() => {
        switch (field.type) {
            case 'text':
                return (
                    <Input
                        id={id}
                        value={asText(value)}
                        maxLength={max}
                        aria-invalid={!!error}
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
            case 'textarea':
                return (
                    <Textarea
                        id={id}
                        value={asText(value)}
                        maxLength={max}
                        rows={3}
                        aria-invalid={!!error}
                        onChange={(e) => onChange(e.target.value)}
                    />
                );
            case 'number':
                return (
                    <Input
                        id={id}
                        type="number"
                        value={Number(value ?? 0)}
                        min={field.constraints?.min}
                        max={field.constraints?.max}
                        onChange={(e) => onChange(Number(e.target.value))}
                    />
                );
            case 'boolean':
                return (
                    <Checkbox
                        id={id}
                        checked={Boolean(value)}
                        onCheckedChange={(checked) =>
                            onChange(checked === true)
                        }
                    />
                );
            case 'select':
                return (
                    <NativeSelect
                        id={id}
                        value={asText(value)}
                        onChange={(e) => onChange(e.target.value)}
                    >
                        {(field.options ?? []).map((o) => (
                            <option key={o.value} value={o.value}>
                                {o.label ?? o.value}
                            </option>
                        ))}
                    </NativeSelect>
                );
            case 'icon':
                return (
                    <NativeSelect
                        id={id}
                        value={asText(value)}
                        onChange={(e) => onChange(e.target.value)}
                    >
                        {context.icons.map((icon) => (
                            <option key={icon} value={icon}>
                                {icon}
                            </option>
                        ))}
                    </NativeSelect>
                );
            case 'color':
                return (
                    <Input
                        id={id}
                        type="color"
                        value={asText(value) || '#000000'}
                        onChange={(e) => onChange(e.target.value)}
                        className="h-9 w-16 p-1"
                    />
                );
            case 'link':
                return <LinkControl {...props} />;
            case 'image':
                return <ImageControl {...props} />;
            case 'repeater':
                return <RepeaterControl {...props} />;
            case 'product_query': {
                const query = (value ?? defaultFor(field)) as {
                    source: string;
                    limit: number;
                    category: string | null;
                    ids: string[];
                };

                return (
                    <div className="grid grid-cols-[1fr_5rem] gap-2">
                        <NativeSelect
                            aria-label="Which products"
                            value={query.source}
                            onChange={(e) =>
                                onChange({ ...query, source: e.target.value })
                            }
                        >
                            <option value="latest">Newest</option>
                            <option value="featured">Featured</option>
                            <option value="on_sale">On sale</option>
                            {context.categories.length > 0 ? (
                                <option value="category">
                                    From a category
                                </option>
                            ) : null}
                        </NativeSelect>
                        <Input
                            type="number"
                            aria-label="How many"
                            min={1}
                            max={24}
                            value={query.limit}
                            onChange={(e) =>
                                onChange({
                                    ...query,
                                    limit: Math.max(
                                        1,
                                        Math.min(24, Number(e.target.value)),
                                    ),
                                })
                            }
                        />
                        {query.source === 'category' ? (
                            <NativeSelect
                                aria-label="Category"
                                className="col-span-2"
                                value={query.category ?? ''}
                                onChange={(e) =>
                                    onChange({
                                        ...query,
                                        category: e.target.value,
                                    })
                                }
                            >
                                <option value="">Choose a category…</option>
                                {context.categories.map((c) => (
                                    <option key={c.ref} value={c.ref}>
                                        {c.name}
                                    </option>
                                ))}
                            </NativeSelect>
                        ) : null}
                    </div>
                );
            }
            case 'product':
            case 'category': {
                const options =
                    field.type === 'product'
                        ? context.products
                        : context.categories;

                if (options.length === 0) {
                    return (
                        <p className="rounded-md border border-dashed p-2 text-xs text-muted-foreground">
                            Add products in the Catalog to choose one here. The
                            preview shows sample data.
                        </p>
                    );
                }

                return (
                    <NativeSelect
                        id={id}
                        value={asText(value)}
                        onChange={(e) =>
                            onChange(
                                e.target.value === '' ? null : e.target.value,
                            )
                        }
                    >
                        <option value="">
                            {field.type === 'product'
                                ? 'First product'
                                : 'Any category'}
                        </option>
                        {options.map((o) => (
                            <option key={o.ref} value={o.ref}>
                                {o.name}
                            </option>
                        ))}
                    </NativeSelect>
                );
            }
            default:
                return null;
        }
    })();

    if (field.type === 'boolean') {
        return (
            <div className="flex items-center gap-2">
                {control}
                <Label htmlFor={id}>{field.label}</Label>
            </div>
        );
    }

    return (
        <div className="grid min-w-0 grid-cols-1 gap-1.5">
            <div className="flex items-center justify-between gap-2">
                <Label htmlFor={id}>{field.label}</Label>
                {field.type === 'text' || field.type === 'textarea' ? (
                    <Counter value={asText(value)} max={max} />
                ) : null}
            </div>
            {control}
            {field.type !== 'repeater' && field.type !== 'link' ? (
                <InputError message={error} />
            ) : null}
        </div>
    );
}
