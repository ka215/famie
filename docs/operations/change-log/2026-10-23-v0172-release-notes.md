# v0.17.2 リリース記録

2026-10-23 JST、v0.17.2を商用へ公開し、ユーザーの商用実機確認に合格した。

## 対応内容

- ビルド・検証用Node.jsを24.19.0からセキュリティ修正版24.22.1へ更新。
- pnpm 11.20.0を維持し、ローカルは旧Node.js内蔵バイナリから修正版nodeで動くJavaScript版へ切替。CIはstandalone=falseを明示。
- self-hosted runnerを公式2.338.1へ更新し、内蔵node24=24.22.1を確認。
- ローカルPHPを8.4.27へ更新。8.2.35／8.3.36／8.5.12も追加し、設定・Imagick・Apache切替ツールを整備。商用・ステージングのWeb側PHPはユーザーが8.4.27を確認済み。
- FQDN別のWeb側PHPを一時リンクで確認する運用シェルを追加。

アプリ機能・DB仕様・依存パッケージ・lockfileの変更はない。詳細は[仕様書](../../issues/v0.17.2.md)と[商用Issue #140](https://github.com/ka215/famie/issues/140)。GitHub-hosted runner本体はGitHub側の管理であり、管理下の実行環境更新と区別する。ローカルApacheの既存SSL警告は別事項として仕様書に記録した。

## 固定候補・公開資材

- 候補PR: #138、commit: `f986064dc5cb5c218a9e94d097651c35680a1cc2`
- 候補／main共通tree: `64eb48cbc73ac22fa899547c4a97ad8ee24bcd93`
- main／正式タグv0.17.2: `e06e274c4adec39cd9acd88c91a9f1e7048ec8d3`（商用PR #141）
- 候補CI: [39801659545](https://github.com/ka215/famie/actions/runs/39801659545)、成果物ID: `12440846775`
- 成果物SHA256: `52d58b74464ce7c7e990cd71691ab9eea9dc97b695bd242bee1b9d514e867e72`
- [GitHub Release](https://github.com/ka215/famie/releases/tag/v0.17.2)にtar.gz・checksum・manifestを公開し、GitHub側digestと配置済みSHA256の一致を確認。

## 配置・受入

- ローカル自動検証: Lint・型・辞書487キー・単体20件・静的ビルド・12 HTML／165参照の検査成功。ブラウザスモーク30件成功、remote API向け2件スキップ。Laravelは265件成功・条件付き37件スキップ。
- ステージング事前検証: [39813077340](https://github.com/ka215/famie/actions/runs/39813077340)、配置: [39813253998](https://github.com/ka215/famie/actions/runs/39813253998)。[Issue #139](https://github.com/ka215/famie/issues/139)に実機合格と商用承認を別々に記録。
- 商用事前検証: [39826904431](https://github.com/ka215/famie/actions/runs/39826904431)、商用PR CI: [39826983235](https://github.com/ka215/famie/actions/runs/39826983235)。成功。
- 商用配置: [39830229517](https://github.com/ka215/famie/actions/runs/39830229517)、attempt 2で成功。同一成果物を再ビルドせず配置。
- ステージング・商用の配置後スモークはいずれも24件成功・ローカル専用等8件スキップ。
- 商用配置・アプリ／DBバックアップ証跡: `~/famie-release-backups/releases/20261023-055744-v0.17.2-64eb48cbc73a`
- ステージング証跡: `~/famie-stg-release-backups/releases/20261023-012120-v0.17.2-64eb48cbc73a`
- 2026-10-23、ユーザーから商用実機確認OKと事後工程の指示を受領。

## 遅延・再実行の記録

GitHub Actionsの結果反映遅延によりrun検出や状態取得に不整合があり、発行済みrunを直接追跡した。商用PRのWebKit導入ではUbuntu配信元のダウンロードが極端に遅かったため、ログ確認後に同一コミットの未成功ジョブを再実行して合格した。

商用配置attempt 1はジョブ未開始（steps=[]）のまま取消。run全体の状態反映が遅れ、再実行・force-cancelが拒否された間は配置しなかった。ユーザーの画面確認とAPIの取消完了反映後、同じrunを再実行し、attempt 2で初めて商用配置した。Environmentレビューは受領済みの商用承認に基づき実施し、保護ルールは維持した。runnerは通信中断後、アイドル状態で再接続し旧セッションの解放を確認した。

## 事後工程

main→dev同期はPR #142。版情報をcurrent=0.17.2／next=nullとし、公開記録・仕様書・一覧・文書索引を更新して、取り込み済み作業ブランチを整理する。公開済みアプリ成果物・正式タグは事後工程では変更しない。

事後工程の同期PR #142でもWebKit用OSパッケージ取得が遅く、ログ確認後に未成功ジョブを1回再実行した。CI 39881568343は最終的に全必須チェック成功。候補・公開済み成果物・保護ルールの変更は行っていない。
