# v0.6.1 緊急修正・本番配置記録

2026-09-26 JST、v0.6.0公開直後に商用・ステージングの画面が白くなる問題を確認し、v0.6.1として修正した。

## 原因と対策

ローカルのNuxt開発サーバーが稼働中に静的生成を実行したため、生成HTMLへ`@vite/client`とWindows絶対パスが混入した。配信先では該当URLがHTMLのフォールバック応答となり、ブラウザがJavaScriptモジュールとして拒否した。

開発サーバー停止後にキャッシュと静的成果物を再生成した。リリース準備スクリプトには、開発用参照とWindows絶対パスの検出、およびHTMLが参照するJS/CSSの存在確認を追加した。

- 修正PR: [#28](https://github.com/ka215/famie/pull/28)
- v0.6.1正式化PR: [#29](https://github.com/ka215/famie/pull/29)
- リリース後同期PR: [#30](https://github.com/ka215/famie/pull/30)
- 正式タグ: `v0.6.1`
- 配置SHA: `cc049a49daa58b5637068feb7d82314dc0cb2dac`

## 確認結果

商用・ステージングのHTMLから開発用参照が消えていることを確認した。HTMLが参照する全JS/CSSについて、HTTP 200と`application/javascript`または`text/css`のContent-Typeを確認した。status API、不正ログイン検証、メンテナンス解除もデプロイスクリプト内で成功した。

| 用途 | 保存先（サーバーのHOMEから） |
| --- | --- |
| ステージングDB | `_db_dump/ka2_famiestg-v041-20260926-212402.oXRdxX.dump` |
| 本番DB | `_db_dump/ka2_famie-v041-20260926-212327.e3dATW.dump` |
| ステージングdeploy.log | `famie-stg-release-backups/releases/20260926-212403-v0.6.1-737200/deploy.log` |
| 本番deploy.log | `famie-release-backups/releases/20260926-212329-v0.6.1-736394/deploy.log` |

- ステージングDB SHA256: `65ff1efcbcfc3103589329776ab33954a62ab743164a25ba7142fbf9f1ceae76`
- 本番DB SHA256: `c8ec1dc6aa8520ddeea83075a241d336ac8c4248a0314274aa24e8653e84d92b`
