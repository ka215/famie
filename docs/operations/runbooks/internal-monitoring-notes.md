# 内部監視・安全なログ・日次バックアップ

対象：v0.19.0候補。2026-10-26（JST）作成。**ローカル検証段階であり、サーバー配置・cron登録・通知受信・商用受入は未実施**。

死活・外形監視、TLS期限の継続監視、管理サイト構築は本手順の範囲外。既存の候補・配置後疎通検証は維持する。サーバー全体・cron全体・SMTPの停止を同一サーバーだけで検知・通知することはできない。

## 1. 有効化前の確認

1. [ステージング配置手順](staging-deploy-notes.md)で固定候補を配置し、既存の環境／DBガードで配置先を確認する。両サーバー設定が`APP_ENV=production`のため、監視用環境名は明示する。
2. Web PHP／CLI PHPの版、PostgreSQLの`pg_dump`・`pg_restore`、PHPのPhar、`du`、cronとtimeoutの利用可否を確認する。新しい常駐プロセスは追加しない。
3. `storage/app/operations`・`storage/logs`が公開docroot外で、運用者とアプリ以外から閲覧できないことを確認する。新規ディレクトリ0700・ファイル0600を基本とする。Web実行ユーザーが別なら、公開読み取り権限を付けず、必要な所有者・グループ権限を設計する。
4. 既存`laravel.log`やWebサーバーログの項目・アクセス権・保持を棚卸しする。過去ログには旧方式の例外詳細が残る可能性があるため、新方式で再処理して安全になったとは扱わない。必要な保全を除き14日を目安に整理する。Webサーバー側で取得できる設定範囲と保持制約は記録する。
5. CoreServer契約600 GBのうちFamieの割当は500 GB（500,000,000,000 bytes）。他サービスを含むホーム全体を使用量にしない。Famieの商用・ステージング配置、公開物、リリース用バックアップなどを含む、親子で重複しない実ディレクトリ一覧を確定する。シンボリックリンクの別名は指定しない。

## 2. 非公開設定

環境ごとの`backend/.env`へ設定し、既存手順でconfig cacheを再生成する。宛先・SMTP認証情報をIssue・ログ・リポジトリへ書かない。SMTP接続は既存の安全なメール設定を利用し、`log`・failover等のtransportを指定しない。

| 環境変数 | 設定 |
| --- | --- |
| `FAMIE_OPERATIONS_ENABLED` | 初期false。初期ジョブ・テスト通知・cron確認後にtrue |
| `FAMIE_OPERATIONS_ENVIRONMENT` | ステージングは`staging`、商用は`production` |
| `FAMIE_OPERATIONS_RECIPIENT` | 運用者メール。アカウント通知の宛先制限とは独立 |
| `FAMIE_OPERATIONS_MAILER` | `smtp`。`mail.mailers.smtp.transport=smtp`を確認 |
| `FAMIE_OPERATIONS_QUOTA_BYTES` | `500000000000` |
| `FAMIE_OPERATIONS_QUOTA_ROOTS` | 上記の絶対パス一覧。カンマ区切り。未設定・重複・取得不能はwarning |
| `FAMIE_OPERATIONS_BACKUP_TIME` | `03:00`（JST） |
| `FAMIE_OPERATIONS_SUPPRESSED_UNTIL` | 通知を一時抑止する期限。通常空。必要時はタイムゾーン付きISO8601 |

抑止の目的・対象環境・開始／期限をIssueに記録する。期限後は自動で通知を再開する。無期限の抑止は使用しない。抑止中もチェック結果・状態を記録する。

有効化時には、非公開設定を確認後に`enabled=true`として以下を実行する（当該backend内）。初期ジョブ実行でデータを変更するため、対象環境・候補を必ず先に確認する。

```powershell
php artisan famie:monitor-internal --test-notification --no-interaction
php artisan famie:run-operation backup --no-interaction
php artisan famie:run-operation image-deletions --no-interaction
php artisan famie:monitor-internal --no-interaction
php artisan schedule:list --no-interaction
```

実サーバーのSSH上では既存運用と同じPHP CLIの絶対パスを使用する。テスト通知はチェック実行・障害状態更新を行わない。メールを運用者が受信したことは別途記録する。受信確認前に設定完了とは扱わない。

## 3. 定期実行

サーバーcronから、対象backendで`php artisan schedule:run --no-interaction`を毎分実行する。既存cronと重複登録せず、実際のPHP絶対パス、backend絶対パス、登録日時、cronタイムゾーンを非公開運用記録に残す。スケジュール時刻はコードで`Asia/Tokyo`を指定している。

| ジョブ | 頻度 | 重複・時間制御 |
| --- | --- | --- |
| `famie:monitor-internal` | 5分ごと | Laravelの重複抑止＋ファイルロック。容量は1時間ごと |
| `famie:run-operation backup` | JST 03:00 | backupと画像削除のファイルロック。画像処理180秒、dump60秒、構造検査10秒を上限の目安とする |
| `famie:run-operation image-deletions` | JST 04:00 | ファイルロック、子プロセス240秒timeout |

cronの起動側に、監視180秒、日次処理300秒等のハードtimeoutを設け、実サーバーで終了コードと子プロセス停止を確認する。Laravelの`withoutOverlapping`はtimeoutではない。メンテナンス中は通常のscheduler抑止を維持し、復帰後に最終実行結果を確認する。

全処理が同じサーバーにあるため、運用者が日次で最終実行時刻とcron自体の稼働を確認する。監視job自身の起動不能は、後続の独立した死活監視が整うまで自動通知を保証できない。

## 4. 結果・通知・障害対応

