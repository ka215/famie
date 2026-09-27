# リリース自動化仕様

## 1. 目的

本仕様は、famieの通常リリースと緊急修正を、再現可能な成果物、明確な状態遷移、機械的な検証、復旧可能なバックアップによって安全に運用するための正本とする。

v0.7.0では次を実現する。

- リリース事前準備、デプロイ＆リリース、リリース後処理の3つの代表スクリプトで通常リリースを完了できる。
- ステージングで合格した同一成果物を、再ビルドせず本番へ配置する。
- アプリのみ、またはDBを含む切り戻しを代表スクリプトから実行できる。
- 中断後に安全に再開でき、PR、タグ、デプロイ、バックアップを重複実行しない。
- ビルド環境、HTTP 200のフォールバック、MIME不正、ブラウザconsole異常を商用配置前に検出する。

## 2. 適用範囲

対象は、Nuxt静的成果物、Laravelバックエンド、migration、DBバックアップと復元、ステージング・商用配置、GitHubのPR・Actions・タグ・Release、ブランチ操作、リリース証跡の管理とする。

アプリ機能の受入判断と実機確認そのものは自動化しない。スクリプトは確認待ちを記録し、承認前に次工程へ進まない。

## 3. 基本原則

### 3.1 Build once, promote unchanged

正式候補はGitHub ActionsのLinux runnerで一度だけ生成する。ステージングと商用は同一の成果物IDとSHA256を使う。ステージング・商用・CoreServer上ではフロントエンドを再ビルドしない。

### 3.2 成果物とGitの対応

マージコミットにより`dev`と`main`のcommit SHAが異なることは許容する。両ブランチのGit tree SHAが一致し、そのtree SHAが成果物manifestと一致することを必須とする。

### 3.3 正式タグは不変

正式タグは本番配置前に`main`へ付与する。発行済みタグは移動・上書きしない。公開後の修正は新しいパッチバージョンで行う。

### 3.4 CIを正式判定とする

ローカルフックは早期検出の補助とする。マージ・成果物生成・リリース可否の正式判定はGitHub Actionsの必須チェックで行う。`--no-verify`で回避可能なローカルフックだけを合格根拠にしない。

### 3.5 安全側で停止する

対象、状態、成果物、DB、検証結果が一意に確定できない場合は変更前に停止する。変更開始後の失敗ではメンテナンス状態、ロック、バックアップ、復旧方法を必ず記録する。

## 4. ブランチとリリース単位

通常リリースは次の順で進める。

1. `dev`から`feature/vX.Y.Z`を作成する。
2. `feature/vX.Y.Z → dev`のPRを必須チェック合格後にマージする。
3. 固定した`dev`のtreeから正式候補成果物を生成し、ステージングへ配置する。
4. ステージング自動検証とユーザー確認の合格後、`dev → main`のPRをマージする。
5. `main`と候補成果物のtree SHA一致を確認し、`vX.Y.Z`タグを発行する。
6. 同一成果物を商用へ配置する。
7. 商用検証後にGitHub Releaseを公開し、`main → dev`を同期する。

緊急修正は`main`から`hotfix/vX.Y.Z`を作成し、`main`へのPR、パッチタグ、商用配置を行う。完了後に`main → dev`を同期する。緊急修正でも成果物生成、ステージング検証、バックアップ、商用検証を省略しない。

## 5. バージョン管理

### 5.1 正本

- 公開済みバージョン：`version.json.current`
- 次回リリース候補：`version.json.next`
- アプリ表示・packageバージョン：`frontend/package.json.version`と`backend/package.json.version`
- 正式公開識別子：`vX.Y.Z`タグ

`version.json.next`は次回版未定時に`null`を許容する。次版の開発開始時に`next`を設定する。

### 5.2 状態遷移

| 状態 | `current` | `next` | package | タグ |
| --- | --- | --- | --- | --- |
| 次版未定 | 公開版 | `null` | 公開版 | 公開版あり |
| 開発中 | 公開版 | 対象版 | 公開版または対象版 | 対象版なし |
| 候補生成済み | 公開版 | 対象版 | 対象版 | 対象版なし |
| 本番配置中 | 公開版 | 対象版 | 対象版 | 対象版あり |
| 完了 | 対象版 | `null`または合意済み次版 | 対象版 | 対象版あり |

