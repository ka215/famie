# ステージングの運用・デプロイ

## 位置付けと配置対象

ステージングは本番リリース前の常設検証環境。通常は `dev` の固定コミットを配置し、実装中の実機確認に限り `feature/*` / `hotfix/*` の固定コミットも許可する。ブランチ名・短縮SHA・未コミット資材は配置指定に使用しない。作業ブランチはGitHubへpushしてから完全な40桁SHAを指定する。

1. 作業ブランチでテスト・静的生成物の準備を行い、コミットする。
2. `dev` へPRで取り込み、固定コミットをステージングへ配置して検証する。検証中は別の候補で上書きしない。
3. 合格した `dev` を `main` へPRで取り込み、正式タグを作る。
4. **正式タグをステージングへ配置して最終確認する。** `main`への取り込みでSHAが変わるため、devの確認だけで同一コミット確認としない。
5. 同じタグ・コミット・コミット済み生成物を本番へ配置する。最終確認後は再ビルドしない。

ステージングはキャッシュや既存データを保持する。通常配置ではDB初期化・UserSeeder・APP_KEY再生成を行わない。データリセットやPWAサイトデータ削除は調査手順として別途合意・記録する。本番データを検証環境へコピーしない。

## 環境の固定値

| 項目 | production（既定） | staging |
|---|---|---|
| リポジトリ | `~/famie` | `~/famie-stg` |
| 公開先 | `~/public_html/famie.ka2.org` | `~/public_html/stg-famie.ka2.org` |
| URL | `https://famie.ka2.org` | `https://stg-famie.ka2.org` |
| DB・ユーザー・スキーマ | `ka2_famie` | `ka2_famiestg` |
| バックアップ・ロックの親 | `~/famie-release-backups/releases` | `~/famie-stg-release-backups/releases` |
| 配置指定 | `origin/main` と一致する正式タグ | `--ref` で完全SHAまたは正式タグ |

両環境とも `APP_ENV=production`、`APP_DEBUG=false`、PostgreSQLの `localhost:5432`。DB_SCHEMAを必ず指定し、DB_URLは使わない。URLは末尾スラッシュなし。`.env`と有効な設定（configキャッシュを含む）、実際に接続したDB・ユーザー・スキーマを照合する。ステージングは独自のAPP_KEY・cookie名を保持し、SESSION_DOMAINで本番とcookieを共有しない。

配置パス・APIリンク・バックアップパスの逸脱、環境不一致、不正なref、未コミット変更、停止済み状態、同一環境での二重実行は拒否する。両環境のロックは独立する。ステージングの正式タグも `origin/main` と同一コミットで、packageバージョンが `version.json.next` に準備済みであることを要求する。開発SHAではpackageがcurrentの段階も認め、候補名にnextとSHAを記録する。

## スクリプト設置と本番入口の刷新

`deploy.sh` と `check-deploy-environment.php` を同じ固定版ディレクトリへ設置する。チェック用PHPを忘れると停止する。サーバーは `ssh s140.coreserver.jp`。非対話SSHでは `~/bin` がPATHにないため、次を明示する。

```sh
export PATH="$HOME/bin:$PATH"
```

初回はレビュー・テスト済みコミットから2ファイルを取り出し、`~/famie-deploy-tools/<完全SHA>/` に設置する。`bash -n deploy.sh` と `php84cli -l check-deploy-environment.php` を確認する。既存の本番入口を変更せず、新版をステージングで試す。

v0.4.0公開直前に次を実施する。

