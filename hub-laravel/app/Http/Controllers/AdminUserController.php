<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = 20;
        $page = max(1, (int) $request->query('page', 1));

        $query = User::with(['shops:id,shop_slug,name', 'balance']);
        $total = $query->count();
        $users = $query->orderByDesc('created_at')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return response()->json([
            'data' => $users->map(fn (User $u) => [
                'id' => $u->id,
                'email' => $u->email,
                'payer_email' => $u->payer_email,
                'payer_name' => $u->payer_name,
                'success_url' => $u->success_url,
                'failed_url' => $u->failed_url,
                'pushcut_url' => $u->pushcut_url,
                'pushcut_notify' => $u->pushcut_notify,
                'cartpanda_param' => $u->cartpanda_param,
                'role' => $u->role,
                'active' => $u->active,
                'created_at' => $u->created_at,
                'balance_pending' => $u->balance?->balance_pending ?? '0.000000',
                'balance_released' => $u->balance?->balance_released ?? '0.000000',
                'shops' => $u->shops->map(fn ($s) => [
                    'id' => $s->id,
                    'shop_slug' => $s->shop_slug,
                    'name' => $s->name,
                ]),
            ]),
            'meta' => [
                'total' => $total,
                'page' => $page,
                'per_page' => $perPage,
                'pages' => (int) ceil($total / $perPage),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'string', 'min:8'],
            'payer_email' => ['nullable', 'email'],
            'payer_name' => ['nullable', 'string'],
            'cartpanda_param' => ['nullable', 'string'],
            'success_url' => ['nullable', 'url'],
            'failed_url' => ['nullable', 'url'],
            'pushcut_url' => ['nullable', 'url'],
            'pushcut_notify' => ['nullable', 'in:all,created,paid'],
            'role' => ['nullable', 'in:user,admin'],
        ]);

        $user = User::create([
            'email' => $data['email'],
            'password_hash' => $data['password'],
            'payer_email' => $data['payer_email'] ?? '',
            'payer_name' => $data['payer_name'] ?? '',
            'cartpanda_param' => $data['cartpanda_param'] ?? null,
            'success_url' => $data['success_url'] ?? '',
            'failed_url' => $data['failed_url'] ?? '',
            'pushcut_url' => $data['pushcut_url'] ?? '',
            'pushcut_notify' => $data['pushcut_notify'] ?? 'all',
            'role' => $data['role'] ?? 'user',
        ]);

        return response()->json(['user' => $user->fresh()->makeVisible([])], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        $data = $request->validate([
            'email' => ['sometimes', 'email', 'unique:users,email,'.$user->id],
            'password' => ['sometimes', 'string', 'min:8'],
            'payer_email' => ['nullable', 'email'],
            'payer_name' => ['nullable', 'string'],
            'success_url' => ['nullable', 'url'],
            'failed_url' => ['nullable', 'url'],
            'pushcut_url' => ['nullable', 'url'],
            'pushcut_notify' => ['sometimes', 'in:all,created,paid'],
            'cartpanda_param' => ['nullable', 'string'],
            'active' => ['sometimes', 'boolean'],
        ]);

        if (isset($data['password'])) {
            $data['password_hash'] = $data['password'];
            unset($data['password']);
        }

        $user->update($data);

        return response()->json(['user' => $user->fresh()->makeVisible([])]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->id === $request->user()->id) {
            return response()->json(['error' => 'Cannot deactivate yourself'], 403);
        }

        $user->update(['active' => false]);

        return response()->json(['message' => 'User deactivated']);
    }
}
