# Famie ドキュメント索引

## 正式文書

実装、テストおよび受入れの基準となる文書は次の 2 件である。

- [要件定義書](fixed_requirements.md)
- [基本設計書](basic_design.md)

正式文書の変更は、関連する意思決定を `development/decisions/` に記録したうえで行う。

## ディレクトリ構成

```text
docs/
├── fixed_requirements.md       # 正式: 要件定義書
├── basic_design.md             # 正式: 基本設計書
├── reference/                  # 旧資料・実装例などの参照資料
├── development/                # 開発時に蓄積する判断・知見・障害対応
└── operations/                 # 本番運用で管理する手順・変更・バックアップ
```

## 利用ルール

- `reference/` の資料は作成時点の内容を保存する参考資料であり、正式仕様ではない。
- 実装方針の採否、設計上の例外、トレードオフは `development/decisions/` に記録する。
- 再利用できる調査結果や実装パターンは `development/knowledge/` に記録する。
- 発生した不具合の原因・対処・再発防止は `development/troubleshooting/` に記録する。
- 本番作業の手順は `operations/runbooks/`、実施履歴は `operations/change-log/`、バックアップ運用は `operations/backup/` に記録する。