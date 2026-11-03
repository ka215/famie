# 代表管理者・退会・メンバー削除 実装詳細素案

作成・承認：2026-11-03（JST）。状態：**正式仕様とともにユーザーが全体を確認・承認し、次工程への指示を受領。F01（代表管理者の固定・保護・表示）に着手**。本文中の「案」「初期値」は承認した実装方針とし、計測・実環境確認が必要な項目は結果を追記する。商用設定変更・実機受入・商用承認は未実施。

## 1. 前提・判断の境界

[正式仕様・版8](account-lifecycle-spec.md)を実装へ落とし込む。DB行の物理削除を目的とせず、即時のアクセス停止、識別情報のマスク、本文消去、画像の保持猶予なし削除、削除者の送信済みいいね維持を実現する。一般の投稿削除等を無関係に仕様変更しない。

本書のテーブル名・API・バッチ件数・時間配分は提案値であり、正式仕様の承認と本素案の承認を区別する。バージョン情報を更新せず、実装着手・コミット・公開の完了とは扱わない。

### 1.1 ユーザー判断済みの事項

| ID | 決定 | 理由・影響 | 状態 |
| --- | --- | --- | --- |
| D01 | 所属の家族IDを履歴参照として残し、`deleted_at`で論理的に所属解除 | 現行の活動・いいね・リワードの複合外部キーを維持できる。物理的な家族IDの解除という従前案を具体化する際の変更 | 2026-11-03承認済み |
| D02 | 無効化中も送信済みいいねを集計に含める | 現状は無効化で除外される。削除時だけ復活する不自然な増加を避け、削除後維持と一貫させる。無効化仕様への変更 | 2026-11-03承認済み |
| D03 | PHP Sodium拡張を暗号化の前提にする | 公開鍵だけで暗号化できる標準実装を使う。ローカルでは無効、商用CLI／Webの確認と必要なら有効化が必要 | 2026-11-03承認済み |

D01〜D03に加え、ユーザーが本書全体を承認して次工程を指示した。商用公開の承認とは区別する。Sodiumの利用可否確認・設定変更・Composer要件追加はF02以降の実装工程で行い、F01では実行しない。

## 2. 現行調査結果

| 領域 | 確認した実装 | 本版で必要な変更 |
| --- | --- | --- |
| 実行環境 | ローカルPHP8.4.27、Laravel13.32.0、Sanctum4.3.3。OpenSSLあり・Sodiumなし | 商用CLI／Web・CIを別途確認。ローカル結果を商用へ外挿しない |
| 所属 | `group_members`は`UNIQUE(user_id)`・`UNIQUE(group_id,user_id)`。状態はactive/inactive | 削除状態と認証除外を追加。履歴FKの扱いはD01 |
| FK | 活動といいねは`(group_id,user_id)`、リワードは`(group_id,group_member_id)`で所属を参照 | group_idの単純NULL化・所属行削除はできない |
| 親保護 | `GroupMembershipService`は自分・最後の親の変更を制限 | 代表者を対象にした降格・無効化・他者パスワード再設定を追加禁止 |
| いいね | `ActivityLike::fromActiveMembers`をカード・累計・`RewardService`が利用 | 対象投稿の可視性と、送信者の寄与保持を別に判定 |
| 画像容量 | `GroupController`と`ActivityImageService`が`activity_images.bytes`を合計 | 削除待ち容量を含める共通計算が必要 |
| 画像削除 | `ActivityImageService`は削除待ち行を作り、画像行削除後にafterCommitでファイル削除 | 削除待ち行に家族ID・bytes・対象画像IDがない。消去まで容量を保持できない |
| 本文 | `ActivityLog`にSoftDeletesなし。通常投稿削除は既存処理 | メンバー削除対象の論理削除・本文消去を追加し、既存通常削除との混在を検証 |
| 複製情報 | `RewardService`が`reward_awards.snapshot.member_name`に表示名を保存 | usersだけのマスクでは不十分。snapshot・通知payloadも点検 |
| 定期処理 | 商用は毎時scheduler1件、全体150秒＋終了猶予5秒。画像再削除は日次 | 既存枠に時間を区切って追加。新cron・常駐workerの稼働を前提にしない |
| backup | `DailyBackup`は画像ディレクトリ全体とDBを収録。画像削除と共通の運用ロック・DBテーブルロックあり | 新しい消去経路も同じ排他に参加。vaultは収録しない |

