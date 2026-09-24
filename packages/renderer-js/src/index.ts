/**
 * @aisg/renderer — the TypeScript twin of packages/renderer-php.
 *
 * Every function here mirrors a PHP class so the editor preview (browser) and
 * the published site (WordPress/PHP) render byte-identical HTML:
 *
 *   defaults()          ↔ SchemaCompiler::defaults()
 *   buildViewModel()    ↔ ViewModelBuilder::build()
 *   render()            ↔ Renderer::render()   (same escape function)
 *   wrap()              ↔ Renderer::wrap()
 *   safeUrl()           ↔ RenderContext::safeUrl()
 *   designTokensCss()   ↔ DesignTokensCss::toCss()
 *   resolveData()       ↔ App\Domain\Preview\PreviewDataResolver::resolve()
 *
 * Parity is enforced by tests/Feature/Sections/RendererParityTest.php.
 * Keep this file free of TS-only runtime syntax (enums, parameter properties):
 * it also runs under `node --experimental-strip-types`.
 */
import Mustache from 'mustache';

export type Field = {
    name: string;
    type: string;
    label: string;
    default?: unknown;
    required?: boolean;
    constraints?: {
        minLength?: number;
        maxLength?: number;
        minItems?: number;
        maxItems?: number;
        min?: number;
        max?: number;
    };
    options?: { value: string; label?: string }[];
    ai?: { generate?: boolean; hint?: string };
    item_label?: string;
    fields?: Field[];
};

export type SectionDefinition = {
    key: string;
    version: number;
    name: string;
    category: string;
    placement: 'header' | 'body' | 'footer';
    pageTypes: string[];
    reviewRequired: boolean;
    plannerDescription?: string;
    fields: Field[];
    style: Field[];
    data: Record<string, { resolver: string; from_field?: string }>;
    template: string;
};

export type LinkTarget = { kind: string; value: string | null };

export type Site = {
    name: string;
    lang: string;
    dir: 'ltr' | 'rtl';
    currency: string;
    year: number;
};

export type RenderContext = {
    site: Site;
    /** URL for page/system/product targets; null → "#". */
    resolveLink?: (target: LinkTarget) => string | null;
};

type Json = Record<string, unknown>;

const ICON_DEFAULT = 'check';

/* ------------------------------------------------------------------ */
/* Escaping & URLs                                                     */
/* ------------------------------------------------------------------ */

const ESCAPES: Record<string, string> = {
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#039;',
};

