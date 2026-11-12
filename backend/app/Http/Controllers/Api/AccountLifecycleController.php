<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccountLifecycleOperation;
use App\Models\Group;
use App\Services\AccountLifecycleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AccountLifecycleController extends Controller
{
    public function store(Request $request, Group $group, AccountLifecycleService $service): JsonResponse
    {
        $this->rejectUnexpected($request, ['kind', 'membership_id']);
        $validated = $request->validate([
            'kind' => ['required', Rule::in([AccountLifecycleOperation::KIND_MEMBER_DELETE, AccountLifecycleOperation::KIND_GROUP_WITHDRAW])],
            'membership_id' => ['nullable', 'integer'],
        ]);
        if (($validated['kind'] === AccountLifecycleOperation::KIND_MEMBER_DELETE) !== isset($validated['membership_id'])) {
            throw ValidationException::withMessages(['membership_id' => [__('messages.lifecycle_membership_required')]]);
        }
        $prepared = $service->prepare($request->user(), $group, $validated['kind'], $validated['membership_id'] ?? null);

        return response()->json([
            'data' => [
                'receipt_id' => $prepared['operation']->id,
                'confirmation_code' => $prepared['code'],
                'kind' => $prepared['operation']->kind,
                'prepared_expires_at' => $prepared['operation']->prepared_expires_at,
                'commit_retry_after' => $this->commitRetryAfter($request),
                'impact' => $prepared['operation']->kind === AccountLifecycleOperation::KIND_GROUP_WITHDRAW
                    ? ['family' => true, 'members' => $prepared['operation']->subjects()->count(), 'posts' => 'delete', 'images' => 'delete']
                    : ['family' => false, 'members' => 1, 'posts' => 'delete', 'images' => 'delete'],
            ],
        ], 201, ['Cache-Control' => 'no-store']);
    }

    public function commit(Request $request, Group $group, string $operation, AccountLifecycleService $service): JsonResponse
    {
        $this->rejectUnexpected($request, ['current_password', 'confirmation_code', 'confirmed']);
        $validated = $request->validate([
            'current_password' => ['required', 'string', 'max:4096'],
            'confirmation_code' => ['required', 'string', 'size:43', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
            'confirmed' => ['required', 'boolean:strict', 'accepted'],
        ]);
        $committed = $service->commit($request->user(), $group, $operation, $validated['current_password'], $validated['confirmation_code']);

        return response()->json(['data' => $this->statusPayload($committed)], 202, ['Cache-Control' => 'no-store']);
    }

    public function status(Request $request, AccountLifecycleService $service): JsonResponse
    {
        $this->rejectUnexpected($request, ['receipt_id', 'confirmation_code']);
        $validated = $request->validate([
            'receipt_id' => ['required', 'uuid'],
            'confirmation_code' => ['required', 'string', 'size:43', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
        ]);
        $operation = $service->status($validated['receipt_id'], $validated['confirmation_code']);
        abort_unless($operation, 404);

        return response()->json(['data' => $this->statusPayload($operation)], 200, [
            'Cache-Control' => 'no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function cancel(Request $request, Group $group, string $operation, AccountLifecycleService $service): JsonResponse
    {
        $this->rejectUnexpected($request, []);
        $cancelled = $service->cancel($request->user(), $group, $operation);

        return response()->json(['data' => $this->statusPayload($cancelled)], 200, ['Cache-Control' => 'no-store']);
    }

    private function commitRetryAfter(Request $request): int
    {
        $retryAfter = 0;
        $limits = RateLimiter::limiter('lifecycle-commit')($request);
        foreach ($limits as $limit) {
            // Match Laravel ThrottleRequests' default key for a named limiter.
            $key = md5('lifecycle-commit'.$limit->key);
            if (RateLimiter::attempts($key) >= $limit->maxAttempts) {
                $retryAfter = max($retryAfter, RateLimiter::availableIn($key));
            }
        }

        return $retryAfter;
    }

    /** @return array<string, mixed> */
    private function statusPayload(AccountLifecycleOperation $operation): array
    {
        return [
            'receipt_id' => $operation->id,
            'kind' => $operation->kind,
            'state' => $operation->state,
            'cleanup_state' => $operation->cleanup_state,
            'committed_at' => $operation->committed_at,
            'retention_until' => $operation->retention_until,
        ];
    }

    /** @param array<int, string> $allowed */
    private function rejectUnexpected(Request $request, array $allowed): void
    {
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['lifecycle' => [__('messages.unexpected_fields')]]);
        }
    }
}
