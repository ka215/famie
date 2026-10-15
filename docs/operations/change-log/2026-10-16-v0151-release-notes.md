# v0.15.1 リリース記録

2026-10-16 JST、v0.15.1を商用環境へ公開した。ステージング・本番の自動検査およびユーザー実機確認は合格。

## 公開内容

- 活動詳細のダークモード配色、編集操作領域、タイムライン／ギャラリーへの条件・ページ・位置の復帰を改善。
- リワード編集モーダルの選択肢配色、期間変更不可の案内、保存ボタン文言、カードの獲得バッジ等を調整。家族メンバー追加欄の親権限説明を補足。
- 全45操作のOpenAPI定義、契約検査、CI検査を追加。
- famie.coへのドメイン移管計画を整理。DNS・公開URL・メール送信元の実切替は後続版。

## 配置証跡

- main SHA: `db141a9726df77f242a91f2234949639cf3b838d`
- source tree: `c04af912ec2b158c8be99ea2aadf049a5040aa69`
- 成果物SHA256: `74dd3df0f462b0c9e6541cc97b2f9717713d54d49fd4fa734fc3eadf5a55ced3`
- [成果物CI](https://github.com/ka215/famie/actions/runs/38804528448)（attempt 2で成功）
- [ステージング配置](https://github.com/ka215/famie/actions/runs/38806635496)
- [商用事前検査](https://github.com/ka215/famie/actions/runs/38810084007)
- [商用配置](https://github.com/ka215/famie/actions/runs/38811168289)
- [本番リリースPR #94](https://github.com/ka215/famie/pull/94)
- [GitHub Release・配布資産](https://github.com/ka215/famie/releases/tag/v0.15.1)

ステージング確認済みの同一成果物を再ビルドせず商用へ配置した。バックアップ・配置処理・HTTP/API/静的資産・ブラウザ検査の詳細は各workflow runとリリースセッションを参照する。

## 検証結果

- 成果物CI：全体E2E 158件成功・2件スキップ、リリースブラウザ検査26件成功・2件スキップ。
- 成果物CI初回はWebKitの画面遷移で内部エラーが1件発生（157件成功・2件スキップ）。同一コミットの失敗ジョブを再実行し全検査成功後に成果物を生成した。
- 配置後ブラウザ検査：ステージング・商用とも24件成功・4件スキップ。スキップは未実行として区別する。
- ユーザーのローカル確認、ステージング実機確認、本番実機確認：合格。

## 事後工程

配置済み成果物・manifest・チェックサムをGitHub Releaseへ登録。[main→dev同期PR #95](https://github.com/ka215/famie/pull/95)を取り込み、公開記録・要件書・課題一覧の更新をPRで実施。`version.json`はcurrentを`0.15.1`、nextを`null`とする。取り込み済みの今回の作業ブランチを整理し、次版は未定とする。
