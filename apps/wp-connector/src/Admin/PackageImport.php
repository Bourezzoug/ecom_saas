<?php

namespace Aisg\Connector\Admin;

use Aisg\Connector\Import\ConflictException;
use Aisg\Connector\Import\Importer;
use Aisg\Connector\Import\Media;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Imports aisg-package.zip (manifest.json + media/) with the exact same
 * importer a live publish uses (spec §5.6).
 */
final class PackageImport
{
    /**
     * @return array{ok: int, failed: int, conflicts: int, messages: list<string>}
     */
    public function run(string $zipPath, bool $force): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('The PHP zip extension is required to import packages.');
        }

        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']).'aisg-import-'.wp_generate_password(12, false);
        wp_mkdir_p($dir);

        try {
            $zip = new ZipArchive;
            if ($zip->open($zipPath) !== true) {
                throw new RuntimeException('This is not a valid ZIP file.');
            }

            // Only the manifest and media/ are extracted (no path traversal, no PHP).
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if ($name === 'manifest.json' || preg_match('~^media/[A-Za-z0-9._-]+\.(jpe?g|png|webp|gif)$~i', $name)) {
                    $zip->extractTo($dir, $name);
                }
            }
            $zip->close();

            $manifest = json_decode((string) @file_get_contents($dir.'/manifest.json'), true);
            if (! is_array($manifest) || ($manifest['package_version'] ?? 0) !== 1) {
                throw new RuntimeException('manifest.json is missing or has an unsupported version.');
            }

            return $this->import($manifest, new Importer(new Media($manifest['assets'] ?? [], $dir)), $force);
        } finally {
            $this->remove($dir);
        }
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array{ok: int, failed: int, conflicts: int, messages: list<string>}
     */
    private function import(array $package, Importer $importer, bool $force): array
    {
        $report = ['ok' => 0, 'failed' => 0, 'conflicts' => 0, 'messages' => []];
        $step = function (string $label, callable $fn) use (&$report) {
            try {
                $fn();
                $report['ok']++;
            } catch (ConflictException $e) {
                $report['conflicts']++;
                $report['messages'][] = "{$label}: {$e->getMessage()}";
            } catch (Throwable $e) {
                $report['failed']++;
                $report['messages'][] = "{$label}: {$e->getMessage()}";
            }
        };

        $step('Design tokens', fn () => $importer->designTokens((array) ($package['design_tokens'] ?? []), (array) $package['project']));

        foreach ($package['assets'] ?? [] as $asset) {
            $step('Image', fn () => $importer->asset($asset));
        }
        foreach ($package['categories'] ?? [] as $category) {
            $step('Category '.$category['name'], fn () => $importer->category($category));
        }
        foreach ($package['products'] ?? [] as $product) {
            $step('Product '.$product['name'], fn () => $importer->product($product));
        }
        foreach ($package['layout_parts'] ?? [] as $part) {
            $step(ucfirst($part['kind']), fn () => $importer->layoutPart($part));
        }
        foreach ($package['pages'] ?? [] as $page) {
            $step('Page '.$page['title'], fn () => $importer->page($page, $force));
        }
        foreach ($package['menus'] ?? [] as $menu) {
            $step('Menu', fn () => $importer->menu($menu['location'], $menu['items']));
        }
        foreach ($package['pages'] ?? [] as $page) {
            if (! empty($page['is_homepage'])) {
                $step('Homepage', fn () => $importer->homepage($page['ref']));
            }
        }
        $step('Cache', fn () => $importer->clearCache());

        return $report;
    }

    private function remove(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($dir);
    }
}
