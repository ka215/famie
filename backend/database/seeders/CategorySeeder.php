<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Support\InitialCategories;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $group = Group::query()->first();
        if ($group && ! $group->categories()->exists()) {
            InitialCategories::createFor($group);
        }
    }
}
