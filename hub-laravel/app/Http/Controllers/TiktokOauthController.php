<?php

namespace App\Http\Controllers;

use App\Models\TiktokOauthConnection;
use App\Models\TiktokPixel;
use App\Services\TiktokDiscoveryService;
use App\Services\TiktokOauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TiktokOauthController extends Controller
{
    public function __construct(
        private TiktokOauthService $oauth,
        private TiktokDiscoveryService $discovery,
    ) {}

    public function start(Request $request): JsonResponse
    {
        $url = $this->oauth->buildAuthorizeUrl($request->user());

        return response()->json(['authorize_url' => $url]);
    }

    /**
     * Public TikTok-facing callback. Validates state, exchanges the auth code,
     * persists the connection, and redirects the user back to the dashboard.
     */
    public function callback(Request $request): RedirectResponse
    {
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('auth_code', $request->query('code', ''));
        $tiktokError = (string) $request->query('error', '');
        $front = rtrim((string) (config('services.tiktok.dashboard_url') ?? config('app.frontend_url') ?? '/'), '/');

        if ($tiktokError !== '') {
            return redirect()->away($front.'/settings?tiktok_oauth=error&reason='.urlencode($tiktokError));
        }

        if ($state === '' || $code === '') {
            return redirect()->away($front.'/settings?tiktok_oauth=error&reason=missing_params');
        }

        $userId = $this->oauth->consumeState($state);
        if (! $userId) {
            return redirect()->away($front.'/settings?tiktok_oauth=error&reason=invalid_state');
        }

        $connection = $this->oauth->completeAuthorization($userId, $code);
        if (! $connection) {
            return redirect()->away($front.'/settings?tiktok_oauth=error&reason=exchange_failed');
        }

        return redirect()->away($front.'/settings?tiktok_oauth=connected&connection='.$connection->id);
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = TiktokOauthConnection::query();

        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        } else {
            if ($request->filled('user_id')) {
                $query->where('user_id', (int) $request->query('user_id'));
            }
            $query->with('user:id,email');
        }

        $connections = $query->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $connections->map(fn (TiktokOauthConnection $c) => [
                'id' => $c->id,
                'user' => $user->isAdmin() && $c->user
                    ? ['id' => $c->user->id, 'email' => $c->user->email]
                    : null,
                'bc_id' => $c->bc_id,
                'bc_name' => $c->bc_name,
                'advertiser_ids' => $c->advertiser_ids ?? [],
                'scope' => $c->scope ?? [],
                'expires_at' => $c->expires_at,
                'status' => $c->status,
                'is_active' => $c->isActive(),
                'created_at' => $c->created_at,
            ]),
        ]);
    }

    public function destroy(Request $request, TiktokOauthConnection $tiktokOauthConnection): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokOauthConnection->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $this->oauth->revoke($tiktokOauthConnection);
        $tiktokOauthConnection->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Discover the BC: returns pixels (deduped — TikTok shares pixels across
     * advertisers) and advertisers (with balance) as separate flat lists.
     *
     * BCs own pixels, share with advertisers. Server-side conversions only
     * need pixel_code; ttclid drives advertiser attribution. So we track at
     * pixel level, not per-advertiser.
     */
    public function discover(Request $request, TiktokOauthConnection $tiktokOauthConnection): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokOauthConnection->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tracked = TiktokPixel::where('tiktok_oauth_connection_id', $tiktokOauthConnection->id)
            ->get(['id', 'pixel_code'])
            ->keyBy('pixel_code');

        $advertisers = $this->discovery->listAdvertisers($tiktokOauthConnection);
        $pixelsByCode = [];

        foreach ($advertisers as $adv) {
            foreach ($this->discovery->listPixels($tiktokOauthConnection, $adv['advertiser_id']) as $p) {
                $code = $p['pixel_code'];
                if (! isset($pixelsByCode[$code])) {
                    $hit = $tracked->get($code);
                    $pixelsByCode[$code] = [
                        ...$p,
                        'shared_with_count' => 0,
                        'tracked' => $hit !== null,
                        'tracked_pixel_id' => $hit?->id,
                    ];
                }
                $pixelsByCode[$code]['shared_with_count']++;
            }
        }

        return response()->json(['data' => [
            'advertisers' => $advertisers,
            'pixels' => array_values($pixelsByCode),
        ]]);
    }

    public function validatePixel(Request $request, TiktokOauthConnection $tiktokOauthConnection): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokOauthConnection->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'pixel_code' => ['required', 'string', 'max:64'],
        ]);

        $hit = $this->discovery->validatePixel($tiktokOauthConnection, $data['pixel_code']);

        return response()->json([
            'data' => [
                'valid' => $hit !== null,
                'advertiser_id' => $hit['advertiser_id'] ?? null,
                'advertiser_name' => $hit['advertiser_name'] ?? null,
                'name' => $hit['name'] ?? null,
            ],
        ]);
    }

    public function trackPixel(Request $request, TiktokOauthConnection $tiktokOauthConnection): JsonResponse
    {
        $user = $request->user();
        if (! $user->isAdmin() && $tiktokOauthConnection->user_id !== $user->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'pixel_code' => ['required', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $hit = $this->discovery->validatePixel($tiktokOauthConnection, $data['pixel_code']);
        if (! $hit) {
            return response()->json(['error' => 'Pixel não acessível por essa conexão'], 422);
        }

        $owner = $tiktokOauthConnection->user;
        $pixel = $owner->tiktokPixels()->firstOrCreate(
            ['pixel_code' => $data['pixel_code']],
            [
                'tiktok_oauth_connection_id' => $tiktokOauthConnection->id,
                'access_token' => '',
                'label' => $data['label'] ?? $hit['name'] ?: null,
                'enabled' => true,
            ],
        );

        // Ensure existing rows get linked to this connection.
        if ($pixel->wasRecentlyCreated === false && $pixel->tiktok_oauth_connection_id !== $tiktokOauthConnection->id) {
            $pixel->update(['tiktok_oauth_connection_id' => $tiktokOauthConnection->id]);
        }

        return response()->json(['data' => [
            'id' => $pixel->id,
            'pixel_code' => $pixel->pixel_code,
            'label' => $pixel->label,
        ]], 201);
    }
}
