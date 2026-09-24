<?php

namespace App\Domain\Generation;

use Aisg\Sections\SectionDefinition;
use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Ai\OutputSanitizer;
use App\Domain\Ai\StructuredRequest;
use App\Domain\Design\FontCatalog;
use App\Domain\Sections\SectionLibrary;
use App\Enums\PageType;
use App\Models\Project;

/**
 * Pass 1 of generation: the structure of the site (pages → ordered section
 * keys with a one-line brief) plus a design suggestion. No copy is written
 * here; that is pass 2 (SectionWriter), one small call per section.
 *
 * Output shape (docs/ARCHITECTURE.md §4.7, simplified for small models):
 * {
 *   "tagline": "...",
 *   "design": {"primary": "#hex", ..., "heading_font": "...", "body_font": "...", "radius": "md", "spacing": "normal"},
 *   "pages": [{"type": "home", "title": "...", "sections": [{"section": "hero-split", "brief": "..."}]}]
 * }
 */
class SitePlanner
{
    public const MAX_PAGES = 5;

    public const MAX_SECTIONS_PER_PAGE = 5;

    public const MIN_PAGES = 3;

    public function __construct(
        private readonly SectionLibrary $sections,
        private readonly OutputSanitizer $sanitizer,
        private readonly JsonSchemaValidator $validator,
    ) {}

    public function request(Project $project): StructuredRequest
    {
        return new StructuredRequest(
            route: 'planner',
            system: 'You are a senior e-commerce web designer. You plan the structure of an online store '
                .'using ONLY the section types provided. You never write code or HTML. Reply with a JSON object only.',
            prompt: $this->prompt($project),
            schema: $this->schema($project),
            prepare: fn (array $plan) => $this->prepare($plan),
            validate: fn (array $plan) => $this->errors($plan, $project),
            // Output cap + per-call timeout: small models sometimes loop; 2 attempts must fit PlanSiteJob::$timeout.
            options: ['temperature' => 0.4, 'max_tokens' => 1200, 'timeout' => 150],
        );
    }

