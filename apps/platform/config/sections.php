<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Section library
    |--------------------------------------------------------------------------
    |
    | The single source of truth for section types (docs/ARCHITECTURE.md §4).
    | Paths are relative to the monorepo, which Docker mounts at the same layout.
    |
    */

    'path' => env('SECTIONS_PATH', base_path('../../sections')),

    // Compiled CSS + Alpine runtime (packages/section-styles, `npm run build`).
    'styles_path' => env('SECTION_STYLES_PATH', base_path('../../packages/section-styles/dist')),

    // Files the preview may serve from styles_path.
    'public_assets' => ['sections.css', 'aisg.js', 'alpine.min.js'],

];
