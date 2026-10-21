# v0.17.1 リリース記録

2026-10-21 JSTに商用環境へ配置し、2026-10-22 JSTにユーザーの商用実機確認に合格した。

## 対応内容

- iPhoneの画像選択キャンセルが親dialogへ伝播して活動登録を閉じる不具合を修正。
- 活動一覧のメモ接頭辞を指定SVGへ変更し、パスワード強度計のトラック表示を調整。
- READMEを公開済み機能に整理し、サービスアイコン・3バッジ・英語UIの3画面・Pexels出典・MAGICMETHODS著作権表示を掲載。

詳細は[仕様書](../../issues/v0.17.1.md)と[商用Issue #131](https://github.com/ka215/famie/issues/131)。

## 候補・公開資材

- 候補PR: #129、commit: `2579ca6ce54bb4816623deeaac4e926a17a138be`
- 候補／main共通tree: `4935987fbb09e124f29173e8e80ed4464d294831`
- main／正式タグv0.17.1: `0bb8a4b65a3c98d79859bc41dc4dc1db0791fc65`（商用PR #132）
- 候補CI: [39612623363](https://github.com/ka215/famie/actions/runs/39612623363)、成果物ID: `12351507486`
- 成果物SHA256: `935d70579a0d552acb7abe44a0f8c09a5b7ef4ace137b31d1e9fb2be6bf09645`
- [GitHub Release](https://github.com/ka215/famie/releases/tag/v0.17.1)にtar.gz・checksum・manifestを公開し、GitHub側digestと配置済みSHA256の一致を確認。

## 配置・受入

- ステージング配置: [39613946679](https://github.com/ka215/famie/actions/runs/39613946679)。[Issue #130](https://github.com/ka215/famie/issues/130)に実機合格を記録。
- 2026-10-21、ステージング実機合格と商用リリース承認をそれぞれ受領。
- 商用事前検証: [39615934687](https://github.com/ka215/famie/actions/runs/39615934687)、商用PRのCI: [39616938704](https://github.com/ka215/famie/actions/runs/39616938704)。いずれも成功。
- 商用配置: [39618226995](https://github.com/ka215/famie/actions/runs/39618226995)。ステージングと同一成果物を再ビルドせず配置。自動スモーク24件成功、ローカル専用等8件スキップ。
- 配置・アプリ／DBバックアップ証跡: `/virtual/ka2/famie-release-backups/releases/20261021-233639-v0.17.1-4935987fbb09`
- 2026-10-22、ユーザーから商用実機確認OKと事後工程の指示を受領。
- 旧ドメイン案内の変更はない。切り戻し時は既存のリリース切り戻し手順と上記バックアップを使用する。

## 運用上の記録・事後工程

商用事前検証では環境承認後に待機が続いたが、その後進行して成功した。環境保護設定の変更や再配置は行っていない。Actionsが宣言するNode.js 20実行環境の警告は別件として記録し、今回のリリースではCI構成を変更していない。アプリのビルド指定はNode.js 24.19.0。

main→dev同期はPR #133。公開記録・仕様書・一覧・文書索引・版情報を更新し、current=0.17.1、next=nullとする。取り込み済み作業ブランチを整理する。事後工程で公開済みアプリ成果物・正式タグを変更しない。
