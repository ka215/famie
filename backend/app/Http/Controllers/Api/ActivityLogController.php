<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Group;
use App\Models\GroupMember;
use App\Services\ActivityImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            ->with(['user:id,display_name', 'category:id,name,color_code,sort_order', 'image'])
            ->dateBetween($request->query('from'), $request->query('to'));
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json($query->orderByDesc('activity_date')->orderByDesc('activity_time')->orderByDesc('created_at')->orderByDesc('id')->paginate(50));
    }

    public function counts(Request $request, Group $group): JsonResponse
    {
        $request->validate([
            'from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', Rule::exists('group_members', 'user_id')->where(fn ($query) => $query->where('group_id', $group->id)->where('status', GroupMember::STATUS_ACTIVE))],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('group_id', $group->id)],
        ]);
        $query = $group->activityLogs()
            ->whereIn('user_id', $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id'))
            ->dateBetween($request->query('from'), $request->query('to'));
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json(['data' => $query->selectRaw('activity_date, count(*) as activity_count')->groupBy('activity_date')->pluck('activity_count', 'activity_date')]);
    }

    public function gallery(Request $request, Group $group): JsonResponse
    {
        $request->validate([
            'from' => ['nullable', 'date'], 'to' => ['nullable', 'date', 'after_or_equal:from'],
            'user_id' => ['nullable', Rule::exists('group_members', 'user_id')->where(fn ($query) => $query->where('group_id', $group->id)->where('status', GroupMember::STATUS_ACTIVE))],
            'category_id' => ['nullable', Rule::exists('categories', 'id')->where('group_id', $group->id)],
        ]);
        $query = $group->activityLogs()->whereHas('image')
            ->whereIn('user_id', $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id'))
            ->with(['user:id,display_name', 'category:id,name,color_code,sort_order', 'image'])
            ->dateBetween($request->query('from'), $request->query('to'));
        if ($request->filled('user_id')) {
            $query->where('user_id', $request->integer('user_id'));
        }
        if ($request->filled('category_id')) {
            $query->where('category_id', $request->integer('category_id'));
        }

        return response()->json($query->orderByDesc('activity_date')->orderByDesc('activity_time')->orderByDesc('created_at')->orderByDesc('id')->paginate(config('famie.gallery_page_size')));
    }

    public function store(Request $request, Group $group, ActivityImageService $images): JsonResponse
    {
        $this->rejectOwnedFields($request);
        $validated = $request->validate([
            'category_id' => ['required', Rule::exists('categories', 'id')->where('group_id', $group->id)],
            'activity_date' => ['required', 'date'], 'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['required', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'],
            'image' => ['sometimes', 'file', 'max:'.config('famie.image_max_input_kb')],
            'client_request_id' => ['sometimes', 'uuid'],
        ]);
        $file = $validated['image'] ?? null;
        unset($validated['image']);
        if (isset($validated['client_request_id'])) {
            $existing = $group->activityLogs()->where('user_id', $request->user()->id)
                ->where('client_request_id', $validated['client_request_id'])->first();
            if ($existing) {
                return response()->json(['message' => 'アクティビティを記録しました。', 'data' => $this->responseLog($request, $group, $existing->id)]);
            }
        }
        abort_if($file && ! $group->images_enabled, 422, 'この家族では画像の追加が無効です。');
        $prepared = $file ? $images->prepare($file) : null;
        try {
            $log = DB::transaction(function () use ($group, $request, $validated, $images, $prepared): ActivityLog {
                $log = $group->activityLogs()->create([...$validated, 'user_id' => $request->user()->id]);
                $images->apply($group, $log, $prepared);

                return $log;
            });
        } catch (Throwable $e) {
            if ($prepared) {
                $images->cleanupPrepared($prepared);
            }
            throw $e;
        }
        $log = $this->responseLog($request, $group, $log->id);

        return response()->json(['message' => 'アクティビティを記録しました。', 'data' => $log], 201);
    }

    public function show(Request $request, Group $group, int $log): JsonResponse
    {
        $target = $this->visibleLog($group, $log);
        Gate::authorize('view', $target);

        return response()->json(['data' => $this->responseLog($request, $group, $target->id)]);
    }

    public function update(Request $request, Group $group, int $log, ActivityImageService $images): JsonResponse
    {
        $this->rejectOwnedFields($request);
        $target = $this->visibleLog($group, $log);
        Gate::authorize('update', $target);
        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', Rule::exists('categories', 'id')->where('group_id', $group->id)],
            'activity_date' => ['sometimes', 'required', 'date'], 'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['sometimes', 'required', 'string', 'max:1000'], 'note' => ['nullable', 'string', 'max:1000'],
            'image' => ['sometimes', 'file', 'max:'.config('famie.image_max_input_kb')],
            'remove_image' => ['sometimes', 'boolean'],
            'revision' => ['sometimes', 'integer', 'min:1'],
        ]);
        $file = $validated['image'] ?? null;
        $remove = (bool) ($validated['remove_image'] ?? false);
        unset($validated['image'], $validated['remove_image']);
        $expectedRevision = isset($validated['revision']) ? (int) $validated['revision'] : null;
        unset($validated['revision']);
        if ($file && $remove) {
            throw ValidationException::withMessages(['image' => ['差し替えと削除は同時に指定できません。']]);
        }
        abort_if($file && ! $group->images_enabled, 422, 'この家族では画像の追加が無効です。');
        $prepared = $file ? $images->prepare($file) : null;
        try {
            DB::transaction(function () use ($target, $validated, $images, $group, $prepared, $remove, $expectedRevision): void {
                $lockedTarget = ActivityLog::query()->lockForUpdate()->findOrFail($target->id);
                abort_if($expectedRevision !== null && $expectedRevision !== $lockedTarget->revision, 409, '他の変更が先に保存されました。再読み込みしてください。');
                $lockedTarget->update([...$validated, 'revision' => $lockedTarget->revision + 1]);
                $images->apply($group, $lockedTarget, $prepared, $remove);
            });
        } catch (Throwable $e) {
            if ($prepared) {
                $images->cleanupPrepared($prepared);
            }
            throw $e;
        }

        return response()->json(['message' => 'ログを更新しました。', 'data' => $this->responseLog($request, $group, $target->id)]);
    }

    public function destroy(Group $group, int $log, ActivityImageService $images): JsonResponse
    {
        $target = $this->visibleLog($group, $log);
        Gate::authorize('delete', $target);
        DB::transaction(function () use ($images, $target): void {
            $images->removeForLog($target);
            $target->delete();
        });

        return response()->json(['message' => 'ログを削除しました。']);
    }

    private function visibleLog(Group $group, int $log): ActivityLog
    {
        return $group->activityLogs()->whereIn('user_id', $group->memberships()->where('status', GroupMember::STATUS_ACTIVE)->select('user_id'))->findOrFail($log);
    }

    private function responseLog(Request $request, Group $group, int $id): ActivityLog
    {
        return $group->activityLogs()->withLikeState($request->user()->id)
            ->with(['user:id,display_name', 'category:id,name,color_code,sort_order', 'image'])->findOrFail($id);
    }

    private function rejectOwnedFields(Request $request): void
    {
        if (array_intersect(array_keys($request->all()), ['group_id', 'user_id'])) {
            throw ValidationException::withMessages(['log' => ['家族または投稿者は指定できません。']]);
        }
    }
}
