import { History, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { Editor } from './use-editor';

export default function VersionsSheet({ editor }: { editor: Editor }) {
    const [label, setLabel] = useState('');
    const [busy, setBusy] = useState<number | 'save' | null>(null);

    const restore = async (id: number, number: number) => {
        if (
            !window.confirm(
                `Restore version #${number}? Your current state is saved first, so you can undo this.`,
            )
        ) {
            return;
        }

        setBusy(id);
        try {
            await editor.restoreVersion(id);
        } catch {
            toast.error('Could not restore this version.');
        } finally {
            setBusy(null);
        }
    };

    return (
        <Sheet onOpenChange={(open) => open && void editor.reloadVersions()}>
            <SheetTrigger asChild>
                <Button variant="ghost" size="sm" data-test="versions-button">
                    <History /> Versions
                </Button>
            </SheetTrigger>
            <SheetContent className="flex flex-col gap-4 overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>Version history</SheetTitle>
                    <SheetDescription>
                        Snapshots are taken after AI generation, before AI
                        rewrites, and every few minutes while you edit.
                    </SheetDescription>
                </SheetHeader>

                <form
                    className="flex gap-2 px-4"
                    onSubmit={async (e) => {
                        e.preventDefault();
                        if (!label.trim()) {
                            return;
                        }
                        setBusy('save');
                        try {
                            await editor.saveVersion(label.trim());
                            setLabel('');
                        } finally {
                            setBusy(null);
                        }
                    }}
                >
                    <Input
                        value={label}
                        maxLength={120}
                        placeholder="Name this version"
                        onChange={(e) => setLabel(e.target.value)}
                    />
                    <Button
                        type="submit"
                        disabled={busy === 'save' || !label.trim()}
                    >
                        Save
                    </Button>
                </form>

                <ul className="flex flex-col divide-y px-4">
                    {editor.versions.map((v) => (
                        <li
                            key={v.id}
                            className="flex items-center justify-between gap-3 py-3"
                        >
                            <div className="min-w-0 text-sm">
                                <p className="truncate font-medium">
                                    #{v.number} · {v.label ?? v.reasonLabel}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {new Date(v.createdAt).toLocaleString()}
                                    {v.author ? ` · ${v.author}` : ''}
                                </p>
                            </div>
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={busy !== null}
                                onClick={() => void restore(v.id, v.number)}
                            >
                                <RotateCcw /> Restore
                            </Button>
                        </li>
                    ))}
                    {editor.versions.length === 0 ? (
                        <li className="py-6 text-center text-sm text-muted-foreground">
                            No versions yet.
                        </li>
                    ) : null}
                </ul>
            </SheetContent>
        </Sheet>
    );
}
