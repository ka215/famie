<?php

namespace App\Services;

use App\Models\AccountLifecycleOperation;
use Illuminate\Support\Str;
use RuntimeException;
use SensitiveParameter;
use Throwable;

class LifecycleInquiryExport
{
    public function __construct(private LifecycleInquiry $inquiries, private LifecycleAudit $audit,
        private LifecycleLedgerInspector $ledger, private LifecycleOperatorFiles $files) {}

    /** @return array<string, string> */
    public function create(string $receipt, #[SensitiveParameter] string $code, string $operator, string $ledgerSha): array
    {
        if (! config('famie.lifecycle_operator_enabled') || ! Str::isUuid($receipt)) {
            throw new RuntimeException('Inquiry export unavailable.');
        }
        $this->audit->record('inquiry.export', 'started', $operator, $receipt);
        try {
            $metadata = $this->inquiries->lookup($receipt, $code, $operator);
            $operation = AccountLifecycleOperation::query()->findOrFail($receipt);
            $evidence = $this->ledger->inspect($ledgerSha, $receipt)['operation'];
            if (! $evidence || $evidence['state'] !== 'committed' || $evidence['group_id'] !== $operation->group_id
                || $evidence['kind'] !== $operation->kind || $evidence['committed_at'] !== $metadata['committed_at']) {
                throw new RuntimeException('Ledger does not confirm the inquiry.');
            }
            $bytes = $this->files->readVault($receipt);
            if (! hash_equals($evidence['vault_sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Inquiry vault does not match the ledger.');
            }
            $vault = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($vault) || ($vault['operation_id'] ?? null) !== $receipt || ($vault['environment'] ?? null) !== app()->environment()
                || ($vault['committed_at'] ?? null) !== $metadata['committed_at'] || ($vault['retention_until'] ?? null) !== $metadata['retention_until']
                || $operation->retention_until->lessThanOrEqualTo(now()) || ! is_string($vault['key_id'] ?? null)) {
                throw new RuntimeException('Inquiry vault metadata mismatch.');
            }
            $id = (string) Str::uuid();
            $package = ['format_version' => 1, 'package_id' => $id, 'receipt_id' => $receipt, 'source_url' => config('app.url'),
                'environment' => app()->environment(), 'key_id' => $vault['key_id'], 'group_id' => $operation->group_id,
                'kind' => $operation->kind, 'committed_at' => $metadata['committed_at'], 'retention_until' => $metadata['retention_until'],
                'confirmation_code_hash' => hash('sha256', $code), 'ledger_sha256' => $ledgerSha,
                'vault_sha256' => $evidence['vault_sha256'], 'vault' => base64_encode($bytes)];
            $this->files->createPackage($id, $package);
            $sha = hash_file('sha256', $this->files->packagePath($id));
            if ($sha === false) {
                throw new RuntimeException('Inquiry export checksum unavailable.');
            }
            $this->audit->record('inquiry.export', 'success', $operator, $receipt, $vault['key_id']);

            return ['package_id' => $id, 'sha256' => $sha, 'retention_until' => $metadata['retention_until'],
                'source_url' => $package['source_url'], 'environment' => $package['environment'], 'key_id' => $vault['key_id'], 'ledger_sha256' => $ledgerSha];
        } catch (Throwable) {
            $this->audit->record('inquiry.export', 'failure', $operator, $receipt);
            throw new RuntimeException('Inquiry export unavailable.');
        } finally {
            sodium_memzero($code);
        }
    }
}
