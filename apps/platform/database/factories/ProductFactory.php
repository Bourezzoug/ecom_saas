<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = ucfirst(implode(' ', (array) fake()->unique()->words(3)));

        return [
            'project_id' => Project::factory(),
            'type' => 'simple',
            'name' => $name,
            'slug' => Str::slug($name),
            'sku' => strtoupper(Str::random(8)),
            'regular_price' => fake()->randomFloat(2, 10, 120),
            'sale_price' => null,
            'short_description' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'images' => [],
            'stock_status' => 'instock',
            'status' => 'publish',
            'source' => 'manual',
        ];
    }
}
