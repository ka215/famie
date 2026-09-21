<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => '勉強・宿題', 'color_code' => '#3B82F6'],
            ['name' => '家事・お手伝い', 'color_code' => '#10B981'],
            ['name' => '運動・スポーツ', 'color_code' => '#F59E0B'],
            ['name' => '習い事', 'color_code' => '#8B5CF6'],
            ['name' => '健康・生活', 'color_code' => '#EC4899'],
            ['name' => '外出・おでかけ', 'color_code' => '#06B6D4'],
            ['name' => 'その他', 'color_code' => '#6B7280'],
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                ['color_code' => $category['color_code']]
            );
        }
    }
}
