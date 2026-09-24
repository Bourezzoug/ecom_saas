<?php

use App\Http\Controllers\Catalog\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Editor\AssetController;
use App\Http\Controllers\Editor\DesignController;
use App\Http\Controllers\Editor\EditorController;
use App\Http\Controllers\Editor\SectionController;
use App\Http\Controllers\Editor\VersionController;
use App\Http\Controllers\Projects\GenerationController;
use App\Http\Controllers\Projects\PreviewController;
use App\Http\Controllers\Projects\ProjectController;
use App\Http\Controllers\Publishing\PublishingController;
use App\Http\Controllers\SectionAssetController;
use App\Http\Controllers\Teams\TeamInvitationController;
use App\Http\Middleware\EnsureTeamMembership;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// Compiled section CSS/JS for previews (public, allowlisted files only).
Route::get('section-assets/{file}', SectionAssetController::class)
    ->where('file', '[a-z.]+')
    ->name('sections.assets');

Route::prefix('{current_team}')
    ->middleware(['auth', 'verified', EnsureTeamMembership::class])
    ->group(function () {
        Route::get('dashboard', DashboardController::class)->name('dashboard');

        Route::resource('projects', ProjectController::class);

        Route::post('projects/{project}/generate', [GenerationController::class, 'store'])
            ->middleware('throttle:ai')
            ->name('projects.generate');

        Route::get('projects/{project}/preview/{page?}', [PreviewController::class, 'show'])
            ->name('projects.preview');

        // ---- Catalog (M4) ----
        Route::prefix('projects/{project}/catalog')->name('catalog.')->group(function () {
            Route::get('/', [CatalogController::class, 'index'])->name('index');
            Route::post('products', [CatalogController::class, 'storeProduct'])->name('products.store');
            Route::put('products/{product}', [CatalogController::class, 'updateProduct'])->name('products.update');
            Route::delete('products/{product}', [CatalogController::class, 'destroyProduct'])->name('products.destroy');
            Route::post('products/publish-all', [CatalogController::class, 'publishAll'])->name('products.publish-all');
            Route::post('categories', [CatalogController::class, 'storeCategory'])->name('categories.store');
            Route::patch('categories/{category}', [CatalogController::class, 'updateCategory'])->name('categories.update');
            Route::delete('categories/{category}', [CatalogController::class, 'destroyCategory'])->name('categories.destroy');
            Route::post('import', [CatalogController::class, 'import'])->middleware('throttle:10,1')->name('import');
        });

        // ---- Publishing (M4) ----
        Route::prefix('projects/{project}/publish')->name('publishing.')->group(function () {
            Route::get('/', [PublishingController::class, 'show'])->name('show');
            Route::post('connection', [PublishingController::class, 'connect'])->name('connect');
            Route::post('connection/health', [PublishingController::class, 'health'])->middleware('throttle:20,1')->name('health');
            Route::delete('connection', [PublishingController::class, 'disconnect'])->name('disconnect');
            Route::post('/', [PublishingController::class, 'publish'])->middleware('throttle:10,1')->name('publish');
            Route::get('exports/{publish}', [PublishingController::class, 'download'])->name('download');
            Route::get('plugin', [PublishingController::class, 'plugin'])->middleware('throttle:10,1')->name('plugin');
        });

        // ---- Visual editor (M3) ----
        Route::get('projects/{project}/editor', [EditorController::class, 'show'])->name('editor.show');

        Route::prefix('projects/{project}/editor')->name('editor.')->group(function () {
            Route::get('sections/{section}', [SectionController::class, 'show'])->name('sections.show');
            Route::patch('sections/{section}', [SectionController::class, 'update'])->name('sections.update');
            Route::delete('sections/{section}', [SectionController::class, 'destroy'])->name('sections.destroy');
            Route::post('sections/{section}/regenerate', [SectionController::class, 'regenerate'])
                ->middleware('throttle:ai')
                ->name('sections.regenerate');

            Route::post('pages/{page}/sections', [SectionController::class, 'store'])->name('pages.sections.store');
            Route::put('pages/{page}/sections/order', [SectionController::class, 'reorder'])->name('pages.sections.reorder');
            Route::patch('pages/{page}', [SectionController::class, 'renamePage'])->name('pages.update');

            Route::put('design', [DesignController::class, 'update'])->name('design.update');

            Route::get('versions', [VersionController::class, 'index'])->name('versions.index');
            Route::post('versions', [VersionController::class, 'store'])->name('versions.store');
            Route::post('versions/{version}/restore', [VersionController::class, 'restore'])->name('versions.restore');

            Route::post('assets', [AssetController::class, 'store'])
                ->middleware('throttle:30,1')
                ->name('assets.store');
        });
    });

Route::middleware(['auth'])->group(function () {
    Route::post('invitations/{invitation}/accept', [TeamInvitationController::class, 'accept'])->name('invitations.accept');
    Route::delete('invitations/{invitation}', [TeamInvitationController::class, 'decline'])->name('invitations.decline');
});

require __DIR__.'/settings.php';
