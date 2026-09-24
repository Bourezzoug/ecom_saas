<?php

namespace App\Domain\Generation;

use Aisg\Sections\SectionDefinition;
use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Ai\OutputSanitizer;
use App\Domain\Ai\StructuredRequest;
use App\Domain\Sections\SectionLibrary;
use App\Models\PageSection;
use App\Models\Project;

/**
 * Pass 2 of generation: fill ONE section's AI fields. Kept deliberately small
 * (one section, its own schema, a short brief) so 3–4B local models cope.
 *
 * The model only returns the ai.generate fields; the result is laid over the
 * section defaults and validated against the full content schema.
 */
class SectionWriter
{
    public function __construct(
        private readonly SectionLibrary $sections,
        private readonly OutputSanitizer $sanitizer,
        private readonly JsonSchemaValidator $validator,
    ) {}

    public function request(Project $project, PageSection $section, ?string $instruction = null): StructuredRequest
    {
        $definition = $this->sections->get($section->section_key);
        $compiler = $this->sections->compiler();
        $contentSchema = $compiler->contentSchema($definition);

        return new StructuredRequest(
            route: 'copy',
            system: 'You are an expert e-commerce copywriter. You write clear, concrete, persuasive copy for one section '
                .'of an online store. Do not invent specific numbers, prices, discounts, delivery times, certifications or '
                .'awards unless they are given. Keep product names, ingredients, materials and brand names exactly as the '
                .'brief states them (translate the words around them, never swap them for something else). '
                .'No HTML, no markdown, no emojis. Reply with a JSON object only.',
            prompt: $this->prompt($project, $section, $definition, $instruction),
            schema: $compiler->aiSchema($definition),
            prepare: fn (array $data) => $compiler->mergeAiOutput(
                $definition,
                $this->sanitizer->sanitize($data, $contentSchema),
            ),
            validate: fn (array $content) => $this->validator->errors($content, $contentSchema),
            // Output cap + per-call timeout: 2 attempts must fit FillSectionJob::$timeout.
            options: ['temperature' => 0.6, 'max_tokens' => 800, 'timeout' => 90],
        );
    }

    private function prompt(Project $project, PageSection $section, SectionDefinition $definition, ?string $instruction): string
    {
        $brief = $project->brief;
        $language = config("languages.{$project->language}.name", $project->language);
        $page = $section->page;
        $tagline = $project->site_plan['tagline'] ?? '';
        $goal = $section->brief ?: $this->defaultBrief($definition, $project);

        $lines = [
            "Store: {$project->name}".($tagline !== '' ? " — {$tagline}" : ''),
            "Niche: {$brief['niche']}",
            'Target audience: '.($brief['audience'] ?? 'not specified'),
            'Tone of voice: '.($brief['tone'] ?? 'friendly'),
            "Page: {$page->title} ({$page->type->value} page)",
            "Section: {$definition->name()}. {$definition->plannerDescription()}",
            "Goal of this section: {$goal}",
        ];

        if (filled($instruction)) {
            $lines[] = "Extra instruction from the user: {$instruction}";
        }

        $lines[] = '';
        $lines[] = "Write every text value in {$language}. Fill every field of the JSON schema and respect the maximum lengths.";

        return implode("\n", $lines);
    }

    private function defaultBrief(SectionDefinition $definition, Project $project): string
    {
        return match ($definition->placement()) {
            'header' => "Site header for {$project->name}.",
            'footer' => "Site footer for {$project->name}: a short description of the store.",
            default => $definition->plannerDescription(),
        };
    }
}
