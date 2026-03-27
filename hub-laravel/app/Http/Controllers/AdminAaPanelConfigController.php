<?php

namespace App\Http\Controllers;

use App\Models\UserAapanelConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminAaPanelConfigController extends Controller
{
    public function index(): JsonResponse
    {
        $configs = UserAapanelConfig::with('user:id,email')->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $configs->map(fn (UserAapanelConfig $config) => $this->formatConfig($config)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'label' => ['required', 'string', 'max:255'],
            'panel_url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
        ]);

        $config = UserAapanelConfig::create($data);
        $config->load('user:id,email');

        return response()->json(['data' => $this->formatConfig($config)], 201);
    }

    public function update(Request $request, UserAapanelConfig $aapanelConfig): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['sometimes', 'exists:users,id'],
            'label' => ['sometimes', 'string', 'max:255'],
            'panel_url' => ['sometimes', 'url'],
            'api_key' => ['sometimes', 'string'],
        ]);

        $aapanelConfig->update($data);
        $aapanelConfig->load('user:id,email');

        return response()->json(['data' => $this->formatConfig($aapanelConfig)]);
    }

    public function destroy(UserAapanelConfig $aapanelConfig): JsonResponse
    {
        $aapanelConfig->delete();

        return response()->json(['message' => 'Config deleted']);
    }

    private function formatConfig(UserAapanelConfig $config): array
    {
        return [
            'id' => $config->id,
            'user_id' => $config->user_id,
            'user_email' => $config->user?->email,
            'label' => $config->label,
            'panel_url' => $config->panel_url,
            'api_key_masked' => '****'.substr($config->api_key, -4),
            'created_at' => $config->created_at,
        ];
    }
}
