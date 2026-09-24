<?php

namespace App\Providers;

use App\Domain\Ai\AiManager;
use App\Domain\Ai\Contracts\AiProvider;
use App\Domain\Credits\CreditLedger;
use App\Domain\Sections\SectionLibrary;
use App\Models\Project;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(AiManager::class, fn ($app) => new AiManager($app));
        $this->app->bind(AiProvider::class, fn ($app) => $app->make(AiManager::class)->driver());
        $this->app->singleton(CreditLedger::class);
        $this->app->singleton(SectionLibrary::class, fn () => new SectionLibrary(config('sections.path')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRouteBindings();
        $this->configureRateLimits();
    }

    /**
     * AI endpoints cost money and GPU time: limit per user and per team.
     */
    protected function configureRateLimits(): void
    {
        RateLimiter::for('ai', fn (Request $request) => [
            Limit::perMinute(6)->by('ai-user:'.$request->user()?->id),
            Limit::perMinute(20)->by('ai-team:'.$request->user()?->current_team_id),
        ]);
    }

    /**
     * Team-scoped route bindings: a project is only resolvable under its own
     * team's URL, so /{other-team}/projects/{id} is a 404 rather than a leak.
     */
    protected function configureRouteBindings(): void
    {
        Route::bind('current_team', fn (string $slug) => Team::query()->where('slug', $slug)->firstOrFail());

        Route::bind('project', function (string $id, RoutingRoute $route) {
            $team = $route->parameter('current_team');
            $teamId = $team instanceof Team ? $team->id : Team::query()->where('slug', $team)->value('id');

            return Project::query()->where('team_id', $teamId)->findOrFail($id);
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
