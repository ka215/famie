<?php

namespace App\Support;

use App\Models\Group;

final class InitialCategories
{
    /** @var list<array{key: string, color_code: string}> */
    public const ITEMS = [
        ['key' => 'study', 'color_code' => '#3B82F6'],
        ['key' => 'chores', 'color_code' => '#10B981'],
        ['key' => 'exercise', 'color_code' => '#F59E0B'],
        ['key' => 'lessons', 'color_code' => '#8B5CF6'],
        ['key' => 'health', 'color_code' => '#EC4899'],
        ['key' => 'outings', 'color_code' => '#06B6D4'],
        ['key' => 'other', 'color_code' => '#6B7280'],
    ];

    public static function createFor(Group $group, string $locale = 'ja'): void
    {
        foreach (self::ITEMS as $index => $category) {
            $group->categories()->create([
                'name' => __('categories.'.$category['key'], [], config('i18n.locales.'.$locale.'.translation', config('i18n.fallback'))),
                'color_code' => $category['color_code'],
                'sort_order' => $index + 1,
            ]);
        }
    }
}
