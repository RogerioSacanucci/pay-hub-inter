<?php

namespace App\Http\Controllers;

use App\Models\UserAapanelConfig;
use App\Models\UserLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserLinkController extends Controller
{
    public function index(): JsonResponse
    {
        $links = UserLink::with(['user:id,email', 'aapanelConfig:id,label'])->orderByDesc('created_at')->get();

        return response()->json([
            'data' => $links->map(fn (UserLink $link) => $this->formatLink($link)),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'aapanel_config_id' => ['required', 'integer', 'exists:user_aapanel_configs,id'],
            'label' => ['required', 'string', 'max:255'],
            'external_url' => ['required', 'url'],
            'file_path' => ['required', 'string', 'max:500'],
        ]);

        $configBelongsToUser = UserAapanelConfig::where('id', $data['aapanel_config_id'])
            ->where('user_id', $data['user_id'])
            ->exists();

        if (! $configBelongsToUser) {
            return response()->json(['errors' => ['aapanel_config_id' => ['aaPanel config does not belong to specified user']]], 422);
        }

        $link = UserLink::create($data);
        $link->load(['user:id,email', 'aapanelConfig:id,label']);

        return response()->json(['data' => $this->formatLink($link)], 201);
    }

    public function update(Request $request, UserLink $userLink): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:255'],
            'external_url' => ['sometimes', 'url'],
            'file_path' => ['sometimes', 'string', 'max:500'],
        ]);

        $userLink->update($data);
        $userLink->load(['user:id,email', 'aapanelConfig:id,label']);

        return response()->json(['data' => $this->formatLink($userLink)]);
    }

    public function destroy(UserLink $userLink): JsonResponse
    {
        $userLink->delete();

        return response()->json(['message' => 'Link deleted']);
    }

    private function formatLink(UserLink $link): array
    {
        return [
            'id' => $link->id,
            'user_id' => $link->user_id,
            'user_email' => $link->user?->email,
            'aapanel_config_id' => $link->aapanel_config_id,
            'aapanel_config_label' => $link->aapanelConfig?->label,
            'label' => $link->label,
            'external_url' => $link->external_url,
            'file_path' => $link->file_path,
            'created_at' => $link->created_at,
        ];
    }
}
