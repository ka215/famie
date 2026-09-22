<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ActivityLog::class);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $query = ActivityLog::with(['user:id,display_name,role', 'category:id,name,color_code'])
            ->dateBetween($request->query('from'), $request->query('to'));

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->query('user_id'));
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->query('category_id'));
        }

        $logs = $query->orderBy('activity_date', 'desc')
            ->orderBy('activity_time', 'desc')
            ->orderBy('created_at', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ActivityLog::class);

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'activity_date' => ['required', 'date'],
            'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['user_id'] = $request->user()->id;

        $log = ActivityLog::create($validated);
        $log->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'message' => 'アクティビティを記録しました。',
            'data' => $log,
        ], 201);
    }

    public function show(ActivityLog $log): JsonResponse
    {
        Gate::authorize('view', $log);

        $log->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'data' => $log,
        ]);
    }

    public function update(Request $request, ActivityLog $log): JsonResponse
    {
        Gate::authorize('update', $log);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'activity_date' => ['sometimes', 'required', 'date'],
            'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['sometimes', 'required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $log->update($validated);
        $log->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'message' => 'ログを更新しました。',
            'data' => $log,
        ]);
    }

    public function destroy(ActivityLog $log): JsonResponse
    {
        Gate::authorize('delete', $log);

        $log->delete();

        return response()->json([
            'message' => 'ログを削除しました。',
        ]);
    }
}
