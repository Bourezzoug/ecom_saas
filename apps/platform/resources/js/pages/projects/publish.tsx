import { Head, router, usePage } from '@inertiajs/react';
import { useEcho } from '@laravel/echo-react';
import {
    AlertTriangle,
    CheckCircle2,
    CircleDashed,
    Copy,
    Download,
    Globe,
    Loader2,
    PlugZap,
    RefreshCw,
    Send,
    Unplug,
    XCircle,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import publishing from '@/routes/publishing';
import { index as projectsIndex, show } from '@/routes/projects';

type Connection = {
    id: string;
    status: 'pending' | 'connected' | 'error' | 'revoked';
    siteUrl: string | null;
    keyLastFour: string | null;
    versions: Record<string, string | null>;
    warnings: string[];
    lastHealthAt: string | null;
    lastError: string | null;
};

type PublishRun = {
    id: string;
    target: 'push' | 'zip';
    status: 'queued' | 'running' | 'succeeded' | 'partial' | 'failed';
    force: boolean;
    summary: Record<string, number>;
    error: string | null;
    createdAt: string;
    finishedAt: string | null;
    downloadUrl: string | null;
};

type LogItem = {
    id: number;
    type: string;
    label: string | null;
    status: 'pending' | 'ok' | 'failed' | 'conflict' | 'skipped';
    action: string | null;
    error: string | null;
    remoteId: number | null;
};

type Props = {
    project: { id: string; name: string; status: string };
    connection: Connection | null;
    newKey: string | null;
    platformUrl: string;
    publishes: PublishRun[];
    logs: LogItem[];
};

const STATUS_BADGE: Record<
    PublishRun['status'],
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    queued: 'secondary',
    running: 'outline',
    succeeded: 'default',
    partial: 'outline',
    failed: 'destructive',
};

function LogIcon({ status }: { status: LogItem['status'] }) {
    switch (status) {
        case 'ok':
            return <CheckCircle2 className="size-4 text-emerald-600" />;
        case 'failed':
            return <XCircle className="size-4 text-destructive" />;
        case 'conflict':
            return <AlertTriangle className="size-4 text-amber-500" />;
        case 'pending':
            return <Loader2 className="size-4 animate-spin text-primary" />;
        default:
            return <CircleDashed className="size-4 text-muted-foreground" />;
    }
}

