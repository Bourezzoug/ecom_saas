import { router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    CheckCircle2,
    CircleDashed,
    Loader2,
    Sparkles,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { generate } from '@/routes/projects';
import type { GenerationState, SectionGenerationStatus } from '@/types';

type Props = {
    teamSlug: string;
    projectId: string;
    generation: GenerationState;
    cost: number;
    canGenerate: boolean;
};

const POLL_MS = 4000;

function StatusIcon({ status }: { status: SectionGenerationStatus }) {
    switch (status) {
        case 'ready':
            return <CheckCircle2 className="size-4 text-emerald-600" />;
        case 'failed':
            return <XCircle className="size-4 text-destructive" />;
        case 'generating':
            return <Loader2 className="size-4 animate-spin text-primary" />;
        default:
            return <CircleDashed className="size-4 text-muted-foreground" />;
    }
}

function reloadProgress() {
    router.reload({ only: ['generation', 'project', 'credits'] });
}

export default function GenerationPanel({
    teamSlug,
    projectId,
    generation,
    cost,
    canGenerate,
}: Props) {
    const credits = usePage().props.credits ?? 0;
    const running =
        generation.stage === 'planning' || generation.stage === 'writing';
    const hasContent = generation.total > 0;
    const done = generation.ready + generation.failed;
    const percent =
        generation.total > 0 ? Math.round((done / generation.total) * 100) : 5;

    // Realtime push from Reverb...
    useEcho(`projects.${projectId}`, '.generation.progressed', reloadProgress);

    // ...with polling as a fallback while a generation runs.
    useEffect(() => {
        if (!running) {
            return;
        }

        const timer = window.setInterval(reloadProgress, POLL_MS);

        return () => window.clearInterval(timer);
    }, [running]);

    const [processing, setProcessing] = useState(false);
    const error = (usePage().props.errors as Record<string, string>)
        ?.generation;
    const disabled = !canGenerate || running || processing || credits < cost;

    const start = () =>
        router.post(
            generate.url({ current_team: teamSlug, project: projectId }),
            {},
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    const label = (
        <>
            <Sparkles />
            {hasContent ? 'Regenerate store' : 'Generate store'} · {cost}{' '}
            credits
        </>
    );

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <Sparkles className="size-4" /> AI generation
                </CardTitle>
                <CardDescription>
                    {running
                        ? generation.stage === 'planning'
                            ? 'Planning pages and design…'
                            : `Writing sections: ${done} of ${generation.total}`
                        : generation.stage === 'failed'
                          ? 'The last generation failed. Your credits were refunded.'
                          : generation.stage === 'done'
                            ? generation.failed > 0
                                ? `Done. ${generation.failed} section(s) kept their default text.`
                                : 'Your store has been generated.'
                            : 'Plan the pages, design and copy of your store with AI.'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                {running ? (
                    <div
                        className="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-valuenow={percent}
                        aria-valuemin={0}
                        aria-valuemax={100}
                    >
                        <div
                            className="h-full bg-primary transition-all duration-500"
                            style={{ width: `${percent}%` }}
                        />
                    </div>
                ) : null}

                {hasContent ? (
                    <ul className="space-y-3 text-sm">
                        {generation.pages.map((page) => (
                            <li key={page.id}>
                                <p className="font-medium">
                                    {page.kind === 'page'
                                        ? page.title
                                        : page.kind === 'header'
                                          ? 'Header'
                                          : 'Footer'}
                                </p>
                                <ul className="mt-1 space-y-1 ps-1">
                                    {page.sections.map((section) => (
                                        <li
                                            key={section.id}
                                            className="flex items-center gap-2 text-muted-foreground"
                                            data-test="section-status"
                                            data-status={section.status}
                                        >
                                            <StatusIcon
                                                status={section.status}
                                            />
                                            {section.name}
                                        </li>
                                    ))}
                                </ul>
                            </li>
                        ))}
                    </ul>
                ) : null}

                {hasContent ? (
                    <Dialog>
                        <DialogTrigger asChild>
                            <Button
                                type="button"
                                className="w-full"
                                disabled={disabled}
                                data-test="generate-button"
                            >
                                {label}
                            </Button>
                        </DialogTrigger>
                        <DialogContent>
                            <DialogHeader>
                                <DialogTitle>
                                    Regenerate the whole store?
                                </DialogTitle>
                                <DialogDescription>
                                    All pages and texts are replaced by a new AI
                                    draft. This costs {cost} credits.
                                </DialogDescription>
                            </DialogHeader>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">Cancel</Button>
                                </DialogClose>
                                <DialogClose asChild>
                                    <Button onClick={start}>Regenerate</Button>
                                </DialogClose>
                            </DialogFooter>
                        </DialogContent>
                    </Dialog>
                ) : (
                    <Button
                        type="button"
                        className="w-full"
                        disabled={disabled}
                        onClick={start}
                        data-test="generate-button"
                    >
                        {label}
                    </Button>
                )}
                <InputError message={error} />

                {credits < cost && !running ? (
                    <p className="text-sm text-muted-foreground">
                        You have {credits} credits; this needs {cost}.
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );
}
