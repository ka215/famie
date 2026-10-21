# v0.17.0 リリース記録

2026-10-21 JST、v0.17.0を商用環境へ公開した。

- main SHA: `2b74e04acd691284927a7b0f46e65bd5ebcc1646`
- 成果物SHA256: `c5f2695f692f90eb174dca63950491082c2ddebc22d4e2914671cd66fd100939`
- ステージングworkflow run: `39440106369`
- 商用workflow run: `39550403957`
- ステージング・商用の自動検証および実機確認: 合格

詳細なバックアップ、配置ログ、検証結果は各workflow runとリリースセッションを参照する。

## 対応内容・承認

利用者向けの共通UI・認証・設定・活動・カレンダー・いいね・画像・ギャラリー・リワード・配信メール4種を日英化。本人の言語設定とゲスト選択を分離し、新規家族の初期カテゴリは登録言語で生成する。保存済みの利用者データは原文を維持する。HTTP環境の一覧復帰、Safariのダークモードselectも修正した。

全6フェーズを同じv0.17.0のまま段階検証し、途中段階の商用公開は行わなかった。2026-10-21に最終ステージング合格・商用リリース承認・商用実機合格をそれぞれ記録した。詳細は[仕様書28章](../../issues/v0.17.0.md#28-最終受入商用公開記録2026-10-21)と[商用Issue #125](https://github.com/ka215/famie/issues/125)。

## 固定候補・資材・CI

- 候補commit: `e532dd77a7d6fcf5aaf53d7cfedef777dfbffe3f`
- 候補／main共通tree: `ad55a35e85cbb0c20db7012e6870bb181005bb15`
- 候補CI: [39438677279](https://github.com/ka215/famie/actions/runs/39438677279)、成果物ID: 12273585145
- 商用PR: #126、公開前CI: [39549810417](https://github.com/ka215/famie/actions/runs/39549810417)
- 商用事前検証: [39549672842](https://github.com/ka215/famie/actions/runs/39549672842)
- 商用配置: [39550403957](https://github.com/ka215/famie/actions/runs/39550403957)。スモーク24件成功、ローカル専用等8件スキップ
- 配置・アプリ／DBバックアップ証跡: `/virtual/ka2/famie-release-backups/releases/20261021-143621-v0.17.0-ad55a35e85cb`
- [GitHub Release](https://github.com/ka215/famie/releases/tag/v0.17.0)へtar.gz・checksum・manifestを登録し、GitHub側の資材digestと商用配置済みSHA256の一致を確認

## 旧ドメインJSON更新・復旧

- 対象: `/virtual/ka2/public_html/famie.ka2.org/domain-moved.json`
- 新SHA256: `f4d4c9c7d951f4b0a804dda76c65a962ac592f2ba978e9ec186548f3c7558392`
- 旧SHA256: `18848a9c417ba2594f614506a97f273f1bc268d26613533e2030992f968094ad`
- バックアップ: `/virtual/ka2/famie-release-backups/v0.17.0-legacy-20261021T053804Z/domain-moved.json`
- 所有者・公開権限604を維持し、JSONだけをrenameで切り替えた。`.htaccess`・APIリンク・storage・DBはこの単体手順では変更していない
- 公開HTTPSの11ケースでAPI／非GET画面の410、通常ページGET／HEADの302、日英message・固定url・JSON MIME・no-storeを確認。新サイトのAPI statusも正常
- 不具合時は[旧ドメイン運用手順](../runbooks/domain-migration-plan.md#v0170以降旧ドメイン410案内jsonだけを更新する)に従い、バックアップしたJSONだけを復元・ハッシュ／HTTP確認する。通常アプリの切り戻しと別に扱い、migrate-domain.shは再実行しない

## 事後工程

main→dev同期はPR #127、公開記録と版情報・関連案内の更新はPR #128で管理する。currentを0.17.0、未定のnextをnullにし、取り込み済み作業ブランチを整理する。公開したアプリ成果物・正式タグはこの文書更新では変更しない。
