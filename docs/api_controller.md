EloquentモデルとPolicyに連携する、Laravelの主要APIコントローラー（`AuthController`, `ActivityLogController`, `UserController`）を作成しました。

リクエストのバリデーション、認可処理、エラーハンドリングを含み、Nuxt 4フロントエンドとのAPI通信にそのまま利用できる実装となっています。

---

## 1. `AuthController.php` (`app/Http/Controllers/Api/AuthController.php`)

ユーザー名（またはメールアドレス）とパスワードによるログイン・ログアウト、および自身のプロフィール取得を行います。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * ログイン処理 (username または email でログイン可能)
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        // username または email でユーザーを検索
        $user = User::where('username', $request->login)
            ->orWhere('email', $request->login)
            ->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['ログインIDまたはパスワードが正しくありません。'],
            ]);
        }

        // 既存トークンを削除して新規作成（またはSanctum SPA認証のセッション発行）
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message' => 'ログインに成功しました。',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => [
                'id' => $user->id,
                'username' => $user->username,
                'display_name' => $user->display_name,
                'role' => $user->role,
            ],
        ]);
    }

    /**
     * ログアウト処理
     */
    public function logout(Request $request): JsonResponse
    {
        // カレントトークンを削除
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'ログアウトしました。',
        ]);
    }

    /**
     * ログイン中のユーザー情報取得
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->only(['id', 'username', 'display_name', 'email', 'role']),
        ]);
    }
}

```

---

## 2. `ActivityLogController.php` (`app/Http/Controllers/Api/ActivityLogController.php`)

ログの一覧取得（期間・ユーザーフィルタ）、新規投稿、詳細取得、更新、削除を扱います。更新および削除には `Gate::authorize()` を呼び出すことで、前ステップで作成した `ActivityLogPolicy` が自動適用されます。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ActivityLogController extends Controller
{
    /**
     * ログ一覧取得 (期間・ユーザー・カテゴリでのフィルタリング対応)
     */
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', ActivityLog::class);

        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'user_id' => ['nullable', 'exists:users,id'],
            'category_id' => ['nullable', 'exists:categories,id'],
        ]);

        $query = ActivityLog::with(['user:id,display_name,role', 'category:id,name,color_code'])
            ->dateBetween($request->from, $request->to);

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->category_id);
        }

        // 実施日の降順、時間の降順で取得
        $logs = $query->orderBy('activity_date', 'desc')
            ->orderBy('activity_time', 'desc')
            ->paginate(50);

        return response()->json($logs);
    }

    /**
     * ログ新規作成
     */
    public function store(Request $request): JsonResponse
    {
        Gate::authorize('create', ActivityLog::class);

        $validated = $request->validate([
            'category_id' => ['required', 'exists:categories,id'],
            'activity_date' => ['required', 'date'],
            'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        // ログインユーザーのIDを割り当て
        $validated['user_id'] = $request->user()->id;

        $log = ActivityLog::create($validated);
        $log->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'message' => 'アクティビティを記録しました。',
            'data' => $log,
        ], 201);
    }

    /**
     * ログ詳細取得
     */
    public function show(ActivityLog $activityLog): JsonResponse
    {
        Gate::authorize('view', $activityLog);

        $activityLog->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'data' => $activityLog,
        ]);
    }

    /**
     * ログ更新 (親: 全員分OK / 子: 自分のログのみOK)
     */
    public function update(Request $request, ActivityLog $activityLog): JsonResponse
    {
        // ActivityLogPolicy::update が実行され、権限がなければ 403 エラーを返却
        Gate::authorize('update', $activityLog);

        $validated = $request->validate([
            'category_id' => ['sometimes', 'required', 'exists:categories,id'],
            'activity_date' => ['sometimes', 'required', 'date'],
            'activity_time' => ['nullable', 'date_format:H:i'],
            'content' => ['sometimes', 'required', 'string', 'max:1000'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $activityLog->update($validated);
        $activityLog->load(['user:id,display_name,role', 'category:id,name,color_code']);

        return response()->json([
            'message' => 'ログを更新しました。',
            'data' => $activityLog,
        ]);
    }

    /**
     * ログ削除 (親: 全員分OK / 子: 自分のログのみOK)
     */
    public function destroy(ActivityLog $activityLog): JsonResponse
    {
        // ActivityLogPolicy::delete が実行される
        Gate::authorize('delete', $activityLog);

        $activityLog->delete();

        return response()->json([
            'message' => 'ログを削除しました。',
        ]);
    }
}

```

---

## 3. `UserController.php` (`app/Http/Controllers/Api/UserController.php`)

ユーザー一覧の取得と、親（管理者）による家族アカウント新規追加を管理します。

```php
<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    /**
     * 家族ユーザー一覧取得（投稿者のフィルタリング用など）
     */
    public function index(): JsonResponse
    {
        $users = User::select(['id', 'username', 'display_name', 'role'])->get();

        return response()->json($users);
    }

    /**
     * 家族アカウントの新規追加 (親アカウントのみ実行可能)
     */
    public function store(Request $request): JsonResponse
    {
        // 親アカウント権限チェック
        if (! $request->user()->isParent()) {
            return response()->json(['message' => 'アカウントの作成権限がありません。'], 403);
        }

        $validated = $request->validate([
            'username' => ['required', 'string', 'max:50', 'unique:users,username'],
            'display_name' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', Rule::in(['parent', 'child'])],
        ]);

        $user = User::create([
            'username' => $validated['username'],
            'display_name' => $validated['display_name'],
            'email' => $validated['email'] ?? null,
            'password' => Hash::make($validated['password']),
            'role' => $validated['role'],
        ]);

        return response()->json([
            'message' => 'ユーザーを作成しました。',
            'data' => $user->only(['id', 'username', 'display_name', 'role']),
        ], 201);
    }
}

```

---

## 4. APIルーティング定義 (`routes/api.php`)

作成したコントローラーを呼び出すためのルーター設定です。

```php
<?php

use App\Http\Controllers\Api\ActivityLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    // 認証不要ルート
    Route::post('/auth/login', [AuthController::class, 'login']);

    // 認証必須ルート (Sanctum)
    Route::middleware('auth:sanctum')->group(function () {
        // 認証・ユーザー情報
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::get('/auth/me', [AuthController::class, 'me']);

        // ユーザー管理
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);

        // アクティビティログ API
        Route::apiResource('logs', ActivityLogController::class)->names([
            'index' => 'logs.index',
            'store' => 'logs.store',
            'show' => 'logs.show',
            'update' => 'logs.update',
            'destroy' => 'logs.destroy',
        ]);
    });
});

```

---