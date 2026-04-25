<?php

namespace App\Services;

use App\Models\TiktokOauthConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TiktokOauthService
{
    public function __construct() {}

    /**
     * Build the URL the user is redirected to in order to authorize the app
     * against their TikTok For Business account.
     */
    public function buildAuthorizeUrl(User $user): string
    {
        $appId = (string) config('services.tiktok.app_id');
        $redirect = (string) config('services.tiktok.oauth_redirect');
        $portal = rtrim((string) config('services.tiktok.oauth_authorize_url'), '/');

        if ($appId === '' || $redirect === '' || $portal === '') {
            throw new \RuntimeException('TikTok OAuth não configurado (TIKTOK_APP_ID / TIKTOK_OAUTH_REDIRECT / TIKTOK_OAUTH_AUTHORIZE_URL).');
        }

        $state = bin2hex(random_bytes(16));

        DB::table('tiktok_oauth_states')->insert([
            'state' => $state,
            'user_id' => $user->id,
            'expires_at' => now()->addMinutes(15),
            'created_at' => now(),
        ]);

        $params = http_build_query([
            'app_id' => $appId,
            'state' => $state,
            'redirect_uri' => $redirect,
        ]);

        return $portal.'?'.$params;
    }

    /**
     * Validate the state value returned by TikTok. Returns the originating
     * user_id, or null if the state is unknown / expired. Consumes the state
     * (one-shot) regardless of outcome so it cannot be replayed.
     */
    public function consumeState(string $state): ?int
    {
        $row = DB::table('tiktok_oauth_states')->where('state', $state)->first();
        DB::table('tiktok_oauth_states')->where('state', $state)->delete();
        DB::table('tiktok_oauth_states')->where('expires_at', '<', now())->delete();

        if (! $row) {
            return null;
        }
        if (now()->greaterThan($row->expires_at)) {
            return null;
        }

        return (int) $row->user_id;
    }

    /**
     * Exchange the auth code for an access token, persist the connection,
     * and try to enrich it with BC + advertiser metadata. Returns the new
     * connection or null on failure.
     */
    public function completeAuthorization(int $userId, string $authCode): ?TiktokOauthConnection
    {
        $appId = (string) config('services.tiktok.app_id');
        $secret = (string) config('services.tiktok.app_secret');
        $base = rtrim((string) config('services.tiktok.open_api_base'), '/');

        try {
            $res = Http::timeout(15)
                ->post($base.'/oauth2/access_token/', [
                    'app_id' => $appId,
                    'secret' => $secret,
                    'auth_code' => $authCode,
                ]);

            $data = (array) $res->json('data', []);
            $code = (int) $res->json('code', 0);
            if (! $res->successful() || $code !== 0 || empty($data['access_token'])) {
                Log::warning('TikTok OAuth code exchange failed', [
                    'user_id' => $userId,
                    'http' => $res->status(),
                    'tiktok_code' => $code,
                    'message' => $res->json('message'),
                ]);

                return null;
            }

            $token = (string) $data['access_token'];
            $advertiserIds = $this->normalizeStringList($data['advertiser_ids'] ?? []);
            $scope = $this->normalizeStringList($data['scope'] ?? []);
            $expiresAt = isset($data['expires_in']) && $data['expires_in'] > 0
                ? now()->addSeconds((int) $data['expires_in'])
                : null;

            $connection = TiktokOauthConnection::create([
                'user_id' => $userId,
                'access_token' => $token,
                'refresh_token' => $data['refresh_token'] ?? null,
                'expires_at' => $expiresAt,
                'scope' => $scope,
                'advertiser_ids' => $advertiserIds,
                'status' => 'active',
            ]);

            $this->enrichBusinessCenter($connection, $token);

            return $connection;
        } catch (Throwable $e) {
            Log::warning('TikTok OAuth exchange exception', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Best-effort fetch of the first BC associated with the token + cache its
     * id/name on the connection. Failures are silent — the connection still
     * works for advertiser-scoped calls.
     */
    private function enrichBusinessCenter(TiktokOauthConnection $connection, string $token): void
    {
        try {
            $base = rtrim((string) config('services.tiktok.open_api_base'), '/');
            $res = Http::timeout(10)
                ->withHeaders(['Access-Token' => $token])
                ->get($base.'/bc/get/');

            if (! $res->successful()) {
                return;
            }

            $list = (array) $res->json('data.list', []);
            if (empty($list)) {
                return;
            }

            $first = (array) $list[0];
            $connection->update([
                'bc_id' => (string) ($first['bc_id'] ?? ''),
                'bc_name' => (string) ($first['bc_info']['name'] ?? $first['name'] ?? ''),
            ]);
        } catch (Throwable $e) {
            Log::info('TikTok BC enrichment failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Best-effort revoke against TikTok + mark connection as revoked locally.
     * Local state is updated even if the remote call fails.
     */
    public function revoke(TiktokOauthConnection $connection): void
    {
        $appId = (string) config('services.tiktok.app_id');
        $secret = (string) config('services.tiktok.app_secret');
        $base = rtrim((string) config('services.tiktok.open_api_base'), '/');
        $token = $connection->access_token;

        try {
            Http::timeout(10)->post($base.'/oauth2/revoke_token/', [
                'app_id' => $appId,
                'secret' => $secret,
                'access_token' => $token,
            ]);
        } catch (Throwable $e) {
            Log::info('TikTok revoke remote call failed', ['error' => $e->getMessage()]);
        }

        $connection->update(['status' => 'revoked']);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_string($v)) {
                $out[] = $v;
            } elseif (is_int($v) || is_float($v)) {
                $out[] = (string) $v;
            }
        }

        return $out;
    }

    public static function newState(): string
    {
        return Str::lower(Str::random(32));
    }
}
