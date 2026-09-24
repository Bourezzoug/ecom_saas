<?php

namespace App\Domain\Catalog;

use App\Models\Product;
use App\Models\Project;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Imports a WooCommerce product CSV (the format of WooCommerce › Products ›
 * Export). Supported: simple and variable products with their variation rows,
 * categories ("Clothing > Hoodies, Sale"), images (comma-separated URLs),
 * prices, stock, featured flag and "Attribute N" columns.
 *
 * Re-importing is idempotent: products are matched by SKU, then by name.
 */
class WooCsvImporter
{
    public const MAX_ROWS = 2000;

    /** @var list<string> */
    private array $errors = [];

    private int $created = 0;

    private int $updated = 0;

    private int $skipped = 0;

    public function __construct(private readonly CatalogService $catalog) {}

    /**
     * @return array{created: int, updated: int, skipped: int, errors: list<string>}
     */
    public function import(Project $project, string $path): array
    {
        $rows = $this->read($path);
        $parents = [];
        $variations = [];

        DB::transaction(function () use ($project, $rows, &$parents, &$variations) {
            foreach ($rows as $line => $row) {
                $types = array_map('trim', explode(',', strtolower($row['type'] ?? 'simple')));

                if (in_array('variation', $types, true)) {
                    $variations[] = [$line, $row];

                    continue;
                }

                if (! array_intersect($types, ['simple', 'variable'])) {
                    $this->skip($line, 'unsupported product type "'.($row['type'] ?? '').'" (only simple and variable are imported)');

                    continue;
                }

                $product = $this->importProduct($project, $line, $row, in_array('variable', $types, true) ? 'variable' : 'simple');

                if ($product !== null) {
                    foreach (array_filter([$row['id'] ?? null, $row['sku'] ?? null]) as $key) {
                        $parents[$this->parentKey($key)] = $product;
                    }
                }
            }

            foreach ($variations as [$line, $row]) {
                $this->attachVariation($parents, $line, $row);
            }

            foreach ($parents as $product) {
                if ($product->isDirty()) {
                    $product->save();
                }
            }
        });

        return [
            'created' => $this->created,
            'updated' => $this->updated,
            'skipped' => $this->skipped,
            'errors' => $this->errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function importProduct(Project $project, int $line, array $row, string $type): ?Product
    {
        $name = trim($row['name'] ?? '');

        if ($name === '') {
            $this->skip($line, 'missing product name');

            return null;
        }

        $sku = trim($row['sku'] ?? '') ?: null;
        $existing = $sku !== null
            ? $project->products()->where('sku', $sku)->first()
            : $project->products()->where('name', $name)->first();

        $categoryIds = [];
        foreach ($this->splitList($row['categories'] ?? '') as $path) {
            $categoryIds[] = $this->catalog->categoryPath($project, $path)?->id;
        }

        $regular = $this->price($row['regular price'] ?? null);
        $sale = $this->price($row['sale price'] ?? null);

        $product = $this->catalog->saveProduct($project, [
            'type' => $type,
            'name' => mb_substr($name, 0, 255),
            'sku' => $sku !== null ? mb_substr($sku, 0, 100) : null,
            'regular_price' => $regular,
            'sale_price' => $sale !== null && ($regular === null || $sale < $regular) ? $sale : null,
            'short_description' => $this->text($row['short description'] ?? ''),
            'description' => $this->text($row['description'] ?? ''),
            'images' => array_values(array_map(
                fn (string $url) => ['asset_id' => null, 'url' => $url, 'alt' => $name],
                array_filter($this->splitList($row['images'] ?? ''), fn ($u) => preg_match('~^https?://~i', $u) === 1),
            )),
            'attributes' => $this->attributes($row),
            'variations' => $existing !== null ? $existing->variations : [],
            'stock_status' => $this->stockStatus($row),
            'stock_quantity' => is_numeric($row['stock'] ?? null) ? (int) $row['stock'] : null,
            'status' => ($row['published'] ?? '1') === '1' ? 'publish' : 'draft',
            'featured' => ($row['is featured?'] ?? '0') === '1',
            'source' => 'csv',
            'category_ids' => array_values(array_filter($categoryIds)),
        ], $existing);

        $existing ? $this->updated++ : $this->created++;

        // Variations are re-attached from this file.
        if ($type === 'variable') {
            $product->variations = [];
        }

        return $product;
    }

    /**
     * @param  array<string, Product>  $parents
     * @param  array<string, string>  $row
     */
    private function attachVariation(array $parents, int $line, array $row): void
    {
        $parent = $parents[$this->parentKey($row['parent'] ?? '')] ?? null;

        if ($parent === null || $parent->type !== 'variable') {
            $this->skip($line, 'variation without a matching variable parent ("'.($row['parent'] ?? '').'")');

            return;
        }

        $attributes = [];
        foreach ($this->attributes($row) as $attribute) {
            $attributes[$attribute['name']] = $attribute['options'][0] ?? '';
        }

        $variations = $parent->variations;
        $variations[] = [
            'ref' => (string) Str::ulid(),
            'sku' => trim($row['sku'] ?? '') ?: null,
            'attributes' => $attributes,
            'regular_price' => $this->price($row['regular price'] ?? null),
            'sale_price' => $this->price($row['sale price'] ?? null),
            'stock_status' => $this->stockStatus($row),
            'image' => $this->splitList($row['images'] ?? '')[0] ?? null,
        ];
        $parent->variations = $variations;
    }

    /**
     * Read the CSV into rows keyed by lower-cased header (line number => row).
     *
     * @return array<int, array<string, string>>
     */
    private function read(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The file could not be read.');
        }

        $header = fgetcsv($handle, escape: '\\');

        if (! is_array($header) || count($header) < 2) {
            throw new RuntimeException('This does not look like a CSV file with a header row.');
        }

        $header = array_map(fn ($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $h) ?? '')), $header);

