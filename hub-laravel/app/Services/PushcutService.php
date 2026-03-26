<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class PushcutService
{
    /**
     * Send a fire-and-forget Pushcut notification.
     *
     * @param  array<string, mixed>|null  $data
     */
    public function send(string $url, string $title, ?array $data = null): void
    {
        try {
            $payload = ['title' => $title];

            if ($data !== null) {
                $payload['data'] = $data;
            }

            Http::timeout(5)
                ->throw()
                ->post($url, $payload);
        } catch (Throwable $e) {
            Log::warning('Pushcut notification failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
