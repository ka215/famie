# フロントエンドのリリース手順

## 1. 目的と構成

Nuxt SPA をローカルで静的生成し、Git リポジトリ経由で CoreServer に配置する。CoreServer では Node.js を起動せず、Apache が静的ファイルを配信する。

| 項目 | 値 |
| --- | --- |
| 公開 URL | `https://famie.ka2.org` |
| サーバー上のリポジトリ | `~/famie` |
| 静的生成物 | `~/famie/frontend/.output/public/` |
| ドキュメントルート | `~/public_html/famie.ka2.org/` |
| API 公開パス | `~/public_html/famie.ka2.org/api` → `~/famie/backend/public` |

`.output/public` は Git 管理する。リリース対象コミットには、対象ソースから生成した最新の静的ファイルも含める。

## 2. 前提条件

- バックエンドの依存関係、`.env`、DB migration の初回設定が完了している。
- CoreServer 上の `~/famie` にリポジトリが clone 済みである。
- ローカル環境で Node.js、pnpm、Git を利用できる。
- リリース対象コミットが `main` に push 済みである。
- `~/public_html/famie.ka2.org/.htaccess` に本番用 IP 許可リストが設定済みである。
- CoreServer でドキュメントルートから `~/famie/backend/public` へのシンボリックリンクを利用できることを確認済みである。

## 3. ローカルでの生成と検証

PowerShell で実行する。

```powershell
Set-Location C:\xampp\htdocs\famie\frontend
pnpm install --frozen-lockfile
pnpm lint
pnpm typecheck
pnpm build
Test-Path .\.output\public\index.html
Test-Path .\.output\public\.htaccess
```

`pnpm build` は `nuxt generate` を実行する。本番 API URL は同一 Origin の `/api/v1` として静的ファイルへ組み込まれる。

生成物の差分を確認し、ソース変更と一緒に commit、push する。

```powershell
Set-Location C:\xampp\htdocs\famie
git status --short
git diff --stat
git add frontend
git commit -m "build: generate frontend assets"
git push origin main
```

既に生成物を含むリリースコミットを push 済みなら、最後の3コマンドは不要である。

## 4. CoreServer への配置

CoreServer に SSH 接続し、次を実行する。`RELEASE_COMMIT` はリリース対象の完全なコミットハッシュに置き換える。

```sh
REPO_DIR="$HOME/famie"
DOCROOT="$HOME/public_html/famie.ka2.org"
BACKUP_ROOT="$HOME/famie-release-backups/frontend"
RELEASE_COMMIT="<RELEASE_COMMIT>"

test -d "$REPO_DIR/.git" || exit 1
test -d "$DOCROOT" || exit 1

cd "$REPO_DIR" || exit 1
git status --short
git fetch origin
git checkout main
git pull --ff-only origin main
test "$(git rev-parse HEAD)" = "$RELEASE_COMMIT" || exit 1
test -f "$REPO_DIR/frontend/.output/public/index.html" || exit 1
```

`git status --short` にサーバー固有の未コミット変更が出た場合は中断する。`.env` は Git 管理外なので表示されない。

現行ファイルを退避してから同期する。`.htaccess` は本番 IP 制限を保持し、`api` はバックエンドへのリンクを保持するため除外する。

```sh
RELEASE_ID="$(date +%Y%m%d-%H%M%S)-$(git rev-parse --short HEAD)"
BACKUP_DIR="$BACKUP_ROOT/$RELEASE_ID"
mkdir -p "$BACKUP_DIR"
rsync -a --exclude='/api' "$DOCROOT/" "$BACKUP_DIR/"

rsync -a --delete \
  --exclude='/.htaccess' \
  --exclude='/api' \
  "$REPO_DIR/frontend/.output/public/" "$DOCROOT/"
```

## 5. 初回のみ行う Apache 設定

生成物の `.htaccess` を配置し、`RewriteEngine On` より前に Apache 2.4 のアクセス制限を追加する。許可 IP は `backend/.env` の `ALLOWED_IPS` と同じ値にする。

```sh
cp "$HOME/famie/frontend/.output/public/.htaccess" \
  "$HOME/public_html/famie.ka2.org/.htaccess"
chmod 644 "$HOME/public_html/famie.ka2.org/.htaccess"
```

```apacheconf
Options -MultiViews

<RequireAny>
    Require ip 202.172.28.141
    Require ip 106.152.55.116
    Require ip 153.124.191.230
</RequireAny>

RewriteEngine On

# API は Laravel 側のルールへ渡す。
RewriteCond %{REQUEST_URI} ^/api(?:/|$) [NC]
RewriteRule ^ - [L]

# 実在する静的ファイルとディレクトリはそのまま配信する。
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]

# Vue Router の画面 URL を SPA のエントリーポイントへ戻す。
RewriteRule ^ index.html [L]
```

許可 IP が未設定の場合は `<RequireAny>` の代わりに `Require all denied` を設定する。設定後は通常リリースで `.htaccess` を上書きしない。

API のリンクも初回だけ作成する。

```sh
REPO_DIR="$HOME/famie"
DOCROOT="$HOME/public_html/famie.ka2.org"

test ! -e "$DOCROOT/api" || exit 1
ln -s "$REPO_DIR/backend/public" "$DOCROOT/api"
test "$(readlink "$DOCROOT/api")" = "$REPO_DIR/backend/public" || exit 1
```

## 6. リリース確認

許可 IP から確認する。

```sh
curl -I https://famie.ka2.org/
curl -I https://famie.ka2.org/login
curl -i \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{}' \
  https://famie.ka2.org/api/v1/auth/login
```

次をすべて満たすことを確認する。

- `/` と `/login` が `200` で HTML を返す。
- ログイン API が HTML ではなく JSON を返す。空データなので `422` が正常である。
- 実アカウントでログイン、ログアウトできる。
- ブラウザの開発者ツールで JavaScript、CSS、manifest、Service Worker に `404` がない。
- 許可していない IP からは画面と API の両方が `403` になる。

## 7. ロールバック

配置直前に作成したバックアップディレクトリを指定する。先に絶対パスを表示し、意図したディレクトリであることを確認する。

```sh
DOCROOT="$HOME/public_html/famie.ka2.org"
BACKUP_DIR="$HOME/famie-release-backups/frontend/<RELEASE_ID>"

printf '%s\n' "$DOCROOT" "$BACKUP_DIR"
test -f "$BACKUP_DIR/index.html" || exit 1

rsync -a --delete \
  --exclude='/.htaccess' \
  --exclude='/api' \
  "$BACKUP_DIR/" "$DOCROOT/"
```

ロールバック後に「6. リリース確認」を再実行する。

## 8. 関連文書

- [バックエンドのリリース手順](./howto_release_backend.md)
- `frontend/package.json`
- `frontend/nuxt.config.ts`
- `frontend/public/.htaccess`
