<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ActivityLogController extends Controller
{
    public function index(Request $request, Group $group): JsonResponse
    {
        $activeUserIds = $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id');
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
            'user_id' => ['nullable', Rule::exists('group_members', 'user_id')->where(fn ($query) => $query->where('group_id', $group->id)->where('status', GroupMember::STATUS_ACTIVE))],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('group_id', $group->id)],
        ]);
        $query = $group->activityLogs()->whereIn('user_id', $activeUserIds)
            ->withLikeState($request->user()->id)
            ->with(['user:id,display_name', 'category:id,name,color_code,sort_order'])
            ->dateBetween($request->query('from'), $request->query('to'));
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json($query->orderByDesc('activity_date')->orderByDesc('activity_time')->orderByDesc('created_at')->paginate(50));
    }

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->rejectOwnedFields($request);
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->where('group_id', $group->id)],
            'activity_date' => ['required', 'date'], 'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['required', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'],
        ]);
        $log = $group->activityLogs()->create([...$validated, 'user_id' => $request->user()->id]);
        $log = $this->responseLog($request, $group, $log->id);

        return response()->json(['message' => 'アクティビティを記録しました。', 'data' => $log], 201);
    }

    public function show(Request $request, Group $group, int $log): JsonResponse
    {
        $target = $this->visibleLog($group, $log);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->responseLog($request, $group, $target->id)]);
    }

    public function update(Request $request, Group $group, int $log): JsonResponse
    {
        $this->rejectOwnedFields($request);
        $target = $this->visibleLog($group, $log);
        Gate::authorize('update', $target);
        $target->update($request->validate([
            'category_id' => ['sometimes', 'required', Rule::exists('categories', 'id')->where('group_id', $group->id)],
            'activity_date' => ['sometimes', 'required', 'date'], 'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['sometimes', 'required', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'],
        ]));

        return response()->json(['message' => 'ログを更新しました。', 'data' => $this->responseLog($request, $group, $target->id)]);
    }

    public function destroy(Group $group, int $log): JsonResponse
    {
        $target = $this->visibleLog($group, $log);
        Gate::authorize('delete', $target);
        $target->delete();

        return response()->json(['message' => 'ログを削除しました。']);
    }

    private function visibleLog(Group $group, int $log): ActivityLog
    {
        return $group->activityLogs()->whereIn('user_id', $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id'))->findOrFail($log);
    }

    private function responseLog(Request $request, Group $group, int $id): ActivityLog
    {
        return $group->activityLogs()->withLikeState($request->user()->id)
            ->with(['user:id,display_name', 'category:id,name,color_code,sort_order'])->findOrFail($id);
    }

    private function rejectOwnedFields(Request $request): void
    {
        if (array_intersect(array_keys($request->all()), ['group_id', 'user_id'])) {
            throw ValidationException::withMessages(['log' => ['家族または投稿者は指定できません。']]);
        }
    }
}
