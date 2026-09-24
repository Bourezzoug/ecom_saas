import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import editorRoutes from '@/routes/editor';
import { ApiError, api } from './api';
import type {
    EditorDocument,
    EditorPage,
    EditorProps,
    EditorSection,
    FullTokens,
    SaveState,
    SectionDefinition,
    VersionSummary,
} from './types';

const SAVE_DEBOUNCE_MS = 700;

type SectionPatch = {
    content?: Record<string, unknown>;
    style?: Record<string, unknown>;
};

export type DesignInput = {
    colors: {
        primary: string;
        secondary: string;
        accent: string;
        background: string;
        text: string;
    };
    fonts: { heading: string; body: string };
    radius: string;
    spacing: string;
};

/**
 * Editor state: the document lives locally (instant preview), writes are
 * autosaved per section (debounced) with optimistic locking. Structural
 * operations (add/remove/reorder) hit the server immediately.
 */
export function useEditor(props: EditorProps, teamSlug: string) {
    const [doc, setDoc] = useState<EditorDocument>(props.document);
    const [versions, setVersions] = useState<VersionSummary[]>(props.versions);
    const [pageId, setPageId] = useState<string>(
        () =>
            props.document.pages.find((p) => p.isHomepage)?.id ??
            props.document.pages.find((p) => p.kind === 'page')?.id ??
            '',
    );
    const [selectedId, setSelectedId] = useState<string | null>(null);
    const [pending, setPending] = useState(0);
    const [failed, setFailed] = useState(false);
    const [dirty, setDirty] = useState(0);
    const [errors, setErrors] = useState<
        Record<string, Record<string, string>>
    >({});

    const docRef = useRef(doc);
    docRef.current = doc;
    const timers = useRef(new Map<string, number>());
    const queued = useRef(new Map<string, SectionPatch>());
    const tokenTimer = useRef<number | null>(null);

    const args = { current_team: teamSlug, project: props.project.id };

    const library = useMemo(
        () =>
            Object.fromEntries(props.library.map((d) => [d.key, d])) as Record<
                string,
                SectionDefinition
            >,
        [props.library],
    );

    const track = useCallback(async <T>(work: Promise<T>): Promise<T> => {
        setPending((n) => n + 1);

        try {
            const result = await work;
            setFailed(false);

            return result;
        } catch (error) {
            setFailed(true);
            throw error;
        } finally {
            setPending((n) => n - 1);
        }
    }, []);

    /* ---------------- document helpers ---------------- */

    const findSection = useCallback(
        (id: string): EditorSection | undefined =>
            docRef.current.pages
                .flatMap((p) => p.sections)
                .find((s) => s.id === id),
        [],
    );

    const replaceSection = useCallback((section: EditorSection) => {
        setDoc((d) => ({
            ...d,
            pages: d.pages.map((p) => ({
                ...p,
                sections: p.sections.map((s) =>
                    s.id === section.id ? section : s,
                ),
            })),
        }));
    }, []);

    const mapPage = useCallback(
        (id: string, fn: (page: EditorPage) => EditorPage) =>
            setDoc((d) => ({
                ...d,
                pages: d.pages.map((p) => (p.id === id ? fn(p) : p)),
            })),
        [],
    );

    /* ---------------- section edits (autosave) ---------------- */

    const flush = useCallback(
        async (id: string) => {
            const timer = timers.current.get(id);
            if (timer) {
                window.clearTimeout(timer);
                timers.current.delete(id);
            }

            const patch = queued.current.get(id);
            const section = findSection(id);
            queued.current.delete(id);
            setDirty(queued.current.size);

            if (!patch || !section) {
                return;
            }

            try {
                const { section: saved } = await track(
                    api<{ section: EditorSection }>(
                        'PATCH',
                        editorRoutes.sections.update.url({
                            ...args,
                            section: id,
                        }),
                        { ...patch, lockVersion: section.lockVersion },
                    ),
                );

                // Keep local edits typed while the request was in flight.
                const latest = findSection(id);
                replaceSection({
                    ...saved,
                    content: latest?.content ?? saved.content,
                    style: latest?.style ?? saved.style,
                    lockVersion: saved.lockVersion,
                });
                setErrors((e) => ({ ...e, [id]: {} }));
            } catch (error) {
                if (error instanceof ApiError && error.status === 409) {
                    const current = error.body.section as
                        | EditorSection
                        | undefined;
                    if (current) {
                        replaceSection(current);
                    }
                    toast.warning(
                        'This section was changed elsewhere. Showing the latest version.',
                    );
                } else if (error instanceof ApiError && error.status === 422) {
                    setErrors((e) => ({
                        ...e,
                        [id]: Object.fromEntries(
                            Object.entries(error.errors).map(([k, v]) => [
                                k,
                                v[0],
                            ]),
                        ),
                    }));
                } else {
                    toast.error('Could not save your changes. Retrying…');
                    queued.current.set(id, patch);
                    timers.current.set(
                        id,
                        window.setTimeout(() => void flush(id), 3000),
                    );
                }
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [findSection, replaceSection, track],
    );

    const updateSection = useCallback(
        (id: string, patch: SectionPatch) => {
            const section = findSection(id);
            if (!section || section.status === 'generating') {
                return;
            }

            const next = {
                ...section,
                content: patch.content ?? section.content,
                style: patch.style ?? section.style,
            };
            replaceSection(next);

            queued.current.set(id, {
                ...queued.current.get(id),
                ...(patch.content ? { content: next.content } : {}),
                ...(patch.style ? { style: next.style } : {}),
            });
            setDirty(queued.current.size);

            const timer = timers.current.get(id);
            if (timer) {
                window.clearTimeout(timer);
            }
            timers.current.set(
                id,
                window.setTimeout(() => void flush(id), SAVE_DEBOUNCE_MS),
            );
        },
        [findSection, replaceSection, flush],
    );

    // Save everything before the tab closes.
    useEffect(() => {
        const handler = (event: BeforeUnloadEvent) => {
            if (queued.current.size > 0) {
                queued.current.forEach((_, id) => void flush(id));
                event.preventDefault();
            }
        };
        window.addEventListener('beforeunload', handler);

        return () => window.removeEventListener('beforeunload', handler);
    }, [flush]);

    /* ---------------- structure ---------------- */

    const addSection = useCallback(
        async (key: string, position: number) => {
            try {
                const { section } = await track(
                    api<{ section: EditorSection }>(
                        'POST',
                        editorRoutes.pages.sections.store.url({
                            ...args,
                            page: pageId,
                        }),
                        { key, position },
                    ),
                );
                mapPage(pageId, (p) => {
                    const sections = [...p.sections];
                    sections.splice(position, 0, section);

                    return {
                        ...p,
                        sections: sections.map((s, i) => ({
                            ...s,
                            position: i,
                        })),
                    };
                });
                setSelectedId(section.id);
            } catch (error) {
                toast.error(
                    error instanceof ApiError
                        ? error.firstError()
                        : 'Could not add the section.',
                );
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [pageId, mapPage, track],
    );

    const removeSection = useCallback(
        async (id: string) => {
            const page = docRef.current.pages.find((p) =>
                p.sections.some((s) => s.id === id),
            );
            if (!page) {
                return;
            }

            const before = page.sections;
            queued.current.delete(id);
            mapPage(page.id, (p) => ({
                ...p,
                sections: p.sections.filter((s) => s.id !== id),
            }));
            if (selectedId === id) {
                setSelectedId(null);
            }

            try {
                await track(
                    api(
                        'DELETE',
                        editorRoutes.sections.destroy.url({
                            ...args,
                            section: id,
                        }),
                    ),
                );
            } catch (error) {
                mapPage(page.id, (p) => ({ ...p, sections: before }));
                toast.error(
                    error instanceof ApiError
                        ? error.firstError()
                        : 'Could not remove the section.',
                );
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [mapPage, selectedId, track],
    );

    const reorder = useCallback(
        async (targetPageId: string, ids: string[]) => {
            const page = docRef.current.pages.find(
                (p) => p.id === targetPageId,
            );
            if (!page) {
                return;
            }

            const before = page.sections;
            const byId = new Map(before.map((s) => [s.id, s]));
            mapPage(targetPageId, (p) => ({
                ...p,
                sections: ids.map((id, i) => ({
                    ...(byId.get(id) as EditorSection),
                    position: i,
                })),
            }));

            try {
                await track(
                    api(
                        'PUT',
                        editorRoutes.pages.sections.reorder.url({
                            ...args,
                            page: targetPageId,
                        }),
                        { ids },
                    ),
                );
            } catch {
                mapPage(targetPageId, (p) => ({ ...p, sections: before }));
                toast.error('Could not reorder the sections.');
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [mapPage, track],
    );

    const renamePage = useCallback(
        async (id: string, title: string) => {
            mapPage(id, (p) => ({ ...p, title }));

            try {
                await track(
                    api(
                        'PATCH',
                        editorRoutes.pages.update.url({ ...args, page: id }),
                        { title },
                    ),
                );
            } catch (error) {
                toast.error(
                    error instanceof ApiError
                        ? error.firstError()
                        : 'Could not rename the page.',
                );
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [mapPage, track],
    );

    /* ---------------- design tokens ---------------- */

    const updateDesign = useCallback(
        (input: DesignInput) => {
            // Optimistic: the preview reacts immediately; derived colours come back from the server.
            setDoc((d) => ({
                ...d,
                tokens: {
                    ...(d.tokens as FullTokens),
                    colors: { ...d.tokens?.colors, ...input.colors },
                    fonts: {
                        heading: { family: input.fonts.heading },
                        body: { family: input.fonts.body },
                    },
                    radius: input.radius,
                    spacing: input.spacing,
                },
            }));

            if (tokenTimer.current) {
                window.clearTimeout(tokenTimer.current);
            }
            tokenTimer.current = window.setTimeout(async () => {
                try {
                    const { tokens } = await track(
                        api<{ tokens: FullTokens }>(
                            'PUT',
                            editorRoutes.design.update.url(args),
                            input,
                        ),
                    );
                    setDoc((d) => ({ ...d, tokens }));
                } catch (error) {
                    toast.error(
                        error instanceof ApiError
                            ? error.firstError()
                            : 'Could not save the design.',
                    );
                }
            }, SAVE_DEBOUNCE_MS);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [track],
    );

    /* ---------------- AI + server refresh ---------------- */

    const refreshSection = useCallback(
        async (id: string) => {
            try {
                const { section } = await api<{ section: EditorSection }>(
                    'GET',
                    editorRoutes.sections.show.url({ ...args, section: id }),
                );
                replaceSection(section);

                return section;
            } catch {
                return undefined;
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [replaceSection],
    );

    const regenerate = useCallback(
        async (id: string, instruction: string) => {
            await flush(id);

            try {
                const { section } = await track(
                    api<{ section: EditorSection; credits: number }>(
                        'POST',
                        editorRoutes.sections.regenerate.url({
                            ...args,
                            section: id,
                        }),
                        { instruction },
                    ),
                );
                replaceSection(section);
                toast.info('Rewriting this section with AI…');
            } catch (error) {
                toast.error(
                    error instanceof ApiError
                        ? error.firstError()
                        : 'Could not start the rewrite.',
                );
            }
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [flush, replaceSection, track],
    );

    /* ---------------- versions ---------------- */

    const saveVersion = useCallback(
        async (label: string) => {
            const { versions: list } = await track(
                api<{ versions: VersionSummary[] }>(
                    'POST',
                    editorRoutes.versions.store.url(args),
                    { label },
                ),
            );
            setVersions(list);
            toast.success('Version saved.');
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [track],
    );

    const restoreVersion = useCallback(
        async (id: number) => {
            await Promise.all([...queued.current.keys()].map((k) => flush(k)));

            const { document, versions: list } = await track(
                api<{ document: EditorDocument; versions: VersionSummary[] }>(
                    'POST',
                    editorRoutes.versions.restore.url({ ...args, version: id }),
                ),
            );
            setDoc(document);
            setVersions(list);
            setSelectedId(null);
            if (!document.pages.some((p) => p.id === pageId)) {
                setPageId(
                    document.pages.find((p) => p.isHomepage)?.id ??
                        document.pages[0]?.id ??
                        '',
                );
            }
            toast.success('Version restored.');
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [flush, pageId, track],
    );

    const reloadVersions = useCallback(async () => {
        const { versions: list } = await api<{ versions: VersionSummary[] }>(
            'GET',
            editorRoutes.versions.index.url(args),
        );
        setVersions(list);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* ---------------- derived ---------------- */

    const page = doc.pages.find((p) => p.id === pageId);
    const header = doc.pages.find((p) => p.kind === 'header');
    const footer = doc.pages.find((p) => p.kind === 'footer');
    const selected = selectedId ? findSection(selectedId) : undefined;
    const saveState: SaveState = failed
        ? 'error'
        : pending > 0 || dirty > 0
          ? 'saving'
          : 'saved';

    return {
        doc,
        library,
        page,
        header,
        footer,
        pageId,
        setPageId,
        selected,
        selectedId,
        setSelectedId,
        saveState,
        errors,
        versions,
        updateSection,
        addSection,
        removeSection,
        reorder,
        renamePage,
        updateDesign,
        refreshSection,
        regenerate,
        saveVersion,
        restoreVersion,
        reloadVersions,
        replaceDocument: setDoc,
    };
}

export type Editor = ReturnType<typeof useEditor>;
