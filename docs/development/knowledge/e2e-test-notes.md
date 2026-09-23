# フロントエンド E2E テスト

## 実行

Node.js、pnpm、PHP（pdo_sqlite 有効）、インストール済みの `backend/vendor` が必要。

```powershell
Set-Location C:\xampp\htdocs\famie\frontend
pnpm install --frozen-lockfile
pnpm test:e2e:install
pnpm test:e2e
```

`pnpm test:e2e:ui` で対話画面を起動できる。Chromium と iPhone 13 相当の WebKit で主要導線を確認する。ブラウザ設定は [Playwright 公式ドキュメント](https://playwright.dev/docs/test-projects) を参照。

## 環境とデータ

- Playwright が Nuxt（127.0.0.1:3100）と Laravel API（127.0.0.1:8100）を起動・終了する。既存サーバーは再利用しないため、この2ポートを空けておく。
- API は実際の Laravel を使用し、起動ごとに `frontend/.cache/e2e/run-*/database.sqlite` を新規作成する。既存DBへのリセット処理は行わない。
- DB接続先、設定・ルートキャッシュのパス、APP_KEY は起動プロセス専用に指定する。開発用・本番用 `.env` を編集しない。
- 既存の migration と seeder で `parent1` / `child1` を作成する。パスワード `Famie-E2E-only-123!` は一時DB専用。本番アカウントに使用しない。
- Nuxt の API プロキシは `FAMIE_E2E=1` の開発サーバーだけで有効になる。
- 一時DB、HTMLレポート、失敗時のスクリーンショット・トレースは Git 管理外。不要になった実行結果は削除可能。

## 対象と限界

ログイン、日時欄の非重複、活動登録、再読み込み後の一覧反映、ログアウトを確認する。パスワード表示切り替えのテストも同じ基盤で実行する。

SQLite によるテストは本番 PostgreSQL 固有の動作を保証しない。WebKit の端末エミュレーションは iPhone 実機の代替ではない。課題1の受入れでは iPhone Safari とホーム画面から起動した PWA で日時欄が重ならず、選択・保存できることを別途確認する。
