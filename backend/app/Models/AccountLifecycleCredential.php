<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['operation_id', 'code_hash', 'expires_at'])]
class AccountLifecycleCredential extends Model
{
    protected $primaryKey = 'operation_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $hidden = ['code_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime'];
    }
}
