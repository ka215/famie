<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use App\Notifications\RepeatedLoginLockout;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoginSecurityAlertController extends Controller
{
    public function index(Request $request, Group $group): JsonResponse
    {
        $unread = $this->alerts($request, $group)->whereNull('read_at');
        $validated = $request->validate(['membership_id' => ['sometimes', 'required', 'integer', 'min:1']]);
        $memberId = isset($validated['membership_id']) ? (int) $validated['membership_id'] : null;
        if ($memberId !== null) {
            GroupMember::query()->where('group_id', $group->id)->findOrFail($memberId);
        }
        $counts = (clone $unread)->reorder()->toBase()
            ->select('data->membership_id as membership_id')->selectRaw('count(*) as count')
            ->groupBy('data->membership_id')->get()
            ->map(fn ($row): array => ['membership_id' => (int) $row->membership_id, 'count' => (int) $row->count]);
        $filtered = clone $unread;
        if ($memberId !== null) {
            $filtered->where('data->membership_id', $memberId);
        }
        $total = $memberId === null ? $counts->sum('count') : $counts->where('membership_id', $memberId)->sum('count');
        $alerts = $filtered->latest()->orderByDesc('id')->limit(20)->get();

        return response()->json(['data' => $alerts->map(fn ($alert): array => [
            'id' => $alert->id, ...$alert->data,
        ]), 'meta' => [
            'unread_total' => $total,
            'unread_by_member' => $counts,
            'limit' => 20,
            'has_more' => $total > $alerts->count(),
        ]]);
    }

    public function read(Request $request, Group $group, string $alert): JsonResponse
    {
        abort_if($request->all() !== [], 422, __('messages.alerts_fields'));
        $this->alerts($request, $group)->findOrFail($alert)->markAsRead();

        return response()->json(['message' => __('messages.alert_reviewed')]);
    }

    private function alerts(Request $request, Group $group): MorphMany
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, __('messages.alerts_forbidden'));

        return $request->user()->notifications()->where('type', RepeatedLoginLockout::class)
            ->where('data->group_id', $group->id);
    }
}
