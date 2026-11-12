<?php

namespace App\Console\Commands;

use App\Services\LifecycleAudit;
use App\Services\LifecycleBackupAssessment;
use App\Services\LifecycleBackupCoverage;
use App\Services\LifecycleCopyExpiry;
use App\Services\LifecycleHold;
use App\Services\LifecycleIdentityRecorder;
use App\Services\LifecycleInquiry;
use App\Services\LifecycleInquiryDecrypt;
use App\Services\LifecycleInquiryExport;
use App\Services\LifecycleLedgerInspector;
use App\Services\LifecycleOperatorFiles;
use App\Services\LifecycleOperatorKey;
use App\Services\LifecycleOrphanImages;
use App\Services\LifecycleRestoreCheck;
use App\Services\LifecycleRestoreImages;
use App\Services\LifecycleRestoreReapply;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

#[Signature('famie:lifecycle-operator {action : key-create, key-verify, inquiry, inquiry-export, decrypt, ledger-verify, audit-prune, hold-start, hold-release, copy-expire, restore-check, restore-images, restore-orphan-images, restore-reapply, restore-holds, identity-record, backup-assess, backup-coverage-check, or ledger-sync-check} {--operator=} {--key-id=} {--receipt=} {--reference=} {--review-at=} {--ledger-sha256=} {--package=} {--package-sha256=} {--copy-location=} {--inventory=} {--inventory-sha256=} {--sync=} {--sync-sha256=} {--coverage=} {--coverage-sha256=} {--catalogue-sha256=} {--restore=} {--restore-sha256=} {--source-url=} {--source-environment=} {--reveal} {--apply}')]
#[Description('Operate lifecycle inquiry metadata, encrypted local keys and private 90-day audit records')]
class LifecycleOperator extends Command
{
    public function handle(LifecycleOperatorKey $keys, LifecycleOperatorFiles $files, LifecycleAudit $audit, LifecycleInquiry $inquiry, LifecycleInquiryExport $export, LifecycleInquiryDecrypt $decrypt, LifecycleLedgerInspector $ledger, LifecycleHold $holds, LifecycleCopyExpiry $copies, LifecycleRestoreCheck $restore, LifecycleBackupAssessment $backups, LifecycleRestoreReapply $reapply, LifecycleIdentityRecorder $identityRecorder, LifecycleRestoreImages $images, LifecycleOrphanImages $orphans, LifecycleBackupCoverage $coverage): int
    {
        if (! config('famie.lifecycle_operator_enabled')) {
            $this->error('Lifecycle operator tools are disabled.');

            return self::FAILURE;
        }
        $operator = (string) $this->option('operator');
        $action = $this->argument('action');
        try {
            if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $operator)) {
                throw new RuntimeException('An opaque operator ID is required.');
            }
            if (in_array($action, ['key-create', 'key-verify'], true)) {
                if (! app()->environment(['local', 'testing'])) {
                    throw new RuntimeException('Private keys belong on an operator workstation.');
                }
                $keyId = (string) $this->option('key-id');
                $files->path($keyId);
                if ($action === 'key-create' && ! $this->option('apply')) {
                    $this->info('Plan: create an encrypted key and public configuration. No files changed.');

                    return self::SUCCESS;
                }
                $event = $action === 'key-create' ? 'key.create' : 'key.verify';
                $audit->record($event, 'started', $operator, null, $keyId);
                $passphrase = (string) $this->secret('Key passphrase', false);
                try {
                    if ($action === 'key-create') {
                        $confirmation = (string) $this->secret('Confirm key passphrase', false);
                        try {
                            if (! hash_equals($passphrase, $confirmation)) {
                                throw new RuntimeException('Passphrases do not match.');
                            }
                        } finally {
                            sodium_memzero($confirmation);
                        }
                        $private = $files->path($keyId);
                        $public = $files->path($keyId, true);
                        if (file_exists($private) || is_link($private) || file_exists($public) || is_link($public)) {
                            throw new RuntimeException('Existing key files are never overwritten.');
                        }
                        $envelope = $keys->create($keyId, $passphrase);
                        $files->create($keyId, $envelope);
                        $files->create($keyId, ['key_id' => $keyId, 'public_key' => $envelope['public_key']], true);
                    } else {
                        $keys->verify($files->read($keyId), $keyId, $passphrase);
                    }
                    $audit->record($event, 'success', $operator, null, $keyId);
                } catch (Throwable $exception) {
                    $audit->record($event, 'failure', $operator, null, $keyId);
                    throw $exception;
                } finally {
                    sodium_memzero($passphrase);
                }
                $this->info('Encrypted key operation verified. No plaintext private key was printed.');
            } elseif ($action === 'inquiry') {
                $code = (string) $this->secret('Inquiry confirmation code', false);
                try {
                    $metadata = $inquiry->lookup((string) $this->option('receipt'), $code, $operator);
                } finally {
                    sodium_memzero($code);
                }
                $this->line(json_encode($metadata, JSON_THROW_ON_ERROR));
            } elseif ($action === 'inquiry-export') {
                if (! $this->option('apply')) {
                    $this->info('Plan: export an encrypted inquiry package. No export created.');

                    return self::SUCCESS;
                }
                $code = (string) $this->secret('Inquiry confirmation code', false);
                try {
                    $result = $export->create((string) $this->option('receipt'), $code, $operator, strtolower((string) $this->option('ledger-sha256')));
                } finally {
                    sodium_memzero($code);
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } elseif ($action === 'decrypt') {
                if (! app()->environment(['local', 'testing'])) {
                    throw new RuntimeException('Decryption belongs on an operator workstation.');
                }
                $reveal = (bool) $this->option('reveal');
                if ($reveal && (! stream_isatty(STDOUT) || ! $this->confirm('Display private inquiry data in this terminal only?', false))) {
                    throw new RuntimeException('Private output requires an interactive terminal and explicit confirmation.');
                }
                $code = (string) $this->secret('Inquiry confirmation code', false);
                $passphrase = '';
                try {
                    $passphrase = (string) $this->secret('Key passphrase', false);
                    $decrypt->decrypt(['receipt_id' => (string) $this->option('receipt'), 'package_id' => (string) $this->option('package'),
                        'key_id' => (string) $this->option('key-id'), 'source_url' => (string) $this->option('source-url'),
                        'environment' => (string) $this->option('source-environment'), 'package_sha256' => strtolower((string) $this->option('package-sha256')),
                        'ledger_sha256' => strtolower((string) $this->option('ledger-sha256'))], $code, $passphrase, $operator,
                        function (#[\SensitiveParameter] array $payload) use ($reveal): void {
                            if ($reveal) {
                                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                            }
                        });
                } finally {
                    sodium_memzero($code);
                    sodium_memzero($passphrase);
                }
                $this->info($reveal ? 'Decryption completed in this terminal.' : 'Decryption verified. No personal data displayed.');
            } elseif ($action === 'backup-coverage-check') {
                if ($this->option('apply')) {
                    throw new RuntimeException('Backup coverage assessment has no apply mode.');
                }
                $context = ['control_id' => (string) $this->option('coverage')];
                $audit->record('backup.coverage', 'started', $operator, context: $context);
                try {
                    $result = $coverage->check((string) $this->option('coverage'), (string) $this->option('coverage-sha256'),
                        (string) $this->option('catalogue-sha256'), (string) $this->option('ledger-sha256'),
                        (string) $this->option('source-url'), (string) $this->option('source-environment'));
                    $audit->record('backup.coverage', $result['blockers'] === [] ? 'success' : 'failure', $operator, context: $context);
                } catch (Throwable $exception) {
                    $audit->record('backup.coverage', 'failure', $operator, context: $context);
                    throw $exception;
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return $result['blockers'] === [] ? self::SUCCESS : self::FAILURE;
            } elseif (in_array($action, ['backup-assess', 'ledger-sync-check'], true)) {
                if ($this->option('apply')) {
                    throw new RuntimeException('Backup assessments have no apply mode.');
                }
                $inventory = $action === 'backup-assess';
                $event = $inventory ? 'backup.assess' : 'ledger.sync-check';
                $context = ['control_id' => (string) $this->option($inventory ? 'inventory' : 'sync')];
                $audit->record($event, 'started', $operator, context: $context);
                try {
                    $result = $inventory
                        ? $backups->inventory((string) $this->option('inventory'), (string) $this->option('inventory-sha256'),
                            (string) $this->option('ledger-sha256'), (string) $this->option('source-url'), (string) $this->option('source-environment'))
                        : $backups->synchronization((string) $this->option('sync'), (string) $this->option('sync-sha256'),
                            (string) $this->option('ledger-sha256'), (string) $this->option('source-url'), (string) $this->option('source-environment'));
                    $audit->record($event, $result['blockers'] === [] ? 'success' : 'failure', $operator, context: $context);
                } catch (Throwable $exception) {
                    $audit->record($event, 'failure', $operator, context: $context);
                    throw $exception;
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                if ($result['blockers'] !== []) {
                    return self::FAILURE;
                }
            } elseif ($action === 'copy-expire') {
                $location = $this->option('copy-location');
                if (! in_array($location, ['operator', 'export'], true)) {
                    throw new RuntimeException('Explicit copy location required.');
                }
                $result = $copies->expire(['package_id' => (string) $this->option('package'), 'package_sha256' => (string) $this->option('package-sha256'),
                    'ledger_sha256' => (string) $this->option('ledger-sha256'), 'source_url' => (string) $this->option('source-url'),
                    'environment' => (string) $this->option('source-environment')], $operator, $location === 'operator', (bool) $this->option('apply'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } elseif ($action === 'identity-record') {
                $result = $identityRecorder->record((string) $this->option('receipt'), (string) $this->option('ledger-sha256'), $operator, (bool) $this->option('apply'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } elseif (in_array($action, ['restore-reapply', 'restore-holds'], true)) {
                $result = $reapply->reapply((string) $this->option('restore'), (string) $this->option('receipt'),
                    (string) $this->option('restore-sha256'), (string) $this->option('ledger-sha256'),
                    (string) $this->option('source-url'), (string) $this->option('source-environment'), $operator, (bool) $this->option('apply'), $action === 'restore-holds');
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                if ($action === 'restore-reapply' && $this->option('apply') && $result['cleanup_state'] !== 'complete') {
                    return self::FAILURE;
                }
            } elseif ($action === 'restore-orphan-images') {
                $result = $orphans->erase((string) $this->option('restore'), (string) $this->option('receipt'),
                    (string) $this->option('restore-sha256'), (string) $this->option('ledger-sha256'),
                    (string) $this->option('source-url'), (string) $this->option('source-environment'), $operator, (bool) $this->option('apply'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return $result['held'] && $this->option('apply') ? self::FAILURE : self::SUCCESS;
            } elseif ($action === 'restore-images') {
                if ($this->option('apply')) {
                    throw new RuntimeException('Image assessment has no apply mode.');
                }
                $result = $images->check((string) $this->option('ledger-sha256'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));

                return $result['findings'] === [] ? self::SUCCESS : self::FAILURE;
            } elseif ($action === 'restore-check') {
                if ($this->option('apply')) {
                    throw new RuntimeException('Restore assessment has no apply mode.');
                }
                $audit->record('restore.check', 'started', $operator);
                try {
                    $result = $restore->check((string) $this->option('ledger-sha256'));
                    $audit->record('restore.check', $result['findings'] === [] ? 'success' : 'failure', $operator);
                } catch (Throwable $exception) {
                    $audit->record('restore.check', 'failure', $operator);
                    throw $exception;
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                if ($result['findings'] !== []) {
                    return self::FAILURE;
                }
            } elseif (in_array($action, ['hold-start', 'hold-release'], true)) {
                $result = $holds->change((string) $this->option('receipt'), (string) $this->option('reference'),
                    (string) $this->option('review-at'), $operator, (string) $this->option('ledger-sha256'),
                    $action === 'hold-release', (bool) $this->option('apply'));
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
            } elseif ($action === 'ledger-verify') {
                $audit->record('ledger.verify', 'started', $operator);
                try {
                    $result = $ledger->inspect((string) $this->option('ledger-sha256'));
                    $audit->record('ledger.verify', $result['unresolved'] === 0 ? 'success' : 'failure', $operator);
                } catch (Throwable $exception) {
                    $audit->record('ledger.verify', 'failure', $operator);
                    throw $exception;
                }
                $this->line(json_encode($result, JSON_THROW_ON_ERROR));
                if ($result['unresolved'] > 0) {
                    $this->error('Unresolved intents require manual reconciliation. No database changes applied.');

                    return self::FAILURE;
                }
            } elseif ($action === 'audit-prune') {
                $audit->record('audit.prune', 'started', $operator);
                try {
                    $result = $audit->prune((bool) $this->option('apply'));
                    $audit->record('audit.prune', 'success', $operator);
                } catch (Throwable $exception) {
                    $audit->record('audit.prune', 'failure', $operator);
                    throw $exception;
                }
                $this->info(($this->option('apply') ? 'Expired' : 'Would expire').': '.$result['expired'].' audit records.');
            } else {
                throw new RuntimeException('Unknown operator action.');
            }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Lifecycle operator action failed. Check the private configuration and audit storage.');

            return self::FAILURE;
        }
    }
}
