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

### v0.13.0：いいねの制限と移行

- `FAMIE_LIKE_RATE_LIMIT_PER_SECOND` は認証ユーザー1人あたり、いいねPUT／DELETE共通の秒間上限。未設定時は2件。正の整数のみ受け付け、不正値では設定エラーとする。取得APIには適用しない。
- 同じユーザーの別カード・別トークンも合算する。複数PHPワーカーで制限を共有するため、通常は既定の `CACHE_STORE=database` を使用する。複数サーバーの場合も同じDBキャッシュとプレフィックスを使う。`array` / `null` はリクエスト間で制限を維持できないため運用では使用しない。
- `.env` の値を変更した後は既存配置手順に沿って `php artisan config:cache` で設定を再生成する。常駐プロセスを使用している環境ではそのプロセスも再起動する。設定変更のためにキャッシュ全体を消去する必要はない。
- 初期値2件を起点に、複数カードの通常操作、低速回線、複数端末で応答時間・429頻度・ロック待ちを確認して最終調整する。変更値・環境・結果をリリース記録へ残す。
- migrationで `activity_likes`、活動の複合一意制約、`users.show_name_suffix` を追加する。既存活動は0いいね、既存ユーザーは敬称ON。migration完了後に新しいFrontendを利用可能にする。
- 配置前に既存手順でDBをバックアップする。旧アプリへ戻す際も追加テーブル・列を保持できるため、データ蓄積後の `migrate:rollback` は使わない。migrationのdownは新しいいいね・敬称設定を失う。DB復元が必要な障害では、メンテナンス中にバックアップと対応するアプリを復元し、バックアップ以降の書き込みが失われる範囲を記録する。
- PostgreSQLの `pg_stat_user_tables` で `activity_likes` の `n_dead_tup / last_autovacuum / autovacuum_count`、`pg_stat_activity` でロック待ちを確認する。dead tuple件数は推定値。autovacuumを有効に保ち、調整は実測に基づく。定期VACUUM FULLは行わない。

### 共通の前提条件

- CoreServer 上の `~/famie` が配置先として初期化済みで、`.env`とstorageを保持できる。
- SSH の PHP CLI 8.4.17 で `pdo_pgsql` が有効であることを確認済みである。
- Composer 2.8.10 を SSH から実行できることを確認済みである。
- rsync 3.1.3 が利用でき、シンボリックリンクを扱えることを確認済みである。
- `pg_dump`と`pg_restore`をリリーススクリプトから実行できる。
- ステージングなどIP制限有効環境では許可する接続元IPが確定している。商用解除は[IP制限変更手順](ip-access-notes.md)に従う。

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

IP_RESTRICTION_ENABLED=false
ALLOWED_IPS=<ROLLBACK_ALLOWED_IP_ADDRESS_1>,<ROLLBACK_ALLOWED_IP_ADDRESS_2>
CORS_ALLOWED_ORIGINS=https://famie.ka2.org

