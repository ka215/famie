<?php

namespace Database\Seeders;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(UserSeeder::class);

        $group = Group::query()->firstOrCreate(['name' => '開発用の家族'], ['type' => Group::TYPE_FAMILY]);
        User::query()->orderBy('id')->get()->each(function (User $user, int $index) use ($group): void {
            GroupMember::query()->firstOrCreate(
                ['user_id' => $user->id],
                ['group_id' => $group->id, 'role' => $index === 0 ? GroupMember::ROLE_ADMIN : GroupMember::ROLE_MEMBER, 'status' => GroupMember::STATUS_ACTIVE],
            );
        });

        $this->call(CategorySeeder::class);
    }
}
