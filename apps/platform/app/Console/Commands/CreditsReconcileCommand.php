<?php

namespace App\Console\Commands;

use App\Domain\Credits\CreditLedger;
use App\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CreditsReconcileCommand extends Command
{
    protected $signature = 'credits:reconcile';

    protected $description = 'Check every team\'s cached credit balance against the ledger';

    public function handle(CreditLedger $ledger): int
    {
        $drifted = 0;

        Team::query()->withTrashed()->lazyById()->each(function (Team $team) use ($ledger, &$drifted) {
            $result = $ledger->reconcile($team);

            if (! $result['in_sync']) {
                $drifted++;
                Log::critical('Credit balance drift detected', ['team_id' => $team->id, ...$result]);
                $this->components->warn("Team {$team->id}: cached {$result['cached']} ≠ ledger {$result['ledger']}");
            }
        });

        if ($drifted === 0) {
            $this->components->info('All team balances match the ledger.');
        }

        return $drifted === 0 ? self::SUCCESS : self::FAILURE;
    }
}
