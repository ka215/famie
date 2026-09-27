# リリース自動化 運用手順

本書は `docs/operations/release-automation-spec.md` の実行手順である。通常リリースは準備、配置、完了処理の3スクリプトで行い、復旧は専用スクリプトで行う。各スクリプトは既定でdry-runとなり、変更を加える場合だけ `-Apply` を付ける。

## 0. バージョン管理

`version.json.current` は公開中の版、`version.json.next` は次の候補版である。開発開始時に `next` を設定し、package版は準備スクリプトが候補版へ更新する。リリース完了時に `current` を公開版へ進め、次版が未定なら `next` を `null` にする。

## 1. 事前準備

作業ブランチをcleanにし、GitHub CLIへログインしてから計画を確認する。

```powershell
pwsh scripts/release/prepare-release.ps1 -Version X.Y.Z
pwsh scripts/release/prepare-release.ps1 -Version X.Y.Z -Apply
```

適用時はpackage版更新、Lint・型検査・単体テスト、コミットとpush、`dev`向けPRの検査・マージ、Release CIの起動を順に行う。CIはNuxtを一度だけ生成し、静的参照検査とブラウザスモークを通した成果物を作る。セッションと成果物は `.release/` に保存され、成果物は候補workflow runごとのディレクトリへ分離し、Git管理しない。中断後は同じコマンドに `-Resume` を付ける。

## 2. ステージング配置と確認

配置・切り戻しworkflowは、このPCのself-hosted runner（`famie-release-ka2-pc`）で実行する。GitHub Actionsは停止中のrunner自身を起動できないため、`release.ps1`または`rollback.ps1`を実行する前にrunnerを起動する。Release CIによる成果物生成には不要である。

runnerが使用するworkflowには`bash`、`ssh`、`scp`、`sha256sum`が必要なため、起動プロセスの`PATH`へGit Bashを明示してから起動する。

```powershell
$runnerRoot = 'C:\actions-runner-famie'
$runnerPath = "C:\Program Files\Git\bin;$env:PATH"
Start-Process -FilePath (Join-Path $runnerRoot 'run.cmd') -WorkingDirectory $runnerRoot -WindowStyle Hidden -Environment @{ PATH = $runnerPath }
```

GitHub上でonlineになったことを確認する。配置・切り戻しスクリプトもdispatch前に`famie-release`ラベルのonline runnerを検査し、offlineなら即時停止する。

```powershell
gh api 'repos/{owner}/{repo}/actions/runners' --jq '.runners[] | select(.name == "famie-release-ka2-pc") | {status,busy,labels:[.labels[].name]}'
```

workflow完了後は、実行中でないことを確認してrunnerを停止する。実機確認中は停止したままでよい。次の商用配置時に同じ手順で起動する。

```powershell
Get-CimInstance Win32_Process |
  Where-Object { $_.Name -match '^Runner\.(Listener|Worker)\.exe$' } |
  ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
```

`Verify downloaded artifact`で`bash: command not found`となった場合は、サーバー転送前の失敗である。上記PATHでrunnerを再起動し、同じリリーススクリプトを再実行する。workflowが実行中の間はrunnerを停止しない。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -Apply
```

workflowは成果物SHA256とmanifestを確認後、CoreServer上で環境・DB接続先、バックアップ、配置資材を検査して配置する。配置後のHTTP、API、JS/CSSのMIME、HTMLフォールバック、ChromiumとiPhone WebKitのコンソールはself-hosted runnerから検査する。実機確認に合格したら記録する。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -ConfirmStaging -Apply
```

## 3. 商用配置

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment production
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment production -Apply
```

ステージング実機確認済みでなければ停止する。`dev`から`main`へのPRを検査・マージし、mainのtreeが候補成果物と一致することを確認してからタグを付ける。同じ成果物を再ビルドせず商用へ配置する。自動検査後に実機確認へ進み、合格を記録する。

```powershell
pwsh scripts/release/complete-release.ps1 -Version X.Y.Z -ConfirmProduction -Apply
```

## 4. リリース後処理

```powershell
pwsh scripts/release/complete-release.ps1 -Version X.Y.Z
pwsh scripts/release/complete-release.ps1 -Version X.Y.Z -Apply
```

GitHub Releaseへ成果物を登録し、`main`を`dev`へ同期する。公開記録と `version.json` の更新PRを作成し、取り込み済み作業ブランチを削除する。次版が決まっている場合は `-NextVersion A.B.C` を指定する。

## 5. 切り戻し

対象の公開済み版と環境を指定し、まずpreflightを実行する。

```powershell
pwsh scripts/release/rollback.ps1 -Environment production -ToVersion X.Y.Z
pwsh scripts/release/rollback.ps1 -Environment production -ToVersion X.Y.Z -Apply
```

通常はアプリだけを戻す。現在版にmigration変更がある場合、互換性が明示されていなければ停止する。DB復元が必要な場合はバックアップを確認後、サーバー上の絶対パスを `-RestoreDatabase` に指定する。復元はデータを巻き戻すため、影響確認と明示指定なしには実行しない。

## 6. 再実行と証跡

- 同一環境の配置workflowはconcurrencyで直列化する。
- dry-runは配置workflowのpreflightを実行するが、サーバー上のアプリとDBは変更しない。
- 再開時は `.release/sessions/vX.Y.Z.json` の状態から続行する。
- GitHub Actionsのrun、Release資産、サーバーのrelease backup、`docs/operations/change-log/` を証跡とする。
- サーバーには最低3世代のアプリ・DBバックアップを残す。世代削除は別の保守作業で行う。

## 7. 必要なGitHub Environment設定

`staging` と `production` に次のsecretを設定する。

- `DEPLOY_SSH_KEY`
- `DEPLOY_KNOWN_HOSTS`
- `DEPLOY_HOST`
- `DEPLOY_USER`

商用Environmentにはrequired reviewersを設定し、workflow上でも承認を必須にする。ローカルhookは補助であり、CI結果を正式な判定とする。

## 関連文書

- [リリース自動化 最終仕様](../release-automation-spec.md)
- [ブランチ運用ルール](../../development/decisions/2026-09-23-branch-strategy.md)
- [フロントエンドのリリース手順](./howto_release_frontend.md)
- [バックエンドのリリース手順](./howto_release_backend.md)
