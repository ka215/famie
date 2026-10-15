<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['group_id', 'reward_version_id', 'group_member_id'])]
class RewardTarget extends Model
{
    public $timestamps = false;
}
