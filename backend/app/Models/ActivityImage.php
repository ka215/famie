<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['group_id', 'activity_log_id', 'disk', 'path', 'bytes', 'width', 'height', 'mime', 'position'])]
class ActivityImage extends Model
{
    protected $hidden = ['disk', 'path', 'group_id', 'activity_log_id', 'created_at', 'updated_at'];

    /** @return BelongsTo<ActivityLog, $this> */
    public function activityLog(): BelongsTo
    {
        return $this->belongsTo(ActivityLog::class);
    }
}
