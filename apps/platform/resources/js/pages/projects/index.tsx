import { Head, Link, usePage } from '@inertiajs/react';
import { Plus, Store } from 'lucide-react';
import Heading from '@/components/heading';
import ProjectStatusBadge from '@/components/project-status-badge';
import { Button } from '@/components/ui/button';
import { create, index, show } from '@/routes/projects';
import type { Paginated, ProjectSummary } from '@/types';

type Props = {
    projects: Paginated<ProjectSummary>;
};

export default function ProjectsIndex({ projects }: Props) {
    const teamSlug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="Projects" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <Heading
                        title="Projects"
                        description="Every store you generate lives here"
                    />
                    <Button asChild>
                        <Link href={create(teamSlug)}>
                            <Plus /> New project
                        </Link>
                    </Button>
                </div>

                {projects.data.length === 0 ? (
                    <div className="flex flex-col items-center gap-4 rounded-xl border border-dashed py-16 text-center">
                        <Store className="size-10 text-muted-foreground" />
                        <div>
                            <p className="font-medium">No projects yet</p>
                            <p className="text-sm text-muted-foreground">
                                Describe a store and let AI draft it for you.
                            </p>
                        </div>
                        <Button asChild>
                            <Link href={create(teamSlug)}>
                                <Plus /> Create your first project
                            </Link>
                        </Button>
                    </div>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {projects.data.map((project) => (
                            <Link
                                key={project.id}
                                href={show({
                                    current_team: teamSlug,
                                    project: project.id,
                                })}
                                className="flex flex-col gap-3 rounded-xl border p-5 transition-colors hover:bg-accent"
                                prefetch
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <span className="font-medium">
                                        {project.name}
                                    </span>
                                    <ProjectStatusBadge
                                        status={project.status}
                                        label={project.statusLabel}
                                    />
                                </div>
                                <p className="line-clamp-2 text-sm text-muted-foreground">
                                    {project.niche}
                                </p>
                                <p className="mt-auto text-xs text-muted-foreground">
                                    {project.language.toUpperCase()}
                                    {project.creator
                                        ? ` · by ${project.creator}`
                                        : ''}
                                    {project.updatedAt
                                        ? ` · updated ${new Date(project.updatedAt).toLocaleDateString()}`
                                        : ''}
                                </p>
                            </Link>
                        ))}
                    </div>
                )}

                {projects.last_page > 1 ? (
                    <nav className="flex items-center justify-center gap-3 text-sm">
                        {projects.prev_page_url ? (
                            <Link
                                href={projects.prev_page_url}
                                className="underline-offset-4 hover:underline"
                            >
                                Previous
                            </Link>
                        ) : null}
                        <span className="text-muted-foreground">
                            Page {projects.current_page} of {projects.last_page}
                        </span>
                        {projects.next_page_url ? (
                            <Link
                                href={projects.next_page_url}
                                className="underline-offset-4 hover:underline"
                            >
                                Next
                            </Link>
                        ) : null}
                    </nav>
                ) : null}
            </div>
        </>
    );
}

ProjectsIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Projects',
            href: props.currentTeam ? index(props.currentTeam.slug) : '/',
        },
    ],
});