確認元はリポジトリのmigrationとコードであり、商用schemaの実照合・データ件数計測は未実施。実装前に利用可能なschema確認手段で差異を確認する。Laravel Boostのschema／search-docsツールは本セッションでは提供されていない。

## 3. DB・索引案

### 3.1 既存テーブルへの追加

| テーブル | 追加・変更案 |
| --- | --- |
| `groups` | `representative_user_id`（usersへのRESTRICT FK）、`withdrawn_at`、`lifecycle_operation_id` |
| `users` | `deleted_at`、`lifecycle_operation_id`。認証・通常関係は削除済みを除外 |
| `group_members` | `deleted_at`、`lifecycle_operation_id`。削除時はstatus=inactive、家族IDは履歴文脈として保持（D01） |
| `activity_logs` | `deleted_at`、`content_erased_at`、`lifecycle_operation_id`。通常モデルは論理削除を除外 |
| `activity_images` | `retired_at`、`lifecycle_operation_id`。API配信・通常のimage関係からは退役画像を除外 |
| `activity_image_deletions` | `group_id`・`bytes`・`activity_image_id`・`operation_id`・`attempts`・`next_attempt_at`・非秘密の`last_error_code`を追加 |

D01では、usersの新しいIDを作って再登録するため`UNIQUE(user_id)`を維持できる。削除済み所属への`withTrashed`等は非公開履歴用の明示的な経路だけに限定する。group_idの物理的解除や別の履歴主体への移行は採用しない。

PostgreSQLには通常の家族別・ユーザー別・日付別取得用に`deleted_at IS NULL`の部分索引、消去用に`(lifecycle_operation_id,id)`、削除待ちに`(next_attempt_at,id)`・家族別容量索引を検討する。SQLiteは同じ論理結果を試験し、実行計画の合格は専用PostgreSQLで判断する。索引は既存索引と重複させずEXPLAINで選ぶ。

### 3.2 新規テーブル案

| テーブル | 主な項目・用途 |
| --- | --- |
| `account_lifecycle_operations` | UUID受付ID、actor・家族・対象所属ID、member_delete/group_withdraw、準備／確定／中止、cleanup状態、prepared_expires_at・committed_at・retention_until・content_cursor・時刻 |
| `account_lifecycle_subjects` | operation_id・user_id・membership_idの対象一覧。家族退会で人数分保持。元の名前・メールは保存しない |
| `account_lifecycle_credentials` | operation_id、SHA256確認コードハッシュ、有効期限。30日失効時に行を削除。高エントロピー値のみを対象とする |
| `account_lifecycle_holds` | 対象・保全範囲・根拠の参照番号・担当者・開始／見直し／解除日時。証拠原文や秘密値は入れない |

operationsは確定記録の稼働コピーでもある。別保全するledgerを併用し、復元DB内の記録だけへ依存しない。90日監査はDBの全体dumpへ際限なく複製せず、専用JSONL等でイベント発生時刻を基準に整理する。

### 3.3 代表者移行

1. 新列は一時nullableで追加し、旧コード稼働中の先行強制を避ける。
2. migration／専用コマンドで各家族のMIN(users.id)を求め、有効adminであることを検証する。不正対象は停止・報告し、自動交代しない。
3. 移行前後のID対応・件数を非公開で照合し、全有効家族で代表者を確定。新規登録は同一トランザクションで代表者を保存する。
4. 家族の代表者ID更新APIを作らず、更新可能項目のallowlistへ追加しない。家族ロック下で代表者の所属不変条件を検証する。

ローカルSQLだけで商用データを書き換えない。候補配置時のschema照合・backup・移行・失敗時のメンテナンス維持を受入条件にする。

## 4. 共通のアクセス停止・認証・マスク

