<?php

namespace App\Services;

use App\Models\Group;

class GroupImageUsage
{
    public function bytes(Group $group): int
    {
        return (int) $group->images()->sum('bytes');
    }
}
