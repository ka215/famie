<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\RewardVersion;
use App\Services\RewardService;
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
            abort_if($group->categories()->count() >= config('famie.max_group_categories'), 422, __('messages.category_limit', ['max' => config('famie.max_group_categories')]));

            return $group->categories()->create([...$validated, 'sort_order' => ($group->categories()->max('sort_order') ?? 0) + 1]);
        });

        return response()->json(['message' => __('messages.category_created'), 'data' => $category], 201);
    }

    public function update(Request $request, Group $group, int $category): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->categories()->findOrFail($category);
        $target->update($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255', Rule::unique('categories')->where('group_id', $group->id)->ignore($target->id)],
            'color_code' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'name.required' => __('messages.category_required'),
            'name.string' => __('messages.category_string'),
            'name.max' => __('messages.category_max'),
            'name.unique' => __('messages.category_duplicate'),
            'color_code.regex' => __('messages.color_invalid'),
        ]));

        return response()->json(['message' => __('messages.category_updated'), 'data' => $target]);
    }

    public function destroy(Request $request, Group $group, int $category, RewardService $rewards): JsonResponse
    {
        $this->authorizeAdmin($request);
        $target = $group->categories()->findOrFail($category);
        abort_if($target->activityLogs()->exists(), 422, __('messages.category_logs_used'));
        abort_if($rewards->categoryInUse($group, $category), 422, __('messages.category_reward_used'));
        RewardVersion::query()->where('group_id', $group->id)->where('category_id', $category)->update(['category_id' => null]);
        $target->delete();

        return response()->json(['message' => __('messages.category_deleted')]);
    }

    private function authorizeAdmin(Request $request): void
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, __('messages.category_forbidden'));
    }
}
