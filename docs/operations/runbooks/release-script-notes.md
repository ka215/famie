# リリース自動化 運用手順

本書は `docs/operations/release-automation-spec.md` の実行手順である。通常リリースは準備、配置、完了処理の3スクリプトで行い、復旧は専用スクリプトで行う。各スクリプトは既定でdry-runとなり、変更を加える場合だけ `-Apply` を付ける。

## 0. バージョン管理

`version.json.current` は公開中の版、`version.json.next` は次の候補版である。開発開始時に `next` を設定し、package版は準備スクリプトが候補版へ更新する。リリース完了時に `current` を公開版へ進め、次版が未定なら `next` を `null` にする。

## 1. 事前準備

ローカル確認でユーザーのOKを得た変更をコミットして作業ブランチをcleanにし、GitHub CLIへログインしてから計画を確認する。準備スクリプトが追加するpackage版更新も候補コミットに含める。

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

配置workflowでは、Git for Windowsの空白を含む`tar.exe`パスによるキャッシュ復元警告を避けるため、`setup-node`のpnpmキャッシュを使用しない。配置のたびに固定lockfileから依存関係を確認し、頻度の低い配置処理では速度より再現性を優先する。Release CIはGitHub-hosted runner上で従来どおりキャッシュを利用できる。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -Apply
```

workflowは成果物SHA256とmanifestを確認後、CoreServer上で環境・DB接続先、バックアップ、配置資材を検査して配置する。配置後のHTTP、API、JS/CSSのMIME、HTMLフォールバック、ChromiumとiPhone WebKitのコンソールはself-hosted runnerから検査する。実機確認に合格したら記録する。

```powershell
pwsh scripts/release/release.ps1 -Version X.Y.Z -Environment staging -ConfirmStaging -Apply
```

### 2.1 フェーズ単位の確認と候補更新

| 判定 | 意味 | 次に進める工程 |
| --- | --- | --- |
| フェーズ合格 | 固定候補の対象範囲がステージング実機確認に合格 | 同じ候補版の次フェーズの開発 |
| 商用リリース承認 | 全フェーズ・最終回帰・最終候補の実機確認を終え、ユーザーが商用公開を明示承認 | 最終候補のmain昇格・正式タグ・商用配置 |

`-ConfirmStaging`は現在の候補の実機確認合格（`staging_verified`）を記録する。商用リリース承認を記録するコマンドではない。自動検査の成功だけでは実機確認合格にしない。

1. 実機確認中は配置候補を固定する。NGなら合格を記録せず、修正・ローカル確認・ユーザーOK・追加コミット・PRからやり直す。
2. OKなら`-ConfirmStaging`で合格を記録し、GitHub Issueへフェーズ名・対象範囲・PR・commit/tree SHA・候補生成／配置workflow run・成果物IDとSHA256・実機／PWA確認結果・確認者・日時・残課題を残す。判定は「フェーズ合格／商用未承認」と明記する。
3. 次フェーズでは同じ作業ブランチへ最新の`dev`を取り込み、新しいPRで変更を追加する。既存コミットをまとめ直さず、同じ版番号で新候補を生成する。`current`は公開版、`next`は共通の候補版を維持する。
4. 新候補の準備前に、実行中の配置workflowがないこと、商用工程に進んでいないことを確認する。旧`.release/sessions/vX.Y.Z.json`を候補workflow runで識別できる保管先へ退避し、その場所をIssueに記録する。旧成果物・ログも保持する。その後、通常の`prepare-release.ps1 -Version X.Y.Z -Apply`で新しいセッションを作る。旧候補の合格状態を引き継がない。
5. `-Resume`は同じ候補の中断再開に限る。新しいフェーズ／修正候補へ進む用途では使わない。旧候補の`staging_verified`や確認結果を新候補の合格根拠にしない。
6. 全フェーズ完了後、最終候補で回帰・実機・PWA更新を確認し、候補を特定したユーザーの商用リリース承認をIssueに記録する。その候補を固定して次節へ進む。承認後に変更した場合は新候補として再検証・再承認する。

本区別は運用上の承認ゲートである。現行CLIに専用の商用リリース承認フラグ／状態はなく、`staging_verified`だけでは承認取得を機械的に保証しない。実行者はIssueの明示承認を確認してから商用工程を開始する。GitHub production Environmentの承認も維持する。

## 3. 商用配置

前提は、全フェーズ完了、最終候補のステージング実機確認合格、およびその候補に対するユーザーの明示的な商用リリース承認である。フェーズ合格だけでは本節の商用工程を実行しない。

v0.9.0では通常配置後に[IP制限変更手順](ip-access-notes.md)に従って商用だけIP制限を解除する。`.env`・`.htaccess`は配置時に保持されるため、コード配置だけでは解除されない。設定反映・従来許可外回線での確認を商用完了判定に含める。ステージングの制限は維持する。

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

商用IP制限を解除した後でv0.8.0以前へ戻す場合は、[IP制限の復旧手順](ip-access-notes.md)も実施する。旧版は新しい解除設定を認識しない。環境設定は通常のアプリ切り戻しでは復元されない。

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
