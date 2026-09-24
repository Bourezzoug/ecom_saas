<?php

namespace App\Domain\Publishing;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

/**
 * Builds the installable connector plugin ZIP from the monorepo:
 *
 *   apps/wp-connector/            → aisg-connector/ (code + vendor/)
 *   sections/                     → aisg-connector/sections/
 *   packages/renderer-php/src/    → aisg-connector/lib/renderer-php/src/
 *   packages/section-styles/dist/ → aisg-connector/assets/runtime/
 *
 * In Docker dev the same folders are mounted into the staging site instead.
 */
class PluginPackager
{
    private const EXCLUDE = ['tests', 'node_modules', '.git', 'lib', 'sections', 'assets/runtime', '.phpunit.cache'];

    public function build(string $target): string
    {
        $root = realpath(base_path('../..')) ?: throw new RuntimeException('Monorepo root not found.');
        $plugin = "{$root}/apps/wp-connector";

        if (! is_file("{$plugin}/vendor/autoload.php")) {
            throw new RuntimeException('Plugin dependencies missing: run composer install in apps/wp-connector.');
        }

        $zip = new ZipArchive;
        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Cannot create {$target}.");
        }

        $this->addDirectory($zip, $plugin, 'aisg-connector', self::EXCLUDE);
        $this->addDirectory($zip, "{$root}/sections", 'aisg-connector/sections', ['_fixtures']);
        $this->addDirectory($zip, "{$root}/packages/renderer-php/src", 'aisg-connector/lib/renderer-php/src');
        $this->addDirectory($zip, "{$root}/packages/section-styles/dist", 'aisg-connector/assets/runtime');

        $zip->close();

        return $target;
    }

    /**
     * @param  list<string>  $exclude  Paths relative to $source.
     */
    private function addDirectory(ZipArchive $zip, string $source, string $prefix, array $exclude = []): void
    {
        $source = rtrim(str_replace('\\', '/', $source), '/');
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile()) {
                continue;
            }

            $relative = ltrim(substr(str_replace('\\', '/', $file->getPathname()), strlen($source)), '/');

            foreach ($exclude as $skip) {
                if ($relative === $skip || str_starts_with($relative, $skip.'/')) {
                    continue 2;
                }
            }

            $zip->addFile($file->getPathname(), "{$prefix}/{$relative}");
        }
    }
}
