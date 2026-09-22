# フロントエンドアプリのリリース手順

## 1. 前提条件
- ローカル開発環境が整っている（Windows）
- 推奨シェルが PowerShell
- パッケージマネージャーが npm / pnpm
- フロントエンドアプリケーションコードが最新
- フロントエンドアプリケーションのテストが全て成功

## 2. 実行者
- フロントエンド開発者
- システム管理者

## 3. 実行コマンド

### 3.1 依存関係のインストール
```powershell
pnpm install
```

### 3.2 プロダクションビルド
```powershell
pnpm build
```

### 3.3 プロダクションビルドのバリデーション
```powershell
pnpm preview
```

### 3.4 静的エクスポート（オプション）
```powershell
pnpm generate
```

### 3.5 キャッシュ削除（オプション）
```powershell
pnpm clean
```

## 4. 確認方法
- ビルドされたファイルが `.output` ディレクトリに存在すること
- プレビューサーバーが `http://localhost:3000` で正常に起動すること
- 静的エクスポートが正常に完了し、`.output/public` ディレクトリにファイルが存在すること

## 5. 失敗時の戻し方
1. コマンド出力を確認して、具体的なエラーメッセージを特定する。
2. エラーメッセージに従って問題を解決する（パッケージ依存関係の不一致、コードのバグなど）。
3. 失敗した手順から再開する。

## 6. リファレンスドキュメント
- `AGENTS.md`
- `famie/frontend/README.md`
- `famie/frontend/package.json`
- `famie/frontend/nuxt.config.ts`