<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_id', 'reward_id', 'reward_period_id', 'group_member_id', 'achieved_count', 'snapshot', 'awarded_at', 'read_at'])]
class RewardAward extends Model
{
    use HasFactory;

    public $timestamps = false;

    public function period(): BelongsTo
    {
        return $this->belongsTo(RewardPeriod::class, 'reward_period_id');
    }

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'achieved_count' => 'integer', 'awarded_at' => 'immutable_datetime', 'read_at' => 'immutable_datetime'];
    }
}
