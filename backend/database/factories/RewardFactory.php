<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

class RewardFactory extends Factory
{
    public function definition(): array
    {
        return ['group_id' => Group::factory(), 'start_month' => now()->startOfMonth()->toDateString(), 'cycle_months' => 1, 'timezone' => 'UTC', 'status' => 'draft'];
    }
}
