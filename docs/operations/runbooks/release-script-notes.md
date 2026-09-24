# 通常リリースの自動化

## 対象と前提

v0.4.0の追加対応から `deploy.sh --env production|staging` で環境を選択する。既定はproduction。ステージングの固定SHA・正式タグ指定、環境一覧、初回Git移行、旧入口刷新のタイミングは[ステージング運用手順](staging-deploy-notes.md)を参照する。

`deploy.sh` と同じ版の `check-deploy-environment.php` を同じディレクトリに設置する。両環境で `.env`・有効な設定・実接続先のDB/スキーマ/ユーザー・URLを照合する。非対話SSHでは `export PATH="$HOME/bin:$PATH"` を明示する。ステージングで正式タグを最終確認してから、再ビルドせず同じタグを本番へ配置する。

v0.3.0 以降の通常更新は、ルートの `version.json` を正本とし、フロント・バック共通のタグを自動決定して行う。[ブランチ運用ルール](../../development/decisions/2026-09-23-branch-strategy.md)に従い、作業ブランチ → `dev` → `main` の PR を経由する。

初回のDB・SSL・Apache・`.env` の設定は既存手順で完了させておく。CoreServer には Bash、Git、PHP CLI、Composer の PHP ファイル（phar）、rsync、curl、jq、realpath が必要。サーバー自身から公開 URL への接続も IP 許可リストに含める。`jq` が未導入の場合は先に用意する。

## 0. バージョンの管理ルール

`version.json` の `current` は開発サイクルの基準となる公開済みバージョン、`next` は今回のリリース対象。先頭ゼロのない安定版の `X.Y.Z` 形式を使用し、`next > current` を必須とする。プレリリース識別子は対象外。

| 段階 | current / next の例 | backend・frontend の package.json |
| --- | --- | --- |
| v0.3.0 の開発開始 | 0.2.0 / 0.3.0 | 両方 0.2.0 |
| 準備スクリプト実行 | 同上 | 両方を 0.3.0 に自動更新 |
| PR・タグ作成・配置 | 同上 | 両方 0.3.0 |
| 公開確認後の次版開発開始 | 0.3.0 / 次版 | 両方 0.3.0 |

準備時、両 package が `current` なら `next` に更新する。両方が `next` なら再実行として検証・生成を許可する。両者の不一致、想定外の値、不正な形式、`next <= current` は処理開始前に停止する。失敗時も更新済みの package は `next` のまま保持し、原因解消後に再実行する。`version.json` 自体はスクリプトで自動更新しない。

**更新タイミング：** 公開の動作確認後、`main` を `dev` に同期して次の作業ブランチを作成した時点で、`current` を公開した版、`next` を合意した次版へ更新する。この変更も通常の PR に含める。現リリースの準備から公開確認完了までは値を固定する。緊急修正でも公開中の版を `current`、新しいパッチ版を `next` として hotfix ブランチで更新し、後で `dev` に反映する。

Git 上の `current` は本番稼働状況の自動記録ではない。実際に配置した版はタグ・コミットと `docs/operations/change-log/` の記録で判断する。ロールバックしても既存タグは付け替えず、復旧履歴を記録する。

## 1. 作業ブランチで生成物を準備（Windows）

[E2E テストの前提](../../development/knowledge/e2e-test-notes.md)を満たし、ブラウザをインストールしてから実行する。

同じ `frontend` で起動中の `pnpm dev` は先に終了する。Nuxt のプロジェクト単位の起動ロックにより、E2E 用サーバーはポートが異なっても競合する。

```powershell
Set-Location C:\xampp\htdocs\famie
./scripts/release/prepare.ps1
```

最初に `jq` でバージョンを検証して両 package を更新し、依存関係の固定インストール、Lint、型チェック、E2E、既存 `.output` の削除、静的生成、必須資材の存在確認を順に行う。失敗時は中断する。削除対象はリポジトリ直下の `frontend/.output` に固定し、リンクの場合は拒否する。

