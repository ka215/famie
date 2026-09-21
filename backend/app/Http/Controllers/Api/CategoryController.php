<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(Category::select(['id', 'name', 'color_code'])->get());
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'カテゴリの作成権限がありません。'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:categories,name'],
            'color_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category = Category::create($validated);

        return response()->json([
            'message' => 'カテゴリを作成しました。',
            'data' => $category,
        ], 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'カテゴリの更新権限がありません。'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories', 'name')->ignore($category->id)],
            'color_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $category->update($validated);

        return response()->json([
            'message' => 'カテゴリを更新しました。',
            'data' => $category,
        ]);
    }

    public function destroy(Request $request, Category $category): JsonResponse
    {
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'カテゴリの削除権限がありません。'], 403);
        }

        if ($category->activityLogs()->exists()) {
            return response()->json(['message' => 'ログが存在するカテゴリは削除できません。'], 422);
        }

        $category->delete();

        return response()->json([
            'message' => 'カテゴリを削除しました。',
        ]);
    }
}