事前準備スクリプトは対象版を両packageへ反映し、その変更を候補コミットへ含める。本番準備時にはpackageを更新しない。これによりステージングと商用の成果物を同一に保つ。

## 6. GitHub Actions

### 6.1 必須チェックworkflow

PRおよび候補生成時に次を実行する。

1. 固定lockfileによる依存関係インストール
2. Lint
3. 型チェック
4. 単体テスト
5. Chromium・WebKit E2E
6. クリーンなリリースビルド
7. 成果物完全性検査
8. ブラウザ・スモークテスト

`dev`と`main`は必須チェックが成功しない限りマージできないよう、GitHubのブランチ保護またはrulesetを設定する。

### 6.2 ビルド環境

- runnerのOS、Node.js、pnpm、PHP、Composerの版をworkflowで固定する。
- 固定lockfileで依存関係を導入する。
- 新規checkoutから生成し、以前の`.nuxt`、`.output`、キャッシュを持ち込まない。
- ビルドへ渡す環境変数は許可リスト方式とする。
- `DEBUG`、`NUXT_DEBUG_PERF`、開発用API URLを除外する。
- Nuxt設定でも本番の`debug: false`を明示する。

### 6.3 成果物

単一のリリースアーカイブに、フロントエンド静的資材、バックエンド配置対象、固定された依存関係または再現可能なlockfile、migration、manifest、ファイルチェックサム一覧を含める。

成果物名は`famie-vX.Y.Z-<tree-sha>.tar.gz`形式とし、アーカイブ全体のSHA256を別ファイルで提供する。manifestには次を記録する。

```json
{
  "schema_version": 1,
  "release_version": "0.7.0",
  "source_commit": "full commit sha",
  "source_tree": "git tree sha",
  "payload_sha256": "sha256",
  "workflow_run_id": 123456789,
  "created_at": "2026-09-27T00:00:00Z",
  "frontend_version": "0.7.0",
  "backend_version": "0.7.0"
}
```

候補成果物はActions artifactとして30日以上保持する。商用配置した成果物、manifest、SHA256はGitHub Release assetとして保持する。

### 6.4 成果物検査

- 全HTMLに`@vite/client`、ローカル絶対パス、`localhost`、`node_modules`、開発専用entryがない。
- 全HTMLが参照する同一OriginのJS/CSSがアーカイブ内に存在する。
- パスにドライブ文字、バックスラッシュ、ディレクトリトラバーサルがない。
- Service Workerのプリキャッシュ対象が存在する。
- 静的サーバーで全入口JS/CSSが200かつ期待するMIMEを返す。
- Playwrightでアプリがマウントされ、主要画面が表示される。
- console errorが0件である。
- console warningは明示した許容リスト以外0件であり、`console.time`重複を許容しない。

## 7. リリースセッション

リリース単位で状態ファイルを管理する。ローカルでは`.release/sessions/vX.Y.Z.json`に置き、Git管理しない。GitHub Actionsの実行結果とサーバー側配置記録を正本として、ローカルファイルを失っても再構築できるようにする。

状態ファイルには対象版、リリース種別、現在状態、各ブランチのcommit/tree SHA、PR、workflow run、成果物、環境別配置、バックアップ、タグ、Release、同期PR、日時、ログ、ユーザー確認待ちを保持する。秘密情報は保存しない。更新は一時ファイルからの置換で原子的に行う。

状態は次の順にのみ進む。

```text
initialized
  → candidate_pr_created
  → candidate_merged
  → artifact_ready
  → staging_deployed
  → staging_verified
  → production_pr_created
  → production_merged
  → tagged
  → production_deployed
  → production_verified
  → completed
```

失敗時は直前状態と`failed_step`を保存する。再実行時はGitHub・サーバーの実状態を照合し、完了済み工程を再実行しない。

## 8. 代表スクリプト

| 役割 | 標準名 | 実行環境 |
| --- | --- | --- |
| リリース事前準備 | `scripts/release/prepare-release.ps1` | ローカルWindows |
| デプロイ＆リリース | `scripts/release/release.ps1` | ローカルWindowsからActionsを制御 |
| リリース後処理 | `scripts/release/complete-release.ps1` | ローカルWindows |
| 切り戻し | `scripts/release/rollback.ps1` | ローカルWindowsからActionsを制御 |

