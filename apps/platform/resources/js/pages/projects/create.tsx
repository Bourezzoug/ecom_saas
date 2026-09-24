import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import ProjectForm from '@/components/project-form';
import { create, index, store } from '@/routes/projects';
import type { ProjectFormOptions } from '@/types';

export default function ProjectsCreate(props: ProjectFormOptions) {
    const teamSlug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title="New project" />

            <div className="mx-auto w-full max-w-3xl p-4">
                <Heading
                    title="New project"
                    description="Tell us about the store. You can change everything later."
                />
                <ProjectForm
                    {...props}
                    action={store.form(teamSlug)}
                    submitLabel="Create project"
                />
            </div>
        </>
    );
}

ProjectsCreate.layout = (props: { currentTeam?: { slug: string } | null }) => {
    const slug = props.currentTeam?.slug ?? '';

    return {
        breadcrumbs: [
            { title: 'Projects', href: index(slug) },
            { title: 'New', href: create(slug) },
        ],
    };
};
