作成したマイグレーションに対応する **Eloquentモデルクラス（User, Category, ActivityLog）** と、認可制御を行う **ActivityLogPolicy** のコードを作成しました。

PHP 8.4 のプロパティ型定義やアトリビュートを活用し、型安全で保守しやすい実装にしています。

---

## 1. Eloquent モデルクラス

### ① `User` モデル (`app/Models/User.php`)

* **変更点・ポイント**:
* ログイン識別子を `username` にも対応可能にする処理（Sanctum / Breeze用）。
* 親アカウント判別用ヘルパーメソッド `isParent()` の追加。
* リレーション: `activityLogs()` (1対多) の定義。



```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * 一括割り当て可能な属性
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'username',
        'display_name',
        'email',
        'password',
        'role',
    ];

    /**
     * 隠蔽する属性
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * キャスト定義
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /**
     * 親アカウント（管理者）かどうかを判定
     */
    public function isParent(): bool
    {
        return $this->role === 'parent';
    }

    /**
     * 投稿したアクティビティログ一覧
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}

```

---

### ② `Category` モデル (`app/Models/Category.php`)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'color_code',
    ];

    /**
     * このカテゴリに属するアクティビティログ一覧
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(ActivityLog::class);
    }
}

```

---

### ③ `ActivityLog` モデル (`app/Models/ActivityLog.php`)

* **変更点・ポイント**:
* 日付（`activity_date`）および時間（`activity_time`）の自動キャスト。
* `user` および `category` へのリレーション (`BelongsTo`) 定義。
* 期間フィルタリング用スコープ (`scopeDateBetween`) の追加。



```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'category_id',
        'activity_date',
        'activity_time',
        'content',
        'note',
    ];

    /**
     * キャスト定義
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'activity_date' => 'date:Y-m-d',
            'activity_time' => 'datetime:H:i',
        ];
    }

    /**
     * ログの投稿者
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * ログのカテゴリ
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * 日付範囲検索用スコープ
     */
    public function scopeDateBetween(Builder $query, ?string $from, ?string $to): Builder
    {
        if ($from) {
            $query->where('activity_date', '>=', $from);
        }
        if ($to) {
            $query->where('activity_date', '<=', $to);
        }
        return $query;
    }
}

```

---

## 2. Policy クラス (認可制御)

### `ActivityLogPolicy` (`app/Policies/ActivityLogPolicy.php`)

確定した要件に沿って認可判定を行います：

* **閲覧 (viewAny / view)**: 家族全員可能。
* **新規作成 (create)**: ログイン済みユーザーなら可能。
* **更新・削除 (update / delete)**:
* **親 (`role === 'parent'`)**: 家族全員のログに対して許可。
* **子 (`role === 'child'`)**: **自分自身が投稿したログのみ** 許可。



```php
<?php

namespace App\Policies;

use App\Models\ActivityLog;
use App\Models\User;

class ActivityLogPolicy
{
    /**
     * 一覧表示の権限チェック
     */
    public function viewAny(User $user): bool
    {
        // ログインユーザーであれば全員閲覧可能
        return true;
    }

    /**
     * 詳細表示の権限チェック
     */
    public function view(User $user, ActivityLog $activityLog): bool
    {
        // ログインユーザーであれば全員閲覧可能
        return true;
    }

    /**
     * 新規投稿の権限チェック
     */
    public function create(User $user): bool
    {
        // ログインユーザーであれば投稿可能
        return true;
    }

    /**
     * 更新の権限チェック
     */
    public function update(User $user, ActivityLog $activityLog): bool
    {
        // 親アカウントなら誰のログでも編集可能
        if ($user->isParent()) {
            return true;
        }

        // 子供アカウントは自分のログのみ編集可能
        return $user->id === $activityLog->user_id;
    }

    /**
     * 削除の権限チェック
     */
    public function delete(User $user, ActivityLog $activityLog): bool
    {
        // 親アカウントなら誰のログでも削除可能
        if ($user->isParent()) {
            return true;
        }

        // 子供アカウントは自分のログのみ削除可能
        return $user->id === $activityLog->user_id;
    }
}

```

---

### コントローラーでの使用イメージ (例: `ActivityLogController.php`)

```php
public function update(Request $request, ActivityLog $log)
{
    // ポリシーに基づく自動認可判定 (権限がなければ 403 Forbidden が返る)
    Gate::authorize('update', $log);

    // バリデーション & 更新処理...
}

```