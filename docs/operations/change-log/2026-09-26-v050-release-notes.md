# v0.5.0 本番配置記録

2026-09-26 JST、v0.5.0を商用環境へ公開した。

- 準備PR: [#19](https://github.com/ka215/famie/pull/19)（`feature/v0.5.0` → `dev`）
- リリースPR: [#20](https://github.com/ka215/famie/pull/20)（`dev` → `main`）
- リリース後同期PR: [#21](https://github.com/ka215/famie/pull/21)（`main` → `dev`）
- 正式タグ: `v0.5.0`
- ステージング・本番配置SHA: `ea1a10240e6f2b7edbb02383047ca8b873d1d4cb`
- スクリプト固定SHA: `04fd137ae3319dc79b52a21b7af60f6e65e50a1b`

## 検証

`prepare.ps1 -PublishMode Publish` でLint、型チェック、単体テスト6件、Chromium／iPhone相当WebKitのE2E 56件、静的生成を実行し、すべて成功した。iPhone実機で対象機能とオーバースクロール抑止を確認済み。Android実機確認はユーザー判断で省略した。

`dev` の固定コミットをステージングへ配置後、`main` の正式タグを同じ環境へ再配置した。正式タグの配置後に、Git SHA一致、作業ツリーのクリーン状態、停止フラグ不在、画面・ログイン・status API・Service Worker・manifestの200応答、不正ログインの422応答を確認した。

本番では正式タグの生成物を再ビルドせず配置した。migrationは追加されておらず、`Nothing to migrate`、`CategorySeeder`、キャッシュ生成、メンテナンス解除まで正常終了した。配置後にタグとGit SHAの一致、作業ツリーのクリーン状態、停止フラグとデプロイロックの不在、APIリンク、画面・ログイン・status API・Service Worker・manifest・全入口JS/CSSの200応答、不正ログインの422応答を確認した。

## バックアップと証跡

DBバックアップはSSH経由のcustom形式で取得した。権限600、非空、`pg_restore --list`で`users`と`activity_logs`の収録を確認した。復元テストは未実施。

| 用途 | 保存先（サーバーのHOMEから） |
| --- | --- |
| ステージング正式タグDB | `_db_dump/ka2_famiestg-v041-20260926-134458.X9qvnm.dump` |
| 本番DB | `_db_dump/ka2_famie-v041-20260926-134816.q5j9zK.dump` |
| ステージング正式タグdeploy.log | `famie-stg-release-backups/releases/20260926-134741-v0.5.0-834765/deploy.log` |
| 本番deploy.log | `famie-release-backups/releases/20260926-134824-v0.5.0-896626/deploy.log` |

本番DB SHA256: `01cffc4a0e93c93d88de8884237e2494406ca3a325f7ca8b59068b0c9635e203`。

最初の正式タグのステージング実配置は、非対話SSHのPATHに`$HOME/bin`が含まれず`jq`を検出できなかったため、配置開始前に停止した。アプリ・DBが未変更、停止フラグ不在、空の残留ロック、実行中プロセス不在を確認してロックを削除し、運用手順どおり`PATH`を設定して再実行した。再実行後は正常終了した。
