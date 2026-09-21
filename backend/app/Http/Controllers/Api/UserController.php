<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(): JsonResponse
    {
        $users = User::select(['id', 'username', 'display_name', 'role'])->get();

        return response()->json($users);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'アカウントの作成権限がありません。'], 403);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'display_name' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'role' => ['required', Rule::in(['parent', 'child'])],
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'display_name' => $validated['display_name'],
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'ユーザーを作成しました。',
            'data' => $user->only(['id', 'username', 'display_name', 'role']),
        ], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'アカウントの編集権限がありません。'], 403);
        }

        $validated = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('users', 'username')->ignore($user->id)],
            'display_name' => ['sometimes', 'required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'role' => ['sometimes', 'required', Rule::in(['parent', 'child'])],
        ]);

        $user->update($validated);

        return response()->json([
            'message' => 'ユーザーを更新しました。',
            'data' => $user->only(['id', 'username', 'display_name', 'role']),
        ]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'アカウントの削除権限がありません。'], 403);
        }

        if ($request->user()->id === $user->id) {
            return response()->json(['message' => '自分自身のアカウントは削除できません。'], 422);
        }

        $user->delete();

        return response()->json([
            'message' => 'ユーザーを削除しました。',
        ]);
    }

    public function updatePassword(Request $request, User $user): JsonResponse
    {
        $authUser = $request->user();

        if ($authUser->id !== $user->id && ! $authUser->isParent()) {
            return response()->json(['message' => 'パスワードの変更権限がありません。'], 403);
        }

        $rules = ['new_password' => ['required', 'string', 'min:6', 'confirmed']];

        if ($authUser->id === $user->id) {
            $rules['current_password'] = ['required', 'string'];
        }

        $validated = $request->validate($rules);

        if ($authUser->id === $user->id && ! Hash::check($validated['current_password'], $user->password)) {
            return response()->json(['message' => '現在のパスワードが正しくありません。'], 422);
        }

        $user->update(['password' => Hash::make($validated['new_password'])]);

        return response()->json([
            'message' => 'パスワードを変更しました。',
        ]);
    }
}
