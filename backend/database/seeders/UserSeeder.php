<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    /**
     * ローカル開発専用のサンプルアカウントを作成する。
     * DEV_SEED_PASSWORD(.env)を指定すればそのパスワードを使用し、未指定ならランダム生成する。
     */
    public function run(): void
    {
        if (User::query()->exists()) {
            return;
        }

        $fixedPassword = env('DEV_SEED_PASSWORD');
        $parentPassword = $fixedPassword ?: Str::password(16);
        $childPassword = $fixedPassword ?: Str::password(16);

        User::create([
            'username' => 'parent1',
            'display_name' => 'おとうさん',
            'email' => null,
            'password' => Hash::make($parentPassword),
            'role' => 'parent',
        ]);

        User::create([
            'username' => 'child1',
            'display_name' => 'たろう',
            'email' => null,
            'password' => Hash::make($childPassword),
            'role' => 'child',
        ]);

        $this->command?->warn('開発用アカウントを作成しました（このパスワードは二度と表示されません）:');
        $this->command?->line("  parent1 / {$parentPassword}");
        $this->command?->line("  child1  / {$childPassword}");
    }
}
