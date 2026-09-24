<?php

namespace Database\Seeders;

use App\Domain\Credits\CreditLedger;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(CreditLedger $credits): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $team = $user->personalTeam();

        $credits->grant($team, 500, 'seed:'.$team->id, 'Development seed');

        Project::factory()->for($team)->create([
            'name' => 'Clay & Co',
            'created_by' => $user->id,
            'brief' => [
                'niche' => 'Handmade ceramic mugs',
                'audience' => 'Coffee lovers who buy thoughtful gifts',
                'tone' => 'friendly',
                'style' => 'Earthy, minimal, warm photography',
                'brand_colors' => ['#7c4a1e', '#f4ede4'],
            ],
        ]);

        Project::factory()->arabic()->for($team)->create([
            'name' => 'أطلس أرغان',
            'created_by' => $user->id,
            'currency' => 'MAD',
            'brief' => [
                'niche' => 'Pure Moroccan argan oil',
                'audience' => 'Women 25-45 interested in natural beauty',
                'tone' => 'premium',
                'style' => null,
                'brand_colors' => ['#2f6f4f'],
            ],
        ]);
    }
}
