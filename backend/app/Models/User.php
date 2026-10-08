<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['username', 'display_name', 'email', 'password', 'show_name_suffix'])]
#[Hidden(['password', 'remember_token', 'email_verification_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'show_name_suffix' => 'boolean',
            'email_verified_at' => 'datetime',
            'email_verification_expires_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }

    /** @return HasMany<ActivityLike, $this> */
    public function sentLikes(): HasMany
    {
        return $this->hasMany(ActivityLike::class);
    }

    /** @return HasOne<GroupMember, $this> */
    public function membership(): HasOne
    {
        return $this->hasOne(GroupMember::class);
    }

    /** @return HasOne<GroupMember, $this> */
    public function activeMembership(): HasOne
    {
        return $this->hasOne(GroupMember::class)->where('status', GroupMember::STATUS_ACTIVE);
    }
}
