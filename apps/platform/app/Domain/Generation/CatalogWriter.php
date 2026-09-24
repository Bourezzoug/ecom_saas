<?php

namespace App\Domain\Generation;

use App\Domain\Ai\JsonSchemaValidator;
use App\Domain\Ai\OutputSanitizer;
use App\Domain\Ai\StructuredRequest;
use App\Domain\Catalog\CatalogService;
use App\Models\Project;

/**
 * Sample catalog for stores generated without products (§9 Q7): a few
 * categories and products so the store looks complete. Everything is stored
 * as source=ai / status=draft; prices are placeholders the owner must set.
 */
class CatalogWriter
{
    public const PRODUCTS = 6;

    public function __construct(
        private readonly OutputSanitizer $sanitizer,
        private readonly JsonSchemaValidator $validator,
        private readonly CatalogService $catalog,
    ) {}

    public function request(Project $project): StructuredRequest
    {
        $language = config("languages.{$project->language}.name", $project->language);
        $brief = $project->brief;

        $schema = [
            'type' => 'object',
            'properties' => [
                'categories' => [
                    'type' => 'array', 'minItems' => 2, 'maxItems' => 3,
                    'items' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'description' => '1 to 3 words'],
                    ], 'required' => ['name']],
                ],
                'products' => [
                    'type' => 'array', 'minItems' => self::PRODUCTS, 'maxItems' => self::PRODUCTS,
                    'items' => ['type' => 'object', 'properties' => [
                        'name' => ['type' => 'string', 'description' => 'Product name, 2 to 6 words'],
                        'short_description' => ['type' => 'string', 'description' => 'One sentence, max 160 characters'],
                        'description' => ['type' => 'string', 'description' => 'Two or three sentences'],
                        'price' => ['type' => 'number', 'description' => 'Typical retail price in the store currency'],
                        'category' => ['type' => 'string', 'description' => 'Exactly one of the category names above'],
                    ], 'required' => ['name', 'short_description', 'description', 'price', 'category']],
                ],
            ],
            'required' => ['categories', 'products'],
        ];

        $strict = [
            'type' => 'object',
            'required' => ['categories', 'products'],
            'properties' => [
                'categories' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => [
                    'type' => 'object', 'required' => ['name'],
                    'properties' => ['name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 60]],
                ]],
                'products' => ['type' => 'array', 'minItems' => 3, 'maxItems' => self::PRODUCTS, 'items' => [
                    'type' => 'object', 'required' => ['name', 'price'],
                    'properties' => [
                        'name' => ['type' => 'string', 'minLength' => 2, 'maxLength' => 120],
                        'short_description' => ['type' => 'string', 'maxLength' => 200],
                        'description' => ['type' => 'string', 'maxLength' => 1000],
                        'price' => ['type' => 'number', 'minimum' => 0, 'maximum' => 100000],
                        'category' => ['type' => 'string', 'maxLength' => 60],
                    ],
                ]],
            ],
        ];

        return new StructuredRequest(
            route: 'copy',
            system: 'You create realistic sample product catalogs for new online stores. Use plausible, generic product '
                .'names (no real brands). Reply with a JSON object only.',
            prompt: implode("\n", [
                "Store: {$project->name}",
                "Niche: {$brief['niche']}",
                'Target audience: '.($brief['audience'] ?? 'not specified'),
                'Currency: '.($project->currency ?: 'USD'),
                '',
                'Create 2 or 3 product categories and '.self::PRODUCTS." products that this store would sell. Write every text in {$language}.",
                'Keep product and ingredient names from the niche exactly as written.',
            ]),
            schema: $schema,
            prepare: fn (array $data) => $this->sanitizer->sanitize($data, $strict),
            validate: fn (array $data) => $this->validator->errors($data, $strict),
            options: ['temperature' => 0.5, 'max_tokens' => 1500, 'timeout' => 150],
        );
    }

    /**
     * @param  array{categories: list<array{name: string}>, products: list<array<string, mixed>>}  $catalog
     */
    public function apply(Project $project, array $catalog): int
    {
        $categories = [];
        foreach ($catalog['categories'] as $category) {
            $categories[mb_strtolower($category['name'])] = $this->catalog->findOrCreateCategory($project, $category['name']);
        }

        $count = 0;
        foreach ($catalog['products'] as $item) {
            $category = $categories[mb_strtolower((string) ($item['category'] ?? ''))] ?? reset($categories) ?: null;

            $this->catalog->saveProduct($project, [
                'name' => $item['name'],
                'short_description' => $item['short_description'] ?? '',
                'description' => $item['description'] ?? '',
                'regular_price' => number_format(max(0, (float) $item['price']), 2, '.', ''),
                'status' => 'draft',
                'source' => 'ai',
                'featured' => $count < 4,
                'category_ids' => $category ? [$category->id] : [],
            ]);
            $count++;
        }

        return $count;
    }
}
