import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import type { DesignInput, Editor } from './use-editor';

const COLORS: { key: keyof DesignInput['colors']; label: string }[] = [
    { key: 'primary', label: 'Primary (buttons, highlights)' },
    { key: 'secondary', label: 'Secondary' },
    { key: 'accent', label: 'Accent (badges, stars)' },
    { key: 'background', label: 'Page background' },
    { key: 'text', label: 'Text' },
];

/**
 * Global design tokens. In WordPress these become Elementor Global Colors /
 * Fonts, so they stay editable there after publishing.
 */
export default function DesignPanel({
    editor,
    fonts,
}: {
    editor: Editor;
    fonts: string[];
}) {
    const tokens = editor.doc.tokens;

    if (!tokens) {
        return null;
    }

    const current: DesignInput = {
        colors: {
            primary: tokens.colors.primary,
            secondary: tokens.colors.secondary,
            accent: tokens.colors.accent,
            background: tokens.colors.background,
            text: tokens.colors.text,
        },
        fonts: {
            heading: tokens.fonts.heading.family,
            body: tokens.fonts.body.family,
        },
        radius: tokens.radius,
        spacing: tokens.spacing,
    };

    const update = (patch: Partial<DesignInput>) =>
        editor.updateDesign({ ...current, ...patch });

    return (
        <div className="flex flex-col gap-5 p-4" data-test="design-panel">
            <section className="grid gap-3">
                <h3 className="text-sm font-semibold">Colours</h3>
                {COLORS.map(({ key, label }) => (
                    <div
                        key={key}
                        className="flex items-center justify-between gap-3"
                    >
                        <Label
                            htmlFor={`color-${key}`}
                            className="text-sm font-normal"
                        >
                            {label}
                        </Label>
                        <div className="flex items-center gap-2">
                            <span className="font-mono text-xs text-muted-foreground">
                                {current.colors[key]}
                            </span>
                            <input
                                id={`color-${key}`}
                                type="color"
                                value={current.colors[key]}
                                onChange={(e) =>
                                    update({
                                        colors: {
                                            ...current.colors,
                                            [key]: e.target.value,
                                        },
                                    })
                                }
                                className="h-8 w-10 cursor-pointer rounded border bg-transparent"
                            />
                        </div>
                    </div>
                ))}
            </section>

            <section className="grid gap-3">
                <h3 className="text-sm font-semibold">Fonts</h3>
                {(['heading', 'body'] as const).map((slot) => (
                    <div key={slot} className="grid gap-1.5">
                        <Label htmlFor={`font-${slot}`}>
                            {slot === 'heading' ? 'Headings' : 'Body text'}
                        </Label>
                        <NativeSelect
                            id={`font-${slot}`}
                            value={current.fonts[slot]}
                            onChange={(e) =>
                                update({
                                    fonts: {
                                        ...current.fonts,
                                        [slot]: e.target.value,
                                    },
                                })
                            }
                        >
                            {fonts.map((f) => (
                                <option key={f} value={f}>
                                    {f}
                                </option>
                            ))}
                        </NativeSelect>
                    </div>
                ))}
            </section>

            <section className="grid gap-3">
                <h3 className="text-sm font-semibold">Shape & spacing</h3>
                <div className="grid gap-1.5">
                    <Label htmlFor="radius">Corner radius</Label>
                    <NativeSelect
                        id="radius"
                        value={current.radius}
                        onChange={(e) => update({ radius: e.target.value })}
                    >
                        <option value="none">Square</option>
                        <option value="sm">Small</option>
                        <option value="md">Medium</option>
                        <option value="lg">Large</option>
                        <option value="full">Extra round</option>
                    </NativeSelect>
                </div>
                <div className="grid gap-1.5">
                    <Label htmlFor="spacing">Section spacing</Label>
                    <NativeSelect
                        id="spacing"
                        value={current.spacing}
                        onChange={(e) => update({ spacing: e.target.value })}
                    >
                        <option value="compact">Compact</option>
                        <option value="normal">Normal</option>
                        <option value="airy">Airy</option>
                    </NativeSelect>
                </div>
            </section>
        </div>
    );
}
