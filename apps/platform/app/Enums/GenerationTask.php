<?php

namespace App\Enums;

/**
 * What an AI call is for. Each task maps to a model route in config/ai.php.
 */
enum GenerationTask: string
{
    case PlanSite = 'plan_site';
    case FillSection = 'fill_section';
    case RegenerateSection = 'regenerate_section';
    case ImportDesign = 'import_design';
    case ProductDescription = 'product_description';
    case PlanLanding = 'plan_landing';
    case Ping = 'ping';

    /**
     * The model route (config/ai.php "models" key) used for this task.
     */
    public function modelRoute(): string
    {
        return match ($this) {
            self::PlanSite, self::PlanLanding => 'planner',
            self::ImportDesign => 'vision',
            default => 'copy',
        };
    }
}
