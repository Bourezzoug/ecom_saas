import { Badge } from '@/components/ui/badge';
import type { ProjectStatus } from '@/types';

const VARIANTS: Record<
    ProjectStatus,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    draft: 'secondary',
    generating: 'outline',
    ready: 'default',
    partial: 'outline',
    failed: 'destructive',
};

export default function ProjectStatusBadge({
    status,
    label,
}: {
    status: ProjectStatus;
    label: string;
}) {
    return <Badge variant={VARIANTS[status]}>{label}</Badge>;
}
