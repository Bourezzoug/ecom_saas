<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the compiled section runtime (packages/section-styles/dist) to previews.
 * Only allowlisted file names; everything else is a 404.
 */
class SectionAssetController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        abort_unless(in_array($file, config('sections.public_assets'), true), 404);

        $path = rtrim(config('sections.styles_path'), '/').'/'.$file;

        abort_unless(is_file($path), 404, 'Section assets are not built. Run `npm run build` in packages/section-styles.');

        return response()->file($path, [
            'Content-Type' => str_ends_with($file, '.css') ? 'text/css; charset=UTF-8' : 'application/javascript; charset=UTF-8',
            'Cache-Control' => app()->isLocal() ? 'no-cache' : 'public, max-age=3600',
        ]);
    }
}
