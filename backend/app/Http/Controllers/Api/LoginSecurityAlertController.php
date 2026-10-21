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
        $alerts = $this->alerts($request, $group)->whereNull('read_at')->latest()->limit(20)->get();

        return response()->json(['data' => $alerts->map(fn ($alert): array => [
            'id' => $alert->id, ...$alert->data,
        ])]);
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
