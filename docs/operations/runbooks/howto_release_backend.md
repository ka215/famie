# バックエンドのリリース手順

## 1. 目的と構成

Laravel API を CoreServer の PHP 8.4、PostgreSQL 14.13、Apache で稼働させる。常駐プロセスは使用せず、Apache から `backend/public/index.php` を実行する。

| 項目 | 値 |
| --- | --- |
| API URL | `https://famie.ka2.org/api/v1` |
| サーバー上のアプリ | `~/famie/backend` |
| Web 公開ディレクトリ | `~/famie/backend/public` |
| ドキュメントルートからの参照 | `~/public_html/famie.ka2.org/api` |
| DB | PostgreSQL 14.13 |

DB 本体とDBユーザーは CoreServer の管理画面で作成する。テーブル、インデックス、制約はLaravel migrationを正本とし、別の初期DDLは作成しない。

Apacheは `/api` を `backend/public` のマウントパスとして取り除いてからLaravelへ渡す。このため、Laravel内部では `/v1`、公開URLでは `/api/v1` となる。

## 2. 前提条件

- CoreServer 上の `~/famie` にリポジトリが clone 済みである。
- SSH の PHP CLI 8.4.17 で `pdo_pgsql` が有効であることを確認済みである。
- Composer 2.8.10 を SSH から実行できることを確認済みである。
- rsync 3.1.3 が利用でき、シンボリックリンクを扱えることを確認済みである。
- CoreServer 管理画面で PostgreSQL のバックアップを取得できる。
- 許可する接続元 IP アドレスが確定している。

初回作業前に実行環境を確認する。

```sh
php -v
php -m | grep -E 'ctype|curl|dom|fileinfo|filter|hash|mbstring|openssl|pcre|PDO|pdo_pgsql|session|tokenizer|xml'
composer --version
git --version
rsync --version
```

確認時の `php` は `/usr/local/bin/php84cli`、Composer が使用する PHP も同じバイナリである。以降はPATH上の `php`、`composer` を使用する。

## 3. 初回DB作成

1. CoreServer 管理画面の「データベース」から PostgreSQL を新規作成する。
2. DB名、ユーザー名、パスワード、ホスト、ポートを安全な場所に記録する。
3. `~/famie/backend/.env` のDB設定へ反映する。
4. 接続後、Laravel migrationでテーブルを作成する。

Laravel migrationは既に次を管理している。

- users、password_reset_tokens、sessions
- cache、cache_locks
- jobs、job_batches、failed_jobs
- personal_access_tokens
- categories
- activity_logs

したがって、CoreServerで空のDBを作成した後にDDLを手動実行する必要はない。

## 4. 初回アプリ設定

### 4.1 依存関係と `.env`

```sh
REPO_DIR="$HOME/famie"
BACKEND_DIR="$REPO_DIR/backend"

cd "$BACKEND_DIR" || exit 1
composer install --no-dev --prefer-dist --optimize-autoloader
test -f .env || cp .env.example .env
chmod 600 .env
```

`.env` を編集する。秘密情報をGitへ追加しない。

```dotenv
APP_NAME=Famie
APP_ENV=production
APP_KEY=
APP_DEBUG=false
APP_URL=https://famie.ka2.org

LOG_CHANNEL=stack
LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=ka2_famie
DB_USERNAME=ka2_famie
DB_PASSWORD=<CORESERVER_DB_PASSWORD>
DB_SCHEMA=ka2_famie

ALLOWED_IPS=202.172.28.141,202.172.30.215,106.152.55.116,153.124.191.230
CORS_ALLOWED_ORIGINS=https://famie.ka2.org

SESSION_DRIVER=database
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database
```

`ALLOWED_IPS` は許可リストの正本であり、フロントエンド用 `.htaccess` にも同じ値を設定する。空の場合、Laravelは全アクセスを拒否する。

APP_KEY は初回だけ生成する。既存のキーをリリース時に再生成してはいけない。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan key:generate
chmod -R u+rwX storage bootstrap/cache
```

### 4.2 PostgreSQLスキーマの初回作成

CoreServerの `public` スキーマにはテーブル作成権限がないため、DBユーザーが所有する専用スキーマを初回だけ作成する。パスワードをコマンドラインへ記載せず、LaravelのDB接続を使用する。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan config:clear
php artisan tinker --execute 'dump(DB::select("select current_user, current_database()"));'
php artisan tinker --execute 'DB::statement("create schema if not exists ka2_famie authorization ka2_famie");'
php artisan tinker --execute 'dump(DB::select("select current_schema()"));'
```

最後の出力で `current_schema` が `ka2_famie` であることを確認する。`permission denied for database ka2_famie` になった場合、契約ユーザーではスキーマを作成できないため、CoreServerへ次のいずれかを依頼する。

- `ka2_famie` ユーザーが所有する `ka2_famie` スキーマの作成
- `public` スキーマに対する `USAGE, CREATE` 権限の付与

