import { Head, Link, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    AlertCircle,
    ArrowLeft,
    Check,
    ExternalLink,
    Loader2,
    Monitor,
    Smartphone,
    Tablet,
} from 'lucide-react';
import { useCallback, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/native-select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import DesignPanel from '@/editor/design-panel';
import PreviewFrame, { type Device } from '@/editor/preview-frame';
import SectionList from '@/editor/section-list';
import SectionPanel from '@/editor/section-panel';
import type { EditorProps } from '@/editor/types';
import { useEditor } from '@/editor/use-editor';
import VersionsSheet from '@/editor/versions-sheet';
import { cn } from '@/lib/utils';
import editorRoutes from '@/routes/editor';
import { preview, show } from '@/routes/projects';

type Tab = 'sections' | 'design';

export default function ProjectEditor(props: EditorProps) {
    const shared = usePage().props;
    const teamSlug = shared.currentTeam?.slug ?? '';
    const credits = Number(shared.credits ?? 0);
    const editor = useEditor(props, teamSlug);
    const [tab, setTab] = useState<Tab>('sections');
    const [device, setDevice] = useState<Device>('desktop');

    const args = { current_team: teamSlug, project: props.project.id };
    const assetUrl = useCallback(
        (file: string) => props.assetsUrl.replace('__FILE__', file),
        [props.assetsUrl],
    );

    // AI rewrites finish in the queue: refresh the section when Reverb says so.
    useEcho(
        `projects.${props.project.id}`,
        '.generation.progressed',
        (event: {
            stage?: string;
            sectionId?: string;
            sectionStatus?: string;
        }) => {
            if (event.stage !== 'section' || !event.sectionId) {
                return;
            }

            void editor.refreshSection(event.sectionId).then(() => {
                if (event.sectionStatus === 'failed') {
                    toast.error(
                        'The AI rewrite failed. Your text is unchanged and the credits were refunded.',
                    );
                } else {
                    toast.success('Section rewritten.');
                }
            });
        },
    );

    const pages = editor.doc.pages.filter((p) => p.kind === 'page');
    const onSelect = useCallback(
        (id: string) => {
            editor.setSelectedId(id);
            setTab('sections');
        },
        [editor],
    );
    const onNavigate = useCallback(
        (pageId: string) => {
            editor.setPageId(pageId);
            editor.setSelectedId(null);
        },
        [editor],
    );

    if (!editor.page) {
        return null;
    }

    return (
        <>
            <Head title={`Editor · ${props.project.name}`} />

            <div className="flex h-dvh flex-col bg-background">
                {/* Top bar */}
                <header className="flex h-14 shrink-0 items-center gap-3 border-b px-3">
                    <Button variant="ghost" size="icon" asChild>
                        <Link href={show(args)} aria-label="Back to project">
                            <ArrowLeft />
                        </Link>
                    </Button>
                    <span className="hidden truncate font-semibold md:block">
                        {props.project.name}
                    </span>

                    <NativeSelect
                        aria-label="Page"
                        className="w-44"
                        value={editor.pageId}
                        onChange={(e) => onNavigate(e.target.value)}
                        data-test="page-select"
                    >
                        {pages.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.title}
                            </option>
                        ))}
                    </NativeSelect>

                    <div className="ms-auto flex items-center gap-2">
                        <span
                            className="flex items-center gap-1.5 text-xs text-muted-foreground"
                            data-test="save-state"
                            data-state={editor.saveState}
                        >
                            {editor.saveState === 'saving' ? (
                                <>
                                    <Loader2 className="size-3.5 animate-spin" />{' '}
                                    Saving…
                                </>
                            ) : editor.saveState === 'error' ? (
                                <>
                                    <AlertCircle className="size-3.5 text-destructive" />{' '}
                                    Not saved
                                </>
                            ) : (
                                <>
                                    <Check className="size-3.5" /> Saved
                                </>
                            )}
                        </span>

                        <ToggleGroup
                            type="single"
                            size="sm"
                            value={device}
                            onValueChange={(v) => v && setDevice(v as Device)}
                        >
                            <ToggleGroupItem
                                value="desktop"
                                aria-label="Desktop"
                            >
                                <Monitor />
                            </ToggleGroupItem>
                            <ToggleGroupItem value="tablet" aria-label="Tablet">
                                <Tablet />
                            </ToggleGroupItem>
                            <ToggleGroupItem value="mobile" aria-label="Mobile">
                                <Smartphone />
                            </ToggleGroupItem>
                        </ToggleGroup>

                        <VersionsSheet editor={editor} />

                        <Button variant="outline" size="sm" asChild>
                            <a
                                href={preview.url({
                                    ...args,
                                    page: editor.pageId,
                                })}
                                target="_blank"
                                rel="noreferrer"
                            >
                                <ExternalLink /> Preview
                            </a>
                        </Button>
                    </div>
                </header>

                <div className="flex min-h-0 flex-1">
                    {/* Left: structure & design */}
                    <aside className="flex w-72 shrink-0 flex-col border-e">
                        <div className="grid grid-cols-2 border-b p-1">
                            {(['sections', 'design'] as const).map((t) => (
                                <button
                                    key={t}
                                    type="button"
                                    onClick={() => setTab(t)}
                                    className={cn(
                                        'rounded-md py-1.5 text-sm font-medium capitalize',
                                        tab === t
                                            ? 'bg-accent'
                                            : 'text-muted-foreground',
                                    )}
                                >
                                    {t}
                                </button>
                            ))}
                        </div>
                        <div className="min-h-0 flex-1 overflow-y-auto">
                            {tab === 'sections' ? (
                                <div className="flex flex-col gap-3 p-3">
                                    <Input
                                        key={editor.page.id}
                                        aria-label="Page title"
                                        defaultValue={editor.page.title}
                                        maxLength={60}
                                        onBlur={(e) => {
                                            const title = e.target.value.trim();
                                            if (
                                                title &&
                                                title !== editor.page?.title
                                            ) {
                                                void editor.renamePage(
                                                    editor.page!.id,
                                                    title,
                                                );
                                            }
                                        }}
                                    />
                                    <SectionList editor={editor} />
                                </div>
                            ) : (
                                <DesignPanel
                                    editor={editor}
                                    fonts={props.fonts}
                                />
                            )}
                        </div>
                    </aside>

                    {/* Centre: live preview */}
                    <main className="min-w-0 flex-1">
                        <PreviewFrame
                            project={props.project}
                            pages={editor.doc.pages}
                            page={editor.page}
                            header={editor.header}
                            footer={editor.footer}
                            tokens={editor.doc.tokens}
                            library={editor.library}
                            catalog={props.catalog}
                            assetUrl={assetUrl}
                            device={device}
                            selectedId={editor.selectedId}
                            onSelect={onSelect}
                            onNavigate={onNavigate}
                        />
                    </main>

                    {/* Right: selected section */}
                    <aside className="w-80 shrink-0 overflow-x-hidden overflow-y-auto border-s">
                        <SectionPanel
                            editor={editor}
                            credits={credits}
                            regenerateCost={props.regenerateCost}
                            context={{
                                pages: editor.doc.pages,
                                icons: props.icons,
                                products: props.catalog.sample
                                    ? []
                                    : props.catalog.products.map((p) => ({
                                          ref: String(p.ref),
                                          name: String(p.name),
                                      })),
                                categories: props.catalog.sample
                                    ? []
                                    : props.catalog.categories.map((c) => ({
                                          ref: String(c.ref),
                                          name: String(c.name),
                                      })),
                                uploadUrl: editorRoutes.assets.store.url(args),
                            }}
                        />
                    </aside>
                </div>
            </div>
        </>
    );
}
