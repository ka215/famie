# v0.4.1 本番配置記録（4-4 実機確認完了）

2026-09-24 JST。ユーザー承認により手動履歴の4-3まで代行した。4-4以降はユーザーが再開する。

- 準備PR: https://github.com/ka215/famie/pull/15 （devマージ済み）
- dev固定SHA: dc1668526da21b3c1bf49fc2c903982529d85bb6
- リリースPR: https://github.com/ka215/famie/pull/16 （mainマージ済み）
- 正式タグ: v0.4.1
- ステージング・本番配置SHA: 0f7c1204eab3ea4a4d8762b073161bc5016783d4
- スクリプト固定SHA: 04fd137ae3319dc79b52a21b7af60f6e65e50a1b
- 既存v0.4.0タグは保持。本番はv0.3.0からv0.4.1へ更新。

## 検証

prepare.ps1: Lint、型チェック、単体、Chromium/WebKit E2E 34件、ビルド成功。ローカル開発サーバー（PID 12204）の競合を解消するため停止して再実行した。開発サーバーは自動再起動していない。

ステージング候補配置、正式タグ配置、本番配置の全てが正常終了。正式タグと候補のGitツリーは一致し、タグ作成後の再ビルドはしていない。Laravel停止前にHTMLおよびAPIの静的503を確認。メンテナンス設定更新時は3経路×GET/POSTのJSON完全一致とヘッダーも確認した。

ステージングのChromium・iPhone相当WebKitでログイン画面・status API・ページ例外なしを確認。iPhone実機確認の代替とはしていない。

本番deploy.shの解除後チェック（画面200、空ログイン422、status API 200/ok、JS/CSS/SW/manifest 200）が成功。Nothing to migrate。CategorySeeder完了。配置SHA一致・フロント/バック双方のメンテナンスフラグ不在も確認。

## バックアップと証跡

DBバックアップは管理画面ではなくSSH経由のpg_dump 14.20で取得した。今回はcustom形式（旧ログのプレーンSQL形式とは異なる）。権限600、非空、pg_restore --listでusers/activity_logsのTABLE DATAを確認。復元テストは未実施。認証情報はサーバー上のLaravel設定から内部利用し、ログには出していない。

| 用途 | 保存先（サーバーのHOMEから） |
| --- | --- |
| ステージング候補DB | `_db_dump/ka2_famiestg-v041-20260924-183051.8IFdwk.dump` |
| ステージング正式タグDB | `_db_dump/ka2_famiestg-v041-20260924-183349.x9Bpnf.dump` |
| 本番DB | `_db_dump/ka2_famie-v041-20260924-183509.z7ZPes.dump` |
| 本番旧スクリプト入口 | `famie-deploy-backup-v041.iRSWMS/` |
| ステージング旧Apache設定 | `famie-stg-maintenance-v041.lSqFdp/` |
| 本番旧Apache設定 | `famie-maintenance-v041.ifOQP5/` |
| ステージング候補deploy.log | `famie-stg-release-backups/releases/20260924-183233-candidate-0.4.1-dc1668526da2-851508/deploy.log` |
| ステージング正式タグdeploy.log | `famie-stg-release-backups/releases/20260924-183351-v0.4.1-852280/deploy.log` |
| 本番deploy.log | `famie-release-backups/releases/20260924-183521-v0.4.1-853455/deploy.log` |

本番DB SHA256: `1a09a110eadee05bf290e42207642f750291f2ec6d48b7003b7fe7b5111d7d64`。

今回のローカル記録: `.temp/v041-deploy-logs.md`、`.temp/v041-prepare.log`。元の `.temp/v040-deploy-logs.md` は変更していない。SHA256: `FBF031286B83F0111AEADA190071C5DEDFB9783FC35E57D43CE0C5DA7CAFD961`。

候補配置前の補助スクリプトでWindows末尾CRによるバックアップ参照ファイル名の不一致を検出。配置前に参照名と転送時の改行を修正し、同じ取得済みダンプで配置を完了した。デプロイ中の失敗はなし。

## 4-4からユーザーが再開

1. 本番でiPhoneのホーム画面起動、既存記録、設定画面を確認。
2. 記録の登録・再読み込み後の保持、表示名変更、カード開閉を確認。
3. ログアウト・再ログインを確認。
4. 結果を本記録へ追記して確定し、記録のコミット・PR、必要なリリース公開/Issue整理、main→dev同期を進める。

実施内容:

- [x] 本番の iPhone 実機確認は全項目正常。
- [x] `main` → `dev` 同期は PR [#17](https://github.com/ka215/famie/pull/17) で完了。
- [x] GitHub Release 公開
- [x] Issue 整理
- [x] ブランチ削除（`feature/v0.4.0`, `feature/v0.4.1`）