1. 実行中のデプロイがないことを確認し、現行 `~/famie-deploy.sh` と `~/famie-deploy-cli.sh` の存在するものをprivateな退避先へ保存する。
2. 検証済み固定版の `deploy.sh` を指す `~/famie-deploy.sh.new` シンボリックリンクを作り、構文確認後に `mv -T` で `~/famie-deploy.sh` へ切り替える。スクリプトはリンクの実体を基準に同じ版のPHPを読む。
3. 同じコミットの `deploy-cli.sh` を `~/famie-deploy-cli.sh.new` へ設置・構文確認し、`mv` で旧名入口へ切り替える。旧名は隣の `famie-deploy.sh` に転送するだけにする。
4. `--help` で両入口を確認。本番のメンテナンス資産・Apache管理ブロックを[共通手順](release-script-notes.md)どおり準備し、本番向け事前確認を実行する。
5. 以降は `~/famie-deploy.sh` を標準入口とする。スクリプト設置だけではアプリ・DBは更新しない。

ステージングに配置された作業ツリーから「その時の最新版」をコピーせず、必ず検証済みコミットを指定する。移行完了までは固定版ディレクトリのスクリプトを直接実行する。本番入口の切り替えは今回のステージング移行と分ける。

## 通常配置

以下の `SCRIPT_SHA` / `REF` / `DB_BACKUP` は確認した値に置き換える。バックアップを用意するまでは事前確認だけ実行できる。

```sh
export PATH="$HOME/bin:$PATH"
SCRIPT_SHA='<スクリプトの検証済み完全SHA>'
DEPLOY="$HOME/famie-deploy-tools/$SCRIPT_SHA/deploy.sh"
REF='<配置する完全SHA、またはv0.4.0のような正式タグ>'
bash "$DEPLOY" --env staging --ref "$REF"

# 専用DBのバックアップを取得し、pg_restore --list等で読み取り確認する。
DB_BACKUP='<ka2_famiestgのバックアップファイルまたは管理画面の識別情報>'
bash "$DEPLOY" --env staging --ref "$REF" --apply --db-backup "$DB_BACKUP"
```

バックアップの実在・取得対象・復元可能性は実行者が確認する。パスワードは引数・ログへ記載せず、管理画面や保護された接続設定を使う。既定PHP CLIは `/usr/local/bin/php84cli`、Composerは `~/bin/composer.phar`。

更新順は両環境共通: 対象固定・環境確認 → バックアップ → `.maintenance` 設置・Laravel稼働中の静的HTML/API503確認 → Laravel停止・503再確認 → checkout → Composer → キャッシュ解除・環境再確認 → migration → CategorySeeder → optimize → 静的資材配置 → 設定保持・停止確認 → 解除 → HTTP確認。APIのJSONは静的 `maintenance.json` と完全一致することを確認する。公開権限はrsyncの `-a --chmod=D705,F604` で適用する。`umask 077` 下で `-r --chmod` だけに置き換えない。

HTTP確認は画面200、入力不正ログイン422、status API200に加え、入口JS/CSS・SW・manifestの200も確認する。失敗時はメンテナンスへ戻る。全ログはバックアップの `deploy.log` に直接保存し、終了時に末尾と保存先を表示する。別のSSH接続で `tail -f` できる。環境、配置元、前後コミット、DBバックアップ参照、実行スクリプトも保存する。

自動確認後はログイン・記録・表示名・カード開閉など変更範囲を確認する。PWA変更時は[PWA手順](pwa-verification-notes.md)に従い、キャッシュを消さず実機確認する。Issueへ環境・SHA/タグ・日時・結果を記録する。本番公開結果は `docs/operations/change-log/` に記録する。

## アーカイブ配置からGit管理への初回移行

この工程は実施済み環境では繰り返さない。旧ディレクトリにある一時資材も含めて退避し、新しいGit管理領域へ不要ファイルを持ち込まない。

