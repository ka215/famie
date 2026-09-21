<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ActivityLog $activityLog): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, ActivityLog $activityLog): bool
    {
        if ($user->isParent()) {
            return true;
        }

        return $user->id === $activityLog->user_id;
    }

    public function delete(User $user, ActivityLog $activityLog): bool
    {
        if ($user->isParent()) {
            return true;
        }

        return $user->id === $activityLog->user_id;
    }
}
