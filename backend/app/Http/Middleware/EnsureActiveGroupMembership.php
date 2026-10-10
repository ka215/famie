<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Models\GroupMember;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveGroupMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $groupId = $request->route('group');
        $group = $groupId instanceof Group ? $groupId : Group::query()->findOrFail($groupId);
        if (! $request->isMethodSafe()) {
            return DB::transaction(function () use ($request, $next, $group): Response {
                $lockedGroup = Group::query()->whereKey($group->id)->lockForUpdate()->firstOrFail();

                return $this->handleForGroup($request, $next, $lockedGroup);
            });
        }

        return $this->handleForGroup($request, $next, $group);
    }

    private function handleForGroup(Request $request, Closure $next, Group $group): Response
    {
        $membership = GroupMember::query()
            ->where('group_id', $group->id)
            ->where('user_id', $request->user()->id)
            ->where('status', GroupMember::STATUS_ACTIVE)
            ->firstOrFail();

        $request->route()->setParameter('group', $group);
        $request->attributes->set('group_membership', $membership);

        return $next($request);
    }
}
