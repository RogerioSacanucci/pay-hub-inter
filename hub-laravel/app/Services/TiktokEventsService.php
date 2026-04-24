<?php

namespace App\Services;

use App\Models\TiktokPixel;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class TiktokEventsService
{
    private const ENDPOINT = 'https://business-api.tiktok.com/open_api/v1.3/pixel/track/';

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

        $context = $this->buildContext($order, $callback);
        $properties = $this->buildProperties($order);
        $eventId = (string) data_get($order, 'id', '');
        $timestamp = $this->toIso8601((string) data_get($order, 'processed_at', ''));

        try {
            $responses = Http::pool(fn (Pool $pool) => $pixels
                ->map(fn (TiktokPixel $pixel) => $pool
                    ->as((string) $pixel->id)
                    ->timeout(10)
                    ->withHeaders(['Access-Token' => $pixel->access_token])
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
                if ($response instanceof Throwable) {
                    Log::warning('TikTok Events API transport error', [
                        'pixel_id' => $pixelId,
                        'order_id' => $eventId,
                        'error' => $response->getMessage(),
                    ]);

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
            }
        } catch (Throwable $e) {
            Log::warning('TikTok Events API pool failed', [
                'order_id' => $eventId,
                'error' => $e->getMessage(),
            ]);
        }
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
            'event' => 'CompletePayment',
            'event_id' => $eventId,
            'timestamp' => $timestamp,
            'context' => $context,
            'properties' => $properties,
            'event_source' => 'web',
            'partner_name' => 'cartpanda',
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
        $email = (string) data_get($order, 'customer.email', '');
        $phone = (string) data_get($order, 'customer.phone', '');
        $externalId = (string) data_get($order, 'customer.id', '');

        $user = [];
        if ($email !== '') {
            $user['email'] = hash('sha256', strtolower(trim($email)));
        }
        if ($phone !== '') {
            $user['phone_number'] = hash('sha256', preg_replace('/\D+/', '', $phone));
        }
        if ($externalId !== '') {
            $user['external_id'] = hash('sha256', $externalId);
        }

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
