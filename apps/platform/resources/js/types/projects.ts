export type ProjectStatus =
    | 'draft'
    | 'generating'
    | 'ready'
    | 'partial'
    | 'failed';

export type ProjectSummary = {
    id: string;
    name: string;
    status: ProjectStatus;
    statusLabel: string;
    language: string;
    niche: string | null;
    creator: string | null;
    updatedAt: string | null;
};

export type ProjectBrief = {
    niche: string;
    audience: string | null;
    tone: string | null;
    style: string | null;
    brand_colors: string[];
};

export type ProjectDetail = ProjectSummary & {
    kind: 'store';
    creationMode: 'describe' | 'import_products' | 'import_design';
    direction: 'ltr' | 'rtl';
    currency: string | null;
    brief: ProjectBrief;
    createdAt: string | null;
};

export type LanguageOption = {
    value: string;
    label: string;
    dir: 'ltr' | 'rtl';
};

export type CreationModeOption = {
    value: ProjectDetail['creationMode'];
    label: string;
    available: boolean;
};

export type ProjectFormOptions = {
    languages: LanguageOption[];
    tones: string[];
    creationModes: CreationModeOption[];
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    next_page_url: string | null;
    prev_page_url: string | null;
    total: number;
};

export type SectionGenerationStatus =
    | 'pending'
    | 'generating'
    | 'ready'
    | 'failed';

export type GenerationPage = {
    id: string;
    kind: 'page' | 'header' | 'footer';
    type: string;
    title: string;
    isHomepage: boolean;
    sections: {
        id: string;
        key: string;
        name: string;
        status: SectionGenerationStatus;
    }[];
};

export type GenerationState = {
    status: ProjectStatus;
    stage: 'idle' | 'planning' | 'writing' | 'done' | 'failed';
    total: number;
    ready: number;
    failed: number;
    pages: GenerationPage[];
};
