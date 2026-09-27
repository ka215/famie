# ステージングの運用・デプロイ

ステージングは商用リリース前の常設検証環境である。v0.7.0以降は、`dev`の固定treeからRelease CIが生成した改変不能な成果物を配置する。ローカル生成物、ブランチ名だけの指定、未コミット資材は配置しない。

## 配置手順

通常の操作は[リリース自動化 運用手順](./release-script-notes.md)に従う。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -Apply
```

配置workflowは次を自動確認する。CoreServer上では成果物、環境、DB、バックアップ、配置後の静的資産を検査し、self-hosted runnerからは公開URLのHTTP、API、ブラウザ動作を検査する。APIは接続元IP制限のためCoreServer自身から確認しない。

- 配布アーカイブとpayloadのSHA256、manifestの版・source tree
- 配置先URL、Laravel設定、接続中DB・schema・DB user
- DBバックアップの完了と必須テーブル
- `/`、`/login/`、Service Worker、manifest、API statusのHTTP応答
- JS/CSSのMIME typeとHTMLフォールバックの混入
- ChromiumとiPhone WebKitのconsole error、未知のwarning、page error

自動検査後、iPhone等の実機で対象機能とPWA更新を確認し、次のコマンドで合格を記録する。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -ConfirmStaging -Apply
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

GitHub Actionsのenvironment別concurrencyにより同時配置を防ぐ。

- CoreServer上の配置処理が途中で失敗した場合はmaintenance状態を維持する。workflow logとサーバーのrelease backupを確認し、`rollback.ps1`で復旧する。migrationを含む場合はDB互換性を確認する。
- CoreServer上の配置とmaintenance解除が成功した後、self-hosted runnerの公開URL検査だけが失敗した場合、サイトは通常稼働状態である。workflow logとスモークテスト成果物を確認し、必要に応じて切り戻す。maintenance状態と決めつけない。
- 実機確認に失敗した場合は合格を記録せず、商用配置へ進まない。修正は新しい候補成果物として準備し直す。

## 関連文書

- [リリース自動化 最終仕様](../release-automation-spec.md)
- [リリース自動化 運用手順](./release-script-notes.md)
