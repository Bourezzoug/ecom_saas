<?php

namespace App\Domain\Generation;

use App\Domain\Design\DesignTokenNormalizer;
use App\Domain\Sections\SectionLibrary;
use App\Enums\PageKind;
use App\Enums\PageType;
use App\Enums\SectionStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;
use Illuminate\Support\Facades\DB;

/**
 * Writes a validated site plan to the database: replaces the project's pages,
 * creates header/footer layout parts, one row per planned section (holding
 * schema defaults until its content job finishes) and the design tokens.
 */
class SitePlanApplier
{
    public function __construct(
        private readonly SectionLibrary $sections,
        private readonly DesignTokenNormalizer $tokens,
    ) {}

    /**
     * @param  array<string, mixed>  $plan
     * @return list<PageSection> The sections to fill, in display order.
     */
    public function apply(Project $project, array $plan): array
    {
        return DB::transaction(function () use ($project, $plan) {
            $project->pages()->delete();

            $project->designTokens()->updateOrCreate([], $this->tokens->normalize(
                $plan['design'] ?? [],
                $project->brief['brand_colors'] ?? [],
                $project->language,
            ));

            $project->forceFill(['site_plan' => $plan])->save();

            $created = [];
            $position = 0;

            $header = $this->layoutPart($project, PageKind::Header, 'header');
            $created[] = $this->addSection($header, $this->firstKey('header'), 0, null);

            foreach ($plan['pages'] as $pagePlan) {
                $type = PageType::from($pagePlan['type']);

                $page = $project->pages()->create([
                    'kind' => PageKind::Page,
                    'type' => $type,
                    'title' => $pagePlan['title'],
                    'slug' => $type === PageType::Home ? 'home' : $type->value,
                    'position' => $position++,
                    'is_homepage' => $type === PageType::Home,
                ]);

                foreach (array_values($pagePlan['sections']) as $i => $sectionPlan) {
                    $created[] = $this->addSection($page, $sectionPlan['section'], $i, $sectionPlan['brief']);
                }
            }

            $footer = $this->layoutPart($project, PageKind::Footer, 'footer');
            $created[] = $this->addSection($footer, $this->firstKey('footer'), 0, null);

            return $created;
        });
    }

    private function layoutPart(Project $project, PageKind $kind, string $title): Page
    {
        return $project->pages()->create([
            'kind' => $kind,
            'type' => PageType::Custom,
            'title' => ucfirst($title),
            'slug' => "__{$kind->value}",
            'position' => $kind === PageKind::Header ? -1 : 1000,
        ]);
    }

    private function addSection(Page $page, string $key, int $position, ?string $brief): PageSection
    {
        $definition = $this->sections->get($key);
        $defaults = $this->sections->compiler()->defaults($definition);

        return $page->sections()->create([
            'section_key' => $key,
            'section_version' => $definition->version,
            'position' => $position,
            'content' => $defaults['content'],
            'style' => $defaults['style'],
            'brief' => $brief,
            'status' => SectionStatus::Pending,
        ]);
    }

    private function firstKey(string $placement): string
    {
        $keys = array_keys($this->sections->registry()->forPlacement($placement));

        return $keys[0] ?? throw new \RuntimeException("No {$placement} section is available.");
    }
}
