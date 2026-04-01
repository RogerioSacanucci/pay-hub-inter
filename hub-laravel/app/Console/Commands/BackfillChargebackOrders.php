<?php

namespace App\Console\Commands;

use App\Models\CartpandaOrder;
use App\Models\User;
use App\Services\BalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillChargebackOrders extends Command
{
    protected $signature = 'backfill:chargeback-orders
                            {log-file : Path to the Laravel log file}
                            {--dry-run : Preview changes without applying them}';

    protected $description = 'Process chargeback/refund events from a log file that were missed due to missing checkout_params';

    /** @var array<string, string> */
    private const EVENT_STATUS_MAP = [
        'order.chargeback' => 'DECLINED',
        'order.refunded' => 'REFUNDED',
    ];

    public function __construct(private BalanceService $balance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $logFile = $this->argument('log-file');
        $dryRun = $this->option('dry-run');

        if (! file_exists($logFile)) {
            $this->error("Log file not found: {$logFile}");

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY RUN — no changes will be applied.');
        }

        $entries = $this->parseLogEntries($logFile);

        if (empty($entries)) {
            $this->info('No chargeback/refund entries found in log file.');

            return self::SUCCESS;
        }

        $this->info(sprintf('Found %d entries to process.', count($entries)));
        $this->newLine();

        $processed = 0;
        $skippedNotFound = 0;
        $skippedAlreadyDone = 0;
        $skippedNotCompleted = 0;

        foreach ($entries as ['order_id' => $orderId, 'event' => $event]) {
            $status = self::EVENT_STATUS_MAP[$event];
            $order = CartpandaOrder::where('cartpanda_order_id', $orderId)->with('user')->first();

            if (! $order) {
                $this->line("  <fg=yellow>SKIP</>  #{$orderId} — not found in database");
                $skippedNotFound++;

                continue;
            }

            if (in_array($order->status, ['DECLINED', 'REFUNDED'], true)) {
                $this->line("  <fg=gray>SKIP</>  #{$orderId} — already {$order->status}");
                $skippedAlreadyDone++;

                continue;
            }

            if ($order->status !== 'COMPLETED') {
                $this->line("  <fg=yellow>SKIP</>  #{$orderId} — status is {$order->status}, expected COMPLETED");
                $skippedNotCompleted++;

                continue;
            }

            /** @var User $user */
            $user = $order->user;
            $this->line("  <fg=green>APPLY</> #{$orderId} — {$event} → {$status} (user #{$user->id}, amount {$order->amount})");

            if (! $dryRun) {
                DB::transaction(function () use ($user, $order, $status, $event) {
                    $this->balance->debitOnChargeback($user, $order);
                    $order->update(['status' => $status, 'event' => $event]);
                });
            }

            $processed++;
        }

        $this->newLine();
        $this->table(
            ['Result', 'Count'],
            [
                [$dryRun ? 'Would apply' : 'Applied', $processed],
                ['Skipped (already processed)', $skippedAlreadyDone],
                ['Skipped (not found in DB)', $skippedNotFound],
                ['Skipped (not COMPLETED)', $skippedNotCompleted],
            ]
        );

        return self::SUCCESS;
    }

    /**
     * Extract chargeback/refund entries from a Laravel log file.
     *
     * @return list<array{order_id: string, event: string}>
     */
    private function parseLogEntries(string $logFile): array
    {
        $entries = [];
        $seen = [];

        $handle = fopen($logFile, 'r');
        if ($handle === false) {
            $this->error("Cannot open log file: {$logFile}");

            return [];
        }

        while (($line = fgets($handle)) !== false) {
            if (! str_contains($line, 'cartpanda_webhook')) {
                continue;
            }

            $jsonStart = strpos($line, '{');
            if ($jsonStart === false) {
                continue;
            }

            $data = json_decode(substr($line, $jsonStart), true);
            if (! is_array($data)) {
                continue;
            }

            $event = $data['event'] ?? null;
            $orderId = isset($data['order_id']) ? (string) $data['order_id'] : null;

            if (! isset(self::EVENT_STATUS_MAP[$event]) || ! $orderId) {
                continue;
            }

            // Deduplicate: same order_id + event (e.g. 48207407 appears twice as chargeback)
            $key = "{$orderId}:{$event}";
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $entries[] = ['order_id' => $orderId, 'event' => $event];
        }

        fclose($handle);

        return $entries;
    }
}
