確定したDB設計に基づき、PostgreSQL用のLaravelマイグレーションファイルと初期データ投入用シーダー（Seeder）を作成しました。

PHP 8.4〜 および PostgreSQL 14.13〜 の環境でそのまま動作するコードとなっています。

---

## 1. マイグレーションファイル

### ① `users` テーブル (`database/migrations/YYYY_MM_DD_000001_create_users_table.php`)

メールアドレスなしのユーザー（子供）に対応するため、`email` を NULL 許容にし、`username` をログイン用のユニークキーとして定義します。また、ロール判定用の `role` カラムを追加しています。

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username')->unique()->comment('ログイン用ユーザー名');
            $table->string('display_name')->comment('表示名');
            $table->string('email')->nullable()->unique()->comment('メールアドレス（任意）');
            $table->string('password');
            $table->enum('role', ['parent', 'child'])->default('child')->comment('権限: parent(親), child(子)');
            $table->rememberToken();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};

```

---

### ② `categories` テーブル (`database/migrations/YYYY_MM_DD_000002_create_categories_table.php`)

アクティビティのカテゴリ情報と、UI表示用のカラーコードを保持します。

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->comment('カテゴリ名');
            $table->string('color_code', 7)->default('#3B82F6')->comment('表示用カラーコード (Hex)');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};

```

---

### ③ `activity_logs` テーブル (`database/migrations/YYYY_MM_DD_000003_create_activity_logs_table.php`)

デイリーアクティビティのログ本体です。期間検索（`activity_date`）を高速化するため、複合インデックスを設定しています。

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->comment('投稿者ID');
            $table->foreignId('category_id')->constrained()->restrictOnDelete()->comment('カテゴリID');
            $table->date('activity_date')->comment('実施日');
            $table->time('activity_time')->nullable()->comment('実施時刻/時間帯（任意）');
            $table->text('content')->comment('活動内容');
            $table->text('note')->nullable()->comment('補足メモ');
            $table->timestamps();

            // 期間指定検索用インデックス
            $table->index(['activity_date', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};

```

---

## 2. シーダー（初期データ挿入）

開発環境の動作確認および本番環境の初期セットアップ用シーダーです。

### ① 初期カテゴリシーダー (`database/seeders/CategorySeeder.php`)

一般的な家庭向けのアクティビティカテゴリを初期登録します。

```php
<?php

namespace Database\Seeders;

use App\Models\Category;
use Illuminate\Database\Seeder;

class CategorySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $categories = [
            ['name' => '勉強・宿題', 'color_code' => '#3B82F6'], // ブルー
            ['name' => '家事・お手伝い', 'color_code' => '#10B981'], // グリーン
            ['name' => '運動・スポーツ', 'color_code' => '#F59E0B'], // オレンジ
            ['name' => '習い事', 'color_code' => '#8B5CF6'], // パープル
            ['name' => '健康・生活', 'color_code' => '#EC4899'], // ピンク
            ['name' => '外出・おでかけ', 'color_code' => '#06B6D4'], // シアン
            ['name' => 'その他', 'color_code' => '#6B7280'], // グレー
        ];

        foreach ($categories as $category) {
            Category::firstOrCreate(
                ['name' => $category['name']],
                ['color_code' => $category['color_code']]
            );
        }
    }
}

```

---

### ② 初期ユーザーシーダー (`database/seeders/UserSeeder.php`)

動作テスト用の親（管理者）アカウントと子供アカウントを作成します。

```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 親アカウント（管理者）
        User::firstOrCreate(
            ['username' => 'parent1'],
            [
                'display_name' => 'おとうさん',
                'email' => 'parent@example.com',
                'password' => Hash::make('password123'),
                'role' => 'parent',
            ]
        );

        // 子供アカウント（一般・メールアドレスなし）
        User::firstOrCreate(
            ['username' => 'child1'],
            [
                'display_name' => 'たろう',
                'email' => null,
                'password' => Hash::make('password123'),
                'role' => 'child',
            ]
        );
    }
}

```

---

### ③ メインシーダー (`database/seeders/DatabaseSeeder.php`)

上記シーダーを一括実行するための呼び出し設定です。

```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CategorySeeder::class,
            UserSeeder::class,
        ]);
    }
}

```

---