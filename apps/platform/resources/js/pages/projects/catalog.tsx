import { Form, Head, router, usePage } from '@inertiajs/react';
import {
    ImageUp,
    Loader2,
    Package,
    Pencil,
    Plus,
    Sparkles,
    Trash2,
    Upload,
    X,
} from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { ApiError, api } from '@/editor/api';
import catalogRoutes from '@/routes/catalog';
import editorRoutes from '@/routes/editor';
import { index as projectsIndex, show } from '@/routes/projects';

type ProductImage = { asset_id: string | null; url: string; alt: string };

type CatalogProduct = {
    id: string;
    type: 'simple' | 'variable';
    name: string;
    sku: string | null;
    regularPrice: string | null;
    salePrice: string | null;
    shortDescription: string | null;
    description: string | null;
    images: ProductImage[];
    stockStatus: string;
    status: 'draft' | 'publish';
    source: 'manual' | 'csv' | 'ai';
    featured: boolean;
    variationCount: number;
    categoryIds: string[];
    categoryNames: string[];
};

type CatalogCategory = {
    id: string;
    name: string;
    parentId: string | null;
    productCount: number;
};

type Props = {
    project: { id: string; name: string; currency: string };
    products: CatalogProduct[];
    categories: CatalogCategory[];
    importResult: {
        created: number;
        updated: number;
        skipped: number;
        errors: string[];
    } | null;
};

type Draft = {
    name: string;
    sku: string;
    regular_price: string;
    sale_price: string;
    short_description: string;
    description: string;
    images: ProductImage[];
    stock_status: string;
    status: 'draft' | 'publish';
    featured: boolean;
    category_ids: string[];
};

const emptyDraft: Draft = {
    name: '',
    sku: '',
    regular_price: '',
    sale_price: '',
    short_description: '',
    description: '',
    images: [],
    stock_status: 'instock',
    status: 'publish',
    featured: false,
    category_ids: [],
};

function toDraft(p: CatalogProduct): Draft {
    return {
        name: p.name,
        sku: p.sku ?? '',
        regular_price: p.regularPrice ?? '',
        sale_price: p.salePrice ?? '',
        short_description: p.shortDescription ?? '',
        description: p.description ?? '',
        images: p.images,
        stock_status: p.stockStatus,
        status: p.status,
        featured: p.featured,
        category_ids: p.categoryIds,
    };
}