- `User`・`GroupMember`・`ActivityLog`の通常取得へ削除除外を導入する。`Group`はwithdrawn_atを確認する共通scopeを使い、route bindingだけに依存しない。
- `EnsureActiveGroupMembership`の読み書き、`AuthController`のlogin/me/update、メール認証・メール変更・パスワード復旧の`AccountEmailService::withActiveUser`を点検し、削除ユーザー・所属・退会家族は拒否する。
- 認証済みユーザー共通の削除チェックをSanctumの後へ追加する。logoutは冪等な失効として扱う。古いトークンのDB行が残った障害ケースでも業務APIへ通さない。
- `DB::table`・join・`toBase`はモデルの論理削除に依存せず、明示的な家族・削除条件を付ける。全テーブルで一斉にSoftDeletesを追加して完了としない。
- loginのusername/email条件は括弧で囲み、OR条件が削除scopeをすり抜けないよう確認する。
- ロック順は既存に合わせ家族→所属ID昇順→ユーザーID昇順→対象関連行とし、変更・削除・メール・パスワード処理が同じ順序に従う。

マスク値案：username=`deleted:{user_id}:{operation短縮ID}`（通常登録で使えないコロンを含め、一意性を保証）、display_name=`Deleted member`、email=null、退会家族名=`Withdrawn family`。UIは削除状態から日英表示する。DBの型長・一意制約に収まることを検査する。

旧メールを使って`clearChallenges`等で復旧tokenを消去してからメールをnull化する。passwordは不明なランダム値のhashへ変更し、remember_token・pending_email・verification関連を消去、全Sanctum tokenを失効する。再登録は別内部ID。旧リンク・遅延処理を旧IDと照合し、同一メールで再登録した別ユーザーへ作用させない。

`reward_awards.snapshot.member_name`・DB通知・遅延メール等の表示名複製を小分け処理で消去する。画面/APIは処理完了を待たず削除主体を除外／固定名表示する。家族退会時のカテゴリ名・リワード名／説明・snapshot内の自由入力の消去対象も棚卸しし、usersとgroupsだけで個人情報消去済みと断定しない。

## 5. API・受付情報の設計案

### 5.1 準備→確定→結果確認

| API（`/api/v1`配下） | 認証・入力 | 成功応答 |
| --- | --- | --- |
| `POST /groups/{group}/lifecycle-operations` | 代表者。`kind`、member_delete時の`membership_id`のみ | 201：受付ID、問い合わせコード、準備期限、対象・影響概要 |
| `POST /groups/{group}/lifecycle-operations/{operation}/commit` | 代表者、`current_password`・確認コード・`confirmed:true` | 202：committed_at、retention_until、アクセス停止確定、cleanup状態 |
| `POST /lifecycle-operation-status` | 未ログイン可。受付IDと確認コードをbodyへ送信 | 200：準備／確定／中止、日時、cleanup状態のみ |

コードは`random_bytes(32)`のbase64url（約43文字）とし、URL・query・ログ・通常メールへ載せない。prepareは元の識別情報を退避せず、破壊操作もしない。有効15分、1家族1件を基本とし、期限切れ準備は中止・資格情報を消去する。確定したコードの有効期限はcommitted_at+30日で固定する。

確認画面で受付IDとコードをコピー／テキスト保存でき、控えを保存した明示確認後にcommitする。原文コードをサーバーに保存しないため、応答消失時も同じコードを再発行する方式にしない。確定前に控えを利用者へ渡しておくことで、代表者のtokenを退会で失効した後でも結果だけ確認できる。

結果確認は業務認証の代替にせず、氏名・メール・内部対象ID・本文・家族詳細を返さない。受付不存在・コード不正・期限切れは同じ404とし、受付IDの列挙を抑える。Cache-Control no-store、Referrer-Policy no-referrerを指定し、コードはフロントのメモリだけに保持する。公開の退会結果ページだけをauth.globalの例外とする。

再送は同じoperationに結び付ける。commit済みの再送ではマスク・退避・期限を重複作成しない。退会で失効したtokenからcommitを迂回して再実行させず、結果確認APIを使う。結果確認と暗号化情報の開示は別物で、後者は運営者の本人確認が必要。

### 5.2 入力・エラー・制限案

