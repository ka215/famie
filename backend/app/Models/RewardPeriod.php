<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group_id', 'reward_id', 'reward_version_id', 'starts_on', 'ends_on', 'timezone'])]
class RewardPeriod extends Model
{
    protected function casts(): array
    {
        return ['starts_on' => 'immutable_date:Y-m-d', 'ends_on' => 'immutable_date:Y-m-d'];
    }
}
