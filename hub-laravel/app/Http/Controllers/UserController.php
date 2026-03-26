<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::select('id', 'email', 'payer_email', 'payer_name', 'role', 'created_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['users' => $users]);
    }
}
