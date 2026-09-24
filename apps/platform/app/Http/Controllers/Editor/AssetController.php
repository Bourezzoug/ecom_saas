<?php

namespace App\Http\Controllers\Editor;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rules\File;

/**
 * Image uploads for section image fields. Files are validated by content
 * (mimetypes sniffed from the bytes, not the extension) and stored under the
 * project's folder on the public disk.
 */
class AssetController extends Controller
{
    public const MAX_KB = 5120;

    public function store(Request $request, Team $team, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $request->validate([
            'file' => ['required', File::types(['jpg', 'jpeg', 'png', 'webp', 'gif'])->max(self::MAX_KB), 'dimensions:max_width=6000,max_height=6000'],
        ]);

        $file = $request->file('file');
        $path = $file->storePublicly("projects/{$project->id}", 'public');
        [$width, $height] = @getimagesize($file->getRealPath()) ?: [null, null];

        $asset = Asset::create([
            'team_id' => $project->team_id,
            'project_id' => $project->id,
            'uploaded_by' => $request->user()->id,
            'kind' => 'section',
            'disk' => 'public',
            'path' => $path,
            'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'width' => $width,
            'height' => $height,
            'checksum' => hash_file('sha256', $file->getRealPath()),
        ]);

        return response()->json(['asset' => ['id' => $asset->id, 'url' => $asset->url(), 'width' => $width, 'height' => $height]], 201);
    }
}
