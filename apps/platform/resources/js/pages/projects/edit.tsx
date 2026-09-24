import { Head, usePage } from '@inertiajs/react';
import Heading from '@/components/heading';
import ProjectForm from '@/components/project-form';
import { edit, index, show, update } from '@/routes/projects';
import type { ProjectDetail, ProjectFormOptions } from '@/types';

type Props = ProjectFormOptions & {
    project: ProjectDetail;
};

export default function ProjectsEdit({ project, ...options }: Props) {
    const teamSlug = usePage().props.currentTeam?.slug ?? '';

    return (
        <>
            <Head title={`Edit ${project.name}`} />

            <div className="mx-auto w-full max-w-3xl p-4">
                <Heading
                    title="Project settings"
                    description="Update the brief used to generate this store."
                />
                <ProjectForm
                    {...options}
                    project={project}
                    action={update.form({
                        current_team: teamSlug,
                        project: project.id,
                    })}
                    submitLabel="Save changes"
                />
            </div>
        </>
    );
}

ProjectsEdit.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: ProjectDetail;
}) => {
    const slug = props.currentTeam?.slug ?? '';
    const args = { current_team: slug, project: props.project.id };

    return {
        breadcrumbs: [
            { title: 'Projects', href: index(slug) },
            { title: props.project.name, href: show(args) },
            { title: 'Settings', href: edit(args) },
        ],
    };
};