- 401：未認証の破壊操作。403：同一家族の非代表者／代表者への禁止操作。404：他家族／不存在対象。409：有効メンバーの削除、準備期限切れ、対象状態変化、競合、backup排他中。422：形式不正・false確認・パスワード不一致。503：vault／別ledger記録不可・暗号環境不備。
- 確認コード・current_passwordの入力は全allowlistで検証し、文字列の暗黙変換やトリミングによる意味変更を避ける。保持選択、代表者変更、actor指定等の未知項目は拒否する。
- 初期案：prepareは代表者5回/時、commitのパスワード検証はユーザー5回/15分＋IP20回/15分、公開statusはIP30回/分＋受付10回/分。429にRetry-After。運営者用CLIの誤照合も監査・制限する。
- 新APIに機械判定用codeを付け、日英message・既存のX-Request-ID・エラー整形を維持する。secretをTraceRequest／例外／監査のcontextへ入れない。

## 6. 確定処理とクラッシュ整合性

通常DBとファイルと別保全ledgerは分散トランザクションにできない。確定前の耐久記録と、途中状態の照合を明示する。

1. 家族ロック、対象・代表者・パスワード・準備期限・コードを再検証。準備時に対象だった家族が退会／対象が再有効化されていたら409。
2. 対象の元の限定情報をメモリ内でJSON化・暗号化。vaultへ受付IDで排他的に書込み、一時ファイルからrenameし、SHA256とサイズを確認する。平文一時ファイルを作らない。
3. DB以外のledgerへ`intent`を先行追記し、書込み結果を確認する。ここに対象ID・確定予定時刻・vaultハッシュを持たせ、本文・コードを入れない。
4. DBトランザクションでoperation確定、対象を削除／退会状態、所属を論理解除、識別情報マスク、認証失効、cleanupの永続cursorを記録する。家族人数は最大10が既定なので対象ユーザーの即時マスクは同一処理で行う。
5. DB確定後、ledgerへ`committed`を追記。即時の短時間cleanupを起動し、残りは定期処理へ渡す。DB状態確定とファイル全消去完了を別の応答項目にする。

vault／intent保存に失敗したらDBを確定しない。DB失敗はrollbackし、ledgerに`aborted`を記録、準備vaultを回収する。DB確定後のledger完了追記失敗は「未退会」と返してやり直させず、確定済み・照合必要の障害として扱う。

DB確定前後でプロセスが落ちた場合、live DBのoperation・vault・intentを照合して完了／中止を確定する。外部ledgerがintentだけで古いbackupを復元した場合、確定／rollbackを自動判別できないため、メンテナンスを維持して個別照合する。intentだけで現利用者のデータを自動消去したり、そのまま公開したりしない。

別媒体へのledgerコピーが更新されない状態も監視する。単一サーバーだけのintent書込みはサーバー全損と同時に失われ得るため、別媒体同期・復元を受入で試験する。完全な同時障害耐性を根拠なく保証しない。

## 7. 大量履歴の消去・参照・いいね

### 7.1 即時遮断と小分け更新

全投稿へ同期で巨大なUPDATEを発行する代わりに、家族withdrawn_at／ユーザー・所属deleted_atを即時の遮断条件にする。各API・画像配信・countはこの条件を確認するため、投稿側deleted_atの付与が未完でも見えない。物理的な投稿更新はcleanupで`id > cursor`のkeysetを使い、1回100件を初期値として進める。

本文は`content=''`、`note=null`、`deleted_at=committed_at`、`content_erased_at=処理時刻`を付与する。未消去行を索引で取得し、cursor進行と更新を同じ短いtransactionで記録する。保全対象はスキップと別管理を行い、cursor通過で永久に取り残さない。原文をjob payloadへ入れず、IDだけで処理する。

スナップショットの表示名・通知dataの消去も別cursorで処理する。既存の通知はログインID・表示名等を含む可能性を調査し、JSON全体をログへ出さない。家族退会済みのカテゴリ・リワードの自由入力の扱いは消去対象一覧としてレビューする。

### 7.2 いいね・リワード（D02承認済み）

`fromActiveMembers`を意味が一致する`fromRetainedContributors`等へ置換し、「同じ家族の有効／無効／削除履歴の正当な送信者」を認める。対象投稿は未削除・現在有効な所有者に限定する。家族をまたぐuser_idだけの結合は使わない。

無効化・削除は送信済みいいねを取り消さず、一般の取消操作は有効な本人だけに許可する。削除された本人の投稿は対象外。カードの件数・獲得いいね累計・RewardServiceのcountを同じ条件へ揃える。公開の送信者リストを新設せず、必要な名前表示は削除状態に応じた固定名にする。

