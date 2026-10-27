# カバレッジとCIの計測

v0.19.0のR04・R05は2026-10-27（JST）商用公開・受入済み（[公開記録](../change-log/2026-10-27-v0190-release-notes.md)）。以下のローカル・初回CIの未実施表記は当時の記録で、確定計測値とCI比較は後半に記載する。公開coverageバッジは保留、追加最適化・異常分岐の追試は残課題。計測失敗はジョブ失敗にし、0%・過去の値で置き換えない。SQLite、PostgreSQL、frontendは対象が異なるため平均しない。

## 実行方法と対象

| 対象 | 実行 | 母集団・除外 |
| --- | --- | --- |
| frontend-unit | `pnpm --dir frontend test:coverage` | `frontend/shared/utils/*.ts`全件。Vue、composable、E2E、型定義は対象外 |
| backend-sqlite | Release CIの専用job | `backend/app/**/*.php`全件。vendor、tests、生成コードは対象外。既存全テストをSQLiteで実行 |
| backend-postgres | Release CIの専用job | 同じapp全件を分母とし、認証・復旧・メール・リワード・競合・隔離バックアップ復元に絞って実行 |

PHPは専用jobだけPCOVを有効化し、既存の通常テストをカバレッジ付き実行へ置き換える。ローカル・E2E・静的検査・商用では有効化しない。PostgreSQLは専用`famie_test`を使い、`FAMIE_REWARD_PG_TEST`・`FAMIE_CONCURRENCY_TEST`・`FAMIE_BACKUP_PG_TEST`を有効化する。バックアップ試験はさらにランダムな隔離schemaを作成・削除する。pg_dump／pg_restoreはサーバーと同じ14系を公式PGDGリポジトリから導入する。

frontendはNode 24.22.1の標準test coverage・TypeScript transform・source mapを使用。全対象を事前importして、未実行ファイルも分母へ入れる。preloadでは関数・constructorを呼ばない。`node scripts/coverage/test-mapping.mjs`が、TypeScriptのparameter property変換後も未実行constructorの元の行と未実行分岐が残ることを実検証する。

Nodeの行数はV8の範囲に基づくため、宣言・空行を含み、未実行の文が行単位では実行済みに見える場合もある。行カバレッジだけで分岐を保証せず、分岐・関数の数字と実際のテストを併記する。PCOVは行計測でありPHP分岐カバレッジとは表示しない。別プロセスで動く競合処理も親PHPプロセスの計測には含まれない。

`coverage-results/`はGit対象外。frontendのLCOV、PHPのCloverはローカル／job内にのみ残し、CIへ公開するのは相対ソース名・未実行行・集計値だけの`summary.json`とjob summary。トークン、個人情報、ソース本文、内部絶対パス、環境値を含めない。artifact保持14日。commit/tree、dirtyフラグ、計測日時、ツール版、対象、分子／分母を保存する。対象ファイルの欠落・重複・対象外ファイル・分母異常は失敗にする。

## ローカル初回値と不足（CI実測前の記録）

2026-10-26、Windows、Node24.22.1、HEAD `a0a7f647b55cf36c61be6e4d2cd74ef7bde5fc9a`＋未コミットのR04・R05変更で取得。確定コミットのCI値はローカル合格後に別途記録する。

| 対象 | 行 | 分岐 | 関数 | 状態 |
| --- | --- | --- | --- | --- |
| frontend-unit・8ファイル／20テスト | 268/284（94.37%） | 98/104（94.23%） | 47/52（90.38%） | ローカル実測 |
| backend-sqlite | 未計測 | 対象外 | 対象外 | ローカルにdriverを追加せず、初回CIで取得 |
| backend-postgres | 未計測 | 対象外 | 対象外 | 同上。SQLiteの値で代用しない |

