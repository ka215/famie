# v0.20.0 リリース記録

2026-11-12 JSTに商用配置し、2026-11-13 JSTに運営者による商用実機確認に合格した。

## 変更内容

- 家族の代表管理者、家族の退会、無効メンバーの削除を追加。
- 削除対象の識別情報の暗号化保管、監査・保全と復旧手順を整備。商用鍵のUSB予備・別端末での確認も完了。
- 日英の利用規約・プライバシーポリシー・Cookieポリシーと同意履歴を追加。ログイン前と設定画面から閲覧できる。文書の施行予定はv1.0.0公開時。
- アクセスログの保持期限に関する運用通知を追加。問い合わせメールは当面手動運用。
- GitHub-hosted LinuxのDNS障害に対するCI限定の条件付き復旧を追加。

## 固定成果物

- main/tag commit: `f23a09cce6958937bc1baec11aeb174da332a1a9`
- source commit: `9df0db4baf6bd4cb1e2b431ee000eadc1cef0d1a`
- source tree: `6b0d90b7af5e42aa4a348070b6433070080ce34d`
- bundle: `famie-v0.20.0-6b0d90b7af5e.tar.gz`
- bundle SHA256: `55c351f3258048d0437647f4793916439acd2ae18c7de467d71f812cd5a56627`
- payload SHA256: `de996c0b021d6043142a7349481db3b7530ce5d94bbf3dc9574792adfeaa5947`
- [成果物生成run 43063144342](https://github.com/ka215/famie/actions/runs/43063144342)
- [昇格PR #208](https://github.com/ka215/famie/pull/208)
- [商用配置run 43174398249](https://github.com/ka215/famie/actions/runs/43174398249)
- [GitHub Release](https://github.com/ka215/famie/releases/tag/v0.20.0)

ステージングは既存supervisor経由で配置したため、配置workflow runは存在しない。配置証跡は `20261112-020731-v0.20.0-6b0d90b7af5e`。商用配置証跡は `20261112-214848-v0.20.0-6b0d90b7af5e`。確認済み成果物を再ビルドせず商用へ配置し、mainのtree一致と配信manifest・checksum一致を確認した。

## 検証と受入

- 必須CI、成果物全checksum・manifest・Git原本照合に合格。
- ステージングのブラウザー検証28成功・22想定skip、運営者の実機/PWA確認に合格。
- 運営者の商用反映承認とGitHub production Environmentの手動承認後に配置。商用のブラウザー検証30成功・22想定skip、実機確認に合格。
- DBバックアップのアーカイブ検証・checksum検証に合格。承認済み前進migration3件を適用し、17件から20件へ更新。DB初期化・データ削除・復元は実施していない。
- 識別付きREAD ONLY確認で既存家族3件・アカウント15件とカテゴリ確認値を維持。有効な代表管理者3件、追加管理テーブル・同意履歴は0件、画像削除待ち0件を確認。
- 公開鍵一致・非公開保管先の権限・監視設定・operator無効を確認。秘密鍵のサーバー転送、検証目的のcleanup/prune、実アカウントの退会・削除・同意送信試験は実施していない。
- 公開status・法務文書配信、メンテナンス解除・配置ロック解除を確認。

## 後続課題

継続台帳保全の運用・自動化はv1.0.0以降かつ管理画面構築後に検討する（[Issue #200](https://github.com/ka215/famie/issues/200)）。問い合わせメールの自動化も管理画面開発時に検討する。COPPA固有対応は運営者の判断で見送りとして継続管理し、対応済みとは扱わない。

全工程の経緯・受入・承認の正本は[Issue #193](https://github.com/ka215/famie/issues/193)を参照する。
