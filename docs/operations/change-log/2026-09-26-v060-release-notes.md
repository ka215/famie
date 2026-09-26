# v0.6.0 本番配置記録

2026-09-26 JST、v0.6.0を商用環境へ公開した。

- 準備PR: [#24](https://github.com/ka215/famie/pull/24)（`feature/v0.6.0` → `dev`）
- リリースPR: [#25](https://github.com/ka215/famie/pull/25)（`dev` → `main`）
- リリース後同期PR: [#26](https://github.com/ka215/famie/pull/26)（`main` → `dev`）
- 正式タグ: `v0.6.0`
- ステージング・本番配置SHA: `2d543cd0ad7cb63c0724aad218a4cc94a6a76690`

## 検証

`prepare.ps1 -PublishMode Publish`でLint、型チェック、単体テスト13件、Chromium／iPhone相当WebKitのE2E 58件、静的生成を実行し、すべて成功した。候補版を配置したステージングではiPhone実機でカレンダー表示、月移動、指定日表示、登録日連携を確認した。

`dev`の確定コミットをステージングへ再配置し、`main`の正式タグを同じ環境へ再配置した。正式タグの検証後、同一タグを商用環境へ配置した。migrationは追加されておらず、`Nothing to migrate`、`CategorySeeder`、キャッシュ生成、メンテナンス解除まで正常終了した。

配置後にタグとGit SHAの一致、作業ツリーのクリーン状態、停止フラグとデプロイロックの不在、APIリンク、トップ・ログイン・status API・Service Worker・manifestの正常応答、不正ログインの422応答と検証本文を確認した。

## バックアップと証跡

DBバックアップはSSH経由のcustom形式で取得した。

| 用途 | 保存先（サーバーのHOMEから） |
| --- | --- |
| ステージング正式タグDB | `_db_dump/ka2_famiestg-v041-20260926-211103.P5saZC.dump` |
| 本番DB | `_db_dump/ka2_famie-v041-20260926-211133.Opk0W3.dump` |
| ステージング正式タグdeploy.log | `famie-stg-release-backups/releases/20260926-211105-v0.6.0-709256/deploy.log` |
| 本番deploy.log | `famie-release-backups/releases/20260926-211135-v0.6.0-709700/deploy.log` |

- ステージングDB SHA256: `dd339df46db05ff3f4078a79e2c2b730e78a0d21e2200c754afe0fdbdd7f8d05`
- 本番DB SHA256: `4856b07c441e5ef1ee978003c63bb685d87eb6c6b16a9f80bdb9ec184b851380`
