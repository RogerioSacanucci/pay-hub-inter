<?php

namespace App\Http\Controllers;

use App\Models\TiktokOauthConnection;
use App\Services\TiktokOauthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TiktokOauthController extends Controller
{
    public function __construct(private TiktokOauthService $oauth) {}

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
}
