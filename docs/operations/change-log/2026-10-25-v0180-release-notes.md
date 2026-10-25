# v0.18.0 商用配置記録

2026-10-25 JST、v0.18.0を商用へ公開し、全フェーズのステージング確認・商用の自動検証とユーザー実機確認に合格した。同日、ユーザーから商用実機OKと事後工程の指示を受領した。

## 対応内容

- 家族メンバーごとの未確認ログイン警告アイコンと、そのメンバーの未確認警告だけを再表示。PostgreSQL集計の500も修正し、権限・親ごとの確認状態を維持。
- 共通活動詳細カード、本人だけの編集フォーム、詳細からのいいね・画像表示・元の一覧条件と位置への復帰。戻るリンクは階層に応じたSVGを使用。
- 新規リワード獲得通知の白い集中線。一定方向に1周12秒・2周24秒で静止し、閉じるまで残す。reduced-motionは初めから静止し、非表示・設定切替時は停止する。
- 日英READMEの相互リンク、架空データによる3画面×2言語の紹介画像、main対象のRelease CIバッジと補記。撮影は非同期フッターのギャラリー導線・4項目の表示を待つ。

詳細は[v0.18.0詳細仕様](../../issues/v0.18.0.md)、[承認・検証記録のIssue #151](https://github.com/ka215/famie/issues/151)を参照。

## 固定候補・成果物

- フェーズPR: [#152](https://github.com/ka215/famie/pull/152)、[#153](https://github.com/ka215/famie/pull/153)、[#154](https://github.com/ka215/famie/pull/154)、[#155](https://github.com/ka215/famie/pull/155)。
- 最終候補commit: `99d9d22ac2301b0e9c3b2c40a4899a2333e75096`。
- 最終候補／main共通tree: `ec03688a5547977fb0a5ffdb7deb1be8c275b784`。
- 商用昇格PR: [#156](https://github.com/ka215/famie/pull/156)。main／正式タグv0.18.0: `96f8583b50a42940af086690b937e7793c7c7548`。
- 成果物生成CI: [40113005456](https://github.com/ka215/famie/actions/runs/40113005456)、artifact ID: `12567512533`。
- bundle SHA256: `9fca46f3ec731f6da77e9b6d0412c18d552b5717eed346b5772d13e5fb9d2943`。
- mainのtree一致を確認してタグ付与。ステージングで確認した同じ成果物を再ビルドせず商用へ配置した。

## 検証・承認・配置

- 最終PR CI [40112356514](https://github.com/ka215/famie/actions/runs/40112356514)、成果物生成CI、商用昇格CI [40115019455](https://github.com/ka215/famie/actions/runs/40115019455)はすべて合格。各全体E2EはChromium／iPhone WebKit計240件合格・撮影専用6件skip、リリースsmoke30件合格・2件skip。
- ステージング配置 [40113701476](https://github.com/ka215/famie/actions/runs/40113701476)、商用配置 [40115787695](https://github.com/ka215/famie/actions/runs/40115787695)に合格。各配置後smoke24件合格・8件skip。
- フェーズ3のiPhone実機では「視差効果を減らす」がオンのとき静止し、オフにすると回転して24秒後に停止することをユーザーが確認。全フェーズ・最終候補のステージング実機合格後、商用公開の明示承認を受領した。
- production Environmentの保護を維持し、ユーザーの商用承認に基づきEnvironmentレビューを反映。商用実機合格をCLIとIssueへ記録した。
- 商用アプリ／DBバックアップ証跡: `~/famie-release-backups/releases/20261025-022256-v0.18.0-ec03688a5547`。
- ステージング証跡: `~/famie-stg-release-backups/releases/20261025-015458-v0.18.0-ec03688a5547`。
- 既存データ・設定・PWAキャッシュを保持。切り戻し不要。必要時は既存rollback手順でv0.17.3の成果物・バックアップを参照する。

## 事後工程

[GitHub Release](https://github.com/ka215/famie/releases/tag/v0.18.0)へtar.gz・checksum・manifestを公開。main→dev同期は[PR #157](https://github.com/ka215/famie/pull/157)。公開記録・詳細仕様・課題一覧・文書索引を更新し、版情報を`current=0.18.0`／`next=null`へ進める。次の作業ブランチ作成時に候補版を設定する。公開済みアプリ成果物・正式タグは事後工程では変更しない。