frontendの不足は`activityImageError.ts`のconstructor／コード判定、`calendar.ts`の一部日付処理、`locale.ts`の一部ロケール処理。詳細は各summaryの`uncovered_lines`・関数計測で確認する。画像エラー分類の単体確認、日付の無効値・ロケール境界を追試候補とする。初回値取得を目的に、数字だけを上げるテストを追加しない。

| 重要領域 | 根拠となる既存試験 | 残る限界・次の確認 |
| --- | --- | --- |
| 認証・復旧・失効 | LoginSecurityTest、PasswordSecurityTest、MemberPasswordRecoveryTest（他家族・非親・無効メンバー・旧リンク無効化を含む） | PostgreSQLでも同じ重点試験を再実行。全操作の競合網羅はv0.21.0 |
| 家族間分離 | GroupIsolationTestの一覧・詳細・書込、R03の親・子・別家族の実API検査 | 全ロール×全操作の表はv0.21.0 |
| 画像取得・更新・削除 | ActivityImageTestのアクセス境界、upload／replace／quota／disable lifecycle | 全形式・破損画像のfuzzingはv0.21.0 |
| ログ秘匿・追跡 | OperationLoggingTestの正常／拒否／例外／rollback／fallback／CLI | 任意の未知ライブラリまでの秘匿保証とはしない |
| 内部監視・通知 | InternalMonitorTest、MonitorNotifierTestの欠落結果・容量・破損状態・連続異常／復旧・SMTP失敗 | 実環境SMTP受信はフェーズ1合格済み |
| バックアップ・復元 | DailyBackupTestとDailyBackupPostgresTest | 既存CIでは後者が無効だったため専用jobで有効化。提供元の画像保管・保存期間・RTOは別途未確認 |

一律の閾値はまだ設定しない。PHP初回実測と不足行を取得後、重要領域の欠落・変更理由をレビューして低下許容量を提案する。CI値・不足一覧の確認前はR04完了としない。

## CIの変更前データと比較

取得は`gh run view RUN --json headSha,event,createdAt,updatedAt,jobs`。保存したJSONを`node scripts/coverage/ci-timings.mjs FILE...`へ渡すとwall time、job／step時間、開始までの待ち、runner使用秒を再集計できる。待ちは依存job待ち＋起動待ちを含み、純粋なrunnerキュー時間とは表示しない。runner秒は課金額ではない。

同日の成功run4件を再利用した。frontend subtreeはすべて`6639da579a352c6b61e4ba4489690016d1ddc87f`、backend subtreeはすべて`983ec82850be1f1e5236f1ab521d149d802ee6c4`。checksのrunner imageはUbuntu24.04／20261012.334.1、Node24.22.1・Composer2.10.3で一致した。

| run | event | checksのpnpm cache | wall秒 | checks秒 | Chromium秒 | iPhone秒 | verify秒 | runner合計秒 |
| --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 40280494815 | PR | hit | 549 | 74 | 263 | 369 | 149 | 889 |
| 40281588113 | 手動成果物 | miss | 586 | 98 | 266 | 458 | 121 | 984 |
| 40283543436 | PR | hit | 507 | 85 | 259 | 370 | 131 | 876 |
| 40284467635 | 手動成果物 | hit | 586 | 80 | 262 | 477 | 102 | 955 |

キャッシュ分類はchecksの実ログによる。他jobのcache hitを推測しない。PRと手動では成果物作成・uploadの有無が異なり、同一条件の4回とは扱わない。温cache3件のchecks中央値80秒、wall中央値549秒は参考値。変更後のCIは未実行で、高速化率は未確定。

採用候補は、PHP計測の専用job化、通常テストの計測付き実行への置換、ubuntu-24.04指定、Node版を含むworkflowとlockをpnpm cache keyへ反映、必須verify待機の修正。API契約検査のPHP依存導入は維持する。重要なPostgreSQL試験を実際に有効化するため、検証量とrunner秒は増える可能性がある。