1. 現行フロントに対応する完全SHAを特定しGitHubへpush。兄弟ディレクトリ `~/famie-stg-git-<識別子>` へcloneし、そのSHAをdetached HEADでcheckoutする。
2. 候補と既存の絶対パス・APIリンクを検証。候補ルート、backend、backend/publicを705、public内の入口PHP・.htaccessを604とする。秘密ファイルの権限を一括で緩めない。
3. privateな `~/famie-stg-release-backups/initial-<識別子>/` を作る。公開フラグと `artisan down` で停止し、専用DBのダンプを取得・読み取り確認する。フロントもAPIリンクをたどらず退避する。
4. 既存backendから候補へ `.env`（600）、`vendor/`、`storage/`、`bootstrap/cache/` を保持してコピーする。`.env`の一致、候補のgit statusがクリーンであることを確認する。
5. 対象パスが想定どおりであることを再確認し、旧 `~/famie-stg` をprivateバックアップの `original-app` へ移動、候補を `~/famie-stg` へ移動する。旧領域は700にする。APIリンクの文字列は変えない。
6. 環境ガードでDB・URL・スキーマを照合し、同じDBを参照することを確認する。`artisan up`、公開フラグ退避、HTTP確認を行う。失敗したらフラグを戻し、原因解消まで停止を維持する。
7. 統合deployの事前確認と実配置を行う。`.env`の一致と、ユーザー・活動記録・認証トークンなどの継続性を確認する。初期アカウント情報は旧領域の退避先で管理し、Git管理領域へ戻さない。

移行途中に中断した場合は、既存・候補・退避先の所在を確認する。新領域へ切り替えた後の移行取り消しは、新領域を別の退避先へ移動して旧 `original-app` を元のパスへ戻す。旧ルートは705へ戻す。DBを変更していなければDB復元は不要。公開フラグは、環境確認と稼働確認を済ませるまで維持する。

## 通常配置の失敗復旧

[共通復旧手順](release-script-notes.md#4-失敗時の復旧)の `repo`・公開先・バックアップ・DBを上表の環境値に読み替える。バックアップの `environment` と `release-commit` を確認し、異なる環境のバックアップを使わない。

- エラーがcheckout前なら現行アプリ・DBの状態を確認し、原因を解消して再開する。
- checkout後はログの段階とmigrationの実施状況を確認。旧コードとDBが互換なら記録された `previous-commit`、その依存関係、退避フロントへ戻す。互換性がなければDB復元の判断が必要。自動でmigrationを巻き戻さない。
- `.env`・APP_KEY・APIリンク・IP制限を保持し、環境ガードと停止中HTTP確認を行ってから解除する。
- SSH切断や強制終了でtrapが走らない場合は、公開フラグ・Laravel停止状態・ログ・実プロセスを確認する。ロックはデプロイプロセスが存在しないことを確認した後に空ディレクトリを `rmdir` する。
- 解除後の画面・API・入口JS/CSSを確認し、失敗したら再び停止する。状態未確認でスクリプトを再実行しない。

## #11 検証記録（2026-09-24）

- ステージングを `2add4346f2dc1e70efa08ebe2366a756e9c652c9` のGit checkoutへ移行。`.env`のバイト一致を確認。DBダンプと旧領域は `~/famie-stg-release-backups/initial-issue11/` へprivate保存。
- 統合スクリプトの事前確認と実配置が成功。migration追加なし、CategorySeeder、キャッシュ、静的配信、解除後の画面/API/入口資材を確認。
- 初回実配置でComposer実行中の中断とロック残存を検出。単独Composerが成功することとプロセス不在、migration状況を確認して復旧。中断原因は断定せず、ログをプロセス置換のteeから直接ファイル保存へ変更し、再実行の正常終了を確認。
- 環境切り替え、11件の入力拒否、dev/feature/正式タグ、対象外コミット・タグずれ・バージョン不整合を隔離Gitで確認。
- 実サーバーで既存バージョン6ケース、停止復旧5ケース、静的403拒否、直接ログ保存時の異常終了、POSIX705/604・privateバックアップ・保護対象保持を確認。
- 実DBへの読み取り確認、10種類の.env不一致、古いconfigキャッシュ、本番との取り違えを確認。テストで本番DB・アプリ・旧入口は変更していない。
- 移行前後のダンプから `users`・`activity_logs`・`personal_access_tokens` を読み出し、pg_restoreのランダムなrestrict識別子だけを除外して内容一致を確認。ユーザー・記録・認証トークンを維持。本番コミットも `30e9826` のまま。
