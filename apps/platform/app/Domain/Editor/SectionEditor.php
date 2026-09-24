<?php

namespace App\Domain\Editor;

use Aisg\Sections\SectionDefinition;
use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Design\DesignTokenNormalizer;
use App\Domain\Editor\Exceptions\StaleSectionException;
use App\Domain\Sections\SectionLibrary;
use App\Enums\PageKind;
use App\Enums\ProjectStatus;
use App\Enums\SectionStatus;
use App\Models\Page;
use App\Models\PageSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Every manual edit made in the editor (free of credits, spec §3.3). Each write
 * validates against the section schema, bumps the project revision and lets
 * the VersionRecorder take a throttled autosave snapshot.
 */
class SectionEditor
{
    public function __construct(
        private readonly SectionLibrary $sections,
        private readonly JsonSchemaValidator $validator,
        private readonly DesignTokenNormalizer $tokens,
        private readonly VersionRecorder $versions,
    ) {}

    /**
     * Save a section's content and/or style (optimistic lock on lock_version).
     *
     * @param  array<string, mixed>|null  $content
     * @param  array<string, mixed>|null  $style
     *
     * @throws StaleSectionException|ValidationException
     */
    public function update(PageSection $section, ?array $content, ?array $style, int $lockVersion, User $user): PageSection
    {
        $this->ensureEditable($section->page->project);

        if ($section->status === SectionStatus::Generating) {
            throw ValidationException::withMessages(['section' => __('This section is being rewritten by AI.')]);
        }

        $definition = $this->sections->get($section->section_key);
        $compiler = $this->sections->compiler();

        $errors = [];
        if ($content !== null) {
            $errors += $this->prefixed('content', $this->validator->errors($content, $compiler->contentSchema($definition)));
        }
        if ($style !== null) {
            $errors += $this->prefixed('style', $this->validator->errors($style, $compiler->styleSchema($definition)));
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $saved = DB::transaction(function () use ($section, $content, $style, $lockVersion) {
            $updated = PageSection::query()
                ->whereKey($section->id)
                ->where('lock_version', $lockVersion)
                ->update(array_filter([
                    'content' => $content !== null ? json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                    'style' => $style !== null ? json_encode((object) $style) : null,
                    'status' => SectionStatus::Ready->value,
                    'lock_version' => $lockVersion + 1,
                    'updated_at' => now(),
                ], fn ($v) => $v !== null));

            if ($updated === 0) {
                throw new StaleSectionException($section->fresh());
            }

            $section->page->project->increment('revision');

            return $section->fresh();
        });

        $this->versions->touch($section->page->project, $user);

        return $saved;
    }

    /**
     * Insert a body section with its defaults at $position (0-based).
     */
    public function add(Page $page, string $key, ?int $position, User $user): PageSection
    {
        $this->ensureEditable($page->project);

        if ($page->kind !== PageKind::Page) {
            throw ValidationException::withMessages(['key' => __('Sections can only be added to pages.')]);
        }

        $definition = $this->definitionOrFail($key);

        if ($definition->placement() !== 'body') {
            throw ValidationException::withMessages(['key' => __('This section type cannot be added to a page.')]);
        }

        $section = DB::transaction(function () use ($page, $definition, $position) {
            $count = $page->sections()->count();
            $position = $position === null ? $count : max(0, min($position, $count));

            $page->sections()->where('position', '>=', $position)->increment('position');

            $defaults = $this->sections->compiler()->defaults($definition);

            $section = $page->sections()->create([
                'section_key' => $definition->key,
                'section_version' => $definition->version,
                'position' => $position,
                'content' => $defaults['content'],
                'style' => $defaults['style'],
                'status' => SectionStatus::Ready,
            ]);

            $page->project->increment('revision');

            return $section;
        });

        $this->versions->touch($page->project, $user);

        return $section;
    }

    public function remove(PageSection $section, User $user): void
    {
        $page = $section->page;
        $this->ensureEditable($page->project);

        if ($page->kind !== PageKind::Page) {
            throw ValidationException::withMessages(['section' => __('The header and footer cannot be removed.')]);
        }

        DB::transaction(function () use ($section, $page) {
            $section->delete();
            $this->renumber($page);
            $page->project->increment('revision');
        });

        $this->versions->touch($page->project, $user);
    }

    /**
     * @param  list<string>  $ids  Every section id of the page, in the new order.
     */
    public function reorder(Page $page, array $ids, User $user): void
    {
        $this->ensureEditable($page->project);

        $current = $page->sections()->pluck('id')->all();

        if (count($ids) !== count($current) || array_diff($ids, $current) !== []) {
            throw ValidationException::withMessages(['ids' => __('The order must list every section of the page exactly once.')]);
        }

        DB::transaction(function () use ($page, $ids) {
            foreach ($ids as $position => $id) {
                PageSection::whereKey($id)->update(['position' => $position]);
            }

            $page->project->increment('revision');
        });

        $this->versions->touch($page->project, $user);
    }

    public function renamePage(Page $page, string $title, User $user): Page
    {
        $this->ensureEditable($page->project);

        $page->update(['title' => $title]);
        $page->project->increment('revision');
        $this->versions->touch($page->project, $user);

        return $page;
    }

    /**
     * Manual design edits: the user's colours are respected (no contrast
     * override); surface/muted/border/primary_contrast are re-derived.
     *
     * @param  array{colors: array<string, string>, fonts: array{heading: string, body: string}, radius: string, spacing: string}  $input
     * @return array<string, mixed>
     */
    public function updateTokens(Project $project, array $input, User $user): array
    {
        $this->ensureEditable($project);

        $tokens = $this->tokens->normalize([
            ...$input['colors'],
            'heading_font' => $input['fonts']['heading'],
            'body_font' => $input['fonts']['body'],
            'radius' => $input['radius'],
            'spacing' => $input['spacing'],
        ], [], $project->language, enforceContrast: false);

        $project->designTokens()->updateOrCreate([], $tokens);
        $project->increment('revision');
        $this->versions->touch($project, $user);

        return $tokens;
    }

    private function ensureEditable(Project $project): void
    {
        if ($project->status === ProjectStatus::Generating) {
            throw ValidationException::withMessages(['project' => __('The store is being generated. Editing is paused until it finishes.')]);
        }
    }

    private function definitionOrFail(string $key): SectionDefinition
    {
        if (! $this->sections->registry()->has($key)) {
            throw ValidationException::withMessages(['key' => __('Unknown section type.')]);
        }

        return $this->sections->get($key);
    }

    private function renumber(Page $page): void
    {
        foreach ($page->sections()->pluck('id') as $position => $id) {
            PageSection::whereKey($id)->update(['position' => $position]);
        }
    }

    /**
     * "/items/0/question: Minimum length is 5" → ["content.items.0.question" => "Minimum length is 5"]
     *
     * @param  list<string>  $errors
     * @return array<string, string>
     */
    private function prefixed(string $root, array $errors): array
    {
        $out = [];

        foreach ($errors as $error) {
            [$path, $message] = array_pad(explode(': ', $error, 2), 2, $error);
            $key = trim($root.'.'.str_replace('/', '.', trim($path, '/')), '.');
            $out[$key] ??= $message;
        }

        return $out;
    }
}
