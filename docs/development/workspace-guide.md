# Famie 開発ガイド

v0.16.0公開時点の構成・運用に合わせた開発情報をまとめる。商用は `https://famie.co`、ステージングは `https://stg-famie.ka2.org`。旧URLは転送・復旧用に維持する。作業ルールの正本は [AGENTS.md](../../AGENTS.md)、Backend固有の規約は [backend/AGENTS.md](../../backend/AGENTS.md) を参照する。

## 構成と環境

| パス | 用途 |
| --- | --- |
| `frontend/` | Nuxt 4・Vue・TypeScript。静的生成したSPAとPWA |
| `backend/` | Laravel 13・Sanctumによる家族単位のAPI |
| `docs/` | 要件・設計・開発・運用資料 |
| `scripts/` | リリース・検証の既存スクリプト |
| `version.json` | 公開済み版と次回版の正本 |
| `.temp/` | Git管理外の一時調査・検証結果 |

ローカルはWindows／PowerShell、Frontendはpnpm、BackendはComposerを使用する。PHP 8.4.27、Node.js 24.22.1、pnpm 11.20.0、Composer、GitとDB接続に必要なPHP拡張を準備する。v0.17.2でNode.jsのセキュリティ修正版へ更新し、CIと配置後テストも24.22.1に揃える。商用DBはPostgreSQL、通常の自動テストは分離したSQLiteを利用する。PostgreSQL固有の制約・競合は専用DBで確認する。

```powershell
Get-Location
node -v
pnpm -v
php -v
composer -V
git --version
```

Node.jsの対象版はルートの`.node-version`にも記載する。pnpm管理の通常nodeは`pnpm runtime set node 24.22.1 -g`で切り替えられる。`@pnpm/exe`はNode.jsを内蔵するため通常nodeの更新だけでは切り替わらない。v0.17.2ではpnpm 11.20.0のJavaScript版を修正版nodeで動かす構成にし、`node -v`、`frontend/`内の`pnpm exec node -v`と、pnpm起動ファイルが`node`で`pnpm.mjs`を実行することを確認する。CIは`standalone: false`を明示する。Actions／runnerの内蔵ランタイムはこの指定とは別管理であり、[v0.17.2の残事項](../issues/v0.17.2.md#2-別管理ランタイムの確認と残事項)を参照する。

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

`frontend/` で実行する。PlaywrightのChromiumを使用し、APIは架空データで差し替える。サンプル日時は2026-10-08、画面サイズは430×932、日本語／英語UI・ライトテーマ。各言語の架空の氏名・活動・リワードを使い、実ユーザーの情報は使用しない。

撮影元写真はGit管理しない。`.tools/assets/`に`pexels-salad.jpg`・`pexels-books.jpg`・`pexels-park.jpg`を準備する。提供された素材はPexels由来で、ユーザーにより商用利用可能・必須クレジット条件なしと確認済み。READMEには希望に基づくPexelsの出典リンクを添える。素材を差し替える場合も、スクリーンショットを公開できる条件を確認する。

```powershell
$env:FAMIE_CAPTURE_SAMPLE = '1'
try {
  pnpm test:e2e tests/e2e/readme-capture.spec.ts --project=chromium
} finally {
  Remove-Item Env:FAMIE_CAPTURE_SAMPLE
}
```

画像は`docs/assets/screenshots/`の`{timeline,rewards,gallery}-{en,ja}.png`、計6枚へ保存する。共通データは`frontend/tests/support/timeline-sample.ts`を再利用し、日英データ・写真・リワード等の撮影専用設定は`frontend/tests/e2e/readme-capture.spec.ts`にある。通常のCIでは明示フラグなしで撮影をスキップし、PNGを生成しない。旧`timeline-v0.13.0.png`と旧撮影処理は過去資料用に保持する。紹介用画像は実APIの結合テストの代替ではない。

撮影用の家族取得レスポンスは`images.enabled: true`を返す。各画面では本文の取得だけでなく、非同期で表示される固定フッターのギャラリーリンクと全4項目を待ってから撮影する。固定秒数のsleepは使わず、表示条件を満たさなければ撮影を失敗させる。

タブアイコンのフォールバックICOは、既存の `frontend/public/icon.svg` から次のコマンドで再生成する。

```powershell
node tests/support/generate-favicon.mjs
```

## 文書と作業記録

長期参照資料は `docs/`、一時メモは `.temp/` に置く。GitHub Issueを起票した場合の進捗・議論の正本はGitHub側とし、[課題一覧](../issues/summary.md)から参照する。秘密情報や実ユーザーのデータはコミットしない。
