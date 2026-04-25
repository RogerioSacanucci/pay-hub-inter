<?php

namespace App\Http\Controllers;

use App\Models\TiktokOauthConnection;
use App\Models\TiktokPixel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TiktokPixelController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $pixels = $request->user()->tiktokPixels()
            ->with('oauthConnection:id,bc_name,bc_id,status')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'data' => $pixels->map(fn (TiktokPixel $p) => $this->transform($p)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'pixel_code' => ['required', 'string', 'max:64'],
            'access_token' => ['nullable', 'string', 'max:500'],
            'tiktok_oauth_connection_id' => ['nullable', 'integer', 'exists:tiktok_oauth_connections,id'],
            'label' => ['nullable', 'string', 'max:100'],
            'test_event_code' => ['nullable', 'string', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $this->ensureCredentialsProvided($data);
        $this->ensureConnectionBelongsToUser($data['tiktok_oauth_connection_id'] ?? null, $request->user()->id);

        // access_token may be empty when using OAuth; encrypted cast handles ''
        $data['access_token'] = $data['access_token'] ?? '';

        $pixel = $request->user()->tiktokPixels()->create($data);

        return response()->json(['data' => $this->transform($pixel->fresh('oauthConnection'))], 201);
    }

    public function update(Request $request, TiktokPixel $tiktokPixel): JsonResponse
    {
        if ($tiktokPixel->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'pixel_code' => ['sometimes', 'string', 'max:64'],
            'access_token' => ['sometimes', 'nullable', 'string', 'max:500'],
            'tiktok_oauth_connection_id' => ['sometimes', 'nullable', 'integer', 'exists:tiktok_oauth_connections,id'],
            'label' => ['nullable', 'string', 'max:100'],
            'test_event_code' => ['nullable', 'string', 'max:50'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('tiktok_oauth_connection_id', $data)) {
            $this->ensureConnectionBelongsToUser($data['tiktok_oauth_connection_id'], $request->user()->id);
        }

        $tiktokPixel->update($data);

        return response()->json(['data' => $this->transform($tiktokPixel->fresh('oauthConnection'))]);
    }

    public function destroy(Request $request, TiktokPixel $tiktokPixel): JsonResponse
    {
        if ($tiktokPixel->user_id !== $request->user()->id) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $tiktokPixel->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function ensureCredentialsProvided(array $data): void
    {
        $hasToken = ! empty($data['access_token']);
        $hasConnection = ! empty($data['tiktok_oauth_connection_id']);
        if (! $hasToken && ! $hasConnection) {
            throw ValidationException::withMessages([
                'access_token' => 'Informe um access_token ou selecione uma conexão BC.',
            ]);
        }
    }

    private function ensureConnectionBelongsToUser(?int $connectionId, int $userId): void
    {
        if ($connectionId === null) {
            return;
        }
        $owns = TiktokOauthConnection::where('id', $connectionId)->where('user_id', $userId)->exists();
        if (! $owns) {
            throw ValidationException::withMessages([
                'tiktok_oauth_connection_id' => 'Conexão BC não pertence ao usuário.',
            ]);
        }
    }

    private function transform(TiktokPixel $pixel): array
    {
        return [
            'id' => $pixel->id,
            'pixel_code' => $pixel->pixel_code,
            'label' => $pixel->label,
            'test_event_code' => $pixel->test_event_code,
            'enabled' => (bool) $pixel->enabled,
            'has_access_token' => ! empty($pixel->access_token),
            'oauth_connection' => $pixel->oauthConnection ? [
                'id' => $pixel->oauthConnection->id,
                'bc_name' => $pixel->oauthConnection->bc_name,
                'bc_id' => $pixel->oauthConnection->bc_id,
                'status' => $pixel->oauthConnection->status,
            ] : null,
            'created_at' => $pixel->created_at,
        ];
    }
}
