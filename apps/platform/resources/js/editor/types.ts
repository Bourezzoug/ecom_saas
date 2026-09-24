import type {
    DesignTokens,
    SampleCatalog,
    SectionDefinition,
} from '@aisg/renderer';

export type SectionStatus = 'pending' | 'generating' | 'ready' | 'failed';

export type EditorSection = {
    id: string;
    key: string;
    version: number;
    position: number;
    content: Record<string, unknown>;
    style: Record<string, unknown>;
    status: SectionStatus;
    lockVersion: number;
};

export type EditorPage = {
    id: string;
    kind: 'page' | 'header' | 'footer';
    type: string;
    title: string;
    slug: string;
    position: number;
    isHomepage: boolean;
    sections: EditorSection[];
};

export type FullTokens = {
    colors: Record<string, string>;
    fonts: { heading: { family: string }; body: { family: string } };
    radius: string;
    spacing: string;
    container_width: number;
};

export type EditorDocument = {
    revision: number;
    tokens: FullTokens | null;
    pages: EditorPage[];
};

export type VersionSummary = {
    id: number;
    number: number;
    reason: string;
    reasonLabel: string;
    label: string | null;
    author: string | null;
    createdAt: string;
};

export type EditorProject = {
    id: string;
    name: string;
    language: string;
    direction: 'ltr' | 'rtl';
    currency: string | null;
    status: string;
};

export type EditorProps = {
    project: EditorProject;
    document: EditorDocument;
    library: SectionDefinition[];
    catalog: SampleCatalog;
    fonts: string[];
    icons: string[];
    regenerateCost: number;
    versions: VersionSummary[];
    assetsUrl: string;
};

export type { DesignTokens, SectionDefinition };

export type SaveState = 'saved' | 'saving' | 'error';
