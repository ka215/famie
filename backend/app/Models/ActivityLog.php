<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['group_id', 'user_id', 'category_id', 'activity_date', 'activity_time', 'content', 'note'])]
class ActivityLog extends Model
{
    use HasFactory;

    /** @return BelongsTo<Group, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'activity_time' => 'datetime:H:i',
            'likes_count' => 'integer',
            'liked_by_me' => 'boolean',
            'can_like' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return HasMany<ActivityLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(ActivityLike::class);
    }

    /** @param Builder<ActivityLog> $query */
    public function scopeWithLikeState(Builder $query, int $viewerId): void
    {
        $query->withCount(['likes' => fn (Builder $likes) => $likes->fromActiveMembers()->whereColumn('activity_likes.group_id', 'activity_logs.group_id')])
            ->withExists(['likes as liked_by_me' => fn (Builder $likes) => $likes->fromActiveMembers()->whereColumn('activity_likes.group_id', 'activity_logs.group_id')->where('user_id', $viewerId)])
            ->selectRaw('case when activity_logs.user_id <> ? then 1 else 0 end as can_like', [$viewerId]);
    }

    /**
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeDateBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('activity_date', '>=', $from);
        }
        if ($to) {
            $query->where('activity_date', '<=', $to);
        }

        return $query;
    }
}
