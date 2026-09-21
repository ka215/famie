# 参考資料

このディレクトリには、正式文書の作成前に作られた要件案、実装例、画面スニペット、旧運用手順を保存する。内容は歴史的経緯や実装時の検討材料として参照できるが、仕様の正本ではない。

| ディレクトリ | 内容 |
| --- | --- |
| `requirements/` | 初期要件と草案 |
| `backend/` | API、Eloquent、マイグレーションの実装例 |
| `frontend/` | Nuxt 画面・Composable の実装例 |
| `infrastructure/` | 旧インフラ・デプロイ手順 |
| `project/` | サービス名などのプロジェクト情報 |

`infrastructure/howto_deploy.md` は Nginx を前提とする旧資料である。現行の Apache 前提は [基本設計書](../basic_design.md) を参照する。