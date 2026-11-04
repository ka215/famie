<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

#[Fillable(['id', 'actor_user_id', 'group_id', 'target_membership_id', 'kind', 'state', 'cleanup_state', 'prepared_expires_at'])]
class AccountLifecycleOperation extends Model
{
    public const KIND_MEMBER_DELETE = 'member_delete';

    public const KIND_GROUP_WITHDRAW = 'group_withdraw';

    public const STATE_PREPARED = 'prepared';

    public const STATE_COMMITTED = 'committed';

    public const STATE_ABORTED = 'aborted';

    public $incrementing = false;

    protected $keyType = 'string';

    protected function casts(): array
    {
        return [
            'prepared_expires_at' => 'datetime',
            'committed_at' => 'datetime',
            'retention_until' => 'datetime',
            'content_cursor' => 'integer',
        ];
    }

    /** Call while holding this operation's row lock when deciding whether to erase data. */
    public function hasActiveHold(): bool
    {
        return DB::table('account_lifecycle_holds')->where('operation_id', $this->id)->whereNull('released_at')->exists();
    }

    /** @return HasMany<AccountLifecycleSubject, $this> */
    public function subjects(): HasMany
    {
        return $this->hasMany(AccountLifecycleSubject::class, 'operation_id');
    }

    /** @return HasOne<AccountLifecycleCredential, $this> */
    public function credential(): HasOne
    {
        return $this->hasOne(AccountLifecycleCredential::class, 'operation_id');
    }
}
