<?php

namespace App\Http\Controllers\Editor;

use App\Domain\Design\DesignTokenNormalizer;
use App\Domain\Design\FontCatalog;
use App\Domain\Editor\SectionEditor;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DesignController extends Controller
{
    public function update(Request $request, Team $team, Project $project, SectionEditor $editor): JsonResponse
    {
        Gate::authorize('update', $project);

        $hex = ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'];
        $fonts = FontCatalog::forLanguage($project->language);

        $data = $request->validate([
            'colors' => ['required', 'array'],
            'colors.primary' => $hex,
            'colors.secondary' => $hex,
            'colors.accent' => $hex,
            'colors.background' => $hex,
            'colors.text' => $hex,
            'fonts.heading' => ['required', Rule::in($fonts)],
            'fonts.body' => ['required', Rule::in($fonts)],
            'radius' => ['required', Rule::in(DesignTokenNormalizer::RADII)],
            'spacing' => ['required', Rule::in(DesignTokenNormalizer::SPACINGS)],
        ]);

        $tokens = $editor->updateTokens($project, [
            'colors' => array_intersect_key($data['colors'], array_flip(['primary', 'secondary', 'accent', 'background', 'text'])),
            'fonts' => $data['fonts'],
            'radius' => $data['radius'],
            'spacing' => $data['spacing'],
        ], $request->user());

        return response()->json(['tokens' => $tokens]);
    }
}