SESSION_DRIVER=database
SESSION_DOMAIN=null
CACHE_STORE=database
QUEUE_CONNECTION=database
```

上記はv0.9.0以降の商用設定。ステージングは`IP_RESTRICTION_ENABLED=true`（未指定も有効）とし、`ALLOWED_IPS`を許可リストの正本としてフロントエンド用`.htaccess`にも同じ値を設定する。有効時に空の場合、Laravelは全アクセスを拒否する。商用解除はApache設定の変更と設定キャッシュ再生成も必要であり、[専用手順](ip-access-notes.md)に従う。旧版へ戻すため、商用でも有効な許可リストを保持する。

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
$password = Laravel\Prompts\password('初期親アカウントのパスワードを入力');
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

`Laravel\Prompts\password()` の引数はパスワードそのものではなく、入力時に表示するラベルである。実際のパスワードは続いて表示される非表示入力へ入力する。作成後、パスワードを表示せずにアカウントの存在を確認する。

```sh
php artisan tinker --execute 'dump(App\Models\User::query()->get(["id", "username", "email", "role"])->toArray());'
```

### 4.4 初期アカウントでログインできない場合

入力するログインIDは `users.username` または `users.email` と完全一致する必要がある。前項のコマンドで対象ユーザーが存在することを確認する。

対象ユーザーが存在する場合は、Tinkerを対話起動してパスワードを再設定する。

```sh
cd "$HOME/famie/backend" || exit 1
php artisan tinker
```

Tinker上で実行する。`<PARENT_USERNAME>` は実在するユーザー名へ置き換える。

```php
$user = App\Models\User::where('username', '<PARENT_USERNAME>')->firstOrFail();
$password = Laravel\Prompts\password('新しいパスワードを入力');
$user->password = Illuminate\Support\Facades\Hash::make($password);
$user->save();
Illuminate\Support\Facades\Hash::check($password, $user->fresh()->password);
unset($password);
exit
```

`Hash::check` の結果が `true` であることを確認してから、同じユーザー名とパスワードでログインする。

`$user->password = ...` はメモリ上の属性を変更するだけなので、必ず `$user->save()` まで実行する。パスワードを直接書いた `tinker --execute="..."` はシェル履歴に平文が残るため使用しない。

初回は `migrate --pretend` でもmigration管理テーブルの作成を試みるため、migration実行前には使用しない。

### 4.5 Web公開

フロントエンド手順の「初回のみ行う Apache 設定」に従い、次のリンクを作成する。

```text
~/public_html/famie.ka2.org/api -> ~/famie/backend/public
```

Laravel のプロジェクトルートを公開せず、`public` ディレクトリだけをApacheから参照させる。

## 5. 通常リリース

v0.7.0以降は[通常リリースの自動化](./release-script-notes.md)を使用する。Release CIが生成した同一成果物をステージングと商用へ配置し、DBバックアップ、migration、フロント・バックの更新、配置後検査をworkflowで実行する。通常リリース前に管理画面で別途DBバックアップを取得したり、サーバー上でGitのタグをcheckoutしたりしない。

コード・依存関係の変更前にメンテナンス状態にし、途中で失敗した場合は復旧確認までメンテナンスを維持する。復旧方法も共通手順を参照する。

## 6. リリース確認

### v0.14.0 画像添付の運用確認

画像保存先は既定で `storage/app/private/activity-images/`。通常配置・復旧ではDBと同じ時点の画像を保持し、公開ディレクトリへシンボリックリンクを作らない。Web側PHPのImagickがWebPを読み書きできること、`upload_max_filesize` と `post_max_size` が中間画像の暫定30MiB上限を超えること、メモリ・実行時間制限内で変換できることを確認する。HEIC原本はサーバーへ送らない。

全家族の初期状態はOFF。公開環境で検証後、対象家族を確認して `php artisan famie:group-images 1 --enable` を実行する。コマンドは変更前後を表示して確認を求める。容量変更はバイト単位で `--quota=1000000000` を指定する。運用者用Web画面や家族APIからは変更できない。ステージングの家族IDは公開環境と一致するとは限らない。

物理削除に失敗した画像は `activity_image_deletions` に残る。`php artisan famie:retry-image-deletions` を定期実行またはリリース確認時に実行し、未削除件数とサーバー空き容量を確認する。WebP変換・iPhone実機でのHEIC変換は公開前に確認する。
`php artisan famie:audit-images` でDBと画像ファイルの不足・孤立を照合できる。孤立ファイルは実行中アップロードの可能性もあるため、このコマンドは自動削除しない。

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
- ステージングでは許可外IPのAPIが403になる。商用では従来許可外IPからログインでき、未認証の保護APIは401になる。

`The route v1/... could not be found.` が返る場合は、古いルートキャッシュが残っている。次を実行してから再確認する。

```sh
php artisan optimize:clear
php artisan optimize
php artisan route:list --path=v1
```

## 7. ロールバック

[通常リリースの自動化](./release-script-notes.md)の「切り戻し」に従う。DB変更に互換性がある場合はアプリだけを戻す。DB復元が必要な場合は、対象バックアップの絶対パスを明示して切り戻しworkflowを実行する。

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
