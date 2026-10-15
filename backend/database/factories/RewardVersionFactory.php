<?php

namespace Database\Factories;

use App\Models\Reward;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardVersionFactory extends Factory
{
    public function definition(): array
    {
        return ['reward_id' => Reward::factory(), 'group_id' => fn (array $a) => Reward::query()->findOrFail($a['reward_id'])->group_id, 'effective_from' => now()->startOfMonth()->toDateString(), 'name' => '勉強目標', 'description' => '好きなおやつ', 'metric' => 'activities', 'color' => 'bronze', 'required_count' => 10];
    }
}
