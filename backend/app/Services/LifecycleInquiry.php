<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;

class LifecycleInquiry
{
    public function __construct(private LifecycleAudit $audit, private AccountLifecycleService $lifecycle) {}

    /** @return array<string, mixed> */
    public function lookup(string $receipt, #[SensitiveParameter] string $code, string $operator): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! Str::isUuid($receipt)) {
            throw new RuntimeException('Invalid receipt format.');
        }
        $this->audit->record('inquiry', 'started', $operator, $receipt);
        try {
            $key = 'lifecycle-operator-inquiry:'.$receipt;
            if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts('lifecycle-operator-inquiry:all', 30)) {
                throw new RuntimeException('Inquiry unavailable.');
            }
            RateLimiter::hit($key, 900);
            RateLimiter::hit('lifecycle-operator-inquiry:all', 60);
            if (! preg_match('/\A[A-Za-z0-9_-]{43}\z/', $code)) {
                throw new RuntimeException('Inquiry unavailable.');
            }
            $operation = $this->lifecycle->status($receipt, $code);
            if (! $operation || $operation->state !== AccountLifecycleOperation::STATE_COMMITTED
                || ! $operation->retention_until || $operation->retention_until->lessThanOrEqualTo(now())) {
                throw new RuntimeException('Inquiry unavailable.');
            }
            $this->audit->record('inquiry', 'success', $operator, $receipt);

            return ['receipt_id' => $operation->id, 'kind' => $operation->kind, 'state' => $operation->state,
                'cleanup_state' => $operation->cleanup_state, 'committed_at' => $operation->committed_at?->utc()->toIso8601String(),
                'retention_until' => $operation->retention_until->utc()->toIso8601String(), 'held' => $operation->hasActiveHold()];
        } catch (\Throwable) {
            $this->audit->record('inquiry', 'failure', $operator, $receipt);
            throw new RuntimeException('Inquiry unavailable.');
        } finally {
            sodium_memzero($code);
        }
    }
}
