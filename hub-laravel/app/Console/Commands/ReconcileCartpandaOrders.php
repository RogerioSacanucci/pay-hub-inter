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

    public function __construct(private BalanceService $balance)
    {
        parent::__construct();
    }

    public function handle(): int
    {
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

        // Phase 1: collect candidates (CartPanda order arrays) keyed by id
        /** @var array<string, array<string, mixed>> $candidates */
        $candidates = [];

        if (in_array($source, ['webhook_logs', 'hybrid'], true)) {
            $fromLogs = $this->candidatesFromWebhookLogs($slug);
            $candidates += $fromLogs;
            $this->info('From webhook_logs (status=ignored, user_not_found): '.count($fromLogs));
        }

        if (in_array($source, ['cartpanda', 'hybrid'], true)) {
            $token = (string) ($this->option('token') ?? env('CARTPANDA_TOKEN_'.strtoupper($slug)) ?? '');
            if ($token === '') {
                if ($source === 'cartpanda') {
                    $this->error('Missing CartPanda API token. Pass --token or set CARTPANDA_TOKEN_'.strtoupper($slug).' env.');

                    return self::FAILURE;
                }
                $this->warn('No CartPanda token — skipping API fetch (hybrid mode falls back to webhook_logs only).');
            } else {
                $cpOrders = $this->fetchAllOrders($slug, $token);
                $this->info('Fetched '.count($cpOrders).' orders from CartPanda API.');

                $existingIds = CartpandaOrder::where('shop_id', $shop->id)
                    ->pluck('cartpanda_order_id')
                    ->map(fn ($v) => (string) $v)
                    ->all();
                $existingSet = array_flip($existingIds);

                foreach ($cpOrders as $o) {
                    $oid = (string) $o['id'];
                    if (isset($existingSet[$oid]) || isset($candidates[$oid])) {
                        continue;
                    }
                    $candidates[$oid] = $o;
                }
            }
        }

        $this->info('Total unique candidates: '.count($candidates));

        // Phase 2: filter eligible (paid, not refund/chargeback, not already in DB)
        $existingIds = CartpandaOrder::pluck('cartpanda_order_id')
            ->map(fn ($v) => (string) $v)
            ->all();
        $existingSet = array_flip($existingIds);

        $eligible = [];
        $skipped = ['not_paid' => 0, 'refunded' => 0, 'chargeback' => 0, 'already_exists' => 0];

        foreach ($candidates as $oid => $o) {
            if (isset($existingSet[$oid])) {
                $skipped['already_exists']++;

                continue;
            }
            if (($o['financial_status'] ?? null) !== 3) {
                $skipped['not_paid']++;

                continue;
            }
            $refunds = $o['refunds'] ?? null;
            if (is_array($refunds) && count($refunds) > 0) {
                $skipped['refunded']++;

                continue;
            }
            if (($o['chargeback_received'] ?? false) || ($o['chargeback_at'] ?? null)) {
                $skipped['chargeback']++;

                continue;
            }
            $eligible[] = $o;
        }

        $this->info('Eligible: '.count($eligible).' Skipped: '.json_encode($skipped));

        if ($dryRun) {
            $totalAmount = 0.0;
            $totalReserve = 0.0;
            foreach ($eligible as $o) {
                [$amount, $reserve] = $this->computeAmounts($o);
                $totalAmount += $amount;
                $totalReserve += $reserve;
            }
            $this->table(
                ['Metric', 'Value'],
                [
                    ['orders to ingest', count($eligible)],
                    ['Σ amount (USD)', number_format($totalAmount, 2)],
                    ['Σ reserve (USD)', number_format($totalReserve, 2)],
                    ['Σ liquid (USD)', number_format($totalAmount - $totalReserve, 2)],
                ]
            );
            $this->warn('DRY RUN — no changes applied.');

            return self::SUCCESS;
        }

        $user->shops()->syncWithoutDetaching([$shop->id]);

        $ingested = 0;
        $bar = $this->output->createProgressBar(count($eligible));
        $bar->start();

        foreach ($eligible as $o) {
            DB::transaction(function () use ($o, $user, $shop, &$ingested): void {
                [$amount, $reserve] = $this->computeAmounts($o);
                $processedAt = isset($o['processed_at'])
                    ? Carbon::parse($o['processed_at'])
                    : now();

                $order = CartpandaOrder::firstOrNew(['cartpanda_order_id' => (string) $o['id']]);

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
                $ingested++;
            });
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Ingested {$ingested} orders. ReleaseBalanceJob will pick up eligible ones on next run.");

        return self::SUCCESS;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function candidatesFromWebhookLogs(string $slug): array
    {
        $candidates = [];

        WebhookLog::where('shop_slug', $slug)
            ->where('status', 'ignored')
            ->where('status_reason', 'user_not_found')
            ->whereNotNull('cartpanda_order_id')
            ->orderBy('id')
            ->chunkById(500, function ($logs) use (&$candidates): void {
                foreach ($logs as $log) {
                    $order = data_get($log->payload, 'order');
                    if (! is_array($order) || empty($order['id'])) {
                        continue;
                    }
                    $candidates[(string) $order['id']] = $order;
                }
            });

        return $candidates;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAllOrders(string $slug, string $token): array
    {
        $all = [];
        $page = 1;

        do {
            $res = Http::withToken($token)
                ->acceptJson()
                ->get("https://accounts.cartpanda.com/api/v3/{$slug}/orders", [
                    'limit' => 200,
                    'page' => $page,
                ]);

            if (! $res->ok()) {
                $this->error("CartPanda API error page={$page}: ".$res->status());
                break;
            }

            $data = $res->json();
            $orders = $data['orders'] ?? [];
            $all = array_merge($all, $orders);
            $last = (int) ($data['meta']['last_page'] ?? 1);
            $page++;
        } while ($page <= $last);

        return $all;
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
