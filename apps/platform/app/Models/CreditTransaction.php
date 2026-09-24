<?php

namespace App\Models;

use App\Enums\CreditTransactionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only ledger row. Only App\Domain\Credits\CreditLedger writes these.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $user_id
 * @property CreditTransactionType $type
 * @property int $amount
 * @property int $balance_after
 * @property string|null $action_key
 * @property string|null $generation_id
 * @property int|null $refunds_id
 * @property string $idempotency_key
 * @property array<string, mixed> $meta
 * @property Carbon $created_at
 * @property-read CreditTransaction|null $refund
 */
class CreditTransaction extends Model
{
    public const UPDATED_AT = null;

    /**
     * The attributes that aren't mass assignable.
     *
     * @var list<string>
     */
    protected $guarded = ['id'];

    /**
     * Bootstrap the model: ledger rows are immutable once written.
     */
    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Credit transactions are append-only.'));
        static::deleting(fn () => throw new LogicException('Credit transactions are append-only.'));
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Generation, $this>
     */
    public function generation(): BelongsTo
    {
        return $this->belongsTo(Generation::class);
    }

    /**
     * The refund row that reversed this spend, if any.
     *
     * @return HasOne<CreditTransaction, $this>
     */
    public function refund(): HasOne
    {
        return $this->hasOne(CreditTransaction::class, 'refunds_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => CreditTransactionType::class,
            'amount' => 'integer',
            'balance_after' => 'integer',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
