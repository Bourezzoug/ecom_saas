import { ExternalLink, Monitor, Smartphone, Tablet } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { cn } from '@/lib/utils';
import { preview } from '@/routes/projects';
import type { GenerationPage } from '@/types';

type Props = {
    teamSlug: string;
    projectId: string;
    pages: GenerationPage[];
    /** Changes whenever content changes, so the iframe reloads. */
    version: string;
};

const WIDTHS = {
    desktop: '100%',
    tablet: '820px',
    mobile: '390px',
} as const;

type Device = keyof typeof WIDTHS;

/**
 * Read-only preview of the generated store (server-rendered). The interactive
 * editor preview replaces this in M3.
 */
export default function SitePreview({
    teamSlug,
    projectId,
    pages,
    version,
}: Props) {
    const contentPages = pages.filter((page) => page.kind === 'page');
    const [pageId, setPageId] = useState(
        contentPages.find((page) => page.isHomepage)?.id ?? contentPages[0]?.id,
    );
    const [device, setDevice] = useState<Device>('desktop');

    if (!pageId) {
        return null;
    }

    const url = preview.url({
        current_team: teamSlug,
        project: projectId,
        page: pageId,
    });

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap gap-1">
                    {contentPages.map((page) => (
                        <Button
                            key={page.id}
                            size="sm"
                            variant={page.id === pageId ? 'default' : 'ghost'}
                            onClick={() => setPageId(page.id)}
                        >
                            {page.title}
                        </Button>
                    ))}
                </div>
                <div className="flex items-center gap-2">
                    <ToggleGroup
                        type="single"
                        value={device}
                        onValueChange={(value) =>
                            value && setDevice(value as Device)
                        }
                        size="sm"
                    >
                        <ToggleGroupItem value="desktop" aria-label="Desktop">
                            <Monitor />
                        </ToggleGroupItem>
                        <ToggleGroupItem value="tablet" aria-label="Tablet">
                            <Tablet />
                        </ToggleGroupItem>
                        <ToggleGroupItem value="mobile" aria-label="Mobile">
                            <Smartphone />
                        </ToggleGroupItem>
                    </ToggleGroup>
                    <Button size="sm" variant="outline" asChild>
                        <a href={url} target="_blank" rel="noreferrer">
                            <ExternalLink /> Open
                        </a>
                    </Button>
                </div>
            </div>
            <div className="overflow-hidden rounded-xl border bg-muted/40 p-2">
                <iframe
                    key={`${pageId}-${version}`}
                    title="Store preview"
                    src={url}
                    className={cn(
                        'mx-auto block h-[75vh] rounded-lg border bg-white transition-[width]',
                    )}
                    style={{ width: WIDTHS[device], maxWidth: '100%' }}
                />
            </div>
        </div>
    );
}