/** Same as PHP htmlspecialchars(ENT_QUOTES): & < > " ' only. */
export function escapeHtml(value: unknown): string {
    return String(value ?? '').replace(/[&<>"']/g, (c) => ESCAPES[c]);
}

/** http(s), mailto:, tel:, relative paths and fragments survive; anything else → "#". */
export function safeUrl(url: unknown): string {
    const value = String(url ?? '').trim();

    if (value === '') {
        return '#';
    }

    return /^(https?:\/\/|mailto:|tel:|\/(?!\/)|#|\?)/i.test(value) ? value : '#';
}

function resolveHref(target: Partial<LinkTarget> | undefined, context: RenderContext): string {
    const kind = target?.kind ?? 'url';
    const value = target?.value ?? null;

    if (kind === 'url') {
        return safeUrl(value);
    }

    const resolved = context.resolveLink ? context.resolveLink({ kind, value }) : null;

    return resolved !== null && resolved !== undefined ? safeUrl(resolved) : '#';
}

/* ------------------------------------------------------------------ */
/* Defaults                                                            */
/* ------------------------------------------------------------------ */

function optionValues(field: Field): string[] {
    return (field.options ?? []).map((o) => String(o.value));
}

export function defaultFor(field: Field): unknown {
    const d = field.default as Record<string, unknown> | undefined;

    switch (field.type) {
        case 'text':
        case 'textarea':
            return String(field.default ?? '');
        case 'number':
            return field.default ?? field.constraints?.min ?? 0;
        case 'boolean':
            return Boolean(field.default ?? false);
        case 'select':
            return field.default ?? optionValues(field)[0];
        case 'icon':
            return field.default ?? ICON_DEFAULT;
        case 'color':
        case 'product':
        case 'category':
            return field.default ?? null;
        case 'link': {
            const target = (d?.target ?? {}) as Partial<LinkTarget>;

            return {
                label: String(d?.label ?? ''),
                target: { kind: target.kind ?? 'url', value: target.value ?? null },
            };
        }
        case 'image':
            return { asset_id: d?.asset_id ?? null, url: d?.url ?? null, alt: String(d?.alt ?? '') };
        case 'product_query':
            return {
                source: d?.source ?? 'latest',
                limit: Number(d?.limit ?? 8),
                category: d?.category ?? null,
                ids: d?.ids ?? [],
            };
        case 'repeater':
            return (Array.isArray(field.default) ? field.default : []).map((item) =>
                repeaterItem(field, item as Json),
            );
        default:
            return field.default ?? null;
    }
}

export function repeaterItem(field: Field, values: Json = {}): Json {
    const item: Json = {};

    for (const child of field.fields ?? []) {
        item[child.name] = child.name in values ? values[child.name] : defaultFor(child);
    }

    return item;
}

export function defaults(definition: SectionDefinition): { content: Json; style: Json } {
    const content: Json = {};
    const style: Json = {};

    for (const field of definition.fields) {
        content[field.name] = defaultFor(field);
    }

    for (const field of definition.style) {
        style[field.name] = defaultFor(field);
    }

    return { content, style };
}

/* ------------------------------------------------------------------ */
/* View model                                                          */
/* ------------------------------------------------------------------ */

function fieldsViewModel(fields: Field[], values: Json, context: RenderContext): Json {
    const out: Json = {};

    for (const field of fields) {
        const value = values[field.name] ?? null;

        switch (field.type) {
            case 'select':
                out[field.name] = String(value ?? '');
                for (const option of field.options ?? []) {
                    out[`${field.name}_is_${option.value}`] = String(option.value) === String(value ?? '');
                }
                break;

            case 'link': {
                const link = (value ?? {}) as { label?: string; target?: LinkTarget };
                const target = link.target ?? { kind: 'url', value: null };
                const href = resolveHref(target, context);
                out[field.name] = {
                    label: String(link.label ?? ''),
                    href,
                    is_external: /^https?:\/\//i.test(href) && (target.kind ?? 'url') === 'url',
                };
                break;
            }

            case 'image': {
                const image = (value ?? {}) as { url?: string | null; alt?: string };
                out[field.name] = {
                    url: image.url ? safeUrl(image.url) : '',
                    alt: String(image.alt ?? ''),
                };
                break;
            }

            case 'repeater': {
                const items = Array.isArray(value) ? (value as Json[]) : [];
                out[field.name] = items.map((item, i) => ({
                    ...fieldsViewModel(field.fields ?? [], item ?? {}, context),
                    _index: i + 1,
                    _first: i === 0,
                    _last: i === items.length - 1,
                    _even: i % 2 === 1,
                }));
                break;
            }

            case 'text':
            case 'textarea':
            case 'icon':
                out[field.name] = String(value ?? '');
                break;

            default:
                out[field.name] = value;
        }
    }

    return out;
}

export function siteViewModel(site: Partial<Site>): Site {
    return {
        name: String(site.name ?? ''),
        lang: String(site.lang ?? 'en'),
        dir: site.dir === 'rtl' ? 'rtl' : 'ltr',
        currency: String(site.currency ?? ''),
        year: Number(site.year ?? new Date().getFullYear()),
    };
}

export function buildViewModel(
    definition: SectionDefinition,
    sectionId: string,
    content: Json,
    style: Json,
    data: Json,
    context: RenderContext,
): Json {
    const d = defaults(definition);

    return {
        section: { id: sectionId, key: definition.key, anchor: `s-${sectionId.toLowerCase()}` },
        site: siteViewModel(context.site),
        content: fieldsViewModel(definition.fields, { ...d.content, ...withoutUndefined(content) }, context),
        style: fieldsViewModel(definition.style, { ...d.style, ...withoutUndefined(style) }, context),
        data,
    };
}

function withoutUndefined(values: Json): Json {
    return Object.fromEntries(Object.entries(values ?? {}).filter(([, v]) => v !== undefined));
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

export function render(definition: SectionDefinition, viewModel: Json): string {
    return Mustache.render(definition.template, viewModel, undefined, { escape: escapeHtml });
}

export function wrap(html: string, site: Pick<Site, 'dir' | 'lang'>, extraAttributes = ''): string {
    return `<div class="aisg" dir="${escapeHtml(site.dir)}" lang="${escapeHtml(site.lang)}"${extraAttributes ? ` ${extraAttributes}` : ''}>${html}</div>`;
}

/* ------------------------------------------------------------------ */
/* Data resolvers (preview)                                            */
/* ------------------------------------------------------------------ */

/** Catalog as previews see it (App\Domain\Preview\CatalogData). */
export type SampleCatalog = {
    products: Json[];
    categories: Json[];
    sample: boolean;
};

export type Menu = {
    items: { label: string; href: string }[];
    home_href: string;
    cart_href: string;
};

type ProductQuery = {
    source?: string;
    limit?: number;
    category?: string | null;
    ids?: string[];
};

/** Mirrors PreviewDataResolver::queryProducts(). */
export function queryProducts(products: Json[], query: ProductQuery): Json[] {
    const source = query.source ?? 'latest';
    const filtered = products.filter((p) => {
        switch (source) {
            case 'featured':
                return Boolean(p.featured);
            case 'on_sale':
                return Boolean(p.on_sale);
            case 'category':
                return ((p.category_refs as string[] | undefined) ?? []).includes(String(query.category ?? ''));
            case 'ids':
                return (query.ids ?? []).includes(String(p.ref));
            default:
                return true;
        }
    });

    return filtered.slice(0, Math.max(1, Math.min(24, Number(query.limit ?? 8))));
}

/** Mirrors PreviewDataResolver::findProduct(). */
export function findProduct(products: Json[], ref: string | null): Json {
    return products.find((p) => ref !== null && p.ref === ref) ?? products[0] ?? {};
}

export function resolveData(
    definition: SectionDefinition,
    content: Json,
    sources: { menu: Menu; catalog: SampleCatalog },
): Json {
    const data: Json = {};

    for (const [key, source] of Object.entries(definition.data ?? {})) {
        const input = source.from_field ? content[source.from_field] : undefined;

        switch (source.resolver) {
            case 'menu':
                data[key] = sources.menu;
                break;
            case 'products':
                data[key] = queryProducts(sources.catalog.products, (input ?? {}) as ProductQuery);
                break;
            case 'product':
                data[key] = findProduct(sources.catalog.products, typeof input === 'string' ? input : null);
                break;
            case 'categories': {
                const limit = typeof input === 'number' || /^d+$/.test(String(input)) ? Number(input) : 4;
                data[key] = sources.catalog.categories.slice(0, Math.max(1, Math.min(8, limit)));
                break;
            }
            default:
                data[key] = [];
        }
    }

    return data;
}

/* ------------------------------------------------------------------ */
/* Design tokens                                                       */
/* ------------------------------------------------------------------ */

export type DesignTokens = {
    colors?: Record<string, string>;
    fonts?: Record<string, { family?: string }>;
    radius?: string;
    spacing?: string;
    container_width?: number;
};

const COLOR_VARIABLES: Record<string, string> = {
    primary: '--e-global-color-primary',
    secondary: '--e-global-color-secondary',
    text: '--e-global-color-text',
    accent: '--e-global-color-accent',
    primary_contrast: '--e-global-color-aisgprimarycontrast',
    background: '--e-global-color-aisgbackground',
    surface: '--e-global-color-aisgsurface',
    muted: '--e-global-color-aisgmuted',
    border: '--e-global-color-aisgborder',
};

export const RADIUS: Record<string, string> = { none: '0px', sm: '0.25rem', md: '0.5rem', lg: '1rem', full: '1.5rem' };
export const SPACING: Record<string, string> = { compact: '3rem', normal: '5rem', airy: '7rem' };

export function designTokenVariables(tokens: DesignTokens): Record<string, string> {
    const vars: Record<string, string> = {};

    for (const [token, variable] of Object.entries(COLOR_VARIABLES)) {
        const value = tokens.colors?.[token];

        if (typeof value === 'string' && /^#[0-9a-fA-F]{6}$/.test(value)) {
            vars[variable] = value.toLowerCase();
        }
    }

    for (const [token, slot] of [['heading', 'primary'], ['body', 'text']] as const) {
        const family = tokens.fonts?.[token]?.family;

        if (typeof family === 'string' && /^[A-Za-z0-9 -]+$/.test(family)) {
            vars[`--e-global-typography-${slot}-font-family`] = `"${family}"`;
        }
    }

    vars['--aisg-token-radius'] = RADIUS[tokens.radius ?? 'md'] ?? RADIUS.md;
    vars['--aisg-token-space-section'] = SPACING[tokens.spacing ?? 'normal'] ?? SPACING.normal;
    vars['--aisg-token-container'] = `${Math.max(640, Math.min(1920, Math.trunc(Number(tokens.container_width ?? 1200))))}px`;

    return vars;
}

export function designTokensCss(tokens: DesignTokens, selector = ':root'): string {
    const body = Object.entries(designTokenVariables(tokens))
        .map(([name, value]) => `${name}:${value};`)
        .join('');

    return `${selector}{${body}}`;
}
