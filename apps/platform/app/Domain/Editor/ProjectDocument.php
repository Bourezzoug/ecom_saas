<?php

namespace App\Domain\Editor;

use App\Enums\PageKind;
use App\Enums\PageType;
use App\Enums\SectionStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * The editable document of a project: pages (incl. header/footer), their ordered
 * sections, and the design tokens. Exported for the editor and for version
 * snapshots; imported when a version is restored (ids are preserved, so page
 * and section refs stay stable for re-publishing).
 */
class ProjectDocument
{
    /**
     * @return array{revision: int, tokens: array<string, mixed>|null, pages: array<int, array<string, mixed>>}
     */
    public function export(Project $project): array
    {
        $pages = $project->pages()->with('sections')->get();

        return [
            'revision' => $project->revision,
            'tokens' => $project->designTokens?->toTokens(),
            'pages' => $pages->map(fn (Page $page) => [
                'id' => $page->id,
                'kind' => $page->kind->value,
                'type' => $page->type->value,
                'title' => $page->title,
                'slug' => $page->slug,
                'position' => $page->position,
                'isHomepage' => $page->is_homepage,
                'sections' => $page->sections->map(fn (PageSection $s) => $this->section($s))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function section(PageSection $section): array
    {
        return [
            'id' => $section->id,
            'key' => $section->section_key,
            'version' => $section->section_version,
            'position' => $section->position,
            'content' => $section->content,
            'style' => $section->style === [] ? new \stdClass : $section->style,
            'status' => $section->status->value,
            'lockVersion' => $section->lock_version,
        ];
    }

    /**
     * Replace the project's pages, sections and tokens with a snapshot.
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function import(Project $project, array $snapshot): void
    {
        DB::transaction(function () use ($project, $snapshot) {
            $project->pages()->delete();

            foreach ($snapshot['pages'] ?? [] as $pageData) {
                $page = new Page([
                    'kind' => PageKind::from($pageData['kind']),
                    'type' => PageType::from($pageData['type']),
                    'title' => $pageData['title'],
                    'slug' => $pageData['slug'],
                    'position' => $pageData['position'],
                    'is_homepage' => $pageData['isHomepage'],
                ]);
                $page->id = $pageData['id'];
                $page->project()->associate($project);
                $page->save();

                foreach ($pageData['sections'] ?? [] as $sectionData) {
                    $section = new PageSection([
                        'section_key' => $sectionData['key'],
                        'section_version' => $sectionData['version'],
                        'position' => $sectionData['position'],
                        'content' => $sectionData['content'],
                        'style' => (array) $sectionData['style'],
                        'status' => SectionStatus::Ready,
                    ]);
                    $section->id = $sectionData['id'];
                    $section->page()->associate($page);
                    $section->save();
                }
            }

            if (! empty($snapshot['tokens'])) {
                $project->designTokens()->updateOrCreate([], $snapshot['tokens']);
            }

            $project->increment('revision');
        });
    }
}
