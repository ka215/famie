# famie.coへの移管計画

作成：2026-10-16。v0.15.1の成果物は本計画のみ。実施はv0.16.0以降。DNS・サーバー・アプリ設定の切替は未実施。

**2026-10-17更新**：実施版の正本は [v0.16.0詳細仕様](../../issues/v0.16.0.md)。新docroot `~/public_html/famie.co` を使用し、旧docrootを切り戻し用に保持する。ユーザーが家族の2グループのみであることを確認済みで、移管時のメンテナンスは不要と確定した。以下はv0.15.1時点の準備計画として残す。既存公開先の再利用、グループIDの再確認を前提にする記述、メンテナンス工程は、実施時にはv0.16.0の方針で置き換える。

## 採用方針

[v0.15.1のD04・D05](../../issues/v0.15.1.md#6-判断結果d01d05承認済み)に基づき、同一CoreServer V1上で既存公開先を再利用する。新正規URLは`https://famie.co`（wwwなし）。利用者は再ログインし、新URLからPWAをホームへ追加する。既存PWAやCookieの自動移行は行わない。旧URL・証明書・メール送信元は当面維持する。

本番のactiveグループがID 1・2のみならユーザーの家族以外の利用者はいない、というユーザー申告に基づき、外部顧客向けの移行条件・告知待機期間は不要とする。これは**本番DB確認済みという意味ではない**。実施直前に確認し、条件不成立ならユーザーへ連携する。

| 資源 | 現在 | 切替後 |
| --- | --- | --- |
| 正規URL | `https://famie.ka2.org` | `https://famie.co` |
| フロント実体 | `~/public_html/famie.ka2.org` | 同じ物理公開先を優先 |
| API公開 | 上記の`api`→`~/famie/backend/public` | 同じsymlink、URLは新ドメインの`/api/v1` |
| バックエンド・storage | `~/famie/backend` | 維持 |
| DB・user・schema | `ka2_famie` | 維持 |
| APP_KEY・画像実体・トロフィー履歴 | 既存 | 維持・初期化しない |
| バックアップ | `~/famie-release-backups` | 維持 |
| ステージングURL・公開先 | `stg-famie.ka2.org`・`~/public_html/stg-famie.ka2.org` | 維持 |
| ステージングDB | `ka2_famiestg` | 維持、IP・メール送信先制限も維持 |
| メール送信元 | `noreply@famie.ka2.org` | 当初は維持 |

CoreServerの現契約・管理画面で新サイトの公開先を既存ディレクトリに指定できるかは未確認。可能なら公開物を移さない。不可なら`~/public_html/famie.co`へフロントだけ配置し、API symlinkを既存backendへ向ける。symlink公開の可否もホスト側で確認する。DB・backend・画像実体を二重化しない。

## 事前確認と実施証跡

担当はユーザーと実施エージェント。CoreServer／DNS管理画面はユーザーが利用可能な手段で設定する。時刻、変更者、設定差分、検証結果、成果物SHA、バックアップ場所を実施版のchange-logに記録する。秘密値は記載しない。

- DNS管理サービス・現在のA/AAAA・新ドメイン登録状態・CoreServerサイト割当・TLS取得可否を確認する。未使用AAAAが別サーバーを向く状態を残さない。
- 新旧ドメイン双方の証明書と有効期限、更新経路を確認する。旧HTTPSも転送前にTLS接続が必要なため維持する。
- 公開ディレクトリ、`.htaccess`、API symlink、設定キャッシュ、SW・manifest、現行成果物・復旧用成果物のSHAを確認する。
- production接続先がDB/schema/userすべて`ka2_famie`であることを既存ガードで確認する。
- `groups`にはactive列がない。`group_members.status='active'`が存在するグループを「activeグループ」として読取専用で確認する。IDだけで外部利用者の有無を推定しない。

実施時のDB確認例（接続先を確認したPostgreSQLセッション内、パスワードをコマンドに含めない）：

```sql
BEGIN READ ONLY;
SELECT current_database(), current_schema(), current_user;
SELECT DISTINCT g.id
FROM groups g JOIN group_members m ON m.group_id = g.id
WHERE m.status = 'active'
ORDER BY g.id;
COMMIT;
```

結果が厳密に`1, 2`ならユーザーの申告条件を適用できる。追加・不足があれば構成を連携して確認する。無効グループや過去履歴を削除して条件を成立させない。

## 後続版で行うコード・設定変更

| 対象 | 必要な変更／検証 |
| --- | --- |
| `server/deploy-artifact.sh` | productionのhostと物理docrootを分離。API symlink・DBガード・バックアップ保持先を維持 |
| `deploy.sh`・`test-targets.sh` | 旧補助手順の対応表とテストも整合。通常配置を旧URLへ戻さない |
| `check-deploy-environment.php`・`test-environment.php` | 新APP_URLを期待し、DB/schema/userの厳密検査を維持 |
| `deploy-release.yml`・`rollback-release.yml` | 公開URLとスモーク検査先を新本番へ。staging分岐は維持 |
| `rollback.ps1`と共通配置ツール | 過去成果物の復旧も新しい公開先対応で実行できることを検証 |
| 本番`.env` | `APP_URL=https://famie.co`、`FAMIE_FRONTEND_URL=https://famie.co`、新originのCORS。DB・APP_KEY・メール配送設定は保持 |
| Cookie／Sanctum設定 | 現行はhost限定CookieからBearer送信。新側で再ログイン。不要なクロスドメインCookieやトークン転送を追加しない |
| フロント設定・公開物 | API相対パス、SW/manifest、アイコン、メールリンク受信ページを確認。`HomeInstallGuide.vue`の旧URLとQR画像、関連E2Eも更新 |
| ドキュメント | OpenAPI servers・現行公開手順・運用対応表を更新。過去の公開記録の旧URLは維持 |

旧公開先を再利用する場合は、サーバー側で旧Hostのみを案内／転送し、新Hostには通常のSPA・APIを返す。Host条件を外すと新サイトも自分自身へ転送するためループ検査を必須とする。公開URLと物理パスの全置換は行わない。

## 切替手順

1. 上記確認を完了し、採用docroot・切替日時・失敗時の復旧担当を記録する。バックアップと旧設定を確保する。家族内へ再ログイン・PWA再追加を案内する。
2. 新ドメインのDNSとCoreServerサイト・TLSを準備する。[CoreServer公式](https://help.coreserver.jp/manual/v1/security-free-ssl/)に従い、適切なAレコードと名前解決を先に確認する。証明書の対象名・チェーン・実際のHTTPS応答を確認する。
3. 新側を一時制限した状態で、公開先割当、API symlink、SPAの直接URL、TLSを検査する。共有DBへの書込みは本番操作になるため、確認用アカウントと識別可能な記録で行う。新旧の同時一般受付はしない。
4. 必要な短時間のメンテナンスを開始する。Laravelのdownと公開側`.maintenance`の扱いを既存手順に合わせる。旧端末からのAPI更新を止める。
5. 正規URL・フロントURL・CORSと配置先対応をまとめて切り替える。config cacheを再生成する。ファイル移動が必要な代替構成ならこの時点で公開物とAPI参照を切り替える。DB移行・Seeder・APP_KEY再生成をしない。
6. 新側の自動検査を通し、メンテナンスを解除する。旧Hostは初期段階では302等の一時転送または移行案内とする。キャッシュを抑制し、恒久転送は安定確認後に決める。
7. 旧APIの書込みは新ドメインへ自動転送しない。後続実装では旧HostのAPIに移行案内JSONを返す方式を定義し、旧クライアントが失敗を理解できるよう検証する。GET/HEAD画面の転送とAPIの処理を分ける。
8. HTTP・API・機能・メール・実機PWAの確認表を完了する。旧PWAはSWが旧画面をキャッシュする可能性があるため、サーバー転送だけで移行完了としない。新URLを通常ブラウザで開き、新PWAを追加してログインする手順を案内する。
9. 家族の利用を確認し、旧URL・TLSは当面維持する。恒久転送の採否・旧側の保持終了は別途記録する。wwwは正規URLにせず、必要なら別途TLS・転送を準備する。

旧メールリンクは`/verify-email#...`、`/reset-password#...`のfragmentがブラウザにだけ渡る。転送先へpath/query/fragmentが届くことをChromium・実機Safariで確認する。リンクには有効期限があるため、旧リンク継続が難しければ新側で再申請する。fragmentやトークンをサーバーログ・移行証跡へ記録しない。

## 切替時の確認表

| 確認 | 合格条件 |
| --- | --- |
| TLS・Host | 新旧の証明書が正しい。新側の転送ループなし。HTTPはHTTPSへ |
| SPA・資産 | `/`・`/login/`・`/gallery/`・詳細直リンク、SW・manifestが200、JS/CSSのMIMEが正しくHTML混入なし |
| API・認証 | `/api/v1/status`がok。新側のログイン・本人情報取得・ログアウトが動作。旧トークンの転送なし |
| 活動・いいね | 確認用記録を作成・編集・閲覧・いいねでき、既存履歴も表示できる |
| 画像 | 既存画像の認証付き閲覧、新規添付・拡大が動作。storageを複製／消失させていない |
| リワード | 既存定義・進捗・獲得履歴が維持され、通知・期間処理が動く |
| メール | 送信元は旧アドレス、新規発行リンクは新URL。切替前リンク／再申請も確認。実配送成功 |
| PWA | 新URLでホーム追加・起動・再ログインできる。旧ホームアイコンからの移行手順を確認 |
| テーマ等 | 新originでの初期状態・再設定が動作。旧端末設定の自動継承は期待しない |
| ステージング | URL・DB・公開先・IP・メール送信先制限が不変 |
| 配置・切り戻し | 新Host/docrootの組合せでpreflightと復旧先の検査が通る |

現時点では全項目未実施。実施版で各結果・日時・担当者を記録する。

## 切り戻し

新URLでログイン不能、API/画像障害、転送ループ、データの参照先不整合などがあれば受付を止め、旧URLへ復帰する。

1. 新側の更新を止め、切替時点以降の投稿が共有DBに保存されていることを確認する。
2. 旧Hostの転送を解除し、APP_URL・FAMIE_FRONTEND_URL・CORS・設定キャッシュ・配備先対応を旧構成へ戻す。
3. 代替docrootへ移した場合は、旧側へ正しい公開資材とAPI参照を戻す。共有backendとstorageを巻き戻して新規データを失わせない。
4. 旧URLでHTTP/API・画像・ログインを確認し、家族へ旧側利用を連携する。新側でのログインで旧トークンが失効していれば旧側も再ログインする。
5. ドメイン切替だけを理由としたDB復元は行わない。アプリ自体の切り戻しが必要なら既存rollback手順のDB互換性ガードに従う。

旧SW・恒久転送キャッシュが残ると復帰が即時反映されない場合がある。初期に一時転送とする理由はここにある。新側へ再追加したPWAは旧側へ自動で戻らないため、ブラウザで旧URLを開く案内を併用する。
