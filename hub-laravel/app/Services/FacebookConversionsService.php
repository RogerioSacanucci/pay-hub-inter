<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class FacebookConversionsService
{
    /**
     * @param  array{email?: string|null, first_name?: string|null, last_name?: string|null}  $userData
     */
    public function sendPurchaseEvent(
        string $pixelId,
        string $accessToken,
        string $orderId,
        float $value,
        string $currency,
        array $userData,
        ?int $eventTime = null,
    ): void {
        try {
            $url = "https://graph.facebook.com/v21.0/{$pixelId}/events";

            $hashedUserData = [];
            if (! empty($userData['email'])) {
                $hashedUserData['em'] = [hash('sha256', strtolower(trim($userData['email'])))];
            }
            if (! empty($userData['first_name'])) {
                $hashedUserData['fn'] = [hash('sha256', strtolower(trim($userData['first_name'])))];
            }
            if (! empty($userData['last_name'])) {
                $hashedUserData['ln'] = [hash('sha256', strtolower(trim($userData['last_name'])))];
            }

            $payload = [
                'data' => [[
                    'event_name' => 'Purchase',
                    'event_time' => $eventTime ?? time(),
                    'event_id' => $orderId,
                    'action_source' => 'system_generated',
                    'user_data' => $hashedUserData,
                    'custom_data' => [
                        'value' => $value,
                        'currency' => $currency,
                        'order_id' => $orderId,
                    ],
                ]],
            ];

            Http::timeout(10)
                ->throw()
                ->withQueryParameters(['access_token' => $accessToken])
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Facebook Conversions API failed', [
                'pixel_id' => $pixelId,
                'order_id' => $orderId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
