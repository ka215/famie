<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Group;
use App\Models\GroupMember;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    public function show(Group $group): JsonResponse
    {
        return response()->json(['data' => $this->payload($group)]);
    }

    public function update(Request $request, Group $group): JsonResponse
    {
        $membership = $request->attributes->get('group_membership');
        abort_unless($membership instanceof GroupMember && $membership->isAdmin(), 403, '家族設定の変更権限がありません。');
        if (array_diff(array_keys($request->all()), ['name'])) {
            throw ValidationException::withMessages(['group' => ['家族名以外は変更できません。']]);
        }
        $group->update($request->validate(['name' => ['required', 'string', 'max:50']]));

        return response()->json(['message' => '家族名を変更しました。', 'data' => $this->payload($group)]);
    }

    /** @return array<string, mixed> */
    private function payload(Group $group): array
    {
        return [
            ...$group->only(['id', 'name', 'type']),
            'limits' => ['members' => config('famie.max_group_members'), 'categories' => config('famie.max_group_categories')],
        ];
    }
}
