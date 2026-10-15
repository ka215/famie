<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Reward;
use App\Models\RewardAward;
use App\Models\RewardTarget;
use App\Services\RewardService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

use function Illuminate\Support\defer;

class RewardController extends Controller
{
    public function __construct(private RewardService $rewards) {}

    private function admin(Request $request): void
    {
        abort_unless($request->attributes->get('group_membership')->isAdmin(), 403, 'リワードの管理権限がありません。');
    }

    private function editable(Group $group): void
    {
        abort_if($group->reward_sync_status === 'processing', 409, '集計中は編集できません。');
    }

    private function target(Group $group, int $reward): Reward
    {
        return Reward::query()->where('group_id', $group->id)->where('status', '!=', 'deleted')->findOrFail($reward);
    }

    private function member(Request $request, Group $group): GroupMember
    {
        $request->validate(['member_id' => ['sometimes', 'integer']]);
        $id = $request->integer('member_id', $request->attributes->get('group_membership')->id);
        $member = $group->memberships()->where('status', 'active')->findOrFail($id);
        abort_unless($request->attributes->get('group_membership')->isAdmin() || $member->user_id === $request->user()->id, 403);

        return $member;
    }

    private function revision(Request $request, Reward $reward): void
    {
        $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        abort_if($request->integer('revision') !== $reward->revision, 409, '他の変更が先に保存されました。再読み込みしてください。');
    }