既存の`prepare.ps1`、`publish.ps1`、`deploy.sh`は共通処理として再利用するか、移行期間の互換入口にする。独立した同等実装を残さない。

### 8.1 共通CLI規則

- バージョンは`-Version X.Y.Z`で必須指定する。
- 変更を伴う操作は`-Apply`を必須とし、省略時は検査と実行計画表示だけを行う。
- CIや無人実行では`-NonInteractive`を指定し、確認が必要なら失敗させる。
- `-Resume`で既存セッションを再開する。
- 完了済み工程は、実状態が一致すれば成功扱いでスキップする。
- 対象環境、バージョン、SHA、tree SHA、成果物SHA256、バックアップを変更前に表示する。

### 8.2 リリース事前準備

```powershell
./scripts/release/prepare-release.ps1 -Version 0.7.0
./scripts/release/prepare-release.ps1 -Version 0.7.0 -Apply
```

責務：

1. 必要コマンド、GitHub認証、ブランチ、作業ツリー、リモート差分を検査する。
2. `feature/vX.Y.Z*`または対象hotfixであることを確認する。
3. `version.json.next`が指定版に一致し、`current < next`であることを確認する。
4. 両packageを指定版へ更新し、差分を提示する。
5. ローカルでは高速なLint、型チェック、単体テストを実行できる。正式判定はCIに委ねる。
6. 変更をコミット・pushし、`dev`または`main`へのPRを作成する。
7. 必須チェックを待機し、失敗時は停止する。
8. `-Apply`時だけPRをマージし、レビュー必須設定を迂回しない。
9. 通常リリースではマージ済み`dev`から候補成果物workflowを起動する。
10. manifestとSHA256を検証し、`artifact_ready`を記録する。

### 8.3 デプロイ＆リリース

```powershell
./scripts/release/release.ps1 -Version 0.7.0 -Environment staging
./scripts/release/release.ps1 -Version 0.7.0 -Environment staging -Apply
./scripts/release/release.ps1 -Version 0.7.0 -Environment production
./scripts/release/release.ps1 -Version 0.7.0 -Environment production -Apply
```

ステージング時の責務：

1. `artifact_ready`と成果物SHA256を検証する。
2. DBと現在の配置状態をバックアップする。
3. メンテナンスモードへ切り替える。
4. 成果物を検証用ディレクトリへ展開し、manifestとチェックサムを確認する。
5. pending migrationを一覧化し、`migrate --force`を実行する。対象なしはno-opとする。
6. 成果物を配置し、キャッシュを再生成する。
7. サーバー内部検査と外部HTTP・ブラウザ検査を実行する。
8. 合格時だけメンテナンスを解除し、`staging_verified`を記録する。
9. 失敗時はメンテナンスを維持し、切り戻しコマンドとログを表示する。

商用時の責務：

1. `staging_verified`とユーザー確認済み状態を必須とする。
2. `dev → main`のPRを作成し、必須チェックとtree差分を確認する。
3. `-Apply`時だけPRをマージする。
4. `main`のtree SHAがステージング済み成果物と一致することを確認する。
5. `vX.Y.Z`タグを発行・pushする。既存タグが異なるSHAなら停止する。
6. DBと現在の配置状態をバックアップする。
7. ステージング済みの同一成果物を商用へ配置し、再ビルドしない。
8. ステージングと同じ自動検証を実行する。
9. 合格時だけメンテナンスを解除し、`production_verified`を記録する。
10. 失敗時はメンテナンスを維持し、切り戻しコマンドとログを表示する。

### 8.4 リリース後処理

```powershell
./scripts/release/complete-release.ps1 -Version 0.7.0
./scripts/release/complete-release.ps1 -Version 0.7.0 -Apply
```

責務：

1. タグ、商用配置、全自動検証、ユーザー確認が完了していることを確認する。
2. 正式成果物、manifest、SHA256をGitHub Releaseへ添付して公開する。
3. `main → dev`同期PRを必須チェック合格後にマージする。
4. `version.json.current`を公開版へ更新し、`next`を`null`または合意済み次版へ更新する。
5. リリース記録と課題一覧を更新する。
6. 取り込み済み作業ブランチを削除する。
7. 保護期間を過ぎた候補成果物、バックアップ、セッション、ログを棚卸する。
8. GitHub、ステージング、商用の最終状態を照合し、`completed`を記録する。