### 4.3 migration と初期データ

設定とDB接続を確認してから実行する。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan about --only=environment
php artisan migrate --force
php artisan db:seed --class=CategorySeeder --force
php artisan migrate:status
php artisan optimize
```

本番では `DatabaseSeeder` と `UserSeeder` を実行しない。これらは開発用アカウントを作成するためである。

初回の親アカウントは、パスワードをシェル履歴に残さないよう Tinker の対話入力で作成する。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan tinker
```

Tinker 上で実行する。

```php
$password = Laravel\Prompts\password('初期親アカウントのパスワード');
App\Models\User::create([
    'username' => '<PARENT_USERNAME>',
    'display_name' => '<PARENT_DISPLAY_NAME>',
    'email' => null,
    'password' => Illuminate\Support\Facades\Hash::make($password),
    'role' => 'parent',
]);
unset($password);
exit
```

初回は `migrate --pretend` でもmigration管理テーブルの作成を試みるため、migration実行前には使用しない。

### 4.4 Web公開

フロントエンド手順の「初回のみ行う Apache 設定」に従い、次のリンクを作成する。

```text
~/public_html/famie.ka2.org/api -> ~/famie/backend/public
```

Laravel のプロジェクトルートを公開せず、`public` ディレクトリだけをApacheから参照させる。

## 5. 通常リリース

先に CoreServer 管理画面で PostgreSQL のバックアップを作成する。次にSSH接続し、`RELEASE_COMMIT` を対象コミットの完全なハッシュへ置き換えて実行する。

```sh
REPO_DIR="$HOME/famie"
BACKEND_DIR="$REPO_DIR/backend"
RELEASE_COMMIT="<RELEASE_COMMIT>"

test -d "$REPO_DIR/.git" || exit 1
test -f "$BACKEND_DIR/.env" || exit 1

cd "$REPO_DIR" || exit 1
git status --short
git fetch origin
git checkout main
git pull --ff-only origin main
test "$(git rev-parse HEAD)" = "$RELEASE_COMMIT" || exit 1

cd "$BACKEND_DIR" || exit 1
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan about --only=environment
php artisan migrate:status
php artisan migrate --pretend
```

`git status --short` にサーバー固有の未コミット変更が出た場合、または environment が `production` でない場合は中断する。`migrate --pretend` の結果も確認する。

確認後、短時間のメンテナンス状態で反映する。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan down --retry=60
php artisan migrate --force
php artisan db:seed --class=CategorySeeder --force
php artisan optimize
php artisan up
```

途中で失敗した場合も、確認が済んだら必ず `php artisan up` を実行してメンテナンス状態を解除する。

## 6. リリース確認

```sh
cd "$HOME/famie/backend" || exit 1
php artisan migrate:status
php artisan about --only=environment

curl -i \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{}' \
  https://famie.ka2.org/api/v1/auth/login
```

次を確認する。

- 全 migration の状態が `Ran` である。
- environment が `production`、debug mode が `OFF` である。
- 空のログインリクエストが HTML ではなく、JSON の `422` を返す。
- 実アカウントでログイン、ログアウト、記録の登録と参照ができる。
- `storage/logs/laravel.log` に新しい例外がない。
- 許可していない IP から API が `403` になる。

`The route v1/... could not be found.` が返る場合は、古いルートキャッシュが残っている。次を実行してから再確認する。

```sh
php artisan optimize:clear
php artisan optimize
php artisan route:list --path=v1
```

## 7. ロールバック

アプリコードだけの障害で、DB変更に後方互換性がある場合は、直前の正常コミットを指定してコードを戻す。

```sh
REPO_DIR="$HOME/famie"
BACKEND_DIR="$REPO_DIR/backend"
ROLLBACK_COMMIT="<LAST_GOOD_COMMIT>"

cd "$REPO_DIR" || exit 1
git fetch origin
git checkout --detach "$ROLLBACK_COMMIT"

cd "$BACKEND_DIR" || exit 1
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction
php artisan optimize:clear
php artisan optimize
php artisan up
```

DB migrationを伴う障害では、原則として `migrate:rollback` を即時実行しない。後方互換の修正 migration を適用するか、事前に取得したDBバックアップをCoreServer管理画面から復元する。復旧後はリポジトリを `main` に戻し、正常なコミットへ揃える。

## 8. 禁止事項

- 本番で `php artisan migrate:fresh`、`migrate:refresh`、`db:wipe` を実行しない。
- 本番で `DatabaseSeeder`、`UserSeeder` を実行しない。
- `.env`、DBバックアップ、認証情報をGitへ追加しない。
- `backend` のプロジェクトルートをWeb公開しない。
- APP_KEYを通常リリースで再生成しない。

## 9. 関連文書

- [フロントエンドのリリース手順](./howto_release_frontend.md)
- `backend/.env.example`
- `backend/database/migrations/`
- `backend/database/seeders/CategorySeeder.php`
