# v0.18.1 商用配置・受入記録

2026-10-25 JST、v0.18.1を商用へ公開した。全対象のステージング実機確認と商用リリース承認を経て配置・自動検証に合格し、2026-10-26 JSTにユーザーから商用実機確認OKと事後工程の指示を受領した。

## 対応内容

- R01（元メモ1-16）：登録禁止名の共通検証。固定版のshouldbee原本からシステム・運営名18件を選別し、Famie独自12件を追加。大小文字を区別しない完全一致で、新規家族登録・親によるメンバー追加・利用可否チェックへ適用する。入力は小文字のみを維持し、既存アカウントに遡及適用しない。
- 原本・MITライセンス全文・出典とSHA256付きの定義を同梱。実行時に外部取得せず、不正な定義は新規作成・利用可否だけを503で停止する。登録画面の日英文言と非同期応答制御も更新。
- R02（元メモ1-18）：インベントリ上部に選択メンバーの金・銀・銅の全期間累計を表示。ページに依存せず獲得時のsnapshot色で数え、削除済み定義の保持履歴も含む。既存権限・トロフィー部品・索引を利用し、DB migrationは追加しない。
- メンバー切替・遅延応答・取得失敗・再試行、日英・ライト／ダーク・320px幅・読み上げに対応。1-17・1-19は保留のまま。

詳細は[v0.18.1詳細仕様](../../issues/v0.18.1.md)、[承認・検証記録のIssue #159](https://github.com/ka215/famie/issues/159)を参照。

## 固定候補・成果物

- R01 PR：[#160](https://github.com/ka215/famie/pull/160)。R02 PR：[#161](https://github.com/ka215/famie/pull/161)。商用昇格PR：[#162](https://github.com/ka215/famie/pull/162)。
- 最終候補commit：`f4ca398f8cf78f449cf0ad4a8a4cee8767002437`。
- 候補／main共通tree：`564c28c62abdf8d37a05104cbdf40ca9203f02ea`。
- main／正式タグv0.18.1：`28ef01338de2c2fbe24f1758e86c30c4b0e8dc7c`。
- 成果物生成CI：[40176785662](https://github.com/ka215/famie/actions/runs/40176785662)、artifact ID：`12592757567`。
- bundle SHA256：`38d810a068f7b1da04ffe0fdf46a6f8152435be11faf131d2c1001e0062eea43`。
- payload SHA256：`3f92ca105373777b70a3af2367306b18df58afd0dc46bfa69574608e3d884990`。
- mainのtree一致を確認してタグを付与。ステージングで確認した同じ成果物を再ビルドせず商用へ配置した。

## 検証・承認・配置

- R02 PR CI [40176178082](https://github.com/ka215/famie/actions/runs/40176178082)、成果物生成CI、商用昇格CI [40182450719](https://github.com/ka215/famie/actions/runs/40182450719)は全ジョブ合格。各全体E2EはChromium／iPhone WebKit計256件合格・6件skip、生成物スモーク30件合格・2件skip。
- SQLite全体297件合格・38件skip。PostgreSQLのリワード7件、認証42件、メール所有権36件合格（メール1件skip）。Pint・Biome・型・辞書・API契約・単体テストも合格。
- 最終ステージング配置：[40177561470](https://github.com/ka215/famie/actions/runs/40177561470)。商用配置：[40183093601](https://github.com/ka215/famie/actions/runs/40183093601)。各配置後スモーク24件合格・8件skip。
- ステージング・商用の自動検証およびユーザー実機確認は全対象合格。production Environmentの保護を維持し、ユーザーの明示商用承認に基づいてレビューを記録した。商用実機合格は2026-10-26にCLI・Issueへ記録した。
- 商用アプリ／DBバックアップ証跡：`~/famie-release-backups/releases/20261025-190300-v0.18.1-564c28c62abd`。
- ステージング証跡：`~/famie-stg-release-backups/releases/20261025-174055-v0.18.1-564c28c62abd`。
- 既存データ・設定・PWAキャッシュを保持。切り戻しは不要だった。

### ステージング初回停止の記録

初回配置[40177492556](https://github.com/ka215/famie/actions/runs/40177492556)は、最初の読み取り専用環境確認PHPがexit 143で終了した。既存アプリ・DBの変更前に停止し、稼働中APIのHTTP 200を確認した。原因は未特定。コード・スクリプト・成果物を変えず再試行した上記40177561470は正常完了した。商用配置では再発していない。両runのログを保持する。

## 事後工程

[GitHub Release](https://github.com/ka215/famie/releases/tag/v0.18.1)へtar.gz・checksum・manifestの3資産を公開し、再ダウンロードして元資産とのSHA256一致を確認した。main→dev同期は[PR #163](https://github.com/ka215/famie/pull/163)。公開記録・詳細仕様・課題一覧・文書索引・ロードマップを更新し、版情報を`current=0.18.1`／`next=null`へ進める。次の計画対象はv0.19.0で、作業ブランチ作成時に候補版を設定する。

公開済みアプリ成果物・正式タグは事後工程では変更しない。確認済みのR01旧セッション・成果物も保持する。詳細な配置ログ・バックアップ・検証結果は各workflowとIssue #159を参照する。
