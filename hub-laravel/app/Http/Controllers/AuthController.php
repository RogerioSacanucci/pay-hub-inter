<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password_hash)) {
            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        if (! $user->active) {
            return response()->json(['error' => 'Account disabled'], 401);
        }

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ]);
    }

    public function register(Request $request): JsonResponse
    {
        if (! hash_equals((string) config('app.admin_register_key'), (string) $request->input('admin_key', ''))) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $data = $request->validate([
            'email' => ['required', 'email', 'unique:users'],
            'password' => ['required', 'min:8'],
            'payer_email' => ['nullable', 'email'],
            'payer_name' => ['nullable', 'string'],
            'success_url' => ['nullable', 'url'],
            'failed_url' => ['nullable', 'url'],
        ]);

        $user = User::create([
            'email' => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'payer_email' => $data['payer_email'] ?? '',
            'payer_name' => $data['payer_name'] ?? '',
            'success_url' => $data['success_url'] ?? '',
            'failed_url' => $data['failed_url'] ?? '',
            'role' => 'user',
        ]);

        $token = $user->createToken('auth')->plainTextToken;

        return response()->json([
            'token' => $token,
            'user' => $this->formatUser($user),
        ], 201);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $this->formatUser($request->user())]);
    }

    /**
     * @return array{id: int, email: string, payer_email: string, payer_name: string, internacional_param: string|null, role: string}
     */
    private function formatUser(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'payer_email' => $user->payer_email,
            'payer_name' => $user->payer_name,
            'internacional_param' => $user->cartpanda_param,
            'role' => $user->role,
        ];
    }
}
