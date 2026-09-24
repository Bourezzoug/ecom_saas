<?php

namespace App\Console\Commands;

use App\Domain\Credits\CreditLedger;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreditsGrantCommand extends Command
{
    protected $signature = 'credits:grant {team : Team slug or id} {amount : Positive number of credits} {--reason=Manual grant}';

    protected $description = 'Grant credits to a team (operator tool until billing lands)';

    public function handle(CreditLedger $ledger): int
    {
        $key = (string) $this->argument('team');
        $team = Team::query()->where('slug', $key)->orWhere('id', ctype_digit($key) ? (int) $key : 0)->first();

        if ($team === null) {
            $this->components->error("Team [{$key}] not found.");

            return self::FAILURE;
        }

        $amount = (int) $this->argument('amount');

        if ($amount <= 0) {
            $this->components->error('Amount must be a positive integer.');

            return self::FAILURE;
        }

        $ledger->grant($team, $amount, 'cli-grant:'.Str::ulid(), (string) $this->option('reason'));

        $this->components->info("Granted {$amount} credits to [{$team->name}]. Balance: {$team->credit_balance}.");

        return self::SUCCESS;
    }
}