成功後、ページャーを使わず差分集計と未追跡ファイルを含む状態を表示する。既定では最後に commit・push・PR 作成を行うか確認する。`y` を選ぶと、表示された Git 管理対象の変更すべて（ignore 対象を除く新規ファイルも含む）を1コミットにまとめ、作業ブランチへ push し、`gh` で PR を作成する。無関係な変更があれば `N` を選んで整理する。課題ごとのコミットはこの最終準備の前に分けておく。

```powershell
# 既定：最後に確認。空入力は公開しない。
./scripts/release/prepare.ps1
# 確認を含め完全に非対話、準備のみ
./scripts/release/prepare.ps1 -PublishMode Skip
# 全変更のコミット・push・PR 作成までを明示的に指定
./scripts/release/prepare.ps1 -PublishMode Publish
```

公開処理には認証済みの `gh` と `origin` への push 権限が必要。`feature/*` は `dev`、`hotfix/*` は `main` 宛てに PR を作る。同じブランチの PR があれば再利用する。失敗時はその時点で停止し、作成済みコミットや push を取り消さない。PR のマージ・本番配置は行わない。`dev`、`main` への PR が完了したら `main` の対象コミットへ `v0.3.0` タグを付けてリモートへ反映する。

改行は `.gitattributes` で原則 LF（bat/cmd は CRLF）とし、VS Code の `files.eol` も LF に揃える。IDE の設定だけではビルド生成物や Git の変換方針は制御できない。

`-CommitMessage` と `-PrTitle` が未指定なら、`next` から `chore: prepare v0.3.0` と `release: v0.3.0` のように生成する。明示指定も可能。公開直前にも両 package と準備対象のバージョンを再検証する。

### Windows のビルド警告

