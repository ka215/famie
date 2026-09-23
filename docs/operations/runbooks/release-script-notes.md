# 通常リリースの自動化

## 対象と前提

v0.3.0 以降の通常更新は、フロント・バック共通のタグを指定して行う。[ブランチ運用ルール](../../development/decisions/2026-09-23-branch-strategy.md)に従い、作業ブランチ → `dev` → `main` の PR を経由する。

初回のDB・SSL・Apache・`.env` の設定は既存手順で完了させておく。CoreServer には Bash、Git、PHP、Composer、rsync、curl、jq、realpath が必要。サーバー自身から公開 URL への接続も IP 許可リストに含める。`jq` が未導入の場合は先に用意する。

## 1. 作業ブランチで生成物を準備（Windows）

[E2E テストの前提](../../development/knowledge/e2e-test-notes.md)を満たし、ブラウザをインストールしてから実行する。

同じ `frontend` で起動中の `pnpm dev` は先に終了する。Nuxt のプロジェクト単位の起動ロックにより、E2E 用サーバーはポートが異なっても競合する。

```powershell
Set-Location C:\xampp\htdocs\famie
./scripts/release/prepare.ps1
```

依存関係の固定インストール、Lint、型チェック、E2E、既存 `.output` の削除、静的生成、必須資材の存在確認を順に行い、失敗時は中断する。削除対象はリポジトリ直下の `frontend/.output` に固定し、リンクの場合は拒否する。

成功後、ページャーを使わず差分集計と未追跡ファイルを含む状態を表示する。既定では最後に commit・push・PR 作成を行うか確認する。`y` を選ぶと、表示された Git 管理対象の変更すべて（ignore 対象を除く新規ファイルも含む）を1コミットにまとめ、作業ブランチへ push し、`gh` で PR を作成する。無関係な変更があれば `N` を選んで整理する。課題ごとのコミットはこの最終準備の前に分けておく。

```powershell
# 既定：最後に確認。空入力は公開しない。
./scripts/release/prepare.ps1 -CommitMessage 'chore: prepare v0.3.0' -PrTitle 'v0.3.0'
# 確認を含め完全に非対話、準備のみ
./scripts/release/prepare.ps1 -PublishMode Skip
# 全変更のコミット・push・PR 作成までを明示的に指定
./scripts/release/prepare.ps1 -PublishMode Publish -CommitMessage 'chore: prepare v0.3.0' -PrTitle 'v0.3.0'
```

公開処理には認証済みの `gh` と `origin` への push 権限が必要。`feature/*` は `dev`、`hotfix/*` は `main` 宛てに PR を作る。同じブランチの PR があれば再利用する。失敗時はその時点で停止し、作成済みコミットや push を取り消さない。PR のマージ・本番配置は行わない。`dev`、`main` への PR が完了したら `main` の対象コミットへ `v0.3.0` タグを付けてリモートへ反映する。

改行は `.gitattributes` で原則 LF（bat/cmd は CRLF）とし、VS Code の `files.eol` も LF に揃える。IDE の設定だけではビルド生成物や Git の変換方針は制御できない。

### Windows のビルド警告

