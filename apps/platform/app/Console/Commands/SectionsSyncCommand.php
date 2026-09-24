<?php

namespace App\Console\Commands;

use Aisg\Sections\Exceptions\InvalidSectionException;
use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Sections\SectionLibrary;
use App\Models\SectionType;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SectionsSyncCommand extends Command
{
    protected $signature = 'sections:sync';

    protected $description = 'Validate /sections and sync every section type into the section_types table';

    public function handle(SectionLibrary $library, JsonSchemaValidator $validator): int
    {
        try {
            $definitions = $library->registry()->all();
        } catch (InvalidSectionException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $sectionMeta = $this->metaSchema($library, 'section.schema.json');
        $metaMeta = $this->metaSchema($library, 'meta.schema.json');
        $failed = false;

        foreach ($definitions as $key => $definition) {
            // Validate the raw files as JSON objects: "{}" and "[]" must stay distinguishable.
            $raw = fn (string $file) => json_decode((string) file_get_contents($definition->path.'/'.$file));

            $errors = [
                ...array_map(fn ($e) => "schema.json {$e}", $validator->errors($raw('schema.json'), $sectionMeta)),
                ...array_map(fn ($e) => "meta.json {$e}", $validator->errors($raw('meta.json'), $metaMeta)),
            ];

            if ($errors !== []) {
                $failed = true;
                $this->components->error("[{$key}]\n  ".implode("\n  ", $errors));
            }
        }

        if ($failed) {
            return self::FAILURE;
        }

        $compiler = $library->compiler();

        DB::transaction(function () use ($definitions, $compiler) {
            foreach ($definitions as $key => $definition) {
                SectionType::query()->updateOrCreate(['key' => $key], [
                    'version' => $definition->version,
                    'name' => $definition->name(),
                    'category' => $definition->category(),
                    'placement' => $definition->placement(),
                    'page_types' => $definition->pageTypes(),
                    'requires_woocommerce' => $definition->requiresWooCommerce(),
                    'schema' => $definition->schema,
                    'meta' => $definition->meta,
                    'compiled' => [
                        'ai' => $compiler->aiSchema($definition),
                        'content' => $compiler->contentSchema($definition),
                        'style' => $compiler->styleSchema($definition),
                        'defaults' => $compiler->defaults($definition),
                    ],
                    'template_hash' => $definition->templateHash(),
                    'is_active' => true,
                    'synced_at' => now(),
                ]);
            }

            SectionType::query()->whereNotIn('key', array_keys($definitions))->update(['is_active' => false]);
        });

        $this->components->info('Synced '.count($definitions).' section types: '.implode(', ', array_keys($definitions)));

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function metaSchema(SectionLibrary $library, string $file): array
    {
        return json_decode((string) file_get_contents($library->path().'/_meta-schema/'.$file), true, flags: JSON_THROW_ON_ERROR);
    }
}