`pnpm build` は `nuxt generate` を呼ぶ。Nuxt 4.5.2 / Nitro 2 の Windows 環境では `cache-driver.mjs` の file URL 解決警告が報告されている。[上流 Issue #36278](https://github.com/nuxt/nuxt/issues/36278) では Nuxt 5 / Nitro 3 で解消と説明されている。併発する H3 の未使用 import 警告は依存パッケージ内の未使用参照に関するもの。

今回の静的生成は成功しており、本番には Nitro サーバーを配置しないため、直ちにリリース失敗を意味しない。SSR サーバー配信へ変更する場合は unresolved import を再評価する。解消には上流修正の適用・対応バージョンへの移行を検証する。メジャー更新や警告の一律抑制は本対応に含めない。

## 2. サーバーで事前確認

初回は、PR で確認済みの `scripts/release/deploy.sh` を SCP などで `~/famie-deploy.sh` に転送する。実行中にリポジトリが切り替わるため、常にリポジトリ外のコピーを使う。自動化導入前のサーバーで、スクリプト取得だけを目的に稼働中リポジトリを更新しない。

```sh
bash "$HOME/famie-deploy.sh"
```

既定は事前確認のみ。作業ツリー、配置先、APIリンク、メンテナンス状態、生成物、稼働中の production/debug 設定を確認する。Git の取得とロック用ディレクトリ作成は行うが、アプリ配置・DB変更は行わない。

取得した `origin/main` のコミットを固定し、そのコミット内の `version.json` と両 package を読み、両 package が `next` であることを検証する。`v<next>` タグが同じコミットを指す場合だけ配置できる。タグは事前に作成・push しておく。サーバーの古い作業ツリーの値は使わない。従来のタグ引数も利用可能だが、自動決定値と異なれば停止する。過去版への復旧は後述の復旧手順を使う。

DB migration の内容を PR で確認し、CoreServer 管理画面からDBバックアップを取得する。事前確認はタグの migration を試行するものではない。配置対象のタグ・コミット、バックアップの識別情報を記録する。

## 2-A. v0.4.0 のメンテナンス表示を初回導入

Apache の `mod_rewrite` / `mod_headers` と `AllowOverride FileInfo` が必要。静的な HTML / JSON を Apache が503で返すため、Composer 更新中も Laravel の起動に依存しない。許可IPは既存の Apache 設定を維持する。Laravel のみを停止した場合もAPIは同じ `code: maintenance` の503を返す。

1. レビュー対象の `frontend/public/maintenance.html` と `maintenance.json` を公開先へ転送し、ファイル権限を604にする。初回は稼働中の Git checkout を変更せず、SCP等で個別に転送する。
2. 本番 `.htaccess` を非公開ディレクトリへバックアップする。`frontend/public/.htaccess` の `# BEGIN FAMIE MAINTENANCE` ～ `# END FAMIE MAINTENANCE` を、既存の `RewriteEngine On` 直後（API除外・静的ファイル除外・SPA書き換えより前）に追加する。既存のIP制限・PHP設定・その他の行は保持し、ファイル全体を上書きしない。`.htaccess` は604を維持する。
3. `.maintenance` がない状態で通常画面、API、許可外IPの403を確認する。設定に誤りがあればバックアップから `.htaccess` を戻す。
4. 初回のメンテナンス開始・解除確認は、検証用ホストか合意した停止時間に行う。公開先に空の `.maintenance` を作ると、画面にHTMLの503、`/api/*` にJSONの503を返す。両方に `Cache-Control: no-store`、`Retry-After: 60` が付き、許可外IPは403のままとなる。

初期導入後、デプロイの事前確認は対象タグの管理ブロックと専用ページが公開先に設置済みであることを検証する。不足・不一致ならサービスを止めずに終了する。将来専用ページや管理ブロックを変更する場合も、レビュー済みの新しい内容を先に導入する。

### 開始・解除と失敗時の扱い

- 開始：公開先 `.maintenance` 作成 → 選択したPHP CLIで `artisan down --retry=60` → 公開URLのHTML/API両方の503を確認 → コード・依存関係・DB・静的資材を更新。
- 更新中は `.maintenance` を静的資材の同期・削除から除外する。本番 `.htaccess` とAPIリンクも保持する。
- 解除前：production/debug設定、本番 `.htaccess` の一致、APIリンク、HTML/API両方のメンテナンス応答を確認。
- 解除（方式A）：`artisan up` → `.maintenance` 削除 → `/` と `/login/` の200、空ログイン要求の422、`/api/v1/status` の200かつ `status: ok` を確認。
- 変更開始後の失敗、解除後のチェック失敗では `.maintenance` を再作成し、`artisan down` を再実行する。PHPが起動できなくても静的な503表示を維持する。復旧は第4節に従う。更新前の検証失敗ではメンテナンスを開始しない。

`GET /api/v1/status` はIP制限内で認証なしに復旧確認するための専用API。画面の再試行はこのGETだけを実行し、成功後に画面を読み込み直す。保存要求は自動再送しない。

### 検証とPWAの確認範囲

```powershell
# 独立したApacheと一時ドキュメントルート。本番設定には触れない。
./scripts/release/test-maintenance.ps1
# 前後の失敗、解除失敗、解除後のHTTP失敗、公開時のフラグ保持
& 'C:/Program Files/Git/bin/bash.exe' scripts/release/test-deploy.sh
```

`test-maintenance.ps1` はHTML/APIの503、GET/POST、no-store、IP制限、解除を確認する。`backend/tests/Feature/MaintenanceTest.php` はLaravel側を別途確認する。`frontend/tests/e2e/maintenance.spec.ts` はAPI応答に対する案内・再試行・認証保持・書き込み非再送・通信障害との区別を確認する。

既存E2Eは開発サーバーを使用しService Workerを無効化しているため、PWA検証の代替にはならない。専用HTML/JSONは商用ビルドのプリキャッシュ・ナビゲーションフォールバックから除外する。商用ビルドのHTTPS検証環境で次を別途確認し、端末・OS・実行版・日時・結果をIssueへ記録する。

1. 通常アクセスでService Workerとキャッシュを作成し、キャッシュを消さずにホーム画面から起動する。
2. メンテナンス開始後、新規アクセスの503と、既に開いている画面の次のAPI操作による案内を確認する。
3. 再試行でメンテナンス継続を確認する。通信を切ると「通信環境を確認」の案内となることを確認する。
4. 解除後の再試行で通常画面に戻り、認証が維持され、保存要求が再送されていないことを確認する。
5. 起動・再読み込みを繰り返し、メンテナンス専用ページがキャッシュに残り続けないことを確認する。

v0.3.0以前の画面自体には専用503を識別するコードがない。旧版を開いたままの端末への遡及適用はできないため、専用案内の受入確認は今回の版を一度読み込んでキャッシュを作成した端末で行い、旧版からの更新経路は#10で別に記録する。

## 3. 通常更新を実行

### PHP CLI・Composer の指定（全環境共通）

標準入口は `scripts/release/deploy.sh`。既定値は `PHPCLI=/usr/local/bin/php84cli`、`COMPOSER_FILE=$HOME/bin/composer.phar`。環境確認・全 Artisan・Composer・失敗時の処理に同じ PHP CLI を使用する。CGI/FastCGI は受け付けない。PHP CLI の絶対パス・実行可否・SAPI、Composer の PHP ファイル形式・実行可否を、Git 取得やメンテナンス開始より前に検証する。対話シェルの alias は使用しない。

```sh
# 既定パスで事前確認
bash "$HOME/famie-deploy.sh"
# パスが異なる場合（Composer はシェルラッパーではなく PHP ファイルを指定）
PHPCLI=/absolute/path/to/php COMPOSER_FILE=/absolute/path/to/composer.phar \
  bash "$HOME/famie-deploy.sh"
# 同じパス設定で通常更新。取得済みの DB バックアップを指定する。
PHPCLI=/usr/local/bin/php84cli COMPOSER_FILE="$HOME/bin/composer.phar" \
  bash "$HOME/famie-deploy.sh" --apply \
  --db-backup "$HOME/_db_dump/pgsql.ka2_famie.1790138083.dump"
```

公開資材は `rsync -a --chmod=D705,F604` でディレクトリ705・ファイル604にする。バックアップ保護用の `umask 077` を維持し、backend 全体の権限は変更しない。本番 `.htaccess`（IP制限を含む）と `api` リンクは保持する。

### 旧スクリプトからの移行

1. 実行中のデプロイがないことを確認する。旧 `~/famie-deploy.sh`、`~/famie-deploy-cli.sh` は存在するものだけリポジトリ外へ退避し、自動実行や手元のコマンドでどちらを使っていたか確認する。
2. ステージング検証済みの固定コミットから `deploy.sh` と `check-deploy-environment.php` を `~/famie-deploy-tools/<完全SHA>/` へ設置する。構文確認後、標準スクリプトへの `~/famie-deploy.sh.new` シンボリックリンクを作り、`mv -T` で `~/famie-deploy.sh` へ切り替える。リンクの実体と同じ版のPHPを読み込む。稼働中リポジトリの更新は不要。本番入口の刷新はv0.4.0公開直前に行う。
3. 旧名を残す必要がある場合だけ、新しい互換入口 `scripts/release/deploy-cli.sh` を `~/famie-deploy-cli.sh` に配置する。同じディレクトリの `famie-deploy.sh` を呼ぶだけで、単体では動作しない。旧版の独立した実装を残したまま使い続けない。
4. `PHPCLI` と `COMPOSER_FILE` を従来の環境に合わせ、`bash "$HOME/famie-deploy.sh"` で事前確認する。旧名を残した場合は `bash "$HOME/famie-deploy-cli.sh" --help` でも標準入口への転送を確認する。
5. 以降の手順・自動実行は `~/famie-deploy.sh` に統一する。退避した旧版を通常更新には使用しない。

旧スクリプトで配置後に 403 になり、公開先 `index.html` が 600 の場合は、メンテナンスを維持したまま以下で公開資材を再同期する（削除は行わない）。配置済みコミットが対象タグであることを先に確認する。v0.4.0の `.maintenance` がある場合は、画面200ではなく503の維持を確認し、第4節の手順で解除する。

```sh
git -C "$HOME/famie" describe --tags --exact-match HEAD
rsync -a --chmod=D705,F604 --exclude='/.htaccess' --exclude='/api' \
  "$HOME/famie/frontend/.output/public/" "$HOME/public_html/famie.ka2.org/"
curl -sS -o /dev/null -w '%{http_code}\n' https://famie.ka2.org/
curl -sS -o /dev/null -w '%{http_code}\n' https://famie.ka2.org/login/
```

旧方式（`.maintenance` 未導入）では、両方が200になったら選択したPHP CLIで `artisan up` を実行する。v0.4.0以降は第4節の順序で解除する。空のログイン要求が422になることと実アカウントでの動作を確認する。これは配置後の確認失敗からの復旧であり、完了済みの migration・seed を再実行する必要はない。

### 更新の処理順

```sh
bash "$HOME/famie-deploy.sh" --apply --db-backup '管理画面で取得したバックアップの識別情報'
```

`--apply` と `--db-backup` の両方が必要。バックアップの存在は実行者が確認する。秘密情報を引数へ入れない。

1. 二重実行をロックし、タグのコミットを固定する。
2. フロントの現行資材、直前コミット、DBバックアップ識別情報を `~/famie-release-backups/releases/<実行ID>/` へ保存する。
3. フロントとバックエンドをメンテナンス状態にし、両方の503を確認してからコードをタグのコミットへ切り替える。
4. Composer、キャッシュ更新、migration、`CategorySeeder` を実行する。
5. `.htaccess` と `api` リンクを保持して静的資材を同期する。
6. 解除前の確認後にメンテナンスを解除し、画面200・空ログイン422・復旧確認APIの200を確認する。失敗したら再度メンテナンスにする。

実行ログはバックアップ内の `deploy.log` に保存する。失敗時は処理段階、バックアップ場所、復旧に使う CLI・Composer と手順書を表示する。バックアップ作成前の失敗は端末の標準エラーで確認する。`UserSeeder`、APP_KEY 再生成、DB自動復元は行わない。サーバーの Git はタグが指すコミットへの detached HEAD となる。以後も `git pull` ではなく本スクリプトで更新する。

成功後は実アカウントでログイン・記録登録・閲覧・ログアウト、PWA 更新、許可外IPからの拒否を確認し、`docs/operations/change-log/` にタグ・コミット・結果を記録する。

## 4. 失敗時の復旧

変更開始後に失敗した場合、スクリプトはフロントとバックエンドをメンテナンス状態に保ち、失敗段階とバックアップの場所を表示する。メンテナンス再設定自体に失敗した場合は警告を出すため、公開先 `.maintenance` と実際の `storage/framework/down` も確認する。DBの自動ロールバックや、未確認のままの `artisan up` は行わない。

まずログと実際の配置状態を確認する。DB変更と旧コードに互換性がある場合は、次を実行する。`BACKUP_DIR` は実行ログに表示された絶対パスへ置き換える。

```sh
BACKUP_DIR="$HOME/famie-release-backups/releases/<実行ID>"
test -f "$BACKUP_DIR/previous-commit" || exit 1
test -f "$BACKUP_DIR/frontend/index.html" || exit 1
PREVIOUS_COMMIT=$(cat "$BACKUP_DIR/previous-commit")
# デプロイで選択した値を使用する（以下は既定値）。
PHPCLI=/usr/local/bin/php84cli
COMPOSER_FILE="$HOME/bin/composer.phar"
: > "$HOME/public_html/famie.ka2.org/.maintenance" || exit 1
cd "$HOME/famie/backend" || exit 1
"$PHPCLI" artisan down --retry=60
git -C "$HOME/famie" checkout --detach "$PREVIOUS_COMMIT" || exit 1
"$PHPCLI" "$COMPOSER_FILE" install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 1
"$PHPCLI" artisan optimize:clear || exit 1
"$PHPCLI" artisan optimize || exit 1
rsync -a --chmod=D705,F604 --delete --exclude='/.htaccess' --exclude='/api' --exclude='/.maintenance' \
  "$BACKUP_DIR/frontend/" "$HOME/public_html/famie.ka2.org/" || exit 1
# ここでは解除しない。配置・DB・APIリンク・503の確認後、下記の順で解除する。
```

復旧配置の確認後、`"$PHPCLI" artisan up` が成功したら公開先の `.maintenance` を削除し、通常更新と同じHTTPチェックを行う。失敗したら先に `.maintenance` を作り直し、`"$PHPCLI" artisan down --retry=60` を実行する。v0.3.0へ戻した場合は復旧確認APIがないため、画面200・空ログイン422・実アカウントの確認を用いる。

DB非互換の場合は修正 migration または管理画面からのDB復元が必要。旧コードだけへ戻さず、復旧方針を決めてから実行する。復旧後に通常と同じ動作確認を行う。

プロセス強制終了などで `.deploy-lock` が残った場合は、実行中プロセスがないことと配置状態を確認してから空のロックディレクトリを `rmdir` で削除する。

## 検証範囲

Windows の Git Bash がある場合は、次で構文と誤操作時の停止を検証できる。本番接続・配置は行わない。

```powershell
./scripts/release/test-guards.ps1 -Bash 'C:/Program Files/Git/bin/bash.exe'
./scripts/release/test-guards.ps1 -Bash 'C:/Program Files/Git/bin/bash.exe' -DeployScript deploy-cli.sh
& 'C:/Program Files/Git/bin/bash.exe' scripts/release/test-cli.sh
& 'C:/Program Files/Git/bin/bash.exe' scripts/release/test-deploy.sh
./scripts/release/test-publish.ps1
./scripts/release/test-version.ps1
& 'C:/Program Files/Git/bin/bash.exe' scripts/release/test-version.sh
```

`test-publish.ps1` は Git / gh / pnpm をスタブ化し、成功・既存PR再利用・commit失敗・push失敗・main拒否・バージョンからの名前生成の6ケースを検証する。実際のコミット・push・PRは作成しない。`test-version.ps1` は専用フィクスチャで更新・再実行・不正値を検証する。`test-version.sh` は `.temp/` の使い捨て Git リポジトリで古い作業ツリー、タグ未作成、タグのコミット不一致などを検証する。本体のブランチ・タグや公開環境は変更しない。

ローカルで準備スクリプト、E2E、ビルド、配置スクリプトの構文と引数による停止を確認する。CoreServer での適用・復旧は本番実行前に検証が必要。iPhone 実機確認も別途行う。

### POSIX 権限の確認（#6 の完了に必須）

`test-deploy.sh` は本番へ接続せず、一時ディレクトリ内で標準スクリプトの公開処理を実行する。PHP はスタブを使い、事前失敗・変更開始後の失敗・メンテナンス再設定失敗・解除後のHTTP失敗・解除失敗の5ケースも確認する。Windows Git Bash ではこの5ケースの後、権限検証をスキップして終了コード2を返す。これは検証完了を意味しない。

Linux / CoreServer の POSIX ファイルシステム上で、レビュー対象の `deploy.sh` と `test-deploy.sh` を専用ディレクトリに転送し、実行する。Bash・rsync・GNU stat が必要。アプリ配置・DB・本番ドキュメントルートには触れず、一時ディレクトリは結果確認用に残す。

```sh
bash /path/to/verification/test-deploy.sh
```

終了コード0と `PASS: POSIX public modes 705/604...` を確認する。公開権限・バックアップの保護・backendの権限・本番相当の `.htaccess` とIP制限・APIリンクの保持・古い資材の削除を検証する。実行環境、日時、出力を Issue #6 に記録する。本番での配置結果とは別に扱う。
