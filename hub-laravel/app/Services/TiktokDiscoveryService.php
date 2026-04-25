<?php

namespace App\Services;

use App\Models\TiktokOauthConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Wraps read-only Marketing API calls scoped to a TiktokOauthConnection.
 * Handles caching + error normalization. Never persists data — caller decides
 * what to surface to the dashboard / save to DB.
 */
class TiktokDiscoveryService
{
    /**
     * List advertisers attached to the connection's token, batched in one call.
     * Returns each advertiser with id, name, balance, currency.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAdvertisers(TiktokOauthConnection $connection): array
    {
        $ids = (array) ($connection->advertiser_ids ?? []);
        if (empty($ids)) {
            return [];
        }

        $cacheKey = $this->cacheKey('advertisers', $connection->id, md5(implode(',', $ids)));

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $ids) {
            $base = $this->base();
            $info = [];
            $balances = [];

            try {
                $infoRes = Http::timeout(10)
                    ->withHeaders(['Access-Token' => $connection->access_token])
                    ->get($base.'/advertiser/info/', [
                        'advertiser_ids' => json_encode($ids),
                        'fields' => json_encode(['id', 'name', 'currency', 'status', 'company']),
                    ]);
                if ($infoRes->successful() && (int) $infoRes->json('code', 0) === 0) {
                    foreach ((array) $infoRes->json('data.list', []) as $row) {
                        $info[(string) ($row['id'] ?? $row['advertiser_id'] ?? '')] = $row;
                    }
                }
            } catch (Throwable $e) {
                Log::info('TikTok advertiser/info/ failed', ['error' => $e->getMessage()]);
            }

            try {
                $balRes = Http::timeout(10)
                    ->withHeaders(['Access-Token' => $connection->access_token])
                    ->get($base.'/advertiser/balance/get/', [
                        'advertiser_ids' => json_encode($ids),
                    ]);
                if ($balRes->successful() && (int) $balRes->json('code', 0) === 0) {
                    foreach ((array) $balRes->json('data.list', []) as $row) {
                        $balances[(string) ($row['advertiser_id'] ?? $row['id'] ?? '')] = $row;
                    }
                }
            } catch (Throwable $e) {
                Log::info('TikTok advertiser/balance/get/ failed', ['error' => $e->getMessage()]);
            }

            $out = [];
            foreach ($ids as $id) {
                $key = (string) $id;
                $i = $info[$key] ?? [];
                $b = $balances[$key] ?? [];
                $out[] = [
                    'advertiser_id' => $key,
                    'name' => (string) ($i['name'] ?? "Advertiser $key"),
                    'currency' => (string) ($i['currency'] ?? $b['currency'] ?? ''),
                    'balance' => isset($b['balance']) ? (float) $b['balance'] : null,
                    'status' => (string) ($i['status'] ?? ''),
                ];
            }

            return $out;
        });
    }

    /**
     * List pixels for a single advertiser.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPixels(TiktokOauthConnection $connection, string $advertiserId): array
    {
        $cacheKey = $this->cacheKey('pixels', $connection->id, $advertiserId);

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($connection, $advertiserId) {
            $base = $this->base();
            try {
                $res = Http::timeout(10)
                    ->withHeaders(['Access-Token' => $connection->access_token])
                    ->get($base.'/pixel/list/', [
                        'advertiser_id' => $advertiserId,
                    ]);

                if (! $res->successful() || (int) $res->json('code', 0) !== 0) {
                    return [];
                }

                $out = [];
                foreach ((array) $res->json('data.pixels', $res->json('data.list', [])) as $row) {
                    $out[] = [
                        'pixel_code' => (string) ($row['pixel_code'] ?? $row['code'] ?? ''),
                        'name' => (string) ($row['pixel_name'] ?? $row['name'] ?? ''),
                        'mode' => (string) ($row['pixel_mode'] ?? ''),
                        'created_at' => (string) ($row['create_time'] ?? ''),
                    ];
                }

                return array_values(array_filter($out, fn ($p) => $p['pixel_code'] !== ''));
            } catch (Throwable $e) {
                Log::info('TikTok pixel/list/ failed', ['error' => $e->getMessage()]);

                return [];
            }
        });
    }

    /**
     * Validate that a pixel_code is accessible by this connection's token.
     * Returns the matching pixel + advertiser_id when found, null otherwise.
     *
     * @return array{advertiser_id: string, advertiser_name: string, name: string}|null
     */
    public function validatePixel(TiktokOauthConnection $connection, string $pixelCode): ?array
    {
        $advertisers = $this->listAdvertisers($connection);
        foreach ($advertisers as $adv) {
            $pixels = $this->listPixels($connection, $adv['advertiser_id']);
            foreach ($pixels as $p) {
                if (strcasecmp($p['pixel_code'], $pixelCode) === 0) {
                    return [
                        'advertiser_id' => $adv['advertiser_id'],
                        'advertiser_name' => $adv['name'],
                        'name' => $p['name'],
                    ];
                }
            }
        }

        return null;
    }

