# v0.19.2 リリース記録

2026-11-02 JST、v0.19.2を商用公開した。最終候補への商用承認と、配置後の主要操作・既存PWAのユーザー確認は完了済み。

## 配置した成果物

- 承認候補: `b8ff019052d516a0e3dfa3ec65064bc560d11c1f`
- main／タグv0.19.2: `d4c672376dcd55a3fe2ceb5908e8fc3b4da8f9a4`
- source tree: `4390b2f3c4a6fe093372596af05f14abb5cf680f`（候補とmainが一致）
- 成果物SHA256: `45ae2354e61969794b1f1e2dac840ac0e6480d4c54d32862f6df113e57032e98`
- [成果物CI41326309752](https://github.com/ka215/famie/actions/runs/41326309752)
- [ステージング配置41327190911](https://github.com/ka215/famie/actions/runs/41327190911)
- [main向けPR #190](https://github.com/ka215/famie/pull/190)・[必須CI41328631984](https://github.com/ka215/famie/actions/runs/41328631984)
- [商用preflight41328525360](https://github.com/ka215/famie/actions/runs/41328525360)・[商用配置41329477489](https://github.com/ka215/famie/actions/runs/41329477489)
- [GitHub Release・検証済み成果物](https://github.com/ka215/famie/releases/tag/v0.19.2)

成果物はステージングと商用で同一であり、配置時には再ビルドしていない。商用manifestのsource/treeとAPI HTTP200、配置後ブラウザー検査の合格を確認した。

## 変更点

- R01（元メモ1-20）：登録モーダルの補足メモを初期1行・縦リサイズ可能なtextareaへ変更。Enterによる意図しない送信を防ぎ、改行を保存・表示・再編集できる。
- R02（元メモ1-21）：インベントリのトロフィー累計の右に投稿総数・獲得いいね総数を追加。指定SVG・色・縦線と日英読み上げ名を使用する。
- 活動累計は選択メンバー・同一家族・全期間の現在保持されている投稿と有効メンバーからのいいねが対象。投稿削除・取消・無効化で減少し、獲得済みトロフィーは取り消さない。
- 獲得一覧APIのmetaを拡張し、既存の閲覧権限・家族間分離・応答世代管理を維持した。DB構造・依存関係・cron設定は変更していない。

## 検証と受入

R01関連E2E10件、R02関連PHP55件・574 assertions、R02関連E2E28件（Chromium／iPhone WebKit）、Lint・型・API契約・日英検査がローカル合格。R02のPR CIでは専用PostgreSQL117件・2238 assertions成功を確認。固定候補CI、ステージングのブラウザー・限定セキュリティ検査、最終候補と商用のユーザー実機/PWA確認も合格した。

R02初回E2Eは27成功・既存WebKitリワード操作1失敗。トレースで保存後再取得と削除クリックの競合を確認し、試験で保存後処理完了を待つよう修正。同じ静的ビルドで28件を再実行して全合格した。アプリの削除操作や確認ダイアログを省略していない。

ステージングPostgreSQLの集計クエリ2本はEXPLAINで取得計画を確認。現在の少量データでは全表走査等が選択された。大規模データ時の性能保証とはせず、新しい索引は追加していない。

## 運用引継ぎ

商用の毎時cron1件と既存rotate2件を読み取り照合し、従来設定を維持。ステージングは通常手動運用のまま。runnerは配置検査終了・busy=false後に停止し、一時SSH鍵ディレクトリ不在を確認した。

既存cron欠測は[Issue #171](https://github.com/ka215/famie/issues/171)で原因追跡し、[内部監視の日次点検](../runbooks/internal-monitoring-notes.md#14-欠測を前提にした日次点検と補完)で補完する。依存の中低残件は[Issue #182](https://github.com/ka215/famie/issues/182)を継続する。本版で解消済みとは扱わない。

詳細な承認・候補履歴・取得計画・検証結果は[Issue #187](https://github.com/ka215/famie/issues/187)と[詳細要件](../../issues/v0.19.2.md)を参照する。
