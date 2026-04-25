<?php

namespace App\Services;

use App\Models\TiktokEventLog;
use App\Models\TiktokPixel;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TiktokEventsService
{
    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/pixel/track/';

    private const EVENT = 'Purchase';

    /**
     * Fire a Purchase event to every enabled pixel the user owns.
     *
     * @param  Collection<int, TiktokPixel>  $pixels
     * @param  array<string, mixed>  $order  Raw cartpanda webhook `order` array
     */
    public function sendPurchaseEvent(Collection $pixels, array $order): void
    {
        if ($pixels->isEmpty()) {
            return;
        }

        $callback = (string) data_get($order, 'checkout_params.ttclid', '');
        if ($callback === '') {
            return;
        }

        // Filter out pixels whose oauth connection (if set) belongs to a different user.
        // Defense in depth — should never happen if controller validation is intact.
        $pixels = $pixels->filter(function (TiktokPixel $pixel) {
            $pixel->loadMissing('oauthConnection');
            if ($pixel->oauthConnection && $pixel->oauthConnection->user_id !== $pixel->user_id) {
                Log::critical('TikTok pixel/connection user mismatch — skipped', [
                    'pixel_id' => $pixel->id,
                    'pixel_user_id' => $pixel->user_id,
                    'connection_user_id' => $pixel->oauthConnection->user_id,
                ]);

                return false;
            }
            // Skip pixels with neither OAuth connection nor per-pixel token.
            $hasToken = $pixel->oauthConnection || ! empty($pixel->access_token);
            if (! $hasToken) {
                Log::warning('TikTok pixel without token — skipped', ['pixel_id' => $pixel->id]);
            }

            return $hasToken;
        });

        if ($pixels->isEmpty()) {
            return;
        }

        $context = $this->buildContext($order, $callback);
        $properties = $this->buildProperties($order);
        $eventId = (string) data_get($order, 'id', '');
        $timestamp = $this->toIso8601((string) data_get($order, 'processed_at', ''));
        $pixelsById = $pixels->keyBy('id');

        try {
            $responses = Http::pool(fn (Pool $pool) => $pixels
                ->map(fn (TiktokPixel $pixel) => $pool
                    ->as((string) $pixel->id)
                    ->timeout(10)
                    ->withHeaders(['Access-Token' => $this->tokenFor($pixel)])
                    ->post(self::ENDPOINT, $this->buildBody(
                        $pixel,
                        $eventId,
                        $timestamp,
                        $context,
                        $properties,
                    ))
                )
                ->all()
            );

            foreach ($responses as $pixelId => $response) {
                $pixel = $pixelsById->get((int) $pixelId);
                if (! $pixel) {
                    continue;
                }

                if ($response instanceof Throwable) {
                    Log::warning('TikTok Events API transport error', [
                        'pixel_id' => $pixelId,
                        'order_id' => $eventId,
                        'error' => $response->getMessage(),
                    ]);
                    $this->persistLog($pixel, $eventId, $properties, null, null, $response->getMessage());

                    continue;
                }

                if (! $response->successful() || (int) $response->json('code', 0) !== 0) {
                    Log::warning('TikTok Events API rejected event', [
                        'pixel_id' => $pixelId,
                        'order_id' => $eventId,
                        'status' => $response->status(),
                        'body' => $response->json(),
                    ]);
                }

                $this->persistLog($pixel, $eventId, $properties, $response, null, null);
            }
        } catch (Throwable $e) {
            Log::warning('TikTok Events API pool failed', [
                'order_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Retry a single event for a single pixel. Used for manual replays after fixing
     * credentials. Persists a new log row with the result and returns it.
     *
     * @param  array<string, mixed>  $order
     */
    public function retryForPixel(TiktokPixel $pixel, array $order): TiktokEventLog
    {
        $callback = (string) data_get($order, 'checkout_params.ttclid', '');
        $context = $this->buildContext($order, $callback);
        $properties = $this->buildProperties($order);
        $eventId = (string) data_get($order, 'id', '');
        $timestamp = $this->toIso8601((string) data_get($order, 'processed_at', ''));

        try {
            $response = Http::timeout(10)
                ->withHeaders(['Access-Token' => $this->tokenFor($pixel)])
                ->post(self::ENDPOINT, $this->buildBody($pixel, $eventId, $timestamp, $context, $properties));

            return $this->persistLog($pixel, $eventId, $properties, $response, null, null);
        } catch (Throwable $e) {
            Log::warning('TikTok Events API retry failed', [
                'pixel_id' => $pixel->id,
                'order_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return $this->persistLog($pixel, $eventId, $properties, null, null, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function persistLog(
        TiktokPixel $pixel,
        string $eventId,
        array $properties,
        ?Response $response,
        ?string $transportError,
        ?string $caughtMessage,
    ): ?TiktokEventLog {
        try {
            $body = $response?->json();
            $code = is_array($body) ? (int) ($body['code'] ?? 0) : null;
            $message = is_array($body) ? (string) ($body['message'] ?? '') : ($transportError ?? $caughtMessage ?? '');
            $requestId = is_array($body) && ! empty($body['request_id'])
                ? (string) $body['request_id']
                : ($response?->header('X-Tt-Logid') ?: null);

            return TiktokEventLog::create([
                'user_id' => $pixel->user_id,
                'tiktok_pixel_id' => $pixel->id,
                'cartpanda_order_id' => $eventId,
                'event' => self::EVENT,
                'http_status' => $response?->status(),
                'tiktok_code' => $response ? $code : null,
                'tiktok_message' => $message !== '' ? $message : null,
                'request_id' => $requestId,
                'payload' => $this->summarizePayload($eventId, $properties),
                'response' => $response ? (is_array($body) ? $body : ['raw' => (string) $response->body()]) : ['error' => $transportError ?? $caughtMessage],
            ]);
        } catch (Throwable $e) {
            Log::warning('TikTok event log persist failed', [
                'pixel_id' => $pixel->id,
                'order_id' => $eventId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Sanitized payload summary — no hashed PII stored.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    /**
     * Pick the access token for a pixel — prefers the user's OAuth connection
     * (BC-wide scope) over the per-pixel token (narrower scope, often hits 40001).
     * The cross-user safety check happens in the caller (sendPurchaseEvent).
     */
    private function tokenFor(TiktokPixel $pixel): string
    {
        $conn = $pixel->oauthConnection;
        if ($conn && $conn->status === 'active' && ! empty($conn->access_token)) {
            return (string) $conn->access_token;
        }

        return (string) ($pixel->access_token ?? '');
    }

    private function summarizePayload(string $eventId, array $properties): array
    {
        $contents = (array) ($properties['contents'] ?? []);

        return [
            'event_id' => $eventId,
            'event' => self::EVENT,
            'value' => $properties['value'] ?? null,
            'currency' => $properties['currency'] ?? null,
            'content_count' => count($contents),
            'order_id' => $properties['order_id'] ?? null,
            'query' => $properties['query'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function buildBody(
        TiktokPixel $pixel,
        string $eventId,
        string $timestamp,
        array $context,
        array $properties,
    ): array {
        $body = [
            'pixel_code' => $pixel->pixel_code,
            'event' => self::EVENT,
            'event_id' => $eventId,
            'timestamp' => $timestamp,
            'context' => $context,
            'properties' => $properties,
            'event_source' => 'PIXEL_EVENTS',
        ];

        if (! empty($pixel->test_event_code)) {
            $body['test_event_code'] = $pixel->test_event_code;
        }

        return $body;
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function buildContext(array $order, string $callback): array
    {
        // Fallback chains — CartPanda popula cada PII em mais de um path,
        // e às vezes o primeiro vem como string vazia em vez de null.
        $email = $this->pickFirstNonEmpty($order, ['customer.email', 'email']);
        $phone = $this->pickFirstNonEmpty($order, ['customer.phone', 'phone', 'address.phone']);

        $firstName = $this->pickFirstNonEmpty($order, ['customer.first_name', 'address.first_name']);
        $lastName = $this->pickFirstNonEmpty($order, ['customer.last_name', 'address.last_name']);

        // Se nome não veio, derivar de full_name ("Belit Rivera" → "Belit" + "Rivera").
        if ($firstName === '' || $lastName === '') {
            $fullName = trim((string) data_get($order, 'customer.full_name', ''));
            if ($fullName !== '') {
                $tokens = preg_split('/\s+/', $fullName) ?: [];
                if ($firstName === '' && ! empty($tokens)) {
                    $firstName = (string) array_shift($tokens);
                }
                if ($lastName === '' && ! empty($tokens)) {
                    $lastName = implode(' ', $tokens);
                }
            }
        }

        $city = $this->pickFirstNonEmpty($order, ['address.city']);
        $state = $this->pickFirstNonEmpty($order, ['address.province_code', 'address.province']);
        $zip = $this->pickFirstNonEmpty($order, ['address.zip']);
        $country = $this->pickFirstNonEmpty($order, ['address.country_code', 'address.country']);

        $externalId = (string) data_get($order, 'customer.id', '');

        $hashedFields = [
            'email' => $this->hashedField($email),
            'phone_number' => $this->hashedField($phone, fn ($v) => preg_replace('/\D+/', '', $v) ?? ''),
            'external_id' => $this->hashedField($externalId, fn ($v) => $v),
            'first_name' => $this->hashedField($firstName),
            'last_name' => $this->hashedField($lastName),
            'city' => $this->hashedField($city),
            'state' => $this->hashedField($state),
            'zip_code' => $this->hashedField($zip, fn ($v) => $v),
            'country' => $this->hashedField($country),
        ];

        $user = array_filter($hashedFields, fn ($v) => $v !== null);

        return [
            'ad' => ['callback' => $callback],
            'page' => [
                'url' => (string) data_get($order, 'thank_you_page', ''),
                'referrer' => '',
            ],
            'user' => $user,
            'user_agent' => (string) data_get($order, 'user_agent', ''),
            'ip' => (string) data_get($order, 'browser_ip', ''),
        ];
    }

    /**
     * Retorna o primeiro valor não-vazio entre os paths dados (null e string vazia
     * contam como vazio).
     *
     * @param  array<string, mixed>  $order
     * @param  array<int, string>  $paths
     */
    private function pickFirstNonEmpty(array $order, array $paths): string
    {
        foreach ($paths as $p) {
            $v = (string) (data_get($order, $p) ?? '');
            if ($v !== '') {
                return $v;
            }
        }

        return '';
    }

    /**
     * Hash sha256 com normalização opcional. Retorna null se o input for vazio/null
     * (o caller filtra com array_filter para não incluir o campo no payload).
     */
    private function hashedField(?string $raw, ?callable $normalize = null): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }
        $normalized = $normalize ? (string) $normalize($trimmed) : strtolower($trimmed);
        if ($normalized === '') {
            return null;
        }

        return hash('sha256', $normalized);
    }

    /**
     * @param  array<string, mixed>  $order
     * @return array<string, mixed>
     */
    private function buildProperties(array $order): array
    {
        $items = (array) data_get($order, 'line_items', []);
        $contents = [];
        foreach ($items as $item) {
            $contents[] = [
                'price' => (float) data_get($item, 'actual_price_paid', 0),
                'quantity' => (int) data_get($item, 'quantity', 1),
                'content_id' => (string) data_get($item, 'sku', ''),
                'content_name' => (string) data_get($item, 'title', ''),
                'content_type' => 'product',
            ];
        }

        $checkoutParams = (array) data_get($order, 'checkout_params', []);
        $queryParts = [];
        foreach (['utm_campaign', 'utm_content', 'utm_source', 'affiliate', 'src', 'cid'] as $key) {
            if (! empty($checkoutParams[$key])) {
                $queryParts[] = $key.'='.$checkoutParams[$key];
            }
        }

        return [
            'contents' => $contents,
            'currency' => (string) data_get($order, 'payment.actual_price_paid_currency', 'USD'),
            'value' => (float) data_get($order, 'payment.actual_price_paid', 0),
            'order_id' => (string) data_get($order, 'id', ''),
            'query' => implode('|', $queryParts),
        ];
    }

    private function toIso8601(string $datetime): string
    {
        if ($datetime === '') {
            return gmdate('Y-m-d\TH:i:s\Z');
        }

        try {
            return (new \DateTimeImmutable($datetime, new \DateTimeZone('UTC')))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (Throwable) {
            return gmdate('Y-m-d\TH:i:s\Z');
        }
    }
}
