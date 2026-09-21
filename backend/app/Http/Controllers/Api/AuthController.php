<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $request->string('login'))
            ->orWhere('email', $request->string('login'))
            ->first();

        if (! $user || ! Hash::check($request->string('password'), $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['ログインIDまたはパスワードが正しくありません。'],
            ]);
        }

        $user->tokens()->delete();
        $token = $user->createToken('auth_token', expiresAt: now()->addDays(30))->plainTextToken;

        return response()->json([
            'message' => 'ログインに成功しました。',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'role' => $user->role,
            ],
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ログアウトしました。',
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'username', 'display_name', 'email', 'role']),
        ]);
    }
}