    /** @return array<string,mixed> */
    private function validated(Request $request, Group $group, ?Reward $reward = null): array
    {
        if (array_diff(array_keys($request->all()), ['name', 'description', 'metric', 'color', 'count_mode', 'required_count', 'category_id', 'target_ids', 'start_month', 'cycle_months', 'timezone', 'revision']) !== []) {
            throw ValidationException::withMessages(['reward' => ['指定できない項目が含まれています。']]);
        }
        foreach (['name', 'description'] as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => trim($request->input($field))]);
            }
        }
        $data = $request->validate([
            'name' => ['required', 'string', 'max:50'], 'description' => ['required', 'string', 'max:500'],
            'metric' => ['required', Rule::in(['activities', 'likes'])], 'color' => ['required', Rule::in(['gold', 'silver', 'bronze'])],
            'count_mode' => ['sometimes', 'required', Rule::in(['fixed', 'period_days'])],
            'required_count' => [Rule::requiredIf($request->input('count_mode', 'fixed') === 'fixed'), Rule::prohibitedIf($request->input('count_mode') === 'period_days'), 'nullable', 'integer', 'between:1,10000'],
            'category_id' => ['nullable', 'integer', Rule::exists('categories', 'id')->where('group_id', $group->id)],
            'target_ids' => ['required', 'array', 'min:1', 'max:'.config('famie.max_group_members')],
            'target_ids.*' => ['required', 'integer', 'distinct', Rule::exists('group_members', 'id')->where(fn ($q) => $q->where('group_id', $group->id)->where('status', 'active'))],
            'start_month' => ['required', 'date_format:Y-m'], 'cycle_months' => ['required', 'integer', 'between:1,12'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
        ]);
        $data['count_mode'] = $data['count_mode'] ?? 'fixed';
        $data['required_count'] = $data['count_mode'] === 'period_days' ? null : $data['required_count'];
        $timezone = $reward?->timezone ?? ($data['timezone'] ?? 'UTC');
        $start = CarbonImmutable::createFromFormat('!Y-m', $data['start_month'], $timezone)->startOfMonth();
        $begun = $reward && $reward->status !== 'draft' && CarbonImmutable::now($reward->timezone)->greaterThanOrEqualTo(CarbonImmutable::parse($reward->start_month->toDateString(), $reward->timezone));
        if ($begun) {
            if ($start->toDateString() !== $reward->start_month->toDateString() || (int) $data['cycle_months'] !== $reward->cycle_months || (isset($data['timezone']) && $data['timezone'] !== $reward->timezone)) {
                throw ValidationException::withMessages(['start_month' => ['開始後の期間設定は変更できません。']]);
            }
        } elseif ($start->lessThan(CarbonImmutable::now($timezone)->startOfMonth())) {
            throw ValidationException::withMessages(['start_month' => ['開始月は当月以降を指定してください。']]);
        }
        if ($reward && isset($data['timezone']) && $data['timezone'] !== $reward->timezone) {
            throw ValidationException::withMessages(['timezone' => ['保存済みタイムゾーンは変更できません。']]);
        }
        $data['start_month'] = $start->toDateString();
        $data['timezone'] = $timezone;
        $data['category_id'] = $data['category_id'] ?? null;
        $data['category_name'] = $data['category_id'] ? Category::query()->findOrFail($data['category_id'])->name : null;

        return $data;
    }

    private function saveVersion(Reward $reward, array $data, string $effective): void
    {
        $fields = array_intersect_key($data, array_flip(['name', 'description', 'metric', 'color', 'count_mode', 'required_count', 'category_id', 'category_name']));
        $version = $reward->versions()->updateOrCreate(['effective_from' => $effective], ['group_id' => $reward->group_id, ...$fields]);
        $version->targets()->delete();
        foreach ($data['target_ids'] as $id) {
            RewardTarget::query()->create(['group_id' => $reward->group_id, 'reward_version_id' => $version->id, 'group_member_id' => $id]);
        }
    }

    private function checkActiveLimit(Group $group): void
    {
        $count = Reward::query()->where('group_id', $group->id)->where('status', 'active')->count();
        if ($count >= $group->reward_active_limit) {
            abort(response()->json(['message' => '有効リワードの上限に達しています。', 'limit' => $group->reward_active_limit, 'count' => $count], 409));
        }
    }

    public function manage(Request $request, Group $group): JsonResponse
    {
        $this->admin($request);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = Reward::query()->where('group_id', $group->id)->where('status', '!=', 'deleted')->orderBy('id')->paginate(10);
        $page->through(fn (Reward $reward) => $this->rewards->present($group, $reward));

        return response()->json([...$page->toArray(), 'limits' => ['active' => $group->reward_active_limit, 'management' => $group->reward_management_limit],
            'counts' => ['active' => Reward::query()->where('group_id', $group->id)->where('status', 'active')->count(), 'management' => $page->total()],
            'sync_status' => $group->reward_sync_status]);
    }

    public function index(Request $request, Group $group): JsonResponse
    {
        $member = $this->member($request, $group);
        $request->validate(['status' => ['sometimes', Rule::in(['active', 'stopped'])]]);
        $visible = [];
        $query = Reward::query()->where('group_id', $group->id)->whereIn('status', ['active', 'stopped']);
        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }
        foreach ($query->get() as $reward) {
            $version = $this->rewards->currentVersion($reward);
            if ($version->targets->contains('group_member_id', $member->id)) {
                $visible[] = $this->rewards->present($group, $reward, $member);
            }
        }
        usort($visible, fn ($a, $b) => [$a['ends_on'], $a['id']] <=> [$b['ends_on'], $b['id']]);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = $request->integer('page', 1);

        return response()->json(['data' => array_slice($visible, ($page - 1) * 10, 10), 'current_page' => $page, 'last_page' => max(1, (int) ceil(count($visible) / 10)), 'total' => count($visible), 'sync_status' => $group->reward_sync_status]);
    }

    public function store(Request $request, Group $group): JsonResponse
    {
        $this->admin($request);
        $this->editable($group);
        $count = Reward::query()->where('group_id', $group->id)->where('status', '!=', 'deleted')->count();
        if ($count >= $group->reward_management_limit) {
            abort(response()->json(['message' => '管理リワードの上限に達しています。', 'limit' => $group->reward_management_limit, 'count' => $count], 409));
        }
        $data = $this->validated($request, $group);
        $reward = Reward::query()->create(['group_id' => $group->id, ...array_intersect_key($data, array_flip(['start_month', 'cycle_months', 'timezone']))]);
        $this->saveVersion($reward, $data, $data['start_month']);

        return response()->json(['data' => $this->rewards->present($group, $reward->fresh())], 201);
    }

    public function update(Request $request, Group $group, int $reward): JsonResponse
    {
        $this->admin($request);
        $this->editable($group);
        $target = $this->target($group, $reward);
        $this->revision($request, $target);
        $data = $this->validated($request, $group, $target);
        $begun = $target->status !== 'draft' && CarbonImmutable::now($target->timezone)->greaterThanOrEqualTo(CarbonImmutable::parse($target->start_month->toDateString(), $target->timezone));
        $effective = $begun ? $this->rewards->boundaries($target)['end']->toDateString() : $data['start_month'];
        if (! $begun) {
            $target->versions()->get()->each(function ($version): void {
                $version->targets()->delete();
                $version->delete();
            });
            $target->update(array_intersect_key($data, array_flip(['start_month', 'cycle_months'])));
        }
        $this->saveVersion($target, $data, $effective);
        $target->increment('revision');

        return response()->json(['data' => $this->rewards->present($group, $target->fresh())]);
    }

    public function status(Request $request, Group $group, int $reward): JsonResponse
    {
        $this->admin($request);
        $this->editable($group);
        $target = $this->target($group, $reward);
        $this->revision($request, $target);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'stopped'])]]);
        abort_if($target->status === $data['status'], 409, '既に指定の状態です。');
        abort_if($target->status === 'draft' && $data['status'] === 'stopped', 409, '下書きは停止できません。不要な下書きは削除してください。');
        if ($data['status'] === 'active') {
            $this->checkActiveLimit($group);
            $version = $this->rewards->currentVersion($target);
            $ids = $version->targets->pluck('group_member_id');
            abort_if($group->memberships()->whereIn('id', $ids)->where('status', 'active')->count() !== $ids->count(), 422, '無効化済み対象者がいます。対象者を確認してください。');
            if ($target->status === 'draft' && $target->start_month->format('Y-m') < CarbonImmutable::now($target->timezone)->format('Y-m')) {
                abort(422, '開始月を当月以降に変更してください。');
            }
        }
        $target->update(['status' => $data['status'], 'revision' => $target->revision + 1]);
        if ($data['status'] === 'active') {
            $this->rewards->evaluate($group);
        }

        return response()->json(['data' => $this->rewards->present($group, $target->fresh())]);
    }

    public function destroy(Request $request, Group $group, int $reward): JsonResponse
    {
        $this->admin($request);
        $this->editable($group);
        $target = $this->target($group, $reward);
        $this->revision($request, $target);
        if ($target->status === 'draft') {
            abort_if($target->prepared_period !== null || $target->awards()->exists()
                || DB::table('reward_periods')->where('reward_id', $target->id)->exists()
                || DB::table('reward_progress')->where('reward_id', $target->id)->exists(), 409, '下書きに集計履歴があるため削除できません。');
            foreach ($target->versions()->get() as $version) {
                $version->targets()->delete();
                $version->delete();
            }
            $target->delete();

            return response()->json(['message' => '下書きを完全に削除しました。']);
        }
        abort_unless($target->status === 'stopped', 409, '下書きまたは停止中のリワードだけ削除できます。');
        $target->update(['status' => 'deleted', 'revision' => $target->revision + 1]);

        return response()->json(['message' => 'リワードを削除しました。獲得履歴は残ります。']);
    }

    public function awards(Request $request, Group $group): JsonResponse
    {
        $member = $this->member($request, $group);
        $request->validate(['page' => ['sometimes', 'integer', 'min:1']]);
        $page = RewardAward::query()->where('group_id', $group->id)->where('group_member_id', $member->id)->orderByDesc('awarded_at')->orderByDesc('id')->paginate(10);
        $deleted = Reward::query()->where('group_id', $group->id)->whereIn('id', $page->pluck('reward_id'))->where('status', 'deleted')->pluck('id')->all();
        $page->through(fn (RewardAward $award) => [...$award->toArray(), 'deleted' => in_array($award->reward_id, $deleted, true)]);

        return response()->json($page);
    }

    public function notifications(Request $request, Group $group): JsonResponse
    {
        abort_if($request->all() !== [], 422, '通知の対象は指定できません。');
        $member = $request->attributes->get('group_membership');
        $awards = RewardAward::query()->where('group_id', $group->id)->where('group_member_id', $member->id)->whereNull('read_at')->orderBy('id')->lockForUpdate()->get();
        RewardAward::query()->whereIn('id', $awards->pluck('id'))->update(['read_at' => now()]);

        return response()->json(['data' => $awards]);
    }

    public function prepare(Request $request, Group $group): JsonResponse
    {
        $fresh = $group->fresh();
        $busy = $fresh->reward_sync_status === 'processing' && $fresh->reward_sync_started_at && CarbonImmutable::parse($fresh->reward_sync_started_at)->greaterThan(now()->subMinutes(2));
        if ($busy) {
            return response()->json(['status' => 'processing']);
        }
        if (! $this->rewards->needsPreparation($fresh)) {
            $fresh->forceFill(['reward_sync_status' => 'complete', 'reward_sync_started_at' => null])->save();

            return response()->json(['status' => 'complete']);
        }
        $startedAt = now()->format('Y-m-d H:i:s');
        $fresh->forceFill(['reward_sync_status' => 'processing', 'reward_sync_started_at' => $startedAt])->save();
        defer(fn () => $this->rewards->prepare($group->id, $startedAt));

        return response()->json(['status' => 'processing']);
    }
}
