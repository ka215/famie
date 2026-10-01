# フロントエンドのリリース手順

## 1. 目的と構成

Nuxt SPA をGitHub Actionsで静的生成し、検証済み成果物をCoreServerへ配置する。CoreServerではNode.jsを起動せず、Apacheが静的ファイルを配信する。

| 項目 | 値 |
| --- | --- |
| 公開 URL | `https://famie.ka2.org` |
| サーバー上のアプリ | `~/famie` |
| 静的生成物 | GitHub Actionsのリリース成果物内 `frontend/public/` |
| ドキュメントルート | `~/public_html/famie.ka2.org/` |
| API 公開パス | `~/public_html/famie.ka2.org/api` → `~/famie/backend/public` |

`frontend/.output` はGit管理しない。CIが対象コミットから一度だけ生成し、SHA256で固定した成果物をステージングと商用で共用する。

## 2. 前提条件

- バックエンドの依存関係、`.env`、DB migration の初回設定が完了している。
- CoreServer 上の `~/famie` が配置先として初期化済みで、永続化する設定・storageを保持できる。
- ローカル環境でPowerShell、Node.js、pnpm、Git、GitHub CLI、jqを利用できる。
- 通常リリースでは作業ブランチから`prepare-release.ps1`を開始できる。事前に`main`へ直接pushしない。
- `famie.ka2.org` のAレコードがCoreServerのIPアドレスを向いている。
- CoreServerのサイト設定で `famie.ka2.org` の無料SSLが有効であり、同FQDNをSANに含む証明書が配信されている。
- `.htaccess`のアクセス設定が対象環境に合っている。v0.9.0以降の商用はIP制限を解除し、ステージングは許可リストを維持する。[IP制限変更手順](ip-access-notes.md)を参照する。
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

リポジトリ管理下の`frontend/public/.htaccess`を基に配置する。ステージングなどIP制限有効環境だけ、`RewriteEngine On`より前にApache 2.4のアクセス制限を追加する。許可IPは`backend/.env`の`ALLOWED_IPS`と同じ値にする。商用では以下の`<RequireAny>`ブロックを追加しない。既存商用設定からの解除は[専用手順](ip-access-notes.md)に従い、上位・API側の残存制限も確認する。

```sh
cp "$HOME/famie/frontend/public/.htaccess" \
  "$HOME/public_html/famie.ka2.org/.htaccess"
chmod 604 "$HOME/public_html/famie.ka2.org/.htaccess"
```

```apacheconf
Options -MultiViews

<RequireAny>
    Require ip <ALLOWED_IP_ADDRESS_1>
    Require ip <ALLOWED_IP_ADDRESS_2>
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

IP制限有効環境で許可IPが未設定の場合は`<RequireAny>`の代わりに`Require all denied`を設定する。設定後は通常リリースで`.htaccess`を上書きしない。上の例はルーティングの説明用であり、配布済みのメンテナンスブロック等を省略して実環境へ上書きしない。

API のリンクも初回だけ作成する。

```sh
REPO_DIR="$HOME/famie"
DOCROOT="$HOME/public_html/famie.ka2.org"

test ! -e "$DOCROOT/api" || exit 1
ln -s "$REPO_DIR/backend/public" "$DOCROOT/api"
test "$(readlink "$DOCROOT/api")" = "$REPO_DIR/backend/public" || exit 1
```

## 6. 初期構築後の確認

商用は従来の許可外IPからも、ステージングは許可IPから確認する。

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

- `/`と`/login/`が`200`でHTMLを返す。`/login`はApache設定に応じたリダイレクト後も正常表示できる。
- ログイン API が HTML ではなく JSON を返す。空データなので `422` が正常である。
- 実アカウントでログイン、ログアウトできる。
- ブラウザの開発者ツールで JavaScript、CSS、manifest、Service Worker に `404` がない。
- ステージングでは許可外IPから画面とAPIが403になる。商用ではIPによる403にならず、未認証の保護APIが401になる。

通常リリース時のHTTP、MIME、API、ブラウザ検査は配置workflowが実行する。

## 7. ロールバック

[通常リリースの自動化](./release-script-notes.md)の「切り戻し」に従い、公開済みの同一成果物からフロントとバックを同じリリース状態へ戻す。DB変更との互換性を先に確認する。

## 8. 関連文書

- [バックエンドのリリース手順](./howto_release_backend.md)
- `frontend/package.json`
- `frontend/nuxt.config.ts`
- `frontend/public/.htaccess`