    /**
     * The schema sent to the model: enums keep a small model on the rails.
     *
     * @return array<string, mixed>
     */
    public function schema(Project $project): array
    {
        $colors = [];
        foreach (['primary', 'secondary', 'accent', 'background', 'text'] as $key) {
            $colors[$key] = ['type' => 'string', 'description' => "{$key} colour as #rrggbb"];
        }

        $fonts = FontCatalog::forLanguage($project->language);

        return [
            'type' => 'object',
            'properties' => [
                'tagline' => ['type' => 'string', 'description' => 'Short store slogan, max 80 characters'],
                'design' => [
                    'type' => 'object',
                    'properties' => [
                        ...$colors,
                        'heading_font' => ['type' => 'string', 'enum' => $fonts],
                        'body_font' => ['type' => 'string', 'enum' => $fonts],
                        'radius' => ['type' => 'string', 'enum' => ['none', 'sm', 'md', 'lg']],
                        'spacing' => ['type' => 'string', 'enum' => ['compact', 'normal', 'airy']],
                    ],
                    'required' => [...array_keys($colors), 'heading_font', 'body_font', 'radius', 'spacing'],
                ],
                'pages' => [
                    'type' => 'array',
                    'minItems' => self::MIN_PAGES,
                    'maxItems' => self::MAX_PAGES,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'type' => ['type' => 'string', 'enum' => PageType::plannable()],
                            'title' => ['type' => 'string'],
                            'sections' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => self::MAX_SECTIONS_PER_PAGE,
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'section' => ['type' => 'string', 'enum' => array_keys($this->plannableSections())],
                                        'brief' => ['type' => 'string'],
                                    ],
                                    'required' => ['section', 'brief'],
                                ],
                            ],
                        ],
                        'required' => ['type', 'title', 'sections'],
                    ],
                ],
            ],
            'required' => ['tagline', 'design', 'pages'],
        ];
    }

    /**
     * The strict schema the prepared plan must satisfy (adds lengths).
     *
     * @return array<string, mixed>
     */
    public function strictSchema(): array
    {
        return [
            'type' => 'object',
            'required' => ['tagline', 'design', 'pages'],
            'properties' => [
                'tagline' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 120],
                'design' => ['type' => 'object'],
                'pages' => [
                    'type' => 'array',
                    'minItems' => self::MIN_PAGES,
                    'maxItems' => self::MAX_PAGES,
                    'items' => [
                        'type' => 'object',
                        'required' => ['type', 'title', 'sections'],
                        'properties' => [
                            'type' => ['enum' => PageType::plannable()],
                            'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 60],
                            'sections' => [
                                'type' => 'array',
                                'minItems' => 1,
                                'maxItems' => self::MAX_SECTIONS_PER_PAGE,
                                'items' => [
                                    'type' => 'object',
                                    'required' => ['section', 'brief'],
                                    'properties' => [
                                        'section' => ['type' => 'string'],
                                        'brief' => ['type' => 'string', 'maxLength' => 240],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sanitise strings, truncate to limits, and put the home page first.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function prepare(array $plan): array
    {
        $plan = $this->sanitizer->sanitize($plan, $this->strictSchema());

        if (isset($plan['pages']) && is_array($plan['pages'])) {
            usort($plan['pages'], fn ($a, $b) => (($b['type'] ?? '') === 'home') <=> (($a['type'] ?? '') === 'home'));
        }

        return $plan;
    }

    /**
     * Schema errors plus the rules JSON Schema cannot express.
     *
     * @param  array<string, mixed>  $plan
     * @return list<string>
     */
    public function errors(array $plan, Project $project): array
    {
        $errors = $this->validator->errors($plan, $this->strictSchema());

        if ($errors !== []) {
            return $errors;
        }

        $types = array_column($plan['pages'], 'type');

        if (count(array_keys($types, 'home', true)) !== 1) {
            $errors[] = 'There must be exactly one page with type "home".';
        }

        foreach (array_count_values($types) as $type => $count) {
            if ($count > 1) {
                $errors[] = "Page type \"{$type}\" is used {$count} times; each page type may appear once.";
            }
        }

        $registry = $this->sections->registry();

        foreach ($plan['pages'] as $i => $page) {
            foreach ($page['sections'] as $j => $section) {
                $key = $section['section'];

                if (! $registry->has($key) || ! $registry->get($key)->fitsPageType($page['type'])) {
                    $allowed = implode(', ', array_keys($registry->forPageType($page['type'])));
                    $errors[] = "/pages/{$i}/sections/{$j}: \"{$key}\" is not allowed on a {$page['type']} page. Allowed: {$allowed}.";
                }
            }
        }

        return $errors;
    }

    private function prompt(Project $project): string
    {
        $brief = $project->brief;
        $language = config("languages.{$project->language}.name", $project->language);
        $colors = implode(', ', $brief['brand_colors'] ?? []) ?: 'none (choose colours that fit the niche)';

        $sections = collect($this->plannableSections())
            ->map(fn (SectionDefinition $d) => "- {$d->key} (allowed on: ".implode(', ', array_intersect($d->pageTypes(), PageType::plannable())).'): '.$d->plannerDescription())
            ->implode("\n");

        $fonts = implode(', ', FontCatalog::forLanguage($project->language));

        return <<<PROMPT
        Store name: {$project->name}
        Niche: {$brief['niche']}
        Target audience: {$this->orNone($brief['audience'] ?? null)}
        Tone of voice: {$this->orNone($brief['tone'] ?? null)}
        Style preferences: {$this->orNone($brief['style'] ?? null)}
        Brand colours: {$colors}
        Language of the website: {$language}

        Plan the website:
        - 3 to 5 pages. Exactly one page of type "home". Allowed page types: home, about, contact, faq. Use each type at most once.
        - For each page choose 1 to 5 sections from the list below, in display order. Only use a section on the page types it allows.
        - Start the home page with a hero. Show products on the home page.
        - For every section write a one-sentence "brief" in English: what this section should say for THIS store.
        - Page "title" and "tagline" must be written in {$language}. Keep the product and ingredient names from the niche exactly (e.g. do not replace one oil, material or product with another).
        - "design": pick colours (#rrggbb) that suit the niche and keep text easy to read on the background. Fonts must be from: {$fonts}.

        Available sections:
        {$sections}
        PROMPT;
    }

    /**
     * Body sections usable on at least one page type the planner may create
     * (landing-only sections are used by the product landing generator, M5).
     *
     * @return array<string, SectionDefinition>
     */
    private function plannableSections(): array
    {
        return array_filter(
            $this->sections->registry()->forPlacement('body'),
            fn (SectionDefinition $d) => array_intersect($d->pageTypes(), PageType::plannable()) !== [],
        );
    }

    private function orNone(?string $value): string
    {
        return filled($value) ? $value : 'not specified';
    }
}