    /**
     * Pixel event stats for the last $days days.
     *
     * @return array<string, mixed>
     */
    public function pixelStats(TiktokOauthConnection $connection, string $advertiserId, string $pixelCode, int $days = 7): array
    {
        $cacheKey = $this->cacheKey('pixel-stats', $connection->id, "$advertiserId|$pixelCode|$days");

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($connection, $advertiserId, $pixelCode, $days) {
            $base = $this->base();
            $start = now()->subDays($days)->format('Y-m-d');
            $end = now()->format('Y-m-d');

            try {
                $res = Http::timeout(15)
                    ->withHeaders(['Access-Token' => $connection->access_token])
                    ->get($base.'/pixel/event/stats/', [
                        'advertiser_id' => $advertiserId,
                        'pixel_ids' => json_encode([$pixelCode]),
                        'date_range' => json_encode(['start_date' => $start, 'end_date' => $end]),
                    ]);

                if (! $res->successful() || (int) $res->json('code', 0) !== 0) {
                    return ['events' => [], 'error' => (string) $res->json('message', '')];
                }

                $events = [];
                $list = (array) $res->json('data.stats', $res->json('data.list', []));
                foreach ($list as $row) {
                    $event = (string) ($row['event'] ?? $row['event_name'] ?? '');
                    $count = (int) ($row['total'] ?? $row['count'] ?? 0);
                    if ($event !== '' && $count > 0) {
                        $events[$event] = ($events[$event] ?? 0) + $count;
                    }
                }

                return ['events' => $events, 'days' => $days];
            } catch (Throwable $e) {
                Log::info('TikTok pixel/event/stats/ failed', ['error' => $e->getMessage()]);

                return ['events' => [], 'error' => $e->getMessage()];
            }
        });
    }

    /**
     * Spend report for the connection's advertisers in a date range. Aggregates spend
     * across all advertisers; per-advertiser breakdown also returned.
     *
     * @return array{total_spend: float, currency: string, by_advertiser: array<int, array<string, mixed>>, by_day: array<int, array<string, mixed>>}
     */
    public function spendReport(TiktokOauthConnection $connection, string $dateFrom, string $dateTo): array
    {
        $cacheKey = $this->cacheKey('spend', $connection->id, "$dateFrom|$dateTo");

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($connection, $dateFrom, $dateTo) {
            $base = $this->base();
            $advertisers = $this->listAdvertisers($connection);

            $totalSpend = 0.0;
            $byAdvertiser = [];
            $byDay = [];
            $currency = '';

            foreach ($advertisers as $adv) {
                $advId = $adv['advertiser_id'];
                if ($currency === '' && ! empty($adv['currency'])) {
                    $currency = $adv['currency'];
                }
                try {
                    $res = Http::timeout(15)
                        ->withHeaders(['Access-Token' => $connection->access_token])
                        ->get($base.'/report/integrated/get/', [
                            'advertiser_id' => $advId,
                            'report_type' => 'BASIC',
                            'data_level' => 'AUDIENCE_ADVERTISER',
                            'dimensions' => json_encode(['advertiser_id', 'stat_time_day']),
                            'metrics' => json_encode(['spend']),
                            'start_date' => $dateFrom,
                            'end_date' => $dateTo,
                            'page' => 1,
                            'page_size' => 100,
                        ]);

                    if (! $res->successful() || (int) $res->json('code', 0) !== 0) {
                        continue;
                    }

                    $sub = 0.0;
                    foreach ((array) $res->json('data.list', []) as $row) {
                        $spend = (float) ($row['metrics']['spend'] ?? 0);
                        $day = (string) ($row['dimensions']['stat_time_day'] ?? '');
                        $sub += $spend;
                        if ($day !== '') {
                            $byDay[$day] = ($byDay[$day] ?? 0) + $spend;
                        }
                    }

                    $totalSpend += $sub;
                    $byAdvertiser[] = [
                        'advertiser_id' => $advId,
                        'name' => $adv['name'],
                        'spend' => round($sub, 2),
                        'currency' => $adv['currency'],
                    ];
                } catch (Throwable $e) {
                    Log::info('TikTok report/integrated/get/ failed', [
                        'advertiser_id' => $advId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $byDayArr = [];
            ksort($byDay);
            foreach ($byDay as $day => $spend) {
                $byDayArr[] = ['date' => $day, 'spend' => round($spend, 2)];
            }

            return [
                'total_spend' => round($totalSpend, 2),
                'currency' => $currency ?: 'USD',
                'by_advertiser' => $byAdvertiser,
                'by_day' => $byDayArr,
            ];
        });
    }

    public function invalidateCache(TiktokOauthConnection $connection): void
    {
        Cache::forget($this->cacheKey('advertisers', $connection->id, '*'));
        // Other keys self-expire; explicit purge isn't worth the bookkeeping.
    }

    private function base(): string
    {
        return rtrim((string) config('services.tiktok.open_api_base'), '/');
    }

    private function cacheKey(string $kind, int $connectionId, string $extra): string
    {
        return "tiktok:disc:{$kind}:{$connectionId}:{$extra}";
    }
}
