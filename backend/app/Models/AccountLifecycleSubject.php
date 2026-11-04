<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['operation_id', 'user_id', 'membership_id'])]
class AccountLifecycleSubject extends Model {}
