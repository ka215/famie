# 開発ナレッジ

開発過程で得られた情報を、後続の課題解決に再利用できる形で保存する。

## 共通ルール

- [E2E テスト](knowledge/e2e-test-notes.md)：実行方法、専用DB、対象範囲。
- [ブランチ運用ルール](decisions/2026-09-23-branch-strategy.md)：作業ブランチ、PR、リリース、緊急修正の運用。

## 記録の配置

| ディレクトリ | 記録対象 |
| --- | --- |
| `decisions/` | 技術・設計判断と採否理由 |
| `knowledge/` | 調査結果、実装パターン、注意点 |
| `troubleshooting/` | 不具合の原因、対処、再発防止 |

正式仕様を変更する判断は、該当する記録から [要件定義書](../fixed_requirements.md) または [基本設計書](../basic_design.md) へ反映する。
