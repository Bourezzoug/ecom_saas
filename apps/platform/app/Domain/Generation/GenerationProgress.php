<?php

namespace App\Domain\Generation;

use App\Domain\Sections\SectionLibrary;
use App\Enums\PageKind;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;

/**
 * Snapshot of a project's generation state for the UI (Inertia prop and
 * polling fallback). The realtime event only says "something changed".
 */
class GenerationProgress
{
    public function __construct(private readonly SectionLibrary $sections) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Project $project): array
    {
        $pages = $project->pages()->with('sections')->get();
        $all = $pages->flatMap->sections;

        $count = fn (SectionStatus $status) => $all->filter(fn (PageSection $s) => $s->status === $status)->count();

        $stage = match (true) {
            $project->status === ProjectStatus::Generating && $all->isEmpty() => 'planning',
            $project->status === ProjectStatus::Generating => 'writing',
            $project->status === ProjectStatus::Failed => 'failed',
            $all->isEmpty() => 'idle',
            default => 'done',
        };

        return [
            'status' => $project->status->value,
            'stage' => $stage,
            'total' => $all->count(),
            'ready' => $count(SectionStatus::Ready),
            'failed' => $count(SectionStatus::Failed),
            'pages' => $pages
                ->sortBy(fn (Page $p) => [$p->kind === PageKind::Header ? 0 : ($p->kind === PageKind::Page ? 1 : 2), $p->position])
                ->values()
                ->map(fn (Page $page) => [
                    'id' => $page->id,
                    'kind' => $page->kind->value,
                    'type' => $page->type->value,
                    'title' => $page->title,
                    'isHomepage' => $page->is_homepage,
                    'sections' => $page->sections->map(fn (PageSection $s) => [
                        'id' => $s->id,
                        'key' => $s->section_key,
                        'name' => $this->sections->registry()->has($s->section_key)
                            ? $this->sections->get($s->section_key)->name()
                            : $s->section_key,
                        'status' => $s->status->value,
                    ])->values(),
                ]),
        ];
    }
}
