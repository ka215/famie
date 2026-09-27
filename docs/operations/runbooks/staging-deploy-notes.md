# ステージングの運用・デプロイ

ステージングは商用リリース前の常設検証環境である。v0.7.0以降は、`dev`の固定treeからRelease CIが生成した改変不能な成果物を配置する。ローカル生成物、ブランチ名だけの指定、未コミット資材は配置しない。

## 配置手順

通常の操作は[リリース自動化 運用手順](./release-script-notes.md)に従う。

```powershell
pwsh scripts/release/release.ps1 -Version 0.7.0 -Environment staging
pwsh scripts/release/release.ps1 -Version 0.7.0 -Environment staging -Apply
```

配置workflowは次を自動確認する。

- 配布アーカイブとpayloadのSHA256、manifestの版・source tree
- 配置先URL、Laravel設定、接続中DB・schema・DB user
- DBバックアップの完了と必須テーブル
- `/`、`/login/`、Service Worker、manifest、API statusのHTTP応答
- JS/CSSのMIME typeとHTMLフォールバックの混入
- ChromiumとiPhone WebKitのconsole error、未知のwarning、page error

自動検査後、iPhone等の実機で対象機能とPWA更新を確認し、次のコマンドで合格を記録する。

```powershell
pwsh scripts/release/release.ps1 -Version 0.7.0 -Environment staging -ConfirmStaging -Apply
```

## 環境の固定値

| 項目 | production | staging |
| --- | --- | --- |
| バックエンド | `~/famie/backend` | `~/famie-stg/backend` |
| 公開先 | `~/public_html/famie.ka2.org` | `~/public_html/stg-famie.ka2.org` |
| URL | `https://famie.ka2.org` | `https://stg-famie.ka2.org` |
| DB・user・schema | `ka2_famie` | `ka2_famiestg` |
| backup root | `~/famie-release-backups` | `~/famie-stg-release-backups` |

ステージングは既存データ、`.env`、Laravel storage、Apacheの `.htaccess`、`api` symlinkを保持する。通常配置でDB初期化、UserSeeder、APP_KEY再生成は行わない。本番データをコピーしない。

## 排他・失敗時

GitHub Actionsのenvironment別concurrencyにより同時配置を防ぐ。配置開始後に失敗した場合はmaintenance状態を維持し、workflow logとサーバーのrelease backupを確認する。復旧には `rollback.ps1` を使用し、migrationを含む場合はDB互換性を確認する。

## 関連文書

- [リリース自動化 最終仕様](../release-automation-spec.md)
- [リリース自動化 運用手順](./release-script-notes.md)