export default function Catalog({
    project,
    products,
    categories,
    importResult,
}: Props) {
    const teamSlug = usePage().props.currentTeam?.slug ?? '';
    const args = { current_team: teamSlug, project: project.id };
    const [editing, setEditing] = useState<CatalogProduct | 'new' | null>(null);
    const [draft, setDraft] = useState<Draft>(emptyDraft);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [saving, setSaving] = useState(false);
    const [uploading, setUploading] = useState(false);
    const [newCategory, setNewCategory] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);
    const drafts = products.filter((p) => p.status === 'draft').length;

    const open = (product: CatalogProduct | 'new') => {
        setEditing(product);
        setDraft(product === 'new' ? emptyDraft : toDraft(product));
        setErrors({});
    };

    const save = () => {
        const payload = {
            ...draft,
            regular_price:
                draft.regular_price === '' ? null : draft.regular_price,
            sale_price: draft.sale_price === '' ? null : draft.sale_price,
            sku: draft.sku === '' ? null : draft.sku,
        };
        const options = {
            preserveScroll: true,
            onStart: () => setSaving(true),
            onFinish: () => setSaving(false),
            onSuccess: () => setEditing(null),
            onError: (e: Record<string, string>) => setErrors(e),
        };

        if (editing === 'new') {
            router.post(
                catalogRoutes.products.store.url(args),
                payload,
                options,
            );
        } else if (editing) {
            router.put(
                catalogRoutes.products.update.url({
                    ...args,
                    product: editing.id,
                }),
                payload,
                options,
            );
        }
    };

    const upload = async (file: File) => {
        const form = new FormData();
        form.append('file', file);
        setUploading(true);

        try {
            const { asset } = await api<{ asset: { id: string; url: string } }>(
                'POST',
                editorRoutes.assets.store.url(args),
                form,
            );
            setDraft((d) => ({
                ...d,
                images: [
                    ...d.images,
                    { asset_id: asset.id, url: asset.url, alt: d.name },
                ],
            }));
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
        <>
            <Head title={`Catalog · ${project.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title="Catalog"
                        description="Products and categories that are published to WooCommerce with your store."
                    />
                    <div className="flex flex-wrap gap-2">
                        <Form
                            {...catalogRoutes.import.form(args)}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                        >
                            {({ errors: formErrors, processing }) => (
                                <>
                                    <label className="inline-flex">
                                        <input
                                            type="file"
                                            name="file"
                                            accept=".csv,text/csv"
                                            className="hidden"
                                            onChange={(e) =>
                                                e.currentTarget.form?.requestSubmit()
                                            }
                                        />
                                        <span className="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border px-3 text-sm font-medium hover:bg-accent">
                                            {processing ? (
                                                <Loader2 className="size-4 animate-spin" />
                                            ) : (
                                                <Upload className="size-4" />
                                            )}
                                            Import WooCommerce CSV
                                        </span>
                                    </label>
                                    <InputError message={formErrors.file} />
                                </>
                            )}
                        </Form>
                        <Button onClick={() => open('new')}>
                            <Plus /> Add product
                        </Button>
                    </div>
                </div>

                {importResult ? (
                    <div
                        className="rounded-lg border p-3 text-sm"
                        data-test="import-result"
                    >
                        <p className="font-medium">
                            Import finished: {importResult.created} created,{' '}
                            {importResult.updated} updated,{' '}
                            {importResult.skipped} skipped.
                        </p>
                        {importResult.errors.length > 0 ? (
                            <ul className="mt-2 list-disc ps-5 text-muted-foreground">
                                {importResult.errors.slice(0, 10).map((e) => (
                                    <li key={e}>{e}</li>
                                ))}
                            </ul>
                        ) : null}
                    </div>
                ) : null}

                {drafts > 0 ? (
                    <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200">
                        <span className="flex items-center gap-2">
                            <Sparkles className="size-4" />
                            {drafts} product(s) are drafts. AI sample products
                            have placeholder prices: check them, then publish.
                        </span>
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                router.post(
                                    catalogRoutes.products.publishAll.url(args),
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Publish all drafts
                        </Button>
                    </div>
                ) : null}

                <div className="grid gap-6 lg:grid-cols-[1fr_18rem]">
                    <div className="overflow-hidden rounded-xl border">
                        {products.length === 0 ? (
                            <div className="flex flex-col items-center gap-3 py-16 text-center">
                                <Package className="size-10 text-muted-foreground" />
                                <p className="font-medium">No products yet</p>
                                <p className="max-w-sm text-sm text-muted-foreground">
                                    Add products by hand, import a WooCommerce
                                    CSV, or generate the store to get AI sample
                                    products.
                                </p>
                            </div>
                        ) : (
                            <table className="w-full text-sm">
                                <thead className="bg-muted/50 text-start text-xs text-muted-foreground uppercase">
                                    <tr>
                                        <th className="p-3 text-start">
                                            Product
                                        </th>
                                        <th className="p-3 text-start">
                                            Price
                                        </th>
                                        <th className="hidden p-3 text-start md:table-cell">
                                            Categories
                                        </th>
                                        <th className="p-3 text-start">
                                            Status
                                        </th>
                                        <th className="p-3" />
                                    </tr>
                                </thead>
                                <tbody className="divide-y">
                                    {products.map((p) => (
                                        <tr key={p.id} data-test="catalog-row">
                                            <td className="p-3">
                                                <div className="flex items-center gap-3">
                                                    {p.images[0]?.url ? (
                                                        <img
                                                            src={
                                                                p.images[0].url
                                                            }
                                                            alt=""
                                                            className="size-10 rounded object-cover"
                                                        />
                                                    ) : (
                                                        <span className="flex size-10 items-center justify-center rounded bg-muted">
                                                            <Package className="size-4 text-muted-foreground" />
                                                        </span>
                                                    )}
                                                    <div>
                                                        <p className="font-medium">
                                                            {p.name}
                                                        </p>
                                                        <p className="text-xs text-muted-foreground">
                                                            {p.sku ?? '—'}
                                                            {p.type ===
                                                            'variable'
                                                                ? ` · ${p.variationCount} variations`
                                                                : ''}
                                                        </p>
                                                    </div>
                                                </div>
                                            </td>
                                            <td className="p-3 whitespace-nowrap">
                                                {p.salePrice ? (
                                                    <>
                                                        <span className="text-muted-foreground line-through">
                                                            {p.regularPrice}
                                                        </span>{' '}
                                                        {p.salePrice}
                                                    </>
                                                ) : (
                                                    (p.regularPrice ?? '—')
                                                )}{' '}
                                                <span className="text-xs text-muted-foreground">
                                                    {project.currency}
                                                </span>
                                            </td>
                                            <td className="hidden p-3 text-muted-foreground md:table-cell">
                                                {p.categoryNames.join(', ') ||
                                                    '—'}
                                            </td>
                                            <td className="p-3">
                                                <div className="flex flex-wrap gap-1">
                                                    <Badge
                                                        variant={
                                                            p.status ===
                                                            'publish'
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {p.status === 'publish'
                                                            ? 'Published'
                                                            : 'Draft'}
                                                    </Badge>
                                                    {p.source === 'ai' ? (
                                                        <Badge variant="outline">
                                                            AI sample
                                                        </Badge>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="p-3 text-end whitespace-nowrap">
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Edit ${p.name}`}
                                                    onClick={() => open(p)}
                                                >
                                                    <Pencil className="size-4" />
                                                </Button>
                                                <Button
                                                    variant="ghost"
                                                    size="icon"
                                                    aria-label={`Delete ${p.name}`}
                                                    onClick={() =>
                                                        window.confirm(
                                                            `Delete ${p.name}?`,
                                                        ) &&
                                                        router.delete(
                                                            catalogRoutes.products.destroy.url(
                                                                {
                                                                    ...args,
                                                                    product:
                                                                        p.id,
                                                                },
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </Button>
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </div>

                    <aside className="flex flex-col gap-3 rounded-xl border p-4">
                        <h2 className="font-semibold">Categories</h2>
                        <ul className="flex flex-col gap-1 text-sm">
                            {categories.map((c) => (
                                <li
                                    key={c.id}
                                    className="group flex items-center justify-between gap-2"
                                >
                                    <span>
                                        {c.parentId ? '↳ ' : ''}
                                        {c.name}{' '}
                                        <span className="text-muted-foreground">
                                            ({c.productCount})
                                        </span>
                                    </span>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        className="size-7 opacity-0 group-hover:opacity-100"
                                        aria-label={`Delete ${c.name}`}
                                        onClick={() =>
                                            router.delete(
                                                catalogRoutes.categories.destroy.url(
                                                    {
                                                        ...args,
                                                        category: c.id,
                                                    },
                                                ),
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        <Trash2 className="size-3.5" />
                                    </Button>
                                </li>
                            ))}
                        </ul>
                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (!newCategory.trim()) {
                                    return;
                                }
                                router.post(
                                    catalogRoutes.categories.store.url(args),
                                    { name: newCategory },
                                    {
                                        preserveScroll: true,
                                        onSuccess: () => setNewCategory(''),
                                    },
                                );
                            }}
                        >
                            <Input
                                value={newCategory}
                                placeholder="New category"
                                maxLength={120}
                                onChange={(e) => setNewCategory(e.target.value)}
                            />
                            <Button
                                type="submit"
                                size="icon"
                                aria-label="Add category"
                            >
                                <Plus />
                            </Button>
                        </form>
                    </aside>
                </div>
            </div>

            <Dialog
                open={editing !== null}
                onOpenChange={(o) => !o && setEditing(null)}
            >
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            {editing === 'new' ? 'Add product' : 'Edit product'}
                        </DialogTitle>
                        <DialogDescription>
                            {editing !== 'new' && editing?.type === 'variable'
                                ? `This product has ${editing.variationCount} variations (from your CSV). Edit variations in WooCommerce after publishing.`
                                : 'Simple product. Everything can still be edited in WooCommerce after publishing.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="p-name">Name</Label>
                            <Input
                                id="p-name"
                                value={draft.name}
                                maxLength={255}
                                onChange={(e) =>
                                    setDraft({ ...draft, name: e.target.value })
                                }
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="p-price">
                                Price ({project.currency})
                            </Label>
                            <Input
                                id="p-price"
                                type="number"
                                step="0.01"
                                min="0"
                                value={draft.regular_price}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        regular_price: e.target.value,
                                    })
                                }
                            />
                            <InputError message={errors.regular_price} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="p-sale">Sale price</Label>
                            <Input
                                id="p-sale"
                                type="number"
                                step="0.01"
                                min="0"
                                value={draft.sale_price}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        sale_price: e.target.value,
                                    })
                                }
                            />
                            <InputError message={errors.sale_price} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="p-sku">SKU</Label>
                            <Input
                                id="p-sku"
                                value={draft.sku}
                                maxLength={100}
                                onChange={(e) =>
                                    setDraft({ ...draft, sku: e.target.value })
                                }
                            />
                            <InputError message={errors.sku} />
                        </div>
                        <div className="grid gap-1.5">
                            <Label htmlFor="p-stock">Stock</Label>
                            <NativeSelect
                                id="p-stock"
                                value={draft.stock_status}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        stock_status: e.target.value,
                                    })
                                }
                            >
                                <option value="instock">In stock</option>
                                <option value="outofstock">Out of stock</option>
                                <option value="onbackorder">
                                    On backorder
                                </option>
                            </NativeSelect>
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="p-short">Short description</Label>
                            <Textarea
                                id="p-short"
                                rows={2}
                                maxLength={1000}
                                value={draft.short_description}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        short_description: e.target.value,
                                    })
                                }
                            />
                        </div>
                        <div className="grid gap-1.5 sm:col-span-2">
                            <Label htmlFor="p-desc">Description</Label>
                            <Textarea
                                id="p-desc"
                                rows={4}
                                maxLength={10000}
                                value={draft.description}
                                onChange={(e) =>
                                    setDraft({
                                        ...draft,
                                        description: e.target.value,
                                    })
                                }
                            />
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label>Images</Label>
                            <div className="flex flex-wrap gap-2">
                                {draft.images.map((img, i) => (
                                    <div key={img.url + i} className="relative">
                                        <img
                                            src={img.url}
                                            alt=""
                                            className="size-20 rounded object-cover"
                                        />
                                        <button
                                            type="button"
                                            aria-label="Remove image"
                                            className="absolute end-1 top-1 rounded bg-background/90 p-0.5"
                                            onClick={() =>
                                                setDraft({
                                                    ...draft,
                                                    images: draft.images.filter(
                                                        (_, j) => j !== i,
                                                    ),
                                                })
                                            }
                                        >
                                            <X className="size-3.5" />
                                        </button>
                                    </div>
                                ))}
                                <input
                                    ref={fileInput}
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
                                    className="size-20 flex-col gap-1 text-xs"
                                    disabled={
                                        uploading || draft.images.length >= 10
                                    }
                                    onClick={() => fileInput.current?.click()}
                                >
                                    {uploading ? (
                                        <Loader2 className="animate-spin" />
                                    ) : (
                                        <ImageUp />
                                    )}
                                    Add
                                </Button>
                            </div>
                        </div>

                        <div className="grid gap-2 sm:col-span-2">
                            <Label>Categories</Label>
                            <div className="flex flex-wrap gap-3">
                                {categories.map((c) => (
                                    <label
                                        key={c.id}
                                        className="flex items-center gap-2 text-sm"
                                    >
                                        <Checkbox
                                            checked={draft.category_ids.includes(
                                                c.id,
                                            )}
                                            onCheckedChange={(checked) =>
                                                setDraft({
                                                    ...draft,
                                                    category_ids: checked
                                                        ? [
                                                              ...draft.category_ids,
                                                              c.id,
                                                          ]
                                                        : draft.category_ids.filter(
                                                              (id) =>
                                                                  id !== c.id,
                                                          ),
                                                })
                                            }
                                        />
                                        {c.name}
                                    </label>
                                ))}
                                {categories.length === 0 ? (
                                    <span className="text-sm text-muted-foreground">
                                        No categories yet.
                                    </span>
                                ) : null}
                            </div>
                        </div>

                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={draft.featured}
                                onCheckedChange={(c) =>
                                    setDraft({ ...draft, featured: c === true })
                                }
                            />
                            Featured product
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox
                                checked={draft.status === 'publish'}
                                onCheckedChange={(c) =>
                                    setDraft({
                                        ...draft,
                                        status:
                                            c === true ? 'publish' : 'draft',
                                    })
                                }
                            />
                            Published (visible in the store)
                        </label>
                    </div>

                    <DialogFooter>
                        <Button
                            variant="secondary"
                            onClick={() => setEditing(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={save}
                            disabled={saving || draft.name.trim() === ''}
                        >
                            {saving ? (
                                <Loader2 className="animate-spin" />
                            ) : null}
                            Save product
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}

Catalog.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: { id: string; name: string };
}) => {
    const slug = props.currentTeam?.slug ?? '';

    return {
        breadcrumbs: [
            { title: 'Projects', href: projectsIndex(slug) },
            {
                title: props.project.name,
                href: show({ current_team: slug, project: props.project.id }),
            },
            {
                title: 'Catalog',
                href: catalogRoutes.index({
                    current_team: slug,
                    project: props.project.id,
                }),
            },
        ],
    };
};