現行の無効化による除外は新仕様の適用時に撤廃し、既存の無効メンバーの送信済みいいねも同じ条件で計上する。切替時に表示件数・現在期間の進捗が増加し得るため、既存の評価処理に従った反映と新規獲得の有無を試験する。既存獲得履歴を取り消さず、過去全期間の新規トロフィーを移行だけで一括発行しない。

## 8. 画像・容量の整合性

### 8.1 容量の二重計上・早期解放を防ぐ

共通`GroupImageUsage`を導入し、家族の使用量を「activity_imagesに残る全実在予定のbytes（retired含む）＋画像行を持たない削除待ちbytes」とする。対象画像が両テーブルにある場合は一度だけ数える。

メンバー削除後、画像行を即座に消さずretiredにして削除待ちへ渡す。配信は親投稿／所属削除でも遮断する。ファイル消去成功後、同一DB transactionで画像行・待ち行を消し、初めて枠を解放する。ファイル消去後にDB更新失敗なら次回「ファイル不存在」を成功として再照合し、過剰計上を回収する。

既存の差替え・通常投稿削除で画像行が消える経路では、待ち行へ家族ID・bytesを移して引き継ぐ。applyの`used - old.bytes + new.bytes`は、旧ファイルが残る間に容量を解放するので見直す。新画像を許可する時点では旧画像消去が未確定なら旧bytesを引かない。この副次的な挙動変更は画像回帰試験で検証し、容量上限ぎりぎりの差替えでは先に旧画像削除が必要になることを表示する。

既存の削除待ち行は家族ID・bytesを持たないため、自動で0と扱わない。配置前に画像実体・backup等から照合できるか確認し、帰属不明なら未解決件数を残して当該経路の移行を停止する。商用ファイルをこの設計作業で削除しない。

### 8.2 backupとの排他

メンバー削除も、`OperationState`の既存`image-deletions`ロックと整合するサービスでファイル消去する。DailyBackupのSHAREロック中は消去を進めない。既存のafterCommit直接消去・retryコマンドも共通経路へ寄せ、片方だけ排他を守る状態にしない。

ロック順の逆転を避け、backupロックが必要な操作は家族DBロックより先に短時間・非待機で取得する。取得不可はファイル消去を延期し、アクセス停止と容量計上を維持する。画像準備ファイルのrollback回収も実在容量監視の対象にする。

## 9. 暗号化・鍵・保存先（D03承認済み）

D03で承認されたSodiumを前提に、ランダムなデータ用鍵でXChaCha20-Poly1305のAEAD暗号化、データ用鍵は`Sodium sealed box`で運営者公開鍵へ暗号化する方式を具体案とする。Composerに`ext-sodium`を必須宣言する方針も承認済み。依存・設定変更は今回行わない。

ファイルの例：`format_version`、`key_id`、受付ID、committed_at・retention_until、base64のnonce・sealed_key・ciphertext。受付ID・期限・環境・key_idをAEADの追加認証データに含め、取り違えを検出する。秘密鍵・データ用鍵の平文は永続化しない。sealed boxは改ざんを検出するが送信者の真正性を保証しないため、vaultハッシュを別ledgerと照合する。

秘密鍵は管理端末で生成し、パスフレーズ付き暗号化ファイルと暗号化オフライン予備に保存する。鍵ファイル保護にも標準のパスワードKDFと認証付き暗号を使い、KDF parameters・saltを形式に含める。メモリ上の平文を可能な範囲で破棄し、平文一時ファイル・shell引数・環境ログへ秘密を出さない。公開鍵だけをSSHでサーバーに配置する。

ローカルはSodiumなし、OpenSSLありを確認。商用CLI／Web・CIは未確認。実装時に可否を確認し、必要なら有効化する。利用不可ならその環境での実装・公開を止めて連携し、既存OpenSSL等へ無断でfallbackしない。

保存先は`FAMIE_LIFECYCLE_VAULT_ROOT`・`FAMIE_LIFECYCLE_LEDGER_ROOT`等の絶対パス設定とする。商用／stagingを分離し、public・storage/app/private・images・releaseディレクトリ・通常backup内を拒否する。シンボリックリンクを許可せず、ディレクトリ0700・ファイル0600相当。実パスは公開文書に書かず、非公開運用設定へ記録する。

