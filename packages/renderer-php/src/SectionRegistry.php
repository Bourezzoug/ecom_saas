<?php

namespace Aisg\Sections;

use Aisg\Sections\Exceptions\InvalidSectionException;
use JsonException;

/**
 * Loads every /sections/{key} package and checks the structural rules that
 * both runtimes rely on. Directories starting with "_" (meta-schemas,
 * fixtures) are skipped.
 */
final class SectionRegistry
{
    /** @var array<string, SectionDefinition> */
    private array $sections = [];

    private function __construct() {}

    public static function fromDirectory(string $directory): self
    {
        $registry = new self;

        $paths = glob(rtrim($directory, '/\\').'/*', GLOB_ONLYDIR) ?: [];
        sort($paths);

        foreach ($paths as $path) {
            if (str_starts_with(basename($path), '_')) {
                continue;
            }

            $definition = self::load($path);
            $registry->sections[$definition->key] = $definition;
        }

        return $registry;
    }

    /**
     * @param  list<SectionDefinition>  $definitions
     */
    public static function fromDefinitions(array $definitions): self
    {
        $registry = new self;

        foreach ($definitions as $definition) {
            $registry->sections[$definition->key] = $definition;
        }

        return $registry;
    }

    public static function load(string $path): SectionDefinition
    {
        $key = basename($path);
        $schema = self::readJson($path.'/schema.json', $key);
        $meta = self::readJson($path.'/meta.json', $key);
        $templatePath = $path.'/template.mustache';

        if (! is_file($templatePath)) {
            throw InvalidSectionException::withErrors($key, ['template.mustache is missing']);
        }

        $definition = new SectionDefinition(
            key: $key,
            version: (int) ($schema['version'] ?? 0),
            schema: $schema,
            meta: $meta,
            template: (string) file_get_contents($templatePath),
            path: $path,
        );

        $errors = self::validate($definition);

        if ($errors !== []) {
            throw InvalidSectionException::withErrors($key, $errors);
        }

        return $definition;
    }

    public function has(string $key): bool
    {
        return isset($this->sections[$key]);
    }

    public function get(string $key): SectionDefinition
    {
        if (! isset($this->sections[$key])) {
            throw new InvalidSectionException("Unknown section type [{$key}].");
        }

        return $this->sections[$key];
    }

    /**
     * @return array<string, SectionDefinition>
     */
    public function all(): array
    {
        return $this->sections;
    }

    /**
     * @return array<string, SectionDefinition>
     */
    public function forPlacement(string $placement): array
    {
        return array_filter($this->sections, fn (SectionDefinition $d) => $d->placement() === $placement);
    }

    /**
     * Body sections allowed on a page type.
     *
     * @return array<string, SectionDefinition>
     */
    public function forPageType(string $pageType): array
    {
        return array_filter(
            $this->forPlacement('body'),
            fn (SectionDefinition $d) => $d->fitsPageType($pageType),
        );
    }

    /**
     * Structural rules (docs/ARCHITECTURE.md §4.1–4.3). Returns a list of errors.
     *
     * @return list<string>
     */
    public static function validate(SectionDefinition $definition): array
    {
        $errors = [];
        $schema = $definition->schema;

        if (($schema['key'] ?? null) !== $definition->key) {
            $errors[] = 'schema.json "key" must equal the directory name';
        }

        if (($definition->meta['key'] ?? null) !== $definition->key) {
            $errors[] = 'meta.json "key" must equal the directory name';
        }

        if ($definition->version < 1) {
            $errors[] = 'schema.json "version" must be a positive integer';
        }

        if (! in_array($definition->placement(), ['header', 'body', 'footer'], true)) {
            $errors[] = 'meta.json "placement" must be header, body or footer';
        }

        $names = [];
        foreach ([...$definition->fields(), ...$definition->styleFields()] as $field) {
            $name = $field['name'] ?? '';

            if (isset($names[$name])) {
                $errors[] = "duplicate field name [{$name}]";
            }
            $names[$name] = true;

            array_push($errors, ...self::validateField($field, false));
        }

        foreach ($definition->styleFields() as $field) {
            if (($field['type'] ?? null) !== FieldTypes::SELECT && ($field['type'] ?? null) !== FieldTypes::BOOLEAN) {
                $errors[] = "style field [{$field['name']}] must be a select or boolean";
            }
        }

        foreach ($definition->data() as $dataKey => $source) {
            if (! in_array($source['resolver'] ?? null, ['products', 'product', 'categories', 'menu'], true)) {
                $errors[] = "data [{$dataKey}] uses an unknown resolver";
            }
        }

        return array_merge($errors, TemplateLinter::lint($definition->template));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<string>
     */
    private static function validateField(array $field, bool $inRepeater): array
    {
        $errors = [];
        $name = $field['name'] ?? '';
        $type = $field['type'] ?? '';

        if (! preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
            $errors[] = "field name [{$name}] must be snake_case";
        }

        if (! in_array($type, FieldTypes::ALL, true)) {
            $errors[] = "field [{$name}] has unknown type [{$type}]";

            return $errors;
        }

        if ($inRepeater && ! in_array($type, FieldTypes::REPEATER_ITEM, true)) {
            $errors[] = "field [{$name}] of type [{$type}] is not allowed inside a repeater";
        }

        if (($field['ai']['generate'] ?? false) && ! in_array($type, FieldTypes::AI_GENERATABLE, true)) {
            $errors[] = "field [{$name}] of type [{$type}] cannot be AI-generated";
        }

        if ($type === FieldTypes::SELECT && empty($field['options'])) {
            $errors[] = "select field [{$name}] needs options";
        }

        if ($type === FieldTypes::SELECT) {
            foreach ($field['options'] ?? [] as $option) {
                if (! preg_match('/^[a-z0-9_]+$/', (string) ($option['value'] ?? ''))) {
                    $errors[] = "select field [{$name}] option values must be [a-z0-9_]";
                }
            }
        }

        if ($type === FieldTypes::ICON && isset($field['default']) && ! in_array($field['default'], Icons::NAMES, true)) {
            $errors[] = "icon field [{$name}] default is not in the icon set";
        }

        if ($type === FieldTypes::REPEATER) {
            if ($inRepeater) {
                $errors[] = "repeater [{$name}] cannot be nested";
            }

            if (empty($field['fields'])) {
                $errors[] = "repeater [{$name}] needs fields";
            }

            foreach ($field['fields'] ?? [] as $child) {
                array_push($errors, ...self::validateField($child, true));
            }
        }

        return $errors;
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(string $file, string $key): array
    {
        if (! is_file($file)) {
            throw InvalidSectionException::withErrors($key, [basename($file).' is missing']);
        }

        try {
            $data = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw InvalidSectionException::withErrors($key, [basename($file).' is not valid JSON: '.$e->getMessage()]);
        }

        return is_array($data) ? $data : [];
    }
}
