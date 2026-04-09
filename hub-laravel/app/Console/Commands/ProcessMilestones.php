<?php

namespace App\Console\Commands;

use App\Models\CartpandaOrder;
use App\Models\RevenueMilestone;
use App\Models\UserMilestoneAchievement;
use Illuminate\Console\Command;

class ProcessMilestones extends Command
{
    protected $signature = 'milestones:process {--dry-run : Simulate without writing}';

    protected $description = 'Process all users retroactively and record achieved revenue milestones';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be applied.');
        }

        $milestones = RevenueMilestone::orderBy('value')->get();

        if ($milestones->isEmpty()) {
            $this->info('No milestones configured. Nothing to process.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d milestones to evaluate.', $milestones->count()));

        /** @var array<int, float> $userTotals */
        $userTotals = CartpandaOrder::where('status', 'COMPLETED')
            ->selectRaw('user_id, SUM(amount) as total')
            ->groupBy('user_id')
            ->pluck('total', 'user_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();

        $this->info(sprintf('Found %d users with completed orders.', count($userTotals)));
        $this->newLine();

        $created = 0;
        $skipped = 0;

        foreach ($userTotals as $userId => $total) {
            foreach ($milestones as $milestone) {
                if ($total < (float) $milestone->value) {
                    continue;
                }

                if ($dryRun) {
                    $this->line("  <fg=green>WOULD CREATE</>  user #{$userId} — milestone #{$milestone->id} ({$milestone->value}) — total: {$total}");
                    $created++;

                    continue;
                }

                $achievement = UserMilestoneAchievement::firstOrCreate(
                    ['user_id' => $userId, 'milestone_id' => $milestone->id],
                    ['achieved_at' => now(), 'total_at_achievement' => $total],
                );

                if ($achievement->wasRecentlyCreated) {
                    $this->line("  <fg=green>CREATED</>  user #{$userId} — milestone #{$milestone->id} ({$milestone->value})");
                    $created++;
                } else {
                    $this->line("  <fg=gray>SKIP</>  user #{$userId} — milestone #{$milestone->id} (already achieved)");
                    $skipped++;
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Result', 'Count'],
            [
                [$dryRun ? 'Would create' : 'Created', $created],
                ['Skipped (already achieved)', $skipped],
            ]
        );

        return self::SUCCESS;
    }
}