鍵IDを更新しても期限内・提供元backupに残り得る旧鍵を即削除しない。復号CLIは受付IDと期限・ledgerハッシュを確認し、期限切れは拒否する。秘密鍵紛失時は復元を約束せず、新公開鍵への切替と失われた範囲を記録する。

## 10. 定期処理・監視・復元

### 10.1 実行経路・予算案

`famie:process-account-lifecycle --max-seconds=10 --batch=100`を提案する。Web commit後は最大2秒の少量処理、残りは毎時の既存schedulerから呼ぶ。コードの処理受付は即時だが、全画像消去・全文消去が同じHTTP応答内で終わる保証はしない。10秒・100件は計測で調整する初期値。

処理優先は期限切れvault／資格情報→画像消去→本文・snapshot消去→ledger照合・保持整理。単一operationが独占しないよう対象を巡回する。24時間以上の未消去や期限超過はwarning、消去失敗・別ledger書込不可はcriticalの案。監視通知は元情報・コードを含めない。

monitorが現在先に登録されているため、新処理の状態を次回まで見落とさないよう、実行結果を直ちにOperationStateと監視通知へ連携する。backup03:00、画像日次04:00の処理を消さず、毎時追加分を含め全体150秒に収まるか計測する。必要なら処理を次回へ繰越し、timeoutを伸ばして解決しない。

現行cronには欠測の既知制約がある。日次点検・手動コマンド・同一ロックを使う再処理を用意し、監視の最終成功時刻だけで消去完了にしない。バックグラウンドの稼働基盤を確認せず、ジョブ投入だけで速やかな消去を保証しない。

### 10.2 90日監査・サポート

操作／照合／復号／保全指定／解除は専用の90日監査へ書く。既存OperationLogの14日保存とは別にし、保存可能なcontextをallowlist化する。監査にはreceipt・ID・event・結果・担当者・時刻だけを含める。

support@famie.coはユーザー作成済み。受信・返信の疎通は未確認。運営者用CLIはコード照合を非表示promptで受け取り、shell historyへ残さない。問い合わせ対応終了日を台帳に記録し、90日後に受信・送信・添付・ごみ箱・管理端末コピーを処分する手順を用意する。メールに秘密情報が届いた場合は再転送せず、不要な複製を増やさない。

### 10.3 外部ledger・復元

別保全ledgerは受付ごとのintent／committed／aborted／cleanup進捗・保全状態を記録する。提供元backupと一緒に巻き戻らない管理端末等へコピーし、ハッシュ／署名検証・同期時刻を管理する。まずは自動の読み取り取得と暗号化端末保存を候補とし、稼働時間・同期遅延・障害時の欠落範囲を受入で確かめる。

保持は「復活させ得る全backupの期限経過＋30日、かつcleanup完了まで」。DB・画像・アプリbackupの入れ子・提供元15世代・調査コピーを登録した台帳から最終復活可能日を算定し、未確認なら期限を自動短縮しない。元の識別情報・問い合わせコードは入れない。

復元時はmaintenance→backup／台帳と外部ledger取得→intent未確定の個別照合→削除／マスク／認証失効再適用→本文・画像消去と期限切れvault・コード・90日監査の消去→容量再照合→公開可否の確認とする。準備済みpublic鍵とledgerだけから秘密鍵を復元できない。メール復元では既に期限切れのsupportメールの再消去も行う。

CoreServer V1の日次15世代・除外不可を維持。vaultはアプリbackupへ入れず約45日、原文／画像の入れ子コピーは約22日残存し得るという正式仕様の説明に合わせる。厳密な消滅保証に読み替えない。

### 10.4 法的保全

運営者専用CLIで根拠参照・対象・範囲・期限・担当者を入力し、通常消去を停止するholdを記録する。運営者による月1回および期限到達時の見直しを初期案とし、命令等に別の期限があれば優先する。保全情報の外部提供はこのCLIの権限に含めない。

元の人物情報が必要な案件記録は運営者の別管理資料とし、通常の公開DB・監査へ転載しない。holdは外部ledgerにも反映し、cleanupと同じ対象ロックで競合を防ぐ。解除後は元の期限で速やかに消去する。

