# v0.11.0 リリース記録

2026-10-04 JST、v0.11.0を[商用環境](https://famie.ka2.org)へ公開した。

- 対応内容：メンバー編集・無効化・復元、カテゴリ編集・未使用カテゴリ削除、端末内保存のライト／ダーク設定。
- main SHA: `9e7eb12918f1f94cd796109adcbc7ed965ec9a5a`
- 成果物SHA256: `6cd7e6ddaad11d7ba2d718a5a687b9475d22415a65e9902542223c6e7c436fd8`
- [ステージングworkflow](https://github.com/ka215/famie/actions/runs/37137132483)：自動検証・ユーザー実機確認ともに合格。
- [商用workflow](https://github.com/ka215/famie/actions/runs/37208522685)：配置・自動検証に合格。リモートブラウザスモーク6件合格。
- ステージングと同一成果物を再ビルドせず配置。既存データ・環境設定を保持した。DBスキーマ変更なし。
- 商用実機確認3項目も合格。GitHub Releaseへ同一成果物を登録し、main→dev同期、version.json更新を実施。currentは0.11.0、nextはnull。

実機確認項目は[v0.11.0詳細要件](../../issues/v0.11.0.md#商用実機確認項目)を参照。バックアップ・配置ログ・検証結果は各workflow runとリリースセッションに保存している。
