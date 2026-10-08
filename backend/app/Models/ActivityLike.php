<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Query\Builder as QueryBuilder;

#[Fillable(['group_id', 'activity_log_id', 'user_id'])]
class ActivityLike extends Model
{
    /** @return BelongsTo<ActivityLog, $this> */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /** @param Builder<ActivityLike> $query */
    public function scopeFromActiveMembers(Builder $query): void
    {
        $query->whereExists(function (QueryBuilder $members): void {
            $members->selectRaw('1')->from('group_members')
                ->whereColumn('group_members.group_id', 'activity_likes.group_id')
                ->whereColumn('group_members.user_id', 'activity_likes.user_id')
                ->where('group_members.status', GroupMember::STATUS_ACTIVE);
        });
    }
}
