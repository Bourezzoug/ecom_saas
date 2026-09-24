<?php

use App\Models\User;

test('new users get the signup grant on their personal team', function () {
    config(['credits.signup_grant' => 100]);

    $this->post(route('register.store'), [
        'name' => 'Agency Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $team = User::where('email', 'owner@example.com')->first()->personalTeam();

    expect($team->credit_balance)->toBe(100)
        ->and($team->creditTransactions()->sole()->idempotency_key)->toBe('signup:'.$team->owner()->id);
});

test('no grant is written when the signup grant is disabled', function () {
    config(['credits.signup_grant' => 0]);

    $this->post(route('register.store'), [
        'name' => 'Agency Owner',
        'email' => 'owner@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $team = User::where('email', 'owner@example.com')->first()->personalTeam();

    expect($team->credit_balance)->toBe(0)
        ->and($team->creditTransactions()->count())->toBe(0);
});
