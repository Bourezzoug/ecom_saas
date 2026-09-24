<?php

namespace App\Domain\Credits;

use App\Domain\Credits\Exceptions\InsufficientCreditsException;
use App\Domain\Credits\Exceptions\UnknownCreditActionException;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Generation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The only write path for credits.
 *
 * Every change is one append-only credit_transactions row, written in the same
 * DB transaction as teams.credit_balance, under a row lock on the team.
 * Concurrent spends on one team are therefore serialised and the balance can
 * never go negative (also enforced by CHECK constraints). Every write carries
 * an idempotency key, so webhook re-deliveries and job retries are no-ops.
 */
class CreditLedger
{
    public function __construct(protected Config $config) {}

    /**
     * The credit cost of an action from config/credits.php.
     *
     * @throws UnknownCreditActionException
     */
    public function cost(string $action, int $quantity = 1): int
    {
        $cost = $this->config->get("credits.actions.{$action}");

        if (! is_int($cost)) {
            throw UnknownCreditActionException::for($action);
        }

        return $cost * max(1, $quantity);
    }

    /**
     * The team's current balance, read from the database (not the in-memory model).
     */
    public function balance(Team $team): int
    {
        return (int) Team::query()->whereKey($team->getKey())->value('credit_balance');
    }

    public function canAfford(Team $team, string $action, int $quantity = 1): bool
    {
        return $this->balance($team) >= $this->cost($action, $quantity);
    }

    /**
     * Charge a team for an AI action. Returns null when the action is free.
     *
     * @param  array<string, mixed>  $meta
     *
     * @throws InsufficientCreditsException
     */
    public function spend(
        Team $team,
        string $action,
        ?User $user = null,
        ?Generation $generation = null,
        int $quantity = 1,
        ?string $idempotencyKey = null,
        array $meta = [],
    ): ?CreditTransaction {
        $cost = $this->cost($action, $quantity);

        if ($cost === 0) {
            return null;
        }

        return $this->record($team, CreditTransactionType::Spend, -$cost, [
            'user_id' => $user?->getKey(),
            'action_key' => $action,
            'generation_id' => $generation?->getKey(),
            'idempotency_key' => $idempotencyKey ?? 'spend:'.Str::ulid(),
            'meta' => ['quantity' => $quantity, ...$meta],
        ]);
    }

    /**
     * Reverse a spend (e.g. the AI action failed). Refunding twice returns the
     * existing refund instead of crediting again.
     *
     * @param  int|null  $amount  Partial refund; defaults to the full spend.
     */
    public function refund(CreditTransaction $spend, ?string $reason = null, ?int $amount = null): CreditTransaction
    {
        if ($spend->type !== CreditTransactionType::Spend) {
            throw new InvalidArgumentException('Only spend transactions can be refunded.');
        }

        $amount ??= -$spend->amount;

        if ($amount <= 0 || $amount > -$spend->amount) {
            throw new InvalidArgumentException('Refund amount must be between 1 and the spent amount.');
        }

        return $this->record($spend->team, CreditTransactionType::Refund, $amount, [
            'user_id' => $spend->user_id,
            'action_key' => $spend->action_key,
            'generation_id' => $spend->generation_id,
            'refunds_id' => $spend->getKey(),
            'idempotency_key' => 'refund:'.$spend->getKey(),
            'meta' => array_filter(['reason' => $reason]),
        ]);
    }

    /**
     * Add credits: subscription grants, signup bonus, purchased packs.
     *
     * @param  array<string, mixed>  $meta
     */
    public function grant(
        Team $team,
        int $amount,
        string $idempotencyKey,
        ?string $reason = null,
        CreditTransactionType $type = CreditTransactionType::Grant,
        ?Model $reference = null,
        array $meta = [],
    ): CreditTransaction {
        if (! in_array($type, [CreditTransactionType::Grant, CreditTransactionType::Purchase], true)) {
            throw new InvalidArgumentException('grant() only records grant or purchase transactions.');
        }

        if ($amount <= 0) {
            throw new InvalidArgumentException('Granted amount must be positive.');
        }

        return $this->record($team, $type, $amount, [
            'reference_type' => $reference?->getMorphClass(),
            'reference_id' => $reference?->getKey(),
            'idempotency_key' => $idempotencyKey,
            'meta' => array_filter(['reason' => $reason, ...$meta]),
        ]);
    }

    /**
     * Manual correction by an operator (signed amount).
     */
    public function adjust(Team $team, int $amount, string $reason, ?User $by = null, ?string $idempotencyKey = null): CreditTransaction
    {
        if ($amount === 0) {
            throw new InvalidArgumentException('Adjustment amount cannot be zero.');
        }

        return $this->record($team, CreditTransactionType::Adjustment, $amount, [
            'user_id' => $by?->getKey(),
            'idempotency_key' => $idempotencyKey ?? 'adjust:'.Str::ulid(),
            'meta' => ['reason' => $reason],
        ]);
    }

    /**
     * Compare the cached balance with the ledger sum.
     *
     * @return array{cached: int, ledger: int, in_sync: bool}
     */
    public function reconcile(Team $team): array
    {
        $cached = $this->balance($team);
        $ledger = (int) CreditTransaction::query()->where('team_id', $team->getKey())->sum('amount');

        return ['cached' => $cached, 'ledger' => $ledger, 'in_sync' => $cached === $ledger];
    }

    /**
     * Append a ledger row and move the cached balance, atomically.
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InsufficientCreditsException
     */
    protected function record(Team $team, CreditTransactionType $type, int $amount, array $attributes): CreditTransaction
    {
        $transaction = DB::transaction(function () use ($team, $type, $amount, $attributes) {
            // Serialises all ledger writes for this team.
            $balance = (int) Team::query()
                ->whereKey($team->getKey())
                ->lockForUpdate()
                ->value('credit_balance');

            $existing = CreditTransaction::query()
                ->where('idempotency_key', $attributes['idempotency_key'])
                ->first();

            if ($existing !== null) {
                if ($existing->team_id !== $team->getKey()) {
                    throw new InvalidArgumentException('Idempotency key already used by another team.');
                }

                return $existing;
            }

            $newBalance = $balance + $amount;

            if ($newBalance < 0) {
                throw new InsufficientCreditsException(required: -$amount, available: $balance);
            }

            $row = CreditTransaction::query()->create([
                ...$attributes,
                'team_id' => $team->getKey(),
                'type' => $type,
                'amount' => $amount,
                'balance_after' => $newBalance,
            ]);

            Team::query()->whereKey($team->getKey())->update(['credit_balance' => $newBalance]);

            return $row;
        });

        // Re-read rather than use balance_after: an idempotent replay returns an older row.
        $team->setAttribute('credit_balance', $this->balance($team));
        $team->syncOriginalAttribute('credit_balance');

        return $transaction;
    }
}