主要待機要因はiPhone E2Eである。Chromium／iPhoneの2対象、workers=1、隔離DB、静的ビルド・成果物の最終検証は維持する。アプリE2E用と配布用のビルドは設定が異なるため共有しない。ブラウザーcacheやshardを未計測のまま追加しない。backendの分割が遅延を増やした場合は、テストを保持して専用jobの統合を検討する。

変更後は同じコード・runner・cache条件を可能な限りそろえ、PR・候補生成等の必要なrunを利用して各3件を基本に比較する。cache key変更直後のcoldは別枠。3件に満たない時は件数不足を明示し、不要なCIを起動して見かけの比較を作らない。

## 公開バッジの保留と再開

A12に従い、日英READMEのカバレッジバッジは保留。job artifactには固定公開URLがなく、未確定値を固定バッジにしない。外部サービス、新しい公開ブランチ、公開先への書込権限は未承認のため追加しない。

再開条件は、3対象の確定SHAでの初回計測、公開先と書込権限の承認、成功した信頼済みmainの集計だけを公開する仕組み、対象名／SHA／日時と失敗時の更新停止・古い値の識別方法の決定。稼働率バッジ・外形監視は本版対象外。

## 技術確認先

- [Node24 test coverage](https://nodejs.org/docs/latest-v24.x/api/test.html#collecting-code-coverage)
- [PHPUnit12.5 coverage](https://docs.phpunit.de/en/12.5/code-coverage.html)
- [setup-php PCOV](https://github.com/shivammathur/setup-php#pcov)
- [PostgreSQL公式Ubuntuパッケージ](https://www.postgresql.org/download/linux/ubuntu/)

## フェーズ3のローカル検証記録

2026-10-26、frontend Lint151ファイル・型検査・OpenAPI49操作の照合が成功。frontend coverage20テスト、TypeScript coverage境界試験、PR必須チェック待機5ケースが成功。backend全体は327成功／39skip、2,273 assertions。skipは専用DB・競合・バックアップ等の環境条件であり、PostgreSQL専用jobでの実行を代替しない。workflowはactionlint1.7.12、追加PHPはPint・構文検査、追加MJSは構文検査、差分はgit diff --checkが成功した。

PHP coverage driver・PostgreSQL14クライアントのCI導入と初回集計は未実行。アプリ本体のソース・依存lock・画面は変更していないため、新たな主要操作の再試行は求めず、変更内容と自動検証結果のユーザー確認後にコミット・PRへ進む。

## フェーズ3の初回CIとテストデータ修正

PR #170、run40293795463でfrontendとbackend-sqliteの計測が成功した。PR検証用merge commit `cb0c981222af10b0a00b6febdcf025f1d362480f`、dirty=false。PHP8.4.27／PHPUnit12.5.35／PCOV1.0.12、app61ファイル、行1487/1732（85.85%）。frontendはローカルと同じ268/284（94.37%）だった。これは途中結果であり、run全体の合格ではない。

PostgreSQL重点検証は110成功・7失敗。全7件がPostgresConcurrencyTestのpassword reset入力で、従来の`new-password`が現行の15文字以上という要件に反し、ロック到達前に422となった。アプリの検証を緩めず、既存PasswordSecurityTestで受入済みの`river otter orchard`へテスト入力・保存結果の期待値を変更した。実行順・ロック待ちの検証・認可・トークン失効の期待値は維持する。

修正後のローカル関連テストは20成功／31skip、87 assertions。Pint・差分検査は成功。ローカルDB接続ユーザーには一時DB作成権限がなく、専用famie_testも未用意のため、競合31ケースの実行は明示フラグ付きCIで再確認する。ローカルの既存アプリDBへmigrate等は実施していない。修正内容のローカル再確認・合格後に追加コミットする。

SQLiteで未実行だったCLI3件（画像audit・設定・削除retry）、DailyBackupのPostgreSQL専用経路等は、次節の確定値と合わせて判断する。SafeExceptionHandlerの未実行2行は非production console表示の経路、ActivityLogPolicyの未実行2行は許可経路であり、fallbackや拒否分岐の欠落とは断定しない。

## 確定候補のCI実測・フェーズ3受入

2026-10-26、PR #170をdevへ取り込み、固定候補`8185a0115113c11e5cdeff9c9a89a0f40fc381f7`、tree `dcd9f51b64a9d56391f2d818e1778a7681e331b0`、Release CI40297632142で取得した。全summaryは当該SHA・dirty=false。PHP8.4.27／PHPUnit12.5.35／PCOV1.0.12、Node24.22.1。配置40298956761の自動検査とユーザーの主要操作・既存PWA確認が合格し、release sessionへstaging_verifiedを記録した。

| 対象 | 母集団・実行 | 行 | 分岐／関数 |
| --- | --- | --- | --- |
| backend-sqlite | app61ファイル、既存全体327成功/39環境skip | 1487/1732（85.85%） | PCOVの行計測のみ |
| backend-postgres | 同じapp61ファイル、重点117成功/2166 assertions | 748/1732（43.19%） | 親プロセスの行計測のみ |
| frontend-unit | shared/utils8ファイル、20成功 | 268/284（94.37%） | 分岐98/104（94.23%）、関数47/52（90.38%） |

DailyBackupはPostgreSQLで65/87行。新たに競合31ケースと隔離backup復元1ケースを実行できた。SQLiteでlogoutが未実行でも、R03の実API検査がlogout後401を確認する。認証のロック取得後の再検証は子プロセス実行を含むため、親PCOVの未実行表示だけで機能未検証としない。

不足と追試候補は、CLI3コマンドの入力・直接実行、画像変換／保存の異常分岐、TraceRequestの最終fallback、frontend画像エラー分類・日付／ロケール境界。初回は基準値と既存の重要分岐の根拠を記録し、画面全体や全異常系の保証とはしない。以後の低下は差分・重要領域・ツール版・母集団の変更をレビューし、一律の閾値はまだ適用しない。

### 変更後3回のCI時間（秒）

| run | event | checks cache | wall | runner合計 | checks | SQLite | PostgreSQL | Chromium | iPhone | verify |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| 40296441844 | PR | hit | 561 | 1033 | 56 | 41 | 81 | 260 | 414 | 141 |
| 40297163435 | 手動・検証のみ | miss | 574 | 1097 | 57 | 44 | 118 | 271 | 441 | 124 |
| 40297632142 | 手動・成果物生成 | miss | 543 | 991 | 55 | 37 | 74 | 254 | 413 | 122 |

後者2回はbuild_artifact=false/trueの違いがある。3回ともtree dcd9f51b64a9d56391f2d818e1778a7681e331b0、checksのUbuntu24.04／image20261012.334.1は変更前と一致。backend appのtree `460fbe630492703675813e41e871a0c08d10bd65`は前後同一、frontend/app・sharedにも差分なし。計測コマンド・テストfixture・専用フラグは変わっている。

参考中央値はwall567.5→561秒、runner922→1033秒。ただし前4回・後3回、冷／温cacheとevent・検証量が異なるため、高速化率とは表示しない。checksは短縮したが、PG32ケース追加・coverage・専用jobの準備によりrunner秒は増えた。iPhone E2Eが支配的でbackend jobはcritical pathになっておらず、現時点で全体遅延の悪化は確認できない。準備の重複削減やE2Eの改善は今後の計測課題とし、検証を削らない。

初回失敗run40293795463では、必須PostgreSQL失敗によりverifyのRequire all verification jobsが失敗し、ビルド・候補作成・uploadがskipされた。成功値だけを参照して失敗を隠さない。初回失敗runを成功時間の比較に含めない。

実行記録・step時間の参照先は[Issue #165](https://github.com/ka215/famie/issues/165)、各runとartifact。公開バッジは上記A12の保留方針を維持する。フェーズ合格は商用承認ではない。
