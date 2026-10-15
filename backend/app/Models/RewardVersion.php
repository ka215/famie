<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'reward_id', 'effective_from', 'name', 'description', 'metric', 'color', 'count_mode', 'required_count', 'category_id', 'category_name'])]
class RewardVersion extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['effective_from' => 'immutable_date:Y-m-d', 'required_count' => 'integer'];
    }

    public function targets(): HasMany
    {
        return $this->hasMany(RewardTarget::class);
    }
}
