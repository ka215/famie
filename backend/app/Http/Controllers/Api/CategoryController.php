<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index(Group $group): JsonResponse
    {
        return response()->json($group->categories()->select(['id', 'group_id', 'name', 'color_code', 'sort_order'])->orderBy('sort_order')->orderBy('id')->get());
    }

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->authorizeAdmin($request);
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('categories')->where('group_id', $group->id)],
            'color_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);
        $category = DB::transaction(function () use ($group, $validated): Category {
            Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();
            abort_if($group->categories()->count() >= config('famie.max_group_categories'), 422, 'カテゴリは最大'.config('famie.max_group_categories').'件です。');

            return $group->categories()->create([...$validated, 'sort_order' => ($group->categories()->max('sort_order') ?? 0) + 1]);
        });

        return response()->json(['message' => 'カテゴリを作成しました。', 'data' => $category], 201);
    }

    public function update(Request $request, Group $group, int $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->categories()->findOrFail($category);
        $target->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories')->where('group_id', $group->id)->ignore($target->id)],
            'color_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => 'カテゴリ名を入力してください。',
            'name.string' => 'カテゴリ名は文字列で入力してください。',
            'name.max' => 'カテゴリ名は255文字以内で入力してください。',
            'name.unique' => '同じ名前のカテゴリが存在します。',
            'color_code.regex' => '色は#に続く6桁の16進数で指定してください。',
        ]));

        return response()->json(['message' => 'カテゴリを更新しました。', 'data' => $target]);
    }

    public function destroy(Request $request, Group $group, int $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->categories()->findOrFail($category);
        abort_if($target->activityLogs()->exists(), 422, 'ログが存在するカテゴリは削除できません。');
        $target->delete();

        return response()->json(['message' => 'カテゴリを削除しました。']);
    }

    private function authorizeAdmin(Request $request): void
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, 'カテゴリの変更権限がありません。');
    }
}
