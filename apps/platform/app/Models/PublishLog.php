<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Status of one item (page, product, menu...) within a publish run.
 *
 * @property int $id
 * @property string $publish_id
 * @property string $item_type
 * @property string|null $item_ref
 * @property string|null $label
 * @property string|null $action
 * @property string $status pending|ok|failed|conflict|skipped
 * @property int|null $remote_id
 * @property int|null $http_status
 * @property string|null $error
 * @property int $duration_ms
 */
class PublishLog extends Model
{
    public const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $guarded = ['id'];
}
