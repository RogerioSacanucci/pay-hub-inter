<?php

namespace App\Http\Controllers;

use App\Models\UserLink;
use App\Services\AaPanelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserLinkController extends Controller
{
    public function index(): JsonResponse
    {
        $links = auth()->user()->links()->get();

        return response()->json([
            'data' => $links->map(fn (UserLink $link) => [
                'id' => $link->id,
                'label' => $link->label,
                'external_url' => $link->external_url,
                'file_path' => $link->file_path,
            ]),
        ]);
    }

    public function getContent(UserLink $link): JsonResponse
    {
        $this->authorizeLink($link);

        $config = $link->aapanelConfig;
        $service = new AaPanelService($config->panel_url, $config->api_key);

        try {
            $content = $service->getFileContent($link->file_path);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to fetch file content'], 502);
        }

        return response()->json(['content' => $content]);
    }

    public function saveContent(Request $request, UserLink $link): JsonResponse
    {
        $this->authorizeLink($link);

        $data = $request->validate([
            'content' => ['required', 'string'],
        ]);

        $config = $link->aapanelConfig;
        $service = new AaPanelService($config->panel_url, $config->api_key);

        try {
            $service->saveFileContent($link->file_path, $data['content']);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to save file content'], 502);
        }

        return response()->json(['message' => 'File saved successfully']);
    }

    private function authorizeLink(UserLink $link): void
    {
        $user = auth()->user();
        if ($link->user_id !== $user->id && ! $user->isAdmin()) {
            abort(403);
        }
    }
}
