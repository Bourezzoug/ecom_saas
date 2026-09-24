<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * A project's link to one WordPress site running the AISG Connector plugin.
 *
 * Auth model (docs/ARCHITECTURE.md §7.3):
 *  - plugin → platform: Sanctum token (the "connection key" the user pastes)
 *  - platform → plugin: HMAC signature with signing_secret (encrypted at rest)
 *
 * @property string $id
 * @property string $project_id
 * @property int $team_id
 * @property string|null $site_url
 * @property string $status pending|connected|error|revoked
 * @property string|null $signing_secret
 * @property string|null $key_last_four
 * @property string|null $wp_version
 * @property string|null $php_version
 * @property string|null $elementor_version
 * @property string|null $woocommerce_version
 * @property string|null $plugin_version
 * @property string|null $theme
 * @property array<string, mixed>|null $last_health
 * @property Carbon|null $last_health_at
 * @property string|null $last_error
 * @property Carbon|null $connected_at
 * @property-read Project $project
 */
class WpConnection extends Model implements AuthenticatableContract
{
    // Authenticatable: the plugin authenticates AS the connection (Sanctum), and
    // middleware such as the rate limiter needs an auth identifier.
    use Authenticatable, HasApiTokens, HasUlids;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @var list<string>
     */
    protected $hidden = ['signing_secret'];

    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<WpRemoteMapping, $this>
     */
    public function mappings(): HasMany
    {
        return $this->hasMany(WpRemoteMapping::class);
    }

    public function isConnected(): bool
    {
        return $this->status === 'connected' && $this->signing_secret !== null && $this->site_url !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'signing_secret' => 'encrypted',
            'last_health' => 'array',
            'last_health_at' => 'datetime',
            'connected_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
