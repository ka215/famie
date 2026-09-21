<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'category_id', 'activity_date', 'activity_time', 'content', 'note'])]
class ActivityLog extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'activity_time' => 'datetime:H:i',
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
