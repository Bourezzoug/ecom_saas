<?php

namespace App\Domain\Sections;

use Aisg\Sections\SectionDefinition;

/**
 * The shape a section definition takes in the browser (@aisg/renderer's
 * SectionDefinition type): editor props and the parity harness both use it.
 */
final class SectionPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function for(SectionDefinition $definition): array
    {
        return [
            'key' => $definition->key,
            'version' => $definition->version,
            'name' => $definition->name(),
            'category' => $definition->category(),
            'placement' => $definition->placement(),
            'pageTypes' => $definition->pageTypes(),
            'reviewRequired' => (bool) ($definition->meta['review_required'] ?? false),
            'plannerDescription' => $definition->plannerDescription(),
            'fields' => $definition->fields(),
            'style' => $definition->styleFields(),
            // Keep {} (not []) so JS sees an object map.
            'data' => $definition->data() === [] ? new \stdClass : $definition->data(),
            'template' => $definition->template,
        ];
    }
}
