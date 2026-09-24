import { Form } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { destroy } from '@/routes/projects';
import type { ProjectDetail } from '@/types';

type Props = PropsWithChildren<{
    teamSlug: string;
    project: ProjectDetail;
}>;

export default function DeleteProjectModal({
    teamSlug,
    project,
    children,
}: Props) {
    return (
        <Dialog>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent>
                <Form
                    {...destroy.form({
                        current_team: teamSlug,
                        project: project.id,
                    })}
                    className="space-y-6"
                >
                    {({ processing }) => (
                        <>
                            <DialogHeader>
                                <DialogTitle>
                                    Delete &ldquo;{project.name}&rdquo;?
                                </DialogTitle>
                                <DialogDescription>
                                    The project and its generated content will
                                    be removed from your workspace. Sites
                                    already published to WordPress are not
                                    affected.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    Delete project
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
