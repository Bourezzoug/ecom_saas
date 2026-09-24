<?php

namespace Database\Factories;

use App\Enums\ProjectStatus;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Project>
 */
class ProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => fake()->company().' Store',
            'kind' => 'store',
            'status' => ProjectStatus::Draft,
            'creation_mode' => 'describe',
            'language' => 'en',
            'direction' => 'ltr',
            'currency' => 'USD',
            'brief' => [
                'niche' => fake()->randomElement(['Handmade ceramics', 'Organic skincare', 'Running gear']),
                'audience' => 'Adults 25-45 who value quality',
                'tone' => 'friendly',
                'style' => null,
                'brand_colors' => ['#7c4a1e'],
            ],
        ];
    }

    /**
     * An Arabic (RTL) store.
     */
    public function arabic(): static
    {
        return $this->state(fn () => ['language' => 'ar', 'direction' => 'rtl']);
    }
}
