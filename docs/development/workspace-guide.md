# Famie 開発ガイド

旧ルートREADMEの開発情報を、v0.13.0の構成に合わせて整理した。作業ルールの正本は [AGENTS.md](../../AGENTS.md)、Backend固有の規約は [backend/AGENTS.md](../../backend/AGENTS.md) を参照する。

## 構成と環境

| パス | 用途 |
| --- | --- |
| `frontend/` | Nuxt 4・Vue・TypeScript。静的生成したSPAとPWA |
| `backend/` | Laravel 13・Sanctumによる家族単位のAPI |
| `docs/` | 要件・設計・開発・運用資料 |
| `scripts/` | リリース・検証の既存スクリプト |
| `version.json` | 公開済み版と次回版の正本 |
| `.temp/` | Git管理外の一時調査・検証結果 |

ローカルはWindows／PowerShell、Frontendはpnpm、BackendはComposerを使用する。PHP 8.4、Node.js、pnpm、Composer、GitとDB接続に必要なPHP拡張を準備する。商用DBはPostgreSQL、通常の自動テストは分離したSQLiteを利用する。PostgreSQL固有の制約・競合は専用DBで確認する。

```powershell
Get-Location
node -v
pnpm -v
php -v
composer -V
git --version
```

検索は `rg` / `rg --files`、JSON操作は `jq`、YAML操作は `yq`、GitHub操作は `gh` を使用する。Pythonを前提にしない。

## 開発・検証

初期設定は各アプリの `.env.example` を参照し、環境固有の値を設定する。Frontendは同一OriginのAPI利用を基本とし、開発用API接続先は `frontend/nuxt.config.ts` を確認する。既存の `.env` は上書きしない。

`frontend/` で実行する。

```powershell
pnpm install --frozen-lockfile
pnpm dev
pnpm typecheck
pnpm lint
pnpm test:unit
pnpm test:e2e
```

`backend/` で実行する。

```powershell
composer install
php artisan key:generate    # 新規環境のみ。既存APP_KEYは変更しない
php artisan migrate
composer dev
composer test
```

既存スクリプトを優先し、変更範囲のLint・型チェック、関連テストの順で確認する。`pnpm clean` は定義されていない。フルビルドは必要な場合に実行し、事前に削除対象が `frontend/.output` であることを確認して古い出力を除去する。`pnpm build` は `nuxt generate` による静的生成である。

古い出力は、移動元・移動先の絶対パスを確認して `.temp/` 内の未使用ディレクトリへ退避してもよい。通常の開発サーバーを起動したままビルドすると `.nuxt` が競合するため、開発サーバーを止めるか、既存の `FAMIE_E2E=1` を使って作業ディレクトリを分離する。この場合も本番ビルドのAPI接続先を使用する。同じ作業ディレクトリを使うE2E開発サーバーとは同時実行しない。

```powershell
$env:FAMIE_E2E = '1'
pnpm build
Remove-Item Env:FAMIE_E2E
pnpm test:release
```

- [E2Eの実行と専用DB](knowledge/e2e-test-notes.md)
- [ブランチ運用](decisions/2026-09-23-branch-strategy.md)
- [リリース手順](../operations/runbooks/release-script-notes.md)
- [Backend配置・環境設定](../operations/runbooks/howto_release_backend.md)

## 紹介画像の再生成

`frontend/` で実行する。PlaywrightのChromiumを使用し、APIは架空データで差し替える。サンプル日時は2026-10-08、画面サイズは430×1060、ライトテーマ。実ユーザーの情報は使用しない。

```powershell
$env:FAMIE_CAPTURE_SAMPLE = '1'
pnpm test:e2e tests/e2e/activity-likes.spec.ts --project=chromium --grep 'README用'
Remove-Item Env:FAMIE_CAPTURE_SAMPLE
```

画像は `docs/assets/screenshots/timeline-v0.13.0.png` に保存する。データは `frontend/tests/support/timeline-sample.ts`、撮影処理は `frontend/tests/e2e/activity-likes.spec.ts` を参照する。紹介用画像は実APIの結合テストの代替ではない。

タブアイコンのフォールバックICOは、既存の `frontend/public/icon.svg` から次のコマンドで再生成する。

```powershell
node tests/support/generate-favicon.mjs
```

## 文書と作業記録

長期参照資料は `docs/`、一時メモは `.temp/` に置く。GitHub Issueを起票した場合の進捗・議論の正本はGitHub側とし、[課題一覧](../issues/summary.md)から参照する。秘密情報や実ユーザーのデータはコミットしない。
