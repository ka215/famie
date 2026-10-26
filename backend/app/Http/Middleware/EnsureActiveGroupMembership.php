<?php

namespace App\Http\Middleware;

use App\Models\Group;
use App\Models\GroupMember;
use App\Services\RewardService;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveGroupMembership
{
    public function __construct(private RewardService $rewards) {}

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

                $response = $this->handleForGroup($request, $next, $lockedGroup);
                if (! $response->isSuccessful()) {
                    throw new HttpResponseException($response);
                }
                if ($response->isSuccessful() && $request->is('v1/groups/*/logs*')) {
                    $this->rewards->evaluate($lockedGroup);
                }

                return $response;
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
