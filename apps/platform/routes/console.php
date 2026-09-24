<?php

use App\Models\TeamInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    TeamInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired team invitations');

Schedule::command('credits:reconcile')->dailyAt('03:00')->description('Verify cached credit balances against the ledger');

Schedule::command('horizon:snapshot')->everyFiveMinutes();
