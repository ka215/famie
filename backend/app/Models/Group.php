<?php

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'type'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    public const TYPE_FAMILY = 'family';

    protected function casts(): array
    {
        return ['images_enabled' => 'boolean', 'image_quota_bytes' => 'integer', 'representative_user_id' => 'integer', 'withdrawn_at' => 'datetime'];
    }

    public function isRepresentative(int $userId): bool
    {
        return $this->representative_user_id === $userId;
    }

    /** @return HasMany<ActivityImage, $this> */
    public function images(): HasMany
    {
        return $this->hasMany(ActivityImage::class);
    }

    /** @return HasMany<GroupMember, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<ActivityLog, $this> */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}