商用が`production_verified`でない場合、GitHub Release公開、同期、ブランチ削除、バージョン更新を行わない。

## 9. デプロイworkflowとサーバー側処理

ローカルの代表スクリプトはGitHub Actionsのworkflow dispatchを起動し、完了まで待機する。ActionsはEnvironmentごとの承認・secretを使い、SSHでCoreServerの固定入口を呼び出す。

サーバー側は、環境・DB確認、デプロイロック、バックアップ、メンテナンス制御、成果物のSHA256検証と配置、migration、Laravelキャッシュ、内部ヘルスチェック、ログ保存だけを担当する。Gitブランチのマージ、タグ作成、フロントエンドビルドは行わない。環境固有の`.env`、`.htaccess`、APIリンク、秘密情報は成果物で上書きしない。

Actionsからのデプロイが利用不能な場合に備え、同じ成果物・manifest・SHA256を入力とする手動入口を残す。手動入口も検証と状態記録を省略しない。

## 10. migration

- 実行者がmigrationの要否を指定しない。
- 配置前にpending migrationを機械的に一覧化する。
- 毎回`migrate --force`を実行し、対象なしを正常なno-opとする。
- destructiveまたは後方互換性のないmigrationはmanifestで明示し、商用配置前の追加確認対象とする。
- アプリ切り戻し後も新DBと互換性があるかをmanifestに記録する。
- DB復元なしで安全に戻せない場合、アプリのみの切り戻しを拒否する。

## 11. 配置後検証

ステージング・商用で共通して次を検証する。

### 11.1 サーバー状態

- 配置したバージョン、成果物SHA256、manifestが期待値と一致する。
- 作業中ロック、不要なメンテナンスフラグが残っていない。
- APIリンクと環境固有設定が正しい。
- Laravelキャッシュとmigration状態が期待値と一致する。

### 11.2 HTTPと静的資産

- トップ、ログイン、設定、status API、Service Worker、manifestが正常応答する。
- 不正ログインが期待する422と検証本文を返す。
- 配信HTMLから全JS/CSSを抽出し、200と正しいContent-Typeを確認する。
- JS/CSSのレスポンスがHTML本文でない。
- 配信ファイルのハッシュが成果物内チェックサムと一致する。

### 11.3 ブラウザ

- ChromiumとWebKitでアプリのマウント完了を確認する。
- 認証前画面、認証後の代表画面、API statusを確認する。
- console errorと未知のwarningがない。
- Service Worker更新後の再読み込みが成功する。
- 旧Service Worker・キャッシュから新成果物へ更新できる。

## 12. バックアップ

デプロイ直前に、DBのcustom形式バックアップ、現在配置中の成果物IDとmanifest、環境固有ファイルの保全情報、migration一覧を取得する。

DBバックアップは、非空、所有者限定権限、SHA256、`pg_restore --list`、必須テーブル収録を検証する。

CoreServerの日次バックアップは補助とし、リリース直前バックアップの代わりにしない。最低限、現在のリリース直前、直前の正常リリース、その一つ前の正常リリースに対応する3世代を保持する。保持中の切り戻し先が参照するバックアップは削除しない。

商用配置した成果物はGitHub Release assetを正本とするため、サーバー上で同一アーカイブを無制限に複製しない。

## 13. 切り戻し

```powershell
./scripts/release/rollback.ps1 -Environment production -ToVersion 0.6.1
./scripts/release/rollback.ps1 -Environment production -ToVersion 0.6.1 -Apply
./scripts/release/rollback.ps1 -Environment production -ToVersion 0.6.1 -RestoreDatabase <backup-id> -Apply
```

既定はドライランとする。DB復元は`-RestoreDatabase`を明示した場合だけ行う。

事前条件：

- 対象環境、現在版、戻し先タグ、成果物SHA256、tree SHAを確定できる。
- 戻し先のGitHub Release assetとmanifestを取得できる。
- migration互換性を確認できる。
- DB復元時はバックアップのSHA256と復元一覧が有効である。
- リモートブランチを更新しない。

