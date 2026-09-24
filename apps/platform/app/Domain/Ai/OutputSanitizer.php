<?php

namespace App\Domain\Ai;

/**
 * Turns model output into safe plain text (spec §3.2: "strip HTML/script").
 *
 * - decodes entities, strips tags (script/style bodies included) and control characters
 * - collapses runs of spaces; keeps at most single newlines (textarea fields)
 * - when a JSON Schema is given, truncates strings to maxLength on a word boundary,
 *   so an over-long headline is shortened instead of failing validation
 */
class OutputSanitizer
{
    /**
     * @param  array<string, mixed>|null  $schema
     */
    public function sanitize(mixed $value, ?array $schema = null): mixed
    {
        if (is_string($value)) {
            $clean = $this->cleanString($value);
            $max = $schema['maxLength'] ?? null;

            return is_int($max) ? $this->truncate($clean, $max) : $clean;
        }

        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            $items = $schema['items'] ?? null;
            $max = $schema['maxItems'] ?? null;

            if (is_int($max)) {
                $value = array_slice($value, 0, $max);
            }

            return array_map(fn ($item) => $this->sanitize($item, is_array($items) ? $items : null), $value);
        }

        $properties = $schema['properties'] ?? [];
        $properties = is_object($properties) ? (array) $properties : $properties;

        $out = [];
        foreach ($value as $key => $item) {
            $out[$key] = $this->sanitize($item, $properties[$key] ?? null);
        }

        return $out;
    }

    public function cleanString(string $value): string
    {
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', '', $value);
        $value = strip_tags($value);
        // Markdown emphasis the model sometimes adds: **bold**, __bold__
        $value = (string) preg_replace('/(\*\*|__)(.+?)\1/u', '$2', $value);
        $value = (string) preg_replace('/[^\P{C}\n]/u', '', $value);
        $value = (string) preg_replace('/[ \t\x{00A0}]+/u', ' ', $value);
        $value = (string) preg_replace('/ *\n */', "\n", $value);
        $value = (string) preg_replace('/\n{2,}/', "\n", $value);

        return trim($value);
    }

    public function truncate(string $value, int $max): string
    {
        if (mb_strlen($value) <= $max) {
            return $value;
        }

        $cut = mb_substr($value, 0, $max);
        $space = mb_strrpos($cut, ' ');

        if ($space !== false && $space > $max * 0.6) {
            $cut = mb_substr($cut, 0, $space);
        }

        return rtrim($cut, " \t\n,;:-–—");
    }
}
