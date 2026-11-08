<?php

namespace App\Http\Middleware;

use App\Models\AccountLifecycleOperation;
use App\Services\LegalAcceptanceService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLegalAcceptance
{
    public function __construct(private LegalAcceptanceService $acceptances) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->acceptances->enabled() || $this->isException($request)) {
            return $next($request);
        }
        if ($this->acceptances->status($request->user())['required']) {
            return response()->json(['code' => 'legal_acceptance_required', 'message' => __('errors.forbidden')], 428,
                ['Cache-Control' => 'no-store, max-age=0']);
        }

        return $next($request);
    }

    private function isException(Request $request): bool
    {
        if ($request->isMethod('GET') && $request->is('v1/auth/me')) {
            return true;
        }
        if (! $request->isMethod('POST')) {
            return false;
        }
        if ($request->is('v1/auth/logout', 'v1/auth/legal-acceptances')) {
            return true;
        }
        if ($request->is('v1/groups/*/lifecycle-operations')) {
            return $request->input('kind') === 'group_withdraw';
        }
        if ($request->is('v1/groups/*/lifecycle-operations/*/commit', 'v1/groups/*/lifecycle-operations/*/cancel')) {
            return AccountLifecycleOperation::query()->whereKey($request->route('operation'))
                ->where('kind', 'group_withdraw')->where('actor_user_id', $request->user()->id)->exists();
        }

        return false;
    }
}
