import { Head, Link, usePage } from '@inertiajs/react';
import { Coins, Plus, Store } from 'lucide-react';
import { useState } from 'react';
import PendingInvitationsModal from '@/components/pending-invitations-modal';
import ProjectStatusBadge from '@/components/project-status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { dashboard } from '@/routes';
import { create, index, show } from '@/routes/projects';
import type { DashboardInvitation, ProjectSummary } from '@/types';

type Props = {
    pendingInvitations?: DashboardInvitation[];
    projectCount: number;
    recentProjects: Pick<
        ProjectSummary,
        'id' | 'name' | 'status' | 'statusLabel' | 'niche' | 'updatedAt'
    >[];
};

export default function Dashboard({
    pendingInvitations = [],
    projectCount,
    recentProjects,
}: Props) {
    const { currentTeam, credits } = usePage().props;
    const teamSlug = currentTeam?.slug ?? '';
    const [showInvitations, setShowInvitations] = useState(
        pendingInvitations.length > 0,
    );

    return (
        <>
            <Head title="Dashboard" />
            <PendingInvitationsModal
                invitations={pendingInvitations}
                open={pendingInvitations.length > 0 && showInvitations}
                onOpenChange={setShowInvitations}
            />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                <div className="grid gap-4 md:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardDescription className="flex items-center gap-2">
                                <Coins className="size-4" /> AI credits
                            </CardDescription>
                            <CardTitle className="text-3xl">
                                {credits ?? 0}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardDescription className="flex items-center gap-2">
                                <Store className="size-4" /> Projects
                            </CardDescription>
                            <CardTitle className="text-3xl">
                                {projectCount}
                            </CardTitle>
                        </CardHeader>
                    </Card>
                    <Card className="justify-center">
                        <CardContent>
                            <Button asChild className="w-full">
                                <Link href={create(teamSlug)}>
                                    <Plus /> New project
                                </Link>
                            </Button>
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Recent projects</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {recentProjects.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">
                                No projects yet. Create one to get started.
                            </p>
                        ) : (
                            <ul className="divide-y">
                                {recentProjects.map((project) => (
                                    <li key={project.id}>
                                        <Link
                                            href={show({
                                                current_team: teamSlug,
                                                project: project.id,
                                            })}
                                            className="flex items-center justify-between gap-4 py-3 hover:underline"
                                        >
                                            <span>
                                                <span className="font-medium">
                                                    {project.name}
                                                </span>
                                                <span className="block text-sm text-muted-foreground">
                                                    {project.niche}
                                                </span>
                                            </span>
                                            <ProjectStatusBadge
                                                status={project.status}
                                                label={project.statusLabel}
                                            />
                                        </Link>
                                    </li>
                                ))}
                            </ul>
                        )}
                        {projectCount > recentProjects.length ? (
                            <Link
                                href={index(teamSlug)}
                                className="mt-2 inline-block text-sm text-muted-foreground hover:underline"
                            >
                                View all {projectCount} projects
                            </Link>
                        ) : null}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: props.currentTeam ? dashboard(props.currentTeam.slug) : '/',
        },
    ],
});
