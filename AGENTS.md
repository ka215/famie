# AGENTS.md — famie ワークスペース共通方針

本ドキュメントは `famie` ワークスペース全体に適用される共通ガイドです。
特定のディレクトリ下に `AGENTS.md` がある場合は、**より深い階層の指示を優先**してください。

---

## ワークスペース構成

```
famie/
├── backend/     # バックエンド・アプリケーション（Laravel）
├── docs/        # ドキュメント
├── frontend/    # フロントエンド・アプリケーション（Nuxt4）
└── README.md    # 全体概要
```

---

## 環境ポリシー

- **ローカル開発環境: Windows**
- **推奨シェル: PowerShell**（Git Bash / WSL が明示されない限り Bash を前提にしない）
- **パッケージマネージャー: npm / pnpm / composer**
- **Python は利用不可と前提してよい。** 確認なしに Python スクリプトを生成・実行しない。
- Node.js / pnpm / PHP が使えるなら、それで完結させる。

### 起動時チェック（Windows）

```powershell
Get-Location
node -v
pnpm -v
php -v
composer -V
git --version
python --version   # 失敗しても続行してよい
```

- `node -v` または `pnpm -v` が失敗 → 不足ツールを説明して停止
- `python --version` が失敗 → Python ベースの代替案を試みない

---

## コマンドの優先順位

タスクに応じたコマンドを以下の優先順で選ぶ。

1. `package.json` / `composer.json` に定義済みのスクリプト
2. リポジトリにコミット済みの既存スクリプト（`scripts/`, `beta/start_server.php` 等）
3. PowerShell コマンド・スクリプト
4. Node.js ワンオフスクリプト
5. Bash（明示的に必要で利用可能な場合のみ）
6. Python（利用確認済みで他手段では解決できない場合のみ）

### 用途別の必須ツール

- JSON の操作には `jq` を使用する。
- YAML の操作には `yq` を使用する。
- GitHub との連携には `gh`（GitHub CLI）を使用する。

### 検索コマンドの共通ルール（rg と zg の使い分け）

検索を行う際は、ユーザーの要求の「解像度」に応じて `rg`（ripgrep）と `zg`（zvec-grep）を明確に使い分け、効率的にコードを探索すること。

#### 1. ツール選択の基準
- **`zg`（zvec-grep）を第一選択とするケース（意味・概念・目的の検索）**
  - ユーザーの要求が曖昧、または自然言語による機能要求であるとき（例:「認証処理はどこ？」「セッション破棄の流れ」）。
  - 具体的な識別子（関数名や変数名）が不明で、実装の「意図」からコードを探したいとき。
  - プロジェクト全体の構造や、関連するコンテキストを一通り把握したいとき。
  - **実行コマンド:** `zg query "検索クエリ"`
    * Codexでは既定のagent markdown出力を使用する。人が読む詳しいプレビューが必要な場合は `--human` を付与する。
    * 導入済みのzg 0.2.2では `--json` は削除済みのため使用しない。非Codex向けの `.github/copilot-instructions.md` は `--human` を用いる運用を維持する。

- **`rg`（ripgrep）を第一選択とするケース（正確なキーワード・パターンの検索）**
  - すでに具体的な関数名、変数名、エラーメッセージ、定数などの「正確な文字列」が判明しているとき。
  - `import` 文や特定のシンボルの定義場所・呼び出し箇所（参照）を100%確実にリストアップしたいとき。
  - 正規表現を用いたテキスト検索を行うとき。
  - ファイル一覧を検索するとき（`rg --files`）。

#### 2. 推奨されるハイブリッド検索フロー
1. まず `zg query "..."` を使って、目的の機能に関係がありそうなファイルや関数名の「あたり」をつける。
2. `zg` の結果から得られた具体的なキーワード（関数名など）をキーにして、`rg` でプロジェクト全体への影響範囲や呼び出し箇所をピンポイントで特定する。

#### 3. Codex実行時の注意事項
- **逐次実行の徹底:** `zg` でアタリをつける前に、勘で大量の `rg` コマンドを並列実行（Parallel execution）しないこと。必ず `zg` の結果を確認してから `rg` へ移行すること。
- **インデックスの確認:** プロジェクトのコードが大きく変更された直後、または `zg` の検索結果が不自然な場合は、必要に応じて `zg index` を実行してインデックスを更新すること。
- `rg` や `zg` が利用できない環境、またはこれらで絞り込んだ後にさらに詳細なテキスト処理が必要な場合に限り、代替コマンド（PowerShell の `Select-String` など）を使用すること。

---

## ワークスペース別コマンド早見表

### frontend/ （pnpm + Nuxt）

```powershell
pnpm install          # 依存関係インストール
pnpm dev              # 開発サーバー起動（http://localhost:3000）
pnpm build            # プロダクションビルド（直前に .output 削除）
pnpm preview          # ビルド後プレビュー
pnpm generate         # 静的エクスポート
pnpm clean            # キャッシュ削除
npx @biomejs/biome check --write .   # Lint＋整形
```

### backend/ （composer + Laravel）

```powershell
composer install
php artisan key:generate
composer dev          # PHP サーバー / キュー / ログ / Vite 並行起動
composer test         # テスト実行
```

### other/ （npm）

```powershell
npm install
```

---

## リポジトリ行動規則

- 作業開始前および PR・リリース時に [ブランチ運用ルール](docs/development/decisions/2026-09-23-branch-strategy.md) を読み、従う。通常開発は `dev` から作業ブランチを切り、PR で `dev` → `main` の順に取り込む。`main` へ通常の開発変更を直接 push しない。
- 既存のスクリプト・コンポーザブル・ユーティリティ・型定義を再利用する。
- 該当タスクのファイル以外を変更しない。
- 構造変更はタスクで明示的に要求された場合のみ。
- 既存の命名・フォーマット・アーキテクチャ規約を守る。

---

## 検証ポリシー

最小コストの検証を先に実行する。

1. 変更ファイルに関係する Lint / 型チェック
2. 影響範囲に対応したテスト
3. フルビルドは必要な場合のみ

```powershell
# frontend
npx @biomejs/biome check .
pnpm build

# backend
php artisan test
```

失敗時はコマンド出力を確認してから次の手を考える。同じ失敗を別ランタイムで繰り返さない。

---

## Windows シェルでの作業

PowerShell を優先して使う。Unix 専用コマンドを避ける。

```powershell
# Unix の代替
Get-ChildItem          # ls
Get-Content .\file.txt # cat
Set-Location .\app     # cd
Test-Path .\node_modules # test -d
```

---

## 失敗時の対処

1. 失敗の具体的な原因を説明する
2. 同じアプローチを別ランタイムで盲目的に再試行しない
3. Windows での代替順: PowerShell → 既存リポジトリスクリプト → Node.js
4. Python が使えない場合は Python ベースの選択肢を使わない
5. 必要なツールが不足している場合は明示して停止する

---

## 禁止事項（全ワークスペース共通）

- Python が確認されていない状態で Python スクリプトを生成・実行する
- ファイル操作、テキスト置換、設定確認などを Python 経由で行う
- `npm` を `pnpm` の代替として使う（frontend）
- 既存の `package.json` スクリプトを確認せずに独自コマンドを発明する
- タスクと無関係なファイルの変更・クリーンアップ

---

## ドキュメント運用

- `docs/` は長期参照ドキュメント（`*-plan.md`, `*-spec.md`, `*-notes.md`）。
- 一時メモ・未整理調査は `.temp/` に置く。
- GitHub Issue の正本は GitHub 側。`.github/ISSUE_TEMPLATE/` テンプレートを利用する。
