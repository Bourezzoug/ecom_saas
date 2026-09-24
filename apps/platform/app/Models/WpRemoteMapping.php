<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Our ref (page/product/category/asset ULID) ↔ WordPress post/term id, per site.
 * What makes re-publishing update instead of duplicating.
 *
 * @property int $id
 * @property string $wp_connection_id
 * @property string $local_type
 * @property string $local_ref
 * @property int $remote_id
 * @property string|null $published_hash
 */
class WpRemoteMapping extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }
}
