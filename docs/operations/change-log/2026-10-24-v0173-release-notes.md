# v0.17.3 商用配置記録

2026-10-24 JST、v0.17.3を商用へ公開し、ステージング再確認・商用の自動検証およびユーザー実機確認に合格した。同日、ユーザーから商用実機OKと事後工程の指示を受領した。

## 対応内容

- モーダル背景の4pxブラーでライト／ダークの境界を改善。
- ギャラリーの週／月送りを既存の左右矢印SVGへ変更し、日英の操作名を維持。
- CI・配置・切り戻しのActionsをNode.js 24対応版へ更新。
- PlaywrightのOS依存導入でAzure Ubuntuミラーの低速取得を回避し、公式HTTPS配信先・時間上限・限定的再試行を採用。E2Eとverifyに適用し、既存のテスト・合格条件を維持。
- v1.0.0までと公開後の正式ロードマップを文書化。

詳細は[v0.17.3要件書](../../issues/v0.17.3.md)、[候補・承認・検証記録のIssue #145](https://github.com/ka215/famie/issues/145)を参照。

## 固定候補・成果物

- 初回候補PR #144、CI修正PR #147。最終候補commit: `2244504a86f759139fe290bcc29f925ebd949117`。
- 最終候補／main共通tree: `6a8fd2e22757794c476308afe53188b167328a63`。
- 商用PR [#148](https://github.com/ka215/famie/pull/148)、main／正式タグv0.17.3: `e44aa3dc68ed7a90982b05c101817a51b9263355`。
- 候補生成CI: [39994625848](https://github.com/ka215/famie/actions/runs/39994625848)、成果物ID: `12520070837`。
- bundle SHA256: `11931e3d33afc8de9f7aabfddc70905a0fc4509e56d47ed37970b82463b71e84`。
- mainのtree一致を確認してタグ付与。ステージング確認済みの同一成果物を再ビルドせず商用へ配置した。

## 検証・承認・配置

- 初回候補はステージング実機合格後、商用PR #146のWebKit導入がOSパッケージ取得中に繰り返し時間切れとなった。商用へ進まず、ユーザー承認に基づきCI修正・新候補生成へ移行。旧PR #146は閉じた。
- 修正PRのCI [39993650260](https://github.com/ka215/famie/actions/runs/39993650260)に合格。WebKitログで107 MBを29秒で取得できたことを確認。
- 最終候補の生成CIは基本検査・両ブラウザE2E・verifyに合格。OS依存導入はChromium 20秒、WebKit 38秒、verify 55秒で成功。
- ステージング事前検査 [39995957439](https://github.com/ka215/famie/actions/runs/39995957439)、再配置 [39996073452](https://github.com/ka215/famie/actions/runs/39996073452)に合格。配置後スモーク24件成功／8件スキップ。
- 2026-10-24、ユーザーから「ステージング再確認OKです。商用リリースへ進んでください」を受領し、新候補の合格・商用承認をIssue #145とCLIへ記録した。
- 商用事前検査 [40002118585](https://github.com/ka215/famie/actions/runs/40002118585)、商用PR CI [40002286595](https://github.com/ka215/famie/actions/runs/40002286595)に合格。
- 商用配置 [40003232281](https://github.com/ka215/famie/actions/runs/40003232281)に合格。配置後スモーク24件成功／8件スキップ。
- production Environmentの保護を維持し、対象候補へのユーザー承認に基づいてレビューを実施した。
- 商用アプリ／DBバックアップ証跡: `~/famie-release-backups/releases/20261024-032643-v0.17.3-6a8fd2e22757`。
- ステージング証跡: `~/famie-stg-release-backups/releases/20261024-023403-v0.17.3-6a8fd2e22757`。

## 事後工程

ユーザーの商用実機確認をCLIとIssue #145へ記録し、[GitHub Release](https://github.com/ka215/famie/releases/tag/v0.17.3)へtar.gz・checksum・manifestを公開した。main→dev同期はPR #149。公開記録・要件書・課題一覧・文書索引を更新し、版情報を`current=0.17.3`／`next=null`へ進める。公開済みアプリ成果物・正式タグは事後工程では変更しない。

CIの固定Composer 2.8.10に関する既存警告、ubuntu-latestの移行予告等はIssue #145に記録済み。今回の候補では無関係な依存更新・CI構造変更を行っていない。
