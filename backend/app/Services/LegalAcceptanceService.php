<?php

namespace App\Services;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\LegalAcceptance;
use App\Models\User;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use RuntimeException;

class LegalAcceptanceService
{
    public function __construct(private LegalDocumentCatalog $catalog) {}

    public function enabled(): bool
    {
        return (bool) config('legal.storage_enabled') && (bool) config('legal.acceptance_enabled');
    }

    /** @return array{enabled: bool, required: bool} */
    public function status(User $user, ?GroupMember $membership = null): array
    {
        if (! $this->enabled()) {
            return ['enabled' => false, 'required' => false];
        }
        $membership ??= GroupMember::query()->with('group')->where('user_id', $user->id)
            ->where('status', GroupMember::STATUS_ACTIVE)->firstOrFail();
        if (! $membership->group->isRepresentative($user->id)) {
            return ['enabled' => true, 'required' => false];
        }
        try {
            $documents = $this->catalog->documents((string) config('legal.manifest_path'));
        } catch (JsonException|RuntimeException) {
            abort(503, __('errors.server'));
        }
        $required = false;
        foreach ($documents as $document) {
            if ($document['id'] === 'cookies') {
                continue;
            }
            $versions = config('legal.accepted_versions.'.$document['id']);
            abort_unless(is_array($versions) && in_array($document['version'], $versions, true), 503, __('errors.server'));
            $exists = LegalAcceptance::query()->where('user_id', $user->id)->where('document_id', $document['id'])
                ->whereIn('document_version', $versions)->where('action', $document['id'] === 'terms' ? 'accept' : 'acknowledge')->exists();
            $required = $required || ! $exists;
        }

        return ['enabled' => true, 'required' => $required];
    }

    /** @return list<array<string, string>> */
    public function validate(mixed $payload): array
    {
        $affirmative = function (string $attribute, mixed $value, Closure $fail): void {
            if ($value !== true) {
                $fail(__('validation.accepted', ['attribute' => $attribute]));
            }
        };
        $validated = Validator::make(['legal' => $payload], [
            'legal' => ['required', 'array:terms,privacy'],
            'legal.terms' => ['required', 'array:version,locale,accepted'],
            'legal.privacy' => ['required', 'array:version,locale,acknowledged'],
            'legal.terms.version' => ['required', 'string', 'max:64'],
            'legal.privacy.version' => ['required', 'string', 'max:64'],
            'legal.terms.locale' => ['required', 'in:ja,en'],
            'legal.privacy.locale' => ['required', 'in:ja,en'],
            'legal.terms.accepted' => ['required', $affirmative],
            'legal.privacy.acknowledged' => ['required', $affirmative],
        ])->validate()['legal'];
        try {
            $documents = $this->catalog->documents((string) config('legal.manifest_path'));
        } catch (JsonException|RuntimeException) {
            throw new HttpResponseException(response()->json(['code' => 'legal_documents_unavailable', 'message' => __('errors.server')], 503, ['Cache-Control' => 'no-store, max-age=0']));
        }
        $records = [];
        foreach ($documents as $document) {
            $id = $document['id'];
            if ($id === 'cookies') {
                continue;
            }
            if ($validated[$id]['version'] !== $document['version']) {
                throw new HttpResponseException(response()->json(['code' => 'legal_version_changed', 'message' => __('errors.validation'), 'data' => $documents], 409, ['Cache-Control' => 'no-store, max-age=0']));
            }
            $locale = $validated[$id]['locale'];
            $records[] = ['document_id' => $id, 'document_version' => $document['version'],
                'document_sha256' => $document['translations'][$locale]['sha256'],
                'action' => $id === 'terms' ? 'accept' : 'acknowledge', 'locale' => $locale];
        }

        return $records;
    }

    public function accept(User $actor, mixed $payload): void
    {
        abort_unless($this->enabled(), 503, __('errors.server'));
        $this->validate($payload);
        DB::transaction(function () use ($actor, $payload): void {
            $membership = GroupMember::query()->where('user_id', $actor->id)->where('status', GroupMember::STATUS_ACTIVE)->firstOrFail();
            $group = Group::query()->lockForUpdate()->findOrFail($membership->group_id);
            abort_unless((int) $group->representative_user_id === (int) $actor->id && $group->withdrawn_at === null, 403, __('errors.forbidden'));
            $user = User::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless(GroupMember::query()->whereKey($membership->id)->where('user_id', $user->id)->where('group_id', $group->id)->where('status', GroupMember::STATUS_ACTIVE)->exists(), 403, __('errors.forbidden'));
            $this->record($user, $this->validate($payload));
        });
    }

    /** @param list<array<string, string>> $records */
    public function record(User $user, array $records): void
    {
        abort_unless($this->enabled(), 503, __('errors.server'));
        foreach ($records as $record) {
            LegalAcceptance::query()->firstOrCreate([
                'user_id' => $user->id, 'document_id' => $record['document_id'],
                'document_version' => $record['document_version'], 'action' => $record['action'],
            ], ['document_sha256' => $record['document_sha256'], 'locale' => $record['locale'], 'recorded_at' => now()->utc()]);
        }
    }

    public function forgetUser(int $userId): void
    {
        if ((bool) config('legal.storage_enabled')) {
            LegalAcceptance::query()->where('user_id', $userId)->delete();
        }
    }
}
