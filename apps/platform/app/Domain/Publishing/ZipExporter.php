<?php

namespace App\Domain\Publishing;

use App\Models\Asset;
use App\Models\Publish;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use ZipArchive;

/**
 * "Download ZIP" (spec §5.6) for users who don't connect their site:
 *
 *   aisg-{project}.zip
 *   ├── README.txt
 *   ├── aisg-connector.zip   (install as a plugin)
 *   └── aisg-package.zip     (upload in AISG Connector › Import package)
 *       ├── manifest.json    (the same package a live publish pushes)
 *       └── media/…          (uploaded images; external image URLs stay URLs)
 */
class ZipExporter
{
    public function __construct(
        private readonly PackageBuilder $packages,
        private readonly PluginPackager $plugin,
    ) {}

    public function run(Publish $publish): void
    {
        $publish->update(['status' => 'running', 'started_at' => now()]);
        $project = $publish->project;
        $tmp = 'tmp/'.Str::ulid();
        Storage::disk('local')->makeDirectory($tmp);
        $work = Storage::disk('local')->path($tmp);

        try {
            $package = $this->packages->build($project);
            $packageZip = "{$work}/aisg-package.zip";
            $this->writePackage($package, $packageZip);
            $pluginZip = $this->plugin->build("{$work}/aisg-connector.zip");

            $name = 'aisg-'.Str::slug($project->name).'-'.now()->format('Ymd-His').'.zip';
            $finalPath = "exports/{$project->id}/{$name}";
            $local = Storage::disk('local');
            $local->makeDirectory(dirname($finalPath));

            $zip = new ZipArchive;
            $zip->open($local->path($finalPath), ZipArchive::CREATE | ZipArchive::OVERWRITE);
            $zip->addFile($pluginZip, 'aisg-connector.zip');
            $zip->addFile($packageZip, 'aisg-package.zip');
            $zip->addFromString('README.txt', $this->readme($project->name));
            $zip->close();

            $asset = Asset::create([
                'team_id' => $project->team_id,
                'project_id' => $project->id,
                'uploaded_by' => $publish->triggered_by,
                'kind' => 'export',
                'disk' => 'local',
                'path' => $finalPath,
                'original_name' => $name,
                'mime' => 'application/zip',
                'size' => (int) $local->size($finalPath),
                'checksum' => hash_file('sha256', $local->path($finalPath)),
            ]);

            $publish->update([
                'status' => 'succeeded',
                'export_asset_id' => $asset->id,
                'summary' => ['pages' => count($package['pages']), 'products' => count($package['products']), 'assets' => count($package['assets'])],
                'finished_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);
            $publish->update(['status' => 'failed', 'error' => $e->getMessage(), 'finished_at' => now()]);
        } finally {
            Storage::disk('local')->deleteDirectory($tmp);
        }
    }

    /**
     * @param  array<string, mixed>  $package
     */
    private function writePackage(array $package, string $target): void
    {
        $zip = new ZipArchive;
        $zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        foreach ($package['assets'] as $i => $asset) {
            if (($asset['file'] ?? null) && ($asset['disk'] ?? null) && Storage::disk($asset['disk'])->exists($asset['file'])) {
                $entry = 'media/'.$asset['ref'].'.'.pathinfo($asset['file'], PATHINFO_EXTENSION);
                $zip->addFile(Storage::disk($asset['disk'])->path($asset['file']), $entry);
                $package['assets'][$i]['file'] = $entry;
            } else {
                $package['assets'][$i]['file'] = null; // plugin downloads the URL
            }
            unset($package['assets'][$i]['disk']);
        }

        $zip->addFromString('manifest.json', (string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $zip->close();
    }

    private function readme(string $project): string
    {
        return <<<TXT
        {$project}: export from AI Store Generator
        ===========================================

        Requirements: WordPress 6.5+, PHP 8.1+, Elementor (free), WooCommerce,
        and the Hello Elementor theme (recommended).

        1. WordPress › Plugins › Add New › Upload Plugin → aisg-connector.zip → Activate.
        2. WordPress › AISG Connector › Import package → upload aisg-package.zip.
        3. Your pages, products, menu and design are created. Edit them in Elementor.

        Importing again updates the same pages and products instead of duplicating them.
        TXT;
    }
}
