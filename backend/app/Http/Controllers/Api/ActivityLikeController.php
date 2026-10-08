<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLike;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ActivityLikeController extends Controller
{
    public function update(Request $request, Group $group, int $log): JsonResponse
    {
        return $this->save($request, $group, $log, true);
    }

    public function destroy(Request $request, Group $group, int $log): JsonResponse
    {
        return $this->save($request, $group, $log, false);
    }

    private function save(Request $request, Group $group, int $log, bool $liked): JsonResponse
    {
        if ($request->all() !== []) {
            throw ValidationException::withMessages(['like' => ['いいねの対象者や件数は指定できません。']]);
        }

        // group.member holds the group lock and transaction for the entire write.
        $members = $group->memberships()->orderBy('id')->lockForUpdate()->get();
        $actorId = $request->user()->id;
        abort_unless($members->contains(fn (GroupMember $member) => $member->user_id === $actorId && $member->status === GroupMember::STATUS_ACTIVE), 403);
        $target = $group->activityLogs()->whereIn('user_id', $members->where('status', GroupMember::STATUS_ACTIVE)->pluck('user_id'))
            ->lockForUpdate()->findOrFail($log);
        abort_if($target->user_id === $actorId, 403, '自分の記録にはいいねできません。');

        if ($liked) {
            DB::insert('INSERT INTO activity_likes (group_id, activity_log_id, user_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT (group_id, activity_log_id, user_id) DO NOTHING', [
                $group->id, $target->id, $actorId, now(), now(),
            ]);
        } else {
            ActivityLike::query()->where('group_id', $group->id)->where('activity_log_id', $target->id)->where('user_id', $actorId)->delete();
        }

        $state = $group->activityLogs()->withLikeState($actorId)->findOrFail($target->id);

        return response()->json(['data' => $state->only(['id', 'likes_count', 'liked_by_me', 'can_like'])]);
    }
}
