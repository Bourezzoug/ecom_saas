import type { SectionDefinition } from '@aisg/renderer';
import { AlertTriangle } from 'lucide-react';
import { useState, type PropsWithChildren } from 'react';
import { Badge } from '@/components/ui/badge';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';

type Props = PropsWithChildren<{
    library: SectionDefinition[];
    pageType: string;
    onAdd: (key: string) => void;
}>;

const CATEGORY_LABELS: Record<string, string> = {
    hero: 'Hero',
    commerce: 'Products',
    content: 'Content',
    social_proof: 'Social proof',
    cta: 'Call to action',
    landing: 'Product landing',
};

export default function AddSectionDialog({
    library,
    pageType,
    onAdd,
    children,
}: Props) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');

    const body = library.filter(
        (d) =>
            d.placement === 'body' &&
            (query === '' ||
                `${d.name} ${d.category}`
                    .toLowerCase()
                    .includes(query.toLowerCase())),
    );
    const recommended = body.filter((d) => d.pageTypes.includes(pageType));
    const others = body.filter((d) => !d.pageTypes.includes(pageType));

    const pick = (key: string) => {
        onAdd(key);
        setOpen(false);
        setQuery('');
    };

    const grid = (items: SectionDefinition[]) => (
        <div className="grid gap-2 sm:grid-cols-2">
            {items.map((d) => (
                <button
                    key={d.key}
                    type="button"
                    onClick={() => pick(d.key)}
                    className="flex flex-col gap-1 rounded-lg border p-3 text-start text-sm transition-colors hover:border-primary hover:bg-accent"
                    data-test="add-section-option"
                >
                    <span className="flex items-center gap-2 font-medium">
                        {d.name}
                        {d.reviewRequired ? (
                            <AlertTriangle className="size-3.5 text-amber-500" />
                        ) : null}
                    </span>
                    <Badge variant="secondary" className="w-fit">
                        {CATEGORY_LABELS[d.category] ?? d.category}
                    </Badge>
                    <span className="line-clamp-2 text-xs text-muted-foreground">
                        {d.plannerDescription}
                    </span>
                </button>
            ))}
        </div>
    );

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{children}</DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Add a section</DialogTitle>
                    <DialogDescription>
                        It is added at the end of the page; drag it to reorder.
                    </DialogDescription>
                </DialogHeader>
                <Input
                    placeholder="Search sections…"
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    autoFocus
                />
                {recommended.length > 0 ? (
                    <section className="space-y-2">
                        <h3 className="text-sm font-semibold">
                            Recommended for this page
                        </h3>
                        {grid(recommended)}
                    </section>
                ) : null}
                {others.length > 0 ? (
                    <section className="space-y-2">
                        <h3 className="text-sm font-semibold text-muted-foreground">
                            More sections
                        </h3>
                        {grid(others)}
                    </section>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
