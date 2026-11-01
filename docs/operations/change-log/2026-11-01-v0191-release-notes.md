# v0.19.1 リリース記録

2026-11-01 JST、v0.19.1を商用公開した。主要操作・既存PWA・運用通知受信をユーザーが確認し、商用毎時cronは管理画面から登録済み。

## 配置した成果物

- 承認候補: `7e70040de4a27cc29149b107ff3cf7b28a268111`
- main／タグv0.19.1: `445b55da669ecee791dfc01fbeb83add9cf35edf`
- source tree: `02432fbbdb9e9caa832a626d56b3d023b0aa1322`（候補とmainが一致）
- 成果物SHA256: `323147626be096b55c2ddfba448cae390d4c9a91df8ae11f31ba0541c1e69fa9`
- [成果物CI 41235421136](https://github.com/ka215/famie/actions/runs/41235421136)
- [ステージング配置41236280307](https://github.com/ka215/famie/actions/runs/41236280307)
- [商用配置41258997336 attempt2](https://github.com/ka215/famie/actions/runs/41258997336/attempts/2)
- [GitHub Releaseと検証済み成果物](https://github.com/ka215/famie/releases/tag/v0.19.1)

ステージングと同じ成果物を再ビルドせず商用へ配置した。CI・配置後ブラウザー検査・ステージング限定動的検査・ユーザー実機/PWA確認・更新後SMTP通知受信は合格。商用manifest・Symfony MIME8.1.10の実体・運用sh2本のSHA256も確認した。

## 変更点と受入条件

- 商用は毎時起動、ステージングは通常手動。cronはユーザーが管理画面から登録・撤去し、こちらは読み取りで照合する。既存rotate2件を保持した。
- ジョブ全体150秒＋終了猶予5秒、容量走査全体30秒、ログ窓1時間、DB・storageの初回異常通知。日次backup03:00・画像削除04:00 JSTは維持。
- 私有履歴で開始／終了・実行段階・終了コード・捕捉可能なシグナルを記録する。
- node-forge1.4.1で期限付きhigh例外を撤去。Nuxt4.6.3・Symfony MIME8.1.10へ最小更新。

毎時方式の24時間観測は開始24／終了24、monitor22／24、日次2処理成功だった。旧無欠測条件の未達を消さず、ユーザー承認により欠測を既知制約・日次点検・手動補完で運用する受入へ変更した。根本原因解消・常時通知の保証ではなく、[Issue #171](https://github.com/ka215/famie/issues/171)で調査を継続する。

監査はhigh／critical0・例外0、Composer指摘0。残るmoderate2・low1は、上流の指定メジャー外／修正版未公開の制約を確認し、ユーザー同意済みの[Issue #182](https://github.com/ka215/famie/issues/182)で追跡する。

## 初回配置失敗と復旧

商用attempt1は09:58 JST、Composerの最適化autoload生成中にプロセスがKilled・exit137となった。DB migration・フロント更新前に停止し、保護maintenanceを維持した。直前snapshotのv0.19.0コードと依存を整合させ、DB・画像を巻き戻さず10:01頃に旧版へ復旧。アプリ／DB接続先ガード、APIと主要ページのHTTP200を確認した。

私有の別コピーで最適化autoloadを検証したところ1.83秒・Composer報告peak14.42 MiBで成功した。これはRSSや提供元制限の測定ではなく、恒常的な失敗を再現しなかった記録である。同じ候補・配置手順・成果物のattempt2で配置・自動検査に合格。終了主体や原因を特定済みとはしない。

配置直前のコード・DBバックアップ、失敗時と再試行時の証跡は商用の私有release-backups領域に保持した。DB復元は実施していない。

## 運用引継ぎ

10:09 JSTに商用backup・画像削除・monitorを時間上限内で手動補完し、すべて成功・全チェックokを確認。テスト通知の受信と商用実機/PWAはユーザー確認済み。10:32に商用毎時1件とrotate2件を読み取り確認した。手動確認を自動cron成功に数えない。初回・以後の自動実行結果は[管理Issue #176](https://github.com/ka215/famie/issues/176)から追跡する。

日次点検は[内部監視手順](../runbooks/internal-monitoring-notes.md#14-欠測を前提にした日次点検と補完)に従い、monitor時刻・各予定枠・日次成功・通知・cron登録を照合する。欠測を許容することは日次処理の未実行を放置する意味ではない。外形監視・管理サイト・別拠点backup等は従来の後続課題として残す。
