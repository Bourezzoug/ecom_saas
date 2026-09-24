<?php

use App\Domain\Credits\CreditLedger;
use App\Domain\Credits\Exceptions\InsufficientCreditsException;
use App\Domain\Credits\Exceptions\UnknownCreditActionException;
use App\Enums\CreditTransactionType;
use App\Models\CreditTransaction;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    config(['credits.actions' => [
        'generate_store' => 40,
        'regenerate_section' => 2,
        'product_description' => 1,
        'free_thing' => 0,
    ]]);

    $this->ledger = app(CreditLedger::class);
    $this->team = Team::factory()->create();
});

test('a new team starts with zero credits', function () {
    expect($this->ledger->balance($this->team))->toBe(0);
});

test('granting credits appends a ledger row and updates the cached balance', function () {
    $row = $this->ledger->grant($this->team, 100, 'test:grant', 'Welcome');

    expect($row->type)->toBe(CreditTransactionType::Grant)
        ->and($row->amount)->toBe(100)
        ->and($row->balance_after)->toBe(100)
        ->and($row->meta)->toBe(['reason' => 'Welcome'])
        ->and($this->team->credit_balance)->toBe(100)
        ->and($this->team->fresh()->credit_balance)->toBe(100);
});

test('spending charges the configured action cost', function () {
    $user = User::factory()->create();
    $this->ledger->grant($this->team, 100, 'test:grant');

    $spend = $this->ledger->spend($this->team, 'generate_store', $user);

    expect($spend->type)->toBe(CreditTransactionType::Spend)
        ->and($spend->amount)->toBe(-40)
        ->and($spend->action_key)->toBe('generate_store')
        ->and($spend->user_id)->toBe($user->id)
        ->and($spend->balance_after)->toBe(60)
        ->and($this->ledger->balance($this->team))->toBe(60);
});

test('per-unit actions are multiplied by quantity', function () {
    $this->ledger->grant($this->team, 100, 'test:grant');

    $spend = $this->ledger->spend($this->team, 'product_description', quantity: 12);

    expect($spend->amount)->toBe(-12)
        ->and($spend->meta['quantity'])->toBe(12);
});

test('spending more than the balance throws and writes nothing', function () {
    $this->ledger->grant($this->team, 10, 'test:grant');

    try {
        $this->ledger->spend($this->team, 'generate_store');
        $this->fail('Expected InsufficientCreditsException');
    } catch (InsufficientCreditsException $e) {
        expect($e->required)->toBe(40)->and($e->available)->toBe(10);
    }

    expect($this->ledger->balance($this->team))->toBe(10)
        ->and(CreditTransaction::where('type', 'spend')->count())->toBe(0);
});

test('free actions record nothing', function () {
    expect($this->ledger->spend($this->team, 'free_thing'))->toBeNull()
        ->and(CreditTransaction::count())->toBe(0);
});

test('unknown actions are rejected', function () {
    $this->ledger->spend($this->team, 'teleport');
})->throws(UnknownCreditActionException::class);

test('a failed action is refunded in full', function () {
    $this->ledger->grant($this->team, 100, 'test:grant');
    $spend = $this->ledger->spend($this->team, 'generate_store');

    $refund = $this->ledger->refund($spend, 'Planner failed');

    expect($refund->type)->toBe(CreditTransactionType::Refund)
        ->and($refund->amount)->toBe(40)
        ->and($refund->refunds_id)->toBe($spend->id)
        ->and($refund->action_key)->toBe('generate_store')
        ->and($this->ledger->balance($this->team))->toBe(100)
        ->and($spend->refund->is($refund))->toBeTrue();
});

test('refunding twice is idempotent', function () {
    $this->ledger->grant($this->team, 100, 'test:grant');
    $spend = $this->ledger->spend($this->team, 'generate_store');

    $first = $this->ledger->refund($spend);
    $second = $this->ledger->refund($spend);

    expect($second->is($first))->toBeTrue()
        ->and($this->ledger->balance($this->team))->toBe(100)
        ->and(CreditTransaction::where('type', 'refund')->count())->toBe(1);
});

test('a partial refund cannot exceed the spend', function () {
    $this->ledger->grant($this->team, 100, 'test:grant');
    $spend = $this->ledger->spend($this->team, 'generate_store');

    expect($this->ledger->refund($spend, amount: 10)->amount)->toBe(10);

    $this->ledger->refund($spend, amount: 41);
})->throws(InvalidArgumentException::class);

test('only spends can be refunded', function () {
    $grant = $this->ledger->grant($this->team, 100, 'test:grant');

    $this->ledger->refund($grant);
})->throws(InvalidArgumentException::class);

test('the same idempotency key never applies twice (webhook redelivery)', function () {
    $this->ledger->grant($this->team, 500, 'paddle:txn_123', type: CreditTransactionType::Purchase);
    $this->ledger->grant($this->team, 500, 'paddle:txn_123', type: CreditTransactionType::Purchase);

    expect($this->ledger->balance($this->team))->toBe(500)
        ->and($this->team->credit_balance)->toBe(500)
        ->and(CreditTransaction::count())->toBe(1);
});

test('an idempotency key cannot be reused across teams', function () {
    $other = Team::factory()->create();
    $this->ledger->grant($this->team, 5, 'shared-key');

    $this->ledger->grant($other, 5, 'shared-key');
})->throws(InvalidArgumentException::class);

test('adjustments can move the balance both ways but never below zero', function () {
    $this->ledger->grant($this->team, 20, 'test:grant');

    $this->ledger->adjust($this->team, -15, 'Abuse correction');
    expect($this->ledger->balance($this->team))->toBe(5);

    $this->ledger->adjust($this->team, -6, 'Too much');
})->throws(InsufficientCreditsException::class);

test('grant rejects non-positive amounts and spend types', function () {
    expect(fn () => $this->ledger->grant($this->team, 0, 'k1'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $this->ledger->grant($this->team, 5, 'k2', type: CreditTransactionType::Spend))->toThrow(InvalidArgumentException::class);
});

test('ledger rows are immutable', function () {
    $row = $this->ledger->grant($this->team, 10, 'test:grant');

    expect(fn () => $row->update(['amount' => 1000]))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

test('the database refuses a negative balance even if application code is bypassed', function () {
    DB::table('teams')->where('id', $this->team->id)->update(['credit_balance' => -1]);
})->throws(QueryException::class);

test('the cached balance always equals the sum of the ledger', function () {
    $this->ledger->grant($this->team, 100, 'g1');
    $a = $this->ledger->spend($this->team, 'generate_store');
    $this->ledger->spend($this->team, 'regenerate_section');
    $this->ledger->refund($a);
    $this->ledger->adjust($this->team, 3, 'bonus');

    expect($this->ledger->reconcile($this->team))->toBe(['cached' => 101, 'ledger' => 101, 'in_sync' => true]);
});

test('credits:reconcile reports drift', function () {
    $this->ledger->grant($this->team, 10, 'g1');
    $this->artisan('credits:reconcile')->assertSuccessful();

    DB::table('teams')->where('id', $this->team->id)->update(['credit_balance' => 99]);
    $this->artisan('credits:reconcile')->assertFailed();
});

test('credits:grant adds credits to a team by slug', function () {
    $this->artisan('credits:grant', ['team' => $this->team->slug, 'amount' => 25])->assertSuccessful();

    expect($this->ledger->balance($this->team))->toBe(25);
});
