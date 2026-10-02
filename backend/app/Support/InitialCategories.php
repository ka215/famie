<?php

namespace App\Support;

use App\Models\Group;

final class InitialCategories
{
    /** @var list<array{name: string, color_code: string}> */
    public const ITEMS = [
        ['name' => '勉強・宿題', 'color_code' => '#3B82F6'],
        ['name' => '家事・お手伝い', 'color_code' => '#10B981'],
        ['name' => '運動・スポーツ', 'color_code' => '#F59E0B'],
        ['name' => '習い事', 'color_code' => '#8B5CF6'],
        ['name' => '健康・生活', 'color_code' => '#EC4899'],
        ['name' => '外出・おでかけ', 'color_code' => '#06B6D4'],
        ['name' => 'その他', 'color_code' => '#6B7280'],
    ];

    public static function createFor(Group $group): void
    {
        foreach (self::ITEMS as $index => $category) {
            $group->categories()->create([
                ...$category,
                'sort_order' => $index + 1,
            ]);
        }
    }
}