`pnpm build` は `nuxt generate` を呼ぶ。Nuxt 4.5.2 / Nitro 2 の Windows 環境では `cache-driver.mjs` の file URL 解決警告が報告されている。[上流 Issue #36278](https://github.com/nuxt/nuxt/issues/36278) では Nuxt 5 / Nitro 3 で解消と説明されている。併発する H3 の未使用 import 警告は依存パッケージ内の未使用参照に関するもの。

今回の静的生成は成功しており、本番には Nitro サーバーを配置しないため、直ちにリリース失敗を意味しない。SSR サーバー配信へ変更する場合は unresolved import を再評価する。解消には上流修正の適用・対応バージョンへの移行を検証する。メジャー更新や警告の一律抑制は本対応に含めない。

## 2. サーバーで事前確認

初回は、PR で確認済みの `scripts/release/deploy.sh` を SCP などで `~/famie-deploy.sh` に転送する。実行中にリポジトリが切り替わるため、常にリポジトリ外のコピーを使う。自動化導入前のサーバーで、スクリプト取得だけを目的に稼働中リポジトリを更新しない。

```sh
bash "$HOME/famie-deploy.sh" v0.3.0
```

既定は事前確認のみ。作業ツリー、配置先、APIリンク、メンテナンス状態、タグと `origin/main` の関係、タグ内の生成物、稼働中の production/debug 設定を確認する。Git の取得とロック用ディレクトリ作成は行うが、アプリ配置・DB変更は行わない。

DB migration の内容を PR で確認し、CoreServer 管理画面からDBバックアップを取得する。事前確認はタグの migration を試行するものではない。配置対象のタグ・コミット、バックアップの識別情報を記録する。

## 3. 通常更新を実行

```sh
bash "$HOME/famie-deploy.sh" v0.3.0 --apply --db-backup '管理画面で取得したバックアップの識別情報'
```

`--apply` と `--db-backup` の両方が必要。バックアップの存在は実行者が確認する。秘密情報を引数へ入れない。

1. 二重実行をロックし、タグのコミットを固定する。
2. フロントの現行資材、直前コミット、DBバックアップ識別情報を `~/famie-release-backups/releases/<実行ID>/` へ保存する。
3. バックエンドをメンテナンス状態にしてからコードをタグのコミットへ切り替える。
4. Composer、キャッシュ更新、migration、`CategorySeeder` を実行する。
5. `.htaccess` と `api` リンクを保持して静的資材を同期する。
6. メンテナンスを解除し、画面の HTTP 200 と、空のログイン要求に対する JSON の HTTP 422 を確認する。

実行ログはバックアップ内の `deploy.log` に保存する。`UserSeeder`、APP_KEY 再生成、DB自動復元は行わない。サーバーの Git はタグが指すコミットへの detached HEAD となる。以後も `git pull` ではなく本スクリプトで更新する。

成功後は実アカウントでログイン・記録登録・閲覧・ログアウト、PWA 更新、許可外IPからの拒否を確認し、`docs/operations/change-log/` にタグ・コミット・結果を記録する。

## 4. 失敗時の復旧

変更開始後に失敗した場合、スクリプトはバックエンドをメンテナンス状態に保ち、バックアップの場所を表示する。DBの自動ロールバックや、未確認のままの `artisan up` は行わない。

まずログと実際の配置状態を確認する。DB変更と旧コードに互換性がある場合は、次を実行する。`BACKUP_DIR` は実行ログに表示された絶対パスへ置き換える。

```sh
BACKUP_DIR="$HOME/famie-release-backups/releases/<実行ID>"
test -f "$BACKUP_DIR/previous-commit" || exit 1
test -f "$BACKUP_DIR/frontend/index.html" || exit 1
PREVIOUS_COMMIT=$(cat "$BACKUP_DIR/previous-commit")
cd "$HOME/famie/backend" || exit 1
php artisan down --retry=60
git -C "$HOME/famie" checkout --detach "$PREVIOUS_COMMIT" || exit 1
composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction || exit 1
php artisan optimize:clear || exit 1
php artisan optimize || exit 1
rsync -a --delete --exclude='/.htaccess' --exclude='/api' \
  "$BACKUP_DIR/frontend/" "$HOME/public_html/famie.ka2.org/" || exit 1
php artisan up
```

DB非互換の場合は修正 migration または管理画面からのDB復元が必要。旧コードだけへ戻さず、復旧方針を決めてから実行する。復旧後に通常と同じ動作確認を行う。

プロセス強制終了などで `.deploy-lock` が残った場合は、実行中プロセスがないことと配置状態を確認してから空のロックディレクトリを `rmdir` で削除する。

## 検証範囲

Windows の Git Bash がある場合は、次で構文と誤操作時の停止を検証できる。本番接続・配置は行わない。

```powershell
./scripts/release/test-guards.ps1 -Bash 'C:/Program Files/Git/bin/bash.exe'
./scripts/release/test-publish.ps1
```

`test-publish.ps1` は Git / gh をスタブ化し、成功・既存PR再利用・commit失敗・push失敗・main拒否の5ケースを検証する。実際のコミット・push・PRは作成しない。

ローカルで準備スクリプト、E2E、ビルド、配置スクリプトの構文と引数による停止を確認する。CoreServer での適用・復旧は本番実行前に検証が必要。iPhone 実機確認も別途行う。