- `storage/app/operations/monitor.json`：最終実行、チェック結果、通知状態、最終容量チェック時刻。
- `backup.json`・`image-deletions.json`：開始／終了・結果・実行ID。開始のみ・失敗・直近成功26時間超過はwarning。
- `monitor-YYYY-MM-DD.jsonl`：UTC時刻・実行ID・固定チェック名・状態。30日保持。
- `storage/logs/operations-YYYY-MM-DD.log`：JSON Linesの固定イベント。`context.request_id`で応答・処理と照合。14日保持。
- 既存`single`・`monthly`チャネル名も互換のため残すが、ファイル出力は日次rotationへ統一。`daily`・緊急fallbackも秘匿化する。ファイル名は`laravel-日付.log`・`emergency-日付.log`。

アプリログはパスワード・トークン・メール・username・本文・画像・IP・URL query・生例外メッセージを含めず、許可した内部ID・結果・例外クラス等だけを記録する。任意のログ文やcontextは安全な固定表現へ変換する。追跡IDはサーバー生成UUIDで、クライアント指定を採用しない。Apache等のアプリ到達前のエラーは時刻で照合する。

`database`・`storage`は2回連続失敗で通知。それ以外は閾値到達で通知し、同一障害の再通知は60分に1回、重大化は即時、2回連続正常で復旧通知する。通知失敗時は送信済みにせず次回再試行する。容量は80% warning・90% critical。5分間の例外／5xxが5件以上、認証失敗・制限・権限拒否がそれぞれ20件以上でwarning。期限超過削除の失敗や緊急ログへのfallbackも確認する。

通知受信後は、環境と実行IDを確認 → 非公開ログ・直近変更・容量・DB・cronを照合 → 影響を判断 → 必要なら既存メンテナンス／切り戻し → 復旧確認 → 原因・影響・再発防止をIssueへ記録する。自動DB復元・自動ロールバックは行わない。機微情報をIssueへ貼らない。

ログ書き込み失敗は通常の更新を取り消さず、固定の代替エラーをPHPエラーログへ出す。記録欠落は正常扱いしない。状態ファイル破損・書込不可の場合はコマンド失敗と固定エラーを残すため、日次点検で見落とさない。容量集計はファイル使用量であり、PostgreSQLの別管理領域や提供元独自quotaの値と一致する保証はない。DB容量・inode等の取得可否をサーバーで確認し、差分は提供元の管理画面等で補う。

## 5. 日次バックアップと隔離復元

日次snapshotは`storage/app/operations/backups/<UTC日時>-<UUID>/`に保存する。`database.dump`、そのSHA256、ローカル画像diskごとのtar、全ファイルのSHA256を持つ`manifest.json`で構成する。DBは接続先の`current_schema()`に限定し、同じDB内の別サービスschemaを取り込まない。外部画像diskは黙って除外せず失敗にする。

画像テーブルと削除待ちテーブルのSHAREロックを保ちながら画像→DBを取得し、参照の整合性を保つ。ロック取得は5秒で打ち切る。取得中の画像変更・活動削除等が待機する可能性があるため、ステージングで所要時間と利用への影響を確認する。長時間化する場合は通知・容量・取得方式を再検討する。

成功したsnapshotだけを正式名に変更する。失敗した`.partial-*`を復元可能な成功世代として扱わない。日次分は7日を超えた対象のみ削除し、失敗残骸も同じ期限で整理する。日次削除対象は専用backups配下に限定し、シンボリックリンクはたどらない。別のリリース用バックアップの最低3世代・候補が参照する世代はこの処理で削除しない。

復元はまず専用テスト家族・画像を使う。実データを使う場合は、非公開の復元先・閲覧者・停止する通知／cron・検証後の削除方法を実施前にユーザーへ提示する。商用DBへ練習で復元せず、商用データを開発PCやGitHub artifactへ複製しない。

1. 非公開の隔離環境を用意し、接続先DB・schema・画像rootを確認する。メール・cron・外向き通知を無効にする。
2. manifestと全ファイルのSHA256を照合する。検査失敗時は復元しない。`pg_restore --list`の成功だけで復元成功と判定しない。
3. dumpは元schema名を保持する。別DBまたは同じ名前を安全に作成できる専用環境へ、`pg_restore --no-owner --no-privileges --exit-on-error`で復元する。既存の`restore-database.sh`は破壊的な`--clean`を使うため、隔離先ガードなしに呼び出さない。
4. tarの一覧に絶対パス・`..`・リンク等がないことを確認し、空の隔離画像rootへ展開する。画像disk名と復元先の対応を確認し、既存の実画像へ上書きしない。
5. 家族・利用者・活動の件数、画像内容、ログイン、家族間の画像アクセス拒否を確認する。検証後の専用schema・画像・snapshotの整理も記録する。

記録項目：日時（JST）、担当、対象環境・候補SHA、バックアップ識別子／SHA256、隔離先、所要時間、検証結果、後片付け。RPOは日次取得で24時間を目標とする。失敗中はRPOを達成した扱いにしない。RTOは実環境で実測して合意する。同一サーバー保存は全損対策ではなく、CoreServer提供バックアップの保存範囲・取得可能性を別途確認する。

## 6. ローカル検証と残る確認

2026-10-26：通知状態・抑止・再試行・容量・保持・秘匿化・追跡・エラー時rollbackの自動検証を実施。ローカルPostgreSQLでは専用一時schemaとダミー画像を使い、実際の`pg_dump`・tar・SHA256・`pg_restore`・復元後ログイン／画像認可を検証する`DailyBackupPostgresTest`を追加した。schemaと画像はテスト終了時に削除する。

サーバー側の権限・実所要時間・ハードtimeout・cron登録・SMTP受信・提供元バックアップの確認は、ローカル確認後の固定候補のステージング工程で行う。ローカル成功だけで運用開始・フェーズ合格・商用公開を意味しない。
