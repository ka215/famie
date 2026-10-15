<?php

namespace Database\Factories;

use App\Models\GroupMember;
use App\Models\RewardPeriod;
use App\Models\RewardVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardAwardFactory extends Factory
{
    public function definition(): array
    {
        return ['reward_period_id' => function () {
            $v = RewardVersion::factory()->create();

            return RewardPeriod::query()->create(['group_id' => $v->group_id, 'reward_id' => $v->reward_id, 'reward_version_id' => $v->id, 'starts_on' => $v->effective_from, 'ends_on' => $v->effective_from->addMonth()->subDay(), 'timezone' => 'UTC'])->id;
        },
            'group_id' => fn (array $a) => RewardPeriod::query()->findOrFail($a['reward_period_id'])->group_id,
            'reward_id' => fn (array $a) => RewardPeriod::query()->findOrFail($a['reward_period_id'])->reward_id,
            'group_member_id' => fn (array $a) => GroupMember::factory()->create(['group_id' => $a['group_id']])->id,
            'achieved_count' => 10, 'snapshot' => ['name' => '勉強目標', 'description' => '好きなおやつ', 'color' => 'bronze'], 'awarded_at' => now()];
    }
}
