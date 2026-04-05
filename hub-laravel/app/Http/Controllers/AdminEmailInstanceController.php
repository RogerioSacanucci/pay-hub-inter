<?php

namespace App\Http\Controllers;

use App\Models\EmailServiceInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminEmailInstanceController extends Controller
{
    public function index(): JsonResponse
    {
        $instances = EmailServiceInstance::orderByDesc('created_at')->get();

        return response()->json([
            'data' => $instances->map(fn (EmailServiceInstance $i) => [
                'id' => $i->id,
                'name' => $i->name,
                'url' => $i->url,
                'active' => $i->active,
                'created_at' => $i->created_at,
                'updated_at' => $i->updated_at,
            ]),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url'],
            'api_key' => ['required', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $instance = EmailServiceInstance::create($data)->fresh();

        return response()->json([
            'data' => ['id' => $instance->id, 'name' => $instance->name, 'url' => $instance->url, 'active' => $instance->active],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $instance = EmailServiceInstance::findOrFail($id);

        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'url' => ['sometimes', 'url'],
            'api_key' => ['sometimes', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        $instance->update($data);

        return response()->json([
            'data' => ['id' => $instance->id, 'name' => $instance->name, 'url' => $instance->url, 'active' => $instance->active],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $instance = EmailServiceInstance::findOrFail($id);
        $instance->delete();

        return response()->json(['message' => 'Instance deleted']);
    }
}
