# フロントエンドのリリース手順

## 1. 目的と構成

Nuxt SPA をGitHub Actionsで静的生成し、検証済み成果物をCoreServerへ配置する。CoreServerではNode.jsを起動せず、Apacheが静的ファイルを配信する。

| 項目 | 値 |
| --- | --- |
| 公開 URL | `https://famie.ka2.org` |
| サーバー上のリポジトリ | `~/famie` |
| 静的生成物 | GitHub Actionsのリリース成果物内 `frontend/public/` |
| ドキュメントルート | `~/public_html/famie.ka2.org/` |
| API 公開パス | `~/public_html/famie.ka2.org/api` → `~/famie/backend/public` |

`frontend/.output` はGit管理しない。CIが対象コミットから一度だけ生成し、SHA256で固定した成果物をステージングと商用で共用する。

## 2. 前提条件

- バックエンドの依存関係、`.env`、DB migration の初回設定が完了している。
- CoreServer 上の `~/famie` にリポジトリが clone 済みである。
- ローカル環境でPowerShell、Node.js、pnpm、Git、GitHub CLI、jqを利用できる。
- リリース対象コミットが `main` に push 済みである。
- `famie.ka2.org` のAレコードがCoreServerのIPアドレスを向いている。
- CoreServerのサイト設定で `famie.ka2.org` の無料SSLが有効であり、同FQDNをSANに含む証明書が配信されている。
- `~/public_html/famie.ka2.org/.htaccess` に本番用 IP 許可リストが設定済みである。
- CoreServer でドキュメントルートから `~/famie/backend/public` へのシンボリックリンクを利用できることを確認済みである。

### 2.1 初回のSSL設定

CoreServer V1のコントロールパネルで、設定済みサイト `famie.ka2.org` の設定変更を開き、「無料SSL」を選択して保存する。DNSのAレコードがCoreServerを向いていない状態では証明書を取得できない。

設定後、数分待ってから証明書を確認する。

```sh
openssl s_client -connect famie.ka2.org:443 \
  -servername famie.ka2.org </dev/null 2>/dev/null \
  | openssl x509 -noout -subject -issuer -dates -ext subjectAltName
curl -I https://famie.ka2.org/
```

`subjectAltName` に `DNS:famie.ka2.org` または同ドメインを包含するワイルドカード名があり、`curl` が証明書エラーにならないことを確認する。`CN=*.coreserver.jp` の証明書が返る場合は、独自ドメイン用SSLがまだ割り当てられていないため、アプリの確認へ進まない。

## 3. ローカルでの生成と検証

[通常リリースの自動化](./release-script-notes.md)に従い、作業ブランチで `prepare-release.ps1` を実行する。ローカル検証後にPRを `dev` へマージし、CIで成果物を生成する。`main`へ直接pushしない。

## 4. CoreServer への配置

[通常リリースの自動化](./release-script-notes.md)の配置workflowを使用する。フロントとバックを同一成果物から更新し、`.htaccess`と`api`リンクを保持する。

## 5. 初回のみ行う Apache 設定

生成物の `.htaccess` を配置し、`RewriteEngine On` より前に Apache 2.4 のアクセス制限を追加する。許可 IP は `backend/.env` の `ALLOWED_IPS` と同じ値にする。

```sh
cp "$HOME/famie/frontend/.output/public/.htaccess" \
  "$HOME/public_html/famie.ka2.org/.htaccess"
chmod 604 "$HOME/public_html/famie.ka2.org/.htaccess"
```

```apacheconf
Options -MultiViews

<RequireAny>
    Require ip 202.172.28.141
    Require ip 202.172.30.215
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
curl -I https://famie.ka2.org/login/
curl -i \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -d '{}' \
  https://famie.ka2.org/api/v1/auth/login
```

次をすべて満たすことを確認する。

- `/` が `200`、`/login` が `/login/` への `301`、`/login/` が `200` で HTML を返す。
- ログイン API が HTML ではなく JSON を返す。空データなので `422` が正常である。
- 実アカウントでログイン、ログアウトできる。
- ブラウザの開発者ツールで JavaScript、CSS、manifest、Service Worker に `404` がない。
- 許可していない IP からは画面と API の両方が `403` になる。

## 7. ロールバック

[通常リリースの自動化](./release-script-notes.md) の「失敗時の復旧」に従い、バックエンドのコミットとフロントの資材を同じリリース状態へ戻す。DB変更との互換性を先に確認する。

## 8. 関連文書

- [バックエンドのリリース手順](./howto_release_backend.md)
- `frontend/package.json`
- `frontend/nuxt.config.ts`
- `frontend/public/.htaccess`