処理：

1. 現在のDBと配置状態を追加バックアップする。
2. メンテナンスモードへ切り替える。
3. 戻し先成果物を検証し、配置する。
4. 指定時のみDBを復元する。
5. Laravelキャッシュを再生成する。
6. 通常デプロイと同じ配置後検証を行う。
7. 合格時だけメンテナンスを解除する。
8. 切り戻し元・先、バックアップ、結果、ログを記録する。

失敗時はメンテナンスを維持し、現在地点、再開・再切り戻しコマンド、手動復旧手順を表示する。ロックは実行中プロセスがないことを確認してから解除する。

## 14. 保持・棚卸

- 正式タグとGitHub Release assetは自動削除しない。
- 候補成果物は30日以上保持する。
- DBバックアップとデプロイ・切り戻しログは最低3リリース世代を保持する。
- 未完了セッションが参照する成果物・バックアップは削除しない。
- 棚卸は削除対象を先に表示し、`-Apply`時だけ削除する。
- 削除対象の絶対パスと許可された保存ルートを照合する。

## 15. 通知と証跡

各代表スクリプトは、成功・失敗・確認待ち、完了工程、次のコマンド、バージョン、commit/tree SHA、タグ、PR・workflow・Release URL、成果物SHA256、環境別結果、DBバックアップ、ログ、メンテナンス・ロック状態を表示してセッションへ保存する。

リリース後処理は同じ内容を`docs/operations/change-log/`の公開記録へ反映する。秘密情報とサーバー接続情報は記録しない。

## 16. 権限と秘密情報

- GitHub Actionsのステージング・商用Environmentを分離する。
- 商用Environmentには必要に応じて承認者を設定する。
- SSH鍵、トークン、DB資格情報はGitHub Secretsまたはサーバー環境に保存する。
- workflowログへ秘密情報を出力しない。
- デプロイ用SSHユーザーとDBユーザーは必要最小限の権限にする。
- fork由来PRへデプロイsecretを渡さない。

## 17. 受入条件

### 17.1 正常系

- 事前準備から候補成果物生成まで1コマンドで完了する。
- ステージング配置、自動検証、ユーザー確認待ちまで1コマンドで完了する。
- 商用PR、タグ、同一成果物配置、自動検証まで1コマンドで完了する。
- リリース後処理がGitHub Release、同期、記録、棚卸を完了する。
- ステージングと商用の成果物SHA256が一致する。

### 17.2 異常系

次を意図的に発生させ、変更前または商用昇格前に停止する。

- バージョン不一致
- 未コミット変更、誤ブランチ、リモート差分
- CI必須チェック失敗
- `DEBUG=release`または`NUXT_DEBUG_PERF`の混入
- `@vite/client`、Windows絶対パス、欠損資産
- JS/CSSへの`text/html`応答
- console error、未知のwarning、`console.time`重複
- manifest、tree SHA、成果物SHA256の不一致
- DB・URL・環境の取り違え
- migration失敗
- HTTP・ブラウザ検証失敗
- 二重実行、既存タグのSHA不一致

### 17.3 再開と切り戻し

- 各状態で中断し、`-Resume`で完了済み工程を重複せず再開できる。
- 切り戻しドライランで変更が発生しない。
- 隔離した検証環境でアプリのみ、DB込みの切り戻しが成功する。
- 切り戻し失敗時にバックアップ、メンテナンス、ロック、手動復旧手順が残る。

## 18. 移行方針

1. 新しいCIと成果物manifestを先に実装する。
2. 既存スクリプトへ成果物検査と`debug: false`を反映する。
3. 代表スクリプトとセッション管理を実装する。
4. GitHub Actionsからステージングへ配置し、既存手順と結果を比較する。
5. 切り戻しを隔離環境で検証する。
6. 商用Environmentのsecret・承認を設定する。
7. 新方式で1回のステージング・リハーサルを完走する。
8. 新方式を標準化し、旧入口は互換転送または廃止案内へ変更する。
9. 既存のリリース手順とブランチ運用文書を新方式へ更新する。

移行完了までは既存運用を無断で削除しない。新旧方式を組み合わせて一つのリリースを行わない。