        if (! in_array('name', $header, true)) {
            throw new RuntimeException('The CSV needs at least a "Name" column (WooCommerce export format).');
        }

        $rows = [];
        $line = 1;

        while (($values = fgetcsv($handle, escape: '\\')) !== false) {
            $line++;

            if ($values === [null]) { // blank line
                continue;
            }

            if (count($rows) >= self::MAX_ROWS) {
                $this->errors[] = 'Only the first '.self::MAX_ROWS.' rows were imported.';

                break;
            }

            $rows[$line] = array_combine($header, array_map(
                fn ($v) => (string) $v,
                array_pad(array_slice($values, 0, count($header)), count($header), ''),
            ));
        }

        fclose($handle);

        return $rows;
    }

    /**
     * "Attribute N name" / "Attribute N value(s)" pairs.
     *
     * @param  array<string, string>  $row
     * @return list<array{name: string, options: list<string>, variation: bool}>
     */
    private function attributes(array $row): array
    {
        $attributes = [];

        for ($i = 1; $i <= 10; $i++) {
            $name = trim($row["attribute {$i} name"] ?? '');

            if ($name === '') {
                continue;
            }

            $attributes[] = [
                'name' => mb_substr($name, 0, 60),
                'options' => array_map(fn ($o) => mb_substr($o, 0, 60), $this->splitList($row["attribute {$i} value(s)"] ?? '')),
                'variation' => true,
            ];
        }

        return $attributes;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function stockStatus(array $row): string
    {
        $value = strtolower(trim($row['in stock?'] ?? '1'));

        return match ($value) {
            '0' => 'outofstock',
            'backorder' => 'onbackorder',
            default => 'instock',
        };
    }

    private function price(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' && is_numeric($value) ? number_format((float) $value, 2, '.', '') : null;
    }

    private function text(string $value): string
    {
        // WooCommerce exports HTML descriptions with literal "\n": keep plain text only.
        $value = str_replace(['\n', '<br>', '<br />', '</p>'], "\n", $value);

        return trim(mb_substr(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 5000));
    }

    /**
     * @return list<string>
     */
    private function splitList(string $value): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $value)), fn ($v) => $v !== ''));
    }

    private function parentKey(string $value): string
    {
        return strtolower(trim(preg_replace('/^id:/i', '', $value) ?? ''));
    }

    private function skip(int $line, string $reason): void
    {
        $this->skipped++;
        $this->errors[] = "Row {$line}: {$reason}";
    }
}
