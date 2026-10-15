<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'status', 'start_month', 'cycle_months', 'timezone', 'revision', 'prepared_period'])]
class Reward extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['start_month' => 'immutable_date:Y-m-d', 'prepared_period' => 'immutable_date:Y-m-d', 'cycle_months' => 'integer', 'revision' => 'integer'];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(RewardVersion::class);
    }

    public function awards(): HasMany
    {
        return $this->hasMany(RewardAward::class);
    }
}