## 11. UI・変更範囲・検証

- `settings.vue`・`MemberManageDialog.vue`の既存導線を拡張し、代表者表示、代表者に対する禁止操作、無効メンバーの削除と確認を追加する。一般メンバー向けの退出ボタンは作らない。
- 退会は家族全体の危険操作として独立表示。prepareで控えを保存→影響確認とpassword→commit→公開結果画面の順。機密値はlocalStorageへ保存しない。
- API型へ`is_representative`や必要なcapabilitiesを追加し、親という判定をすべて特権へ置換しない。日英辞書・生成物・OpenAPI・契約検査を同時更新する。
- 一般メンバー一覧・inactive一覧・リワード対象は削除済みを除外する。閲覧画面に削除済みメンバー管理を追加しない。

| 段階 | 検証対象 |
| --- | --- |
| 移行 | 最小userID・不整合停止・代表者固定・既存ID維持・FK整合・旧版との混在 |
| API | 代表者保護、同一家族／他家族、有効対象削除409、入力偽装、password不正・制限、削除直後の認証・メール・画像直接取得拒否 |
| 永続処理 | vault保存失敗、DB rollback、intentのみ、DB確定後の応答消失、二重commit、再有効化との競合、cleanup再試行 |
| DB／PostgreSQL | 履歴FK、query builder除外、いいね・進捗維持、再登録別ID、snapshot消去、keyset・索引の実行計画、容量二重計上なし |
| ファイル | 退避改ざん・取り違え・symlink拒否、期限拒否、backupとの排他、実ファイル消去失敗／DB更新失敗、copy復元時の再消去 |
| E2E／実機 | 日英、代表者／他の親、控え保存・応答断・結果照合、無効化／再有効化、iPhone Safari／既存PWA、画像容量更新 |
| 運用 | 商用CLI／Web暗号可否、support送受信、鍵予備からの復号、毎時150秒内・欠測補完、外部ledger復元と期限算定 |

定義済み`pnpm lint`・`typecheck`・`i18n:check`・関連unit／Playwright・`test:api`、既存PHPUnitと専用PostgreSQLを使う。文書素案作成ではアプリ試験・buildを実行しない。

## 12. 進め方と未実施事項

1. D01〜D03と本書全体は承認済み。残る実パス・鍵管理端末・ledger別媒体の選定は非公開の運用設定として決める。
2. 最新devからfeature/v0.20.0を作成済み。規約側と切り分けたF01〜F03を版別詳細要件とロードマップへ反映した。進捗・受入は[Issue #193](https://github.com/ka215/famie/issues/193)で管理する。
3. 代表者移行・保護→削除・vault・容量・cleanup・復元→運用確認→規約・同意の順に実装・検証する。
4. ローカルのユーザー合格、PRとCI、固定ステージング実機、最終回帰・商用承認を経る。migration・環境変更・依存変更は素案作成と別工程。

素案作成時は文書・参照リンクだけを変更した。全体承認後はF01からアプリ実装と検証を開始する。商用DB・実ファイル・メール設定・鍵・cronは、このローカル実装では変更しない。

## 13. 参照資料

- [正式仕様](account-lifecycle-spec.md)・[決定記録](decisions/2026-11-03-account-lifecycle.md)
- [v0.20.0詳細要件](../issues/v0.20.0.md)・[ロードマップ](v1.0.0-roadmap-plan.md)
- [Laravel13 Eloquent](https://laravel.com/docs/13.x/eloquent)：論理削除・一括処理のmodel event非発火を確認。副作用をイベント任せにしない
- [Laravel13 Queues](https://laravel.com/docs/13.x/queues)：transaction後の処理を確認。afterCommitだけを唯一の永続再試行手段にしない
- [PHP Sodium sealed box](https://www.php.net/manual/en/function.sodium-crypto-box-seal.php)・[AEAD](https://www.php.net/manual/en/function.sodium-crypto-aead-xchacha20poly1305-ietf-encrypt.php)：改ざん検出と送信者認証の違いを含む
- [PHP OpenSSL公開鍵暗号](https://www.php.net/manual/en/function.openssl-public-encrypt.php)：OAEP digest指定はPHP8.5追加