export default function Publish({
    project,
    connection,
    newKey,
    platformUrl,
    publishes,
    logs,
}: Props) {
    const page = usePage();
    const teamSlug = page.props.currentTeam?.slug ?? '';
    const errors = page.props.errors as Record<string, string>;
    const args = { current_team: teamSlug, project: project.id };
    const [force, setForce] = useState(false);
    const [busy, setBusy] = useState<string | null>(null);

    const running = publishes.some(
        (p) => p.status === 'queued' || p.status === 'running',
    );
    const connected = connection?.status === 'connected';
    const reload = () =>
        router.reload({ only: ['publishes', 'logs', 'connection'] });

    useEcho(`projects.${project.id}`, '.publish.progressed', reload);

    useEffect(() => {
        if (!running) {
            return;
        }
        const timer = window.setInterval(reload, 2000);

        return () => window.clearInterval(timer);
    }, [running]);

    const post = (
        url: string,
        data: Record<string, string | boolean> = {},
        key = url,
    ) =>
        router.post(url, data, {
            preserveScroll: true,
            onStart: () => setBusy(key),
            onFinish: () => setBusy(null),
        });

    const copy = (text: string) =>
        void navigator.clipboard
            .writeText(text)
            .then(() => toast.success('Copied.'));

    return (
        <>
            <Head title={`Publish · ${project.name}`} />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <Heading
                    title="Publish"
                    description="Send your store to WordPress (Elementor + WooCommerce), or download it as a ZIP."
                />

                <div className="grid gap-6 lg:grid-cols-2">
                    {/* Connection */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Globe className="size-4" /> WordPress site
                            </CardTitle>
                            <CardDescription>
                                {connected
                                    ? 'Connected. Publishing updates the same pages and products every time.'
                                    : 'Install the AISG Connector plugin on your site and connect it with a key.'}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4 text-sm">
                            {newKey ? (
                                <div
                                    className="space-y-3 rounded-lg border border-primary/40 bg-primary/5 p-3"
                                    data-test="connection-key"
                                >
                                    <p className="font-medium">
                                        Your connection key (shown once):
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <code className="flex-1 truncate rounded bg-background px-2 py-1 font-mono text-xs">
                                            {newKey}
                                        </code>
                                        <Button
                                            size="icon"
                                            variant="outline"
                                            onClick={() => copy(newKey)}
                                            aria-label="Copy key"
                                        >
                                            <Copy />
                                        </Button>
                                    </div>
                                    <ol className="list-decimal space-y-1 ps-5 text-muted-foreground">
                                        <li>
                                            <a
                                                className="underline"
                                                href={publishing.plugin.url(
                                                    args,
                                                )}
                                            >
                                                Download the plugin
                                            </a>{' '}
                                            and install it in WordPress (Plugins
                                            › Add New › Upload).
                                        </li>
                                        <li>
                                            Open <strong>AISG Connector</strong>{' '}
                                            in the WordPress menu.
                                        </li>
                                        <li>
                                            Platform URL:{' '}
                                            <button
                                                type="button"
                                                className="font-mono underline"
                                                onClick={() =>
                                                    copy(platformUrl)
                                                }
                                            >
                                                {platformUrl}
                                            </button>
                                            , paste the key, click Connect.
                                        </li>
                                        <li>
                                            Come back here and click “Check
                                            connection”.
                                        </li>
                                    </ol>
                                </div>
                            ) : null}

                            {connection && connection.status !== 'pending' ? (
                                <dl className="grid grid-cols-[8rem_1fr] gap-x-3 gap-y-1">
                                    <dt className="text-muted-foreground">
                                        Site
                                    </dt>
                                    <dd
                                        className="truncate"
                                        data-test="site-url"
                                    >
                                        {connection.siteUrl ?? '—'}
                                    </dd>
                                    <dt className="text-muted-foreground">
                                        Status
                                    </dt>
                                    <dd>
                                        <Badge
                                            variant={
                                                connected
                                                    ? 'default'
                                                    : 'destructive'
                                            }
                                        >
                                            {connection.status}
                                        </Badge>
                                    </dd>
                                    {Object.entries(connection.versions)
                                        .filter(([, v]) => v)
                                        .map(([name, version]) => (
                                            <div
                                                key={name}
                                                className="contents"
                                            >
                                                <dt className="text-muted-foreground capitalize">
                                                    {name}
                                                </dt>
                                                <dd>{version}</dd>
                                            </div>
                                        ))}
                                </dl>
                            ) : connection?.status === 'pending' && !newKey ? (
                                <p className="text-muted-foreground">
                                    Waiting for the plugin to connect with key
                                    ••••{connection.keyLastFour}.
                                </p>
                            ) : null}

                            {connection?.warnings.map((w) => (
                                <p
                                    key={w}
                                    className="flex gap-2 text-amber-700 dark:text-amber-400"
                                >
                                    <AlertTriangle className="size-4 shrink-0" />{' '}
                                    {w}
                                </p>
                            ))}
                            {connection?.lastError ? (
                                <p className="flex gap-2 text-destructive">
                                    <XCircle className="size-4 shrink-0" />{' '}
                                    {connection.lastError}
                                </p>
                            ) : null}

                            <div className="flex flex-wrap gap-2">
                                {connection ? (
                                    <>
                                        <Button
                                            variant="outline"
                                            disabled={busy !== null}
                                            onClick={() =>
                                                post(
                                                    publishing.health.url(args),
                                                    {},
                                                    'health',
                                                )
                                            }
                                            data-test="health-button"
                                        >
                                            {busy === 'health' ? (
                                                <Loader2 className="animate-spin" />
                                            ) : (
                                                <RefreshCw />
                                            )}
                                            Check connection
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() =>
                                                window.confirm(
                                                    'Disconnect this site? Published content stays on WordPress.',
                                                ) &&
                                                router.delete(
                                                    publishing.disconnect.url(
                                                        args,
                                                    ),
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            <Unplug /> Disconnect
                                        </Button>
                                    </>
                                ) : (
                                    <Button
                                        onClick={() =>
                                            post(
                                                publishing.connect.url(args),
                                                {},
                                                'connect',
                                            )
                                        }
                                        disabled={busy !== null}
                                        data-test="connect-button"
                                    >
                                        <PlugZap /> Connect WordPress
                                    </Button>
                                )}
                                {connection &&
                                !newKey &&
                                connection.status === 'pending' ? (
                                    <Button
                                        variant="ghost"
                                        onClick={() =>
                                            post(
                                                publishing.connect.url(args),
                                                {},
                                                'connect',
                                            )
                                        }
                                    >
                                        New key
                                    </Button>
                                ) : null}
                            </div>
                        </CardContent>
                    </Card>

                    {/* Publish */}
                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Send className="size-4" /> Publish
                            </CardTitle>
                            <CardDescription>
                                Pages become Elementor containers with editable
                                AISG widgets; products go to WooCommerce.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <label className="flex items-start gap-2 text-sm">
                                <Checkbox
                                    checked={force}
                                    onCheckedChange={(c) =>
                                        setForce(c === true)
                                    }
                                />
                                <span>
                                    Overwrite pages edited in WordPress
                                    <span className="block text-xs text-muted-foreground">
                                        Otherwise pages changed in Elementor
                                        since the last publish are skipped.
                                    </span>
                                </span>
                            </label>
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    disabled={
                                        !connected || running || busy !== null
                                    }
                                    onClick={() =>
                                        post(
                                            publishing.publish.url(args),
                                            { target: 'push', force },
                                            'push',
                                        )
                                    }
                                    data-test="publish-button"
                                >
                                    {running ? (
                                        <Loader2 className="animate-spin" />
                                    ) : (
                                        <Send />
                                    )}
                                    Publish to WordPress
                                </Button>
                                <Button
                                    variant="outline"
                                    disabled={running || busy !== null}
                                    onClick={() =>
                                        post(
                                            publishing.publish.url(args),
                                            { target: 'zip' },
                                            'zip',
                                        )
                                    }
                                >
                                    <Download /> Export ZIP
                                </Button>
                            </div>
                            <InputError message={errors.publish} />

                            {logs.length > 0 ? (
                                <div
                                    className="max-h-80 overflow-y-auto rounded-lg border"
                                    data-test="publish-log"
                                >
                                    <ul className="divide-y text-sm">
                                        {logs.map((l) => (
                                            <li
                                                key={l.id}
                                                className="flex items-start gap-2 px-3 py-2"
                                            >
                                                <LogIcon status={l.status} />
                                                <div className="min-w-0 flex-1">
                                                    <p className="truncate">
                                                        <span className="text-muted-foreground capitalize">
                                                            {l.type}
                                                        </span>{' '}
                                                        {l.label}
                                                        {l.action &&
                                                        l.status === 'ok' ? (
                                                            <span className="text-xs text-muted-foreground">
                                                                {' '}
                                                                · {l.action}
                                                            </span>
                                                        ) : null}
                                                    </p>
                                                    {l.error ? (
                                                        <p className="text-xs text-muted-foreground">
                                                            {l.error}
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>History</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {publishes.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                Nothing published yet.
                            </p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {publishes.map((p) => (
                                    <li
                                        key={p.id}
                                        className="flex flex-wrap items-center justify-between gap-2 py-2"
                                        data-test="publish-row"
                                        data-status={p.status}
                                    >
                                        <span className="flex items-center gap-2">
                                            <Badge
                                                variant={STATUS_BADGE[p.status]}
                                            >
                                                {p.status}
                                            </Badge>
                                            {p.target === 'zip'
                                                ? 'ZIP export'
                                                : 'Publish to WordPress'}
                                            {p.force ? ' (overwrite)' : ''}
                                            <span className="text-muted-foreground">
                                                {new Date(
                                                    p.createdAt,
                                                ).toLocaleString()}
                                            </span>
                                        </span>
                                        <span className="flex items-center gap-3 text-muted-foreground">
                                            {p.target === 'push' &&
                                            p.summary.ok !== undefined
                                                ? `${p.summary.ok} ok · ${p.summary.failed ?? 0} failed · ${p.summary.conflict ?? 0} skipped`
                                                : null}
                                            {p.error ? (
                                                <span className="text-destructive">
                                                    {p.error}
                                                </span>
                                            ) : null}
                                            {p.downloadUrl ? (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    asChild
                                                >
                                                    <a href={p.downloadUrl}>
                                                        <Download /> Download
                                                    </a>
                                                </Button>
                                            ) : null}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Publish.layout = (props: {
    currentTeam?: { slug: string } | null;
    project: { id: string; name: string };
}) => {
    const slug = props.currentTeam?.slug ?? '';
    const args = { current_team: slug, project: props.project.id };

    return {
        breadcrumbs: [
            { title: 'Projects', href: projectsIndex(slug) },
            { title: props.project.name, href: show(args) },
            { title: 'Publish', href: publishing.show(args) },
        ],
    };
};
