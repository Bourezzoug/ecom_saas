import { Head, Link, usePage } from '@inertiajs/react';
import { Package, Pencil, PenTool, Send, Trash2 } from 'lucide-react';
import DeleteProjectModal from '@/components/delete-project-modal';
import GenerationPanel from '@/components/generation-panel';
import ProjectStatusBadge from '@/components/project-status-badge';
import SitePreview from '@/components/site-preview';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import catalogRoutes from '@/routes/catalog';
import editorRoutes from '@/routes/editor';
import publishing from '@/routes/publishing';
import { edit, index, show } from '@/routes/projects';
import type { GenerationState, ProjectDetail } from '@/types';

type Props = {
    project: ProjectDetail;
    generation: GenerationState;
    generateCost: number;
    can: { update: boolean; delete: boolean };
};

function Detail({ label, value }: { label: string; value: React.ReactNode }) {
    return (
        <div className="grid gap-1">
            <dt className="text-xs font-medium tracking-wide text-muted-foreground uppercase">
                {label}
            </dt>
            <dd className="text-sm">{value || '—'}</dd>
        </div>
    );
}

export default function ProjectsShow({
    project,
    generation,
    generateCost,
    can,
}: Props) {
    const teamSlug = usePage().props.currentTeam?.slug ?? '';
    const args = { current_team: teamSlug, project: project.id };

    return (
        <>
            <Head title={project.name} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-3">
                            <h1 className="text-xl font-semibold tracking-tight">
                                {project.name}
                            </h1>
                            <ProjectStatusBadge
                                status={project.status}
                                label={project.statusLabel}
                            />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {project.brief.niche}
                        </p>
                    </div>

                    <div className="flex gap-2">
                        {can.update &&
                        generation.total > 0 &&
                        generation.stage !== 'planning' &&
                        generation.stage !== 'writing' ? (
                            <Button asChild data-test="open-editor">
                                <Link href={editorRoutes.show(args)}>
                                    <PenTool /> Open editor
                                </Link>
                            </Button>
                        ) : null}
                        {generation.total > 0 ? (
                            <Button variant="outline" asChild>
                                <Link href={publishing.show(args)}>
                                    <Send /> Publish
                                </Link>
                            </Button>
                        ) : null}
                        <Button variant="outline" asChild>
                            <Link href={catalogRoutes.index(args)}>
                                <Package /> Catalog
                            </Link>
                        </Button>
                        {can.update ? (
                            <Button variant="outline" asChild>
                                <Link href={edit(args)}>
                                    <Pencil /> Settings
                                </Link>
                            </Button>
                        ) : null}
                        {can.delete ? (
                            <DeleteProjectModal
                                teamSlug={teamSlug}
                                project={project}
                            >
                                <Button variant="outline">
                                    <Trash2 /> Delete
                                </Button>
                            </DeleteProjectModal>
                        ) : null}
                    </div>
                </div>

                <div className="grid gap-6 lg:grid-cols-3">
                    <Card className="lg:col-span-2">
                        <CardHeader>
                            <CardTitle>Brief</CardTitle>
                            <CardDescription>
                                What the AI will use to plan and write your
                                store.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-5 sm:grid-cols-2">
                                <Detail
                                    label="Audience"
                                    value={project.brief.audience}
                                />
                                <Detail
                                    label="Tone"
                                    value={
                                        project.brief.tone
                                            ? project.brief.tone
                                                  .charAt(0)
                                                  .toUpperCase() +
                                              project.brief.tone.slice(1)
                                            : null
                                    }
                                />
                                <Detail
                                    label="Language"
                                    value={`${project.language.toUpperCase()} (${project.direction.toUpperCase()})`}
                                />
                                <Detail
                                    label="Currency"
                                    value={project.currency}
                                />
                                <div className="sm:col-span-2">
                                    <Detail
                                        label="Style"
                                        value={project.brief.style}
                                    />
                                </div>
                                <div className="sm:col-span-2">
                                    <Detail
                                        label="Brand colours"
                                        value={
                                            project.brief.brand_colors
                                                .length ? (
                                                <span className="flex gap-2">
                                                    {project.brief.brand_colors.map(
                                                        (color) => (
                                                            <span
                                                                key={color}
                                                                className="flex items-center gap-1 font-mono text-xs"
                                                            >
                                                                <span
                                                                    className="size-4 rounded border"
                                                                    style={{
                                                                        backgroundColor:
                                                                            color,
                                                                    }}
                                                                />
                                                                {color}
                                                            </span>
                                                        ),
                                                    )}
                                                </span>
                                            ) : null
                                        }
                                    />
                                </div>
                            </dl>
                        </CardContent>
                    </Card>

                    <GenerationPanel
                        teamSlug={teamSlug}
                        projectId={project.id}
                        generation={generation}
                        cost={generateCost}
                        canGenerate={can.update}
                    />
                </div>

                {generation.total > 0 && generation.stage !== 'planning' ? (
                    <section className="space-y-3">
                        <h2 className="text-lg font-semibold">Preview</h2>
                        <SitePreview
                            teamSlug={teamSlug}
                            projectId={project.id}
                            pages={generation.pages}
                            version={`${generation.ready}-${generation.failed}-${generation.status}`}
                        />
                    </section>
                ) : null}
            </div>
        </>
    );
}

ProjectsShow.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: ProjectDetail;
}) => {
    const slug = props.currentTeam?.slug ?? '';

    return {
        breadcrumbs: [
            { title: 'Projects', href: index(slug) },
            {
                title: props.project.name,
                href: show({ current_team: slug, project: props.project.id }),
            },
        ],
    };
};
