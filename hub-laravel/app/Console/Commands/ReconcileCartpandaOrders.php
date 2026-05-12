<?php

namespace App\Console\Commands;

use App\Models\CartpandaOrder;
use App\Models\CartpandaShop;
use App\Models\User;
use App\Models\WebhookLog;
use App\Services\BalanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class ReconcileCartpandaOrders extends Command
{
    protected $signature = 'cartpanda:reconcile
                            {shop_slug : CartPanda shop slug (e.g. herbellenatural)}
                            {--token= : CartPanda API token (or set CARTPANDA_TOKEN_<SLUG_UPPER>)}
                            {--user= : Email of fallback user (defaults to config cartpanda.default_user_email)}
                            {--source=hybrid : webhook_logs | cartpanda | hybrid (default)}
                            {--dry-run : Preview without applying}';

    protected $description = 'Recover orders rejected by user_not_found. hybrid: webhook_logs payloads first, then CartPanda API for older gaps.';

    /** @var array<string, int> */
    private array $skipped = [
        'not_paid' => 0,
        'refunded' => 0,
        'chargeback' => 0,
        'already_exists' => 0,
    ];

    private int $ingested = 0;

    private float $totalAmount = 0.0;

    private float $totalReserve = 0.0;

    public function __construct(private BalanceService $balance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        ini_set('memory_limit', '512M');

        $slug = (string) $this->argument('shop_slug');
        $userEmail = (string) ($this->option('user') ?? config('cartpanda.default_user_email'));
        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($source, ['webhook_logs', 'cartpanda', 'hybrid'], true)) {
            $this->error('Invalid --source. Use webhook_logs | cartpanda | hybrid.');

            return self::FAILURE;
        }

        $user = User::where('email', $userEmail)->first();
        if (! $user) {
            $this->error("Fallback user not found: {$userEmail}");

            return self::FAILURE;
        }

        $shop = CartpandaShop::where('shop_slug', $slug)->first();
        if (! $shop) {
            $this->error("Shop not found in DB: {$slug}");

            return self::FAILURE;
        }

        $this->info("Reconciling shop={$slug} source={$source} fallback_user={$user->email} dry_run=".($dryRun ? 'yes' : 'no'));

        if (! $dryRun) {
            $user->shops()->syncWithoutDetaching([$shop->id]);
        }

        // Set of order IDs already processed in this run (covers cross-source dedup
        // when both webhook_logs + cartpanda contribute the same id).
        /** @var array<string, true> $seenThisRun */
        $seenThisRun = [];

        // Phase 1 — webhook_logs (cheap, small)
        if (in_array($source, ['webhook_logs', 'hybrid'], true)) {
            $count = $this->processFromWebhookLogs($slug, $user, $shop, $dryRun, $seenThisRun);
            $this->info("Processed from webhook_logs: {$count}");
        }

        // Phase 2 — stream CartPanda pages, process each page inline
        if (in_array($source, ['cartpanda', 'hybrid'], true)) {
            $token = (string) ($this->option('token') ?? env('CARTPANDA_TOKEN_'.strtoupper($slug)) ?? '');
            if ($token === '') {
                if ($source === 'cartpanda') {
                    $this->error('Missing CartPanda API token. Pass --token or set CARTPANDA_TOKEN_'.strtoupper($slug).' env.');

                    return self::FAILURE;
                }
                $this->warn('No CartPanda token — skipping API fetch.');
            } else {
                $count = $this->processFromCartpandaApi($slug, $token, $user, $shop, $dryRun, $seenThisRun);
                $this->info("Processed from CartPanda API: {$count}");
            }
        }

        $this->newLine();
        if ($dryRun) {
            $this->table(
                ['Metric', 'Value'],
                [
                    ['eligible to ingest', count($seenThisRun) - array_sum($this->skipped)],
                    ['Σ amount (USD)', number_format($this->totalAmount, 2)],
                    ['Σ reserve (USD)', number_format($this->totalReserve, 2)],
                    ['Σ liquid (USD)', number_format($this->totalAmount - $this->totalReserve, 2)],
                    ['Skipped not_paid', $this->skipped['not_paid']],
                    ['Skipped refunded', $this->skipped['refunded']],
                    ['Skipped chargeback', $this->skipped['chargeback']],
                    ['Skipped already_exists', $this->skipped['already_exists']],
                ]
            );
            $this->warn('DRY RUN — no changes applied.');
        } else {
            $this->info("Ingested {$this->ingested} orders. Skipped: ".json_encode($this->skipped));
            $this->info('ReleaseBalanceJob will pick up eligible ones on next run.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, true>  $seenThisRun
     */
    private function processFromWebhookLogs(string $slug, User $user, CartpandaShop $shop, bool $dryRun, array &$seenThisRun): int
    {
        $count = 0;

        WebhookLog::where('shop_slug', $slug)
            ->where('status', 'ignored')
            ->where('status_reason', 'user_not_found')
            ->whereNotNull('cartpanda_order_id')
            ->orderBy('id')
            ->chunkById(200, function ($logs) use ($user, $shop, $dryRun, &$seenThisRun, &$count): void {
                foreach ($logs as $log) {
                    $order = data_get($log->payload, 'order');
                    if (! is_array($order) || empty($order['id'])) {
                        continue;
                    }
                    $oid = (string) $order['id'];
                    if (isset($seenThisRun[$oid])) {
                        continue;
                    }
                    $seenThisRun[$oid] = true;
                    if ($this->processOne($order, $user, $shop, $dryRun)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    /**
     * Stream CartPanda pages and process orders inline (no accumulation in memory).
     *
     * @param  array<string, true>  $seenThisRun
     */
    private function processFromCartpandaApi(string $slug, string $token, User $user, CartpandaShop $shop, bool $dryRun, array &$seenThisRun): int
    {
        $count = 0;
        $page = 1;
        $lastPage = 1;

        do {
            $res = Http::withToken($token)
                ->acceptJson()
                ->get("https://accounts.cartpanda.com/api/v3/{$slug}/orders", [
                    'limit' => 100,
                    'page' => $page,
                ]);

            if (! $res->ok()) {
                $this->error("CartPanda API error page={$page}: ".$res->status());
                break;
            }

            $data = $res->json();
            $orders = $data['orders'] ?? [];
            $lastPage = (int) ($data['meta']['last_page'] ?? 1);
            // free response buffer early
            unset($res, $data);

            foreach ($orders as $o) {
                if (! isset($o['id'])) {
                    continue;
                }
                $oid = (string) $o['id'];
                if (isset($seenThisRun[$oid])) {
                    continue;
                }
                $seenThisRun[$oid] = true;
                if ($this->processOne($o, $user, $shop, $dryRun)) {
                    $count++;
                }
            }
            unset($orders);

            $this->line("  page {$page}/{$lastPage} processed (running ingested={$this->ingested})");
            $page++;
            gc_collect_cycles();
        } while ($page <= $lastPage);

        return $count;
    }

    /**
     * @param  array<string, mixed>  $o
     * @return bool true if ingested/eligible, false if skipped
     */
    private function processOne(array $o, User $user, CartpandaShop $shop, bool $dryRun): bool
    {
        if (($o['financial_status'] ?? null) !== 3) {
            $this->skipped['not_paid']++;

            return false;
        }
        $refunds = $o['refunds'] ?? null;
        if (is_array($refunds) && count($refunds) > 0) {
            $this->skipped['refunded']++;

            return false;
        }
        if (($o['chargeback_received'] ?? false) || ($o['chargeback_at'] ?? null)) {
            $this->skipped['chargeback']++;

            return false;
        }

        $oid = (string) $o['id'];
        if (CartpandaOrder::where('cartpanda_order_id', $oid)->exists()) {
            $this->skipped['already_exists']++;

            return false;
        }

        [$amount, $reserve] = $this->computeAmounts($o);
        $this->totalAmount += $amount;
        $this->totalReserve += $reserve;

        if ($dryRun) {
            return true;
        }

        $processedAt = isset($o['processed_at'])
            ? Carbon::parse($o['processed_at'])
            : now();

        DB::transaction(function () use ($o, $oid, $user, $shop, $amount, $reserve, $processedAt): void {
            $order = CartpandaOrder::firstOrNew(['cartpanda_order_id' => $oid]);
            if ($order->exists) {
                return;
            }
            $order->fill([
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'amount' => $amount,
                'reserve_amount' => $reserve,
                'currency' => 'USD',
                'status' => 'COMPLETED',
                'event' => 'order.paid',
                'payer_email' => $o['contact_email'] ?? ($o['customer']['email'] ?? null),
                'payer_name' => trim((string) ($o['customer']['first_name'] ?? '').' '.($o['customer']['last_name'] ?? '')) ?: null,
                'payload' => ['order' => $o, 'event' => 'order.paid', '_reconciled' => true],
                'release_eligible_at' => $processedAt->copy()->addDays(2),
                'created_at' => $processedAt,
            ]);
            $order->save();

            $this->balance->creditPending($user, $order);
            $this->ingested++;
        });

        return true;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array{0: float, 1: float}
     */
    private function computeAmounts(array $order): array
    {
        $payments = $order['all_payments'] ?? [];
        $first = is_array($payments) && isset($payments[0]) ? $payments[0] : (is_array($payments) ? $payments : []);

        $tx = ($order['transactions'][0] ?? []);
        $xr = (float) ($tx['actual_exchange_rate']
            ?? ($order['payment']['actual_exchange_rate'] ?? 0));
        $sellerSplit = (float) ($first['seller_split_amount']
            ?? $tx['seller_split_amount']
            ?? 0);
        $allowance = (float) ($first['seller_allowance_amount'] ?? 0);

        $amount = round($sellerSplit * $xr, 6);
        $reserve = $allowance > 0
            ? round($allowance * $xr, 6)
            : round($amount * (float) config('cartpanda.reserve_rate_fallback', 0.05), 6);

        return [$amount, $reserve];
    }
}
