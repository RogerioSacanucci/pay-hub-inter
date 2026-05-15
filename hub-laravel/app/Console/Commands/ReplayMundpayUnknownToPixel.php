<?php

namespace App\Console\Commands;

use App\Models\MundpayWebhookLog;
use App\Models\TiktokEventLog;
use App\Models\TiktokPixel;
use App\Models\WebhookLog;
use App\Services\TiktokEventsService;
use Carbon\Carbon;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('tiktok:replay-mundpay-unknown {--from= : Start datetime (app TZ)} {--to= : End datetime (app TZ)} {--pixel-code= : Target TikTok pixel_code} {--status=paid : Required payload.status} {--source=mundpay : mundpay|cartpanda — which webhook_logs table to scan} {--dry-run}')]
#[Description('Replay webhook logs marked unknown_event to a specific TikTok pixel as Purchase events (payload assumed to be Mundpay shape)')]
class ReplayMundpayUnknownToPixel extends Command
{
    public function handle(TiktokEventsService $svc): int
    {
        $fromOpt = (string) $this->option('from');
        $toOpt = (string) $this->option('to');
        $pixelCode = (string) $this->option('pixel-code');
        $requiredStatus = (string) $this->option('status');
        $source = (string) $this->option('source');
        $dryRun = (bool) $this->option('dry-run');

        if (! in_array($source, ['mundpay', 'cartpanda'], true)) {
            $this->error("--source must be mundpay or cartpanda (got: {$source})");

            return self::INVALID;
        }

        if ($fromOpt === '' || $toOpt === '' || $pixelCode === '') {
            $this->error('Required: --from --to --pixel-code');

            return self::INVALID;
        }

        $from = Carbon::parse($fromOpt);
        $to = Carbon::parse($toOpt);

        $pixel = TiktokPixel::with(['user', 'oauthConnection'])
            ->where('pixel_code', $pixelCode)
            ->first();

        if (! $pixel) {
            $this->error("Pixel not found: {$pixelCode}");

            return self::FAILURE;
        }

        $hasToken = $pixel->oauthConnection || ! empty($pixel->access_token);
        $this->line(sprintf(
            'pixel id=%d user=%s label=[%s] enabled=%d has_token=%d oauth=%d',
            $pixel->id,
            $pixel->user?->email ?? '?',
            $pixel->label,
            (int) $pixel->enabled,
            (int) $hasToken,
            (int) (bool) $pixel->oauthConnection,
        ));
        $this->line("range: {$from} → {$to}");
        $this->line("source={$source} dry_run=".($dryRun ? 'YES' : 'no'));
        $this->newLine();

        if (! $hasToken) {
            $this->error('Pixel has no usable token (oauth + access_token both empty).');

            return self::FAILURE;
        }

        $model = $source === 'mundpay' ? MundpayWebhookLog::class : WebhookLog::class;

        $logs = $model::where('status_reason', 'unknown_event')
            ->whereBetween('created_at', [$from, $to])
            ->orderBy('created_at')
            ->get();

        $col = collect([$pixel]);
        $matched = 0;
        $sent = 0;
        $skippedNotPaid = 0;
        $skippedNoTtclid = 0;
        $startedAt = now();

        foreach ($logs as $log) {
            $payload = $log->payload;
            $status = data_get($payload, 'status');
            $ttclid = (string) data_get($payload, 'tracking.ttclid', '');
            $orderId = (string) data_get($payload, 'id', '');
            $row = "log_id={$log->id} at={$log->created_at} event={$log->event} status={$status} order={$orderId}";

            if ($status !== $requiredStatus) {
                $skippedNotPaid++;
                $this->line("[skip status!={$requiredStatus}] {$row}");

                continue;
            }

            if ($ttclid === '') {
                $skippedNoTtclid++;
                $this->line("[skip no_ttclid] {$row}");

                continue;
            }

            $matched++;

            if ($dryRun) {
                $this->line("[dry] {$row}");

                continue;
            }

            $svc->sendPurchaseEventForMundpay($col, $payload);
            $sent++;
            $this->line("[sent] {$row}");
        }

        $this->newLine();
        $this->info(sprintf(
            'TOTAL logs=%d matched=%d sent=%d skipped_not_%s=%d skipped_no_ttclid=%d',
            $logs->count(),
            $matched,
            $sent,
            $requiredStatus,
            $skippedNotPaid,
            $skippedNoTtclid,
        ));

        if (! $dryRun && $sent > 0) {
            $this->newLine();
            $this->line('=== TikTok response per event (this run) ===');

            $rows = TiktokEventLog::where('tiktok_pixel_id', $pixel->id)
                ->where('created_at', '>=', $startedAt)
                ->orderBy('id')
                ->get();

            $accepted = 0;
            $rejected = 0;
            foreach ($rows as $r) {
                $orderId = $r->mundpay_order_id ?: $r->cartpanda_order_id;
                $ok = $r->http_status === 200 && (int) $r->tiktok_code === 0;
                if ($ok) {
                    $accepted++;
                } else {
                    $rejected++;
                }
                $this->line(sprintf(
                    '%s order=%s http=%s code=%s msg=%s',
                    $ok ? '[ok]' : '[ko]',
                    $orderId ?: '-',
                    $r->http_status ?? '-',
                    $r->tiktok_code ?? '-',
                    $r->tiktok_message ?? '',
                ));
            }
            $this->newLine();
            $this->info("RESULT accepted={$accepted} rejected={$rejected}");
        }

        return self::SUCCESS;
    }
}
