# メール送信元のfamie.coへの移管

対象：[v0.16.1](../../issues/v0.16.1.md)。**完了：商用は新SMTP／ステージングは旧商用SMTP。商用実配送・PWA確認合格**。商用URLの移管はv0.16.0で完了済み。本手順はメール送信元とSMTP認証の変更だけを扱う。

## 前提

- CoreServerの新アカウント `noreply@famie.co` とSMTP送信権限を確認する。新秘密値は非公開の管理画面・サーバー設定で扱う。
- 商用の旧from／SMTP usernameは `noreply@famie.ka2.org`。stagingの開始時設定は `anonymous@ka2.org`。ユーザーの承認により、下記の順序で切り替える。
- 現行SMTPは `s140.coreserver.jp:587`。新アカウントでの実要件を確認し、TLS・証明書検証を無効化しない。
- stagingの `FAMIE_MAIL_RESTRICT_RECIPIENTS=true` と許可宛先・IP制限を保持する。宛先制限を解除して送信テストを通さない。
- テストを行う本人の宛先・操作をユーザーと共有する。任意の利用者へテストメールを送らない。

## 確定した切替順

| 工程 | 商用 | ステージング |
| --- | --- | --- |
| 開始時 | noreply@famie.ka2.org | anonymous@ka2.org |
| ステージング検証 | 旧送信元を維持 | noreply@famie.coを一時設定し実配送を検証 |
| 商用公開・検証 | noreply@famie.coへ変更 | 検証済みの新送信元を維持 |
| 商用確認合格後 | 新送信元を維持 | noreply@famie.ka2.orgへ変更し認証・制限を確認 |

最後の工程は開始時設定の復元ではなく、旧商用アカウントをstaging専用として使う変更。旧商用SMTPアカウントを削除しない。fromだけでなくusername／passwordも組として変更する。

## 設定更新ツール

`scripts/release/server/set-mail-account.php` を利用する。入力ファイルは `MAIL_FROM_ADDRESS`・`MAIL_USERNAME`・`MAIL_PASSWORD` の3項目だけ。Git管理外・非公開・600で保持し、コマンド引数にパスワードを渡さない。候補アカウントは新／旧noreplyに限定し、SMTP接続先・TLS必須・元のfrom／usernameが想定どおりであることを検査する。

以下は初回staging切替の実施例（CoreServer SSH上）。事前に既存の環境／DBガードとバックアップ先の確認を行う。入力ファイルの中身は表示しない。

```sh
tool="$HOME/famie-release-incoming/mail-v0161/set-mail-account.php"
credential="$HOME/famie-release-incoming/mail-v0161/account.env"
backup="$HOME/famie-stg-release-backups/mail-v0161-new-account"
/usr/local/bin/php84cli "$tool" "$HOME/famie-stg/backend" anonymous@ka2.org "$credential" "$backup" --check-smtp
/usr/local/bin/php84cli "$tool" "$HOME/famie-stg/backend" anonymous@ka2.org "$credential" "$backup" --apply
```

`--check-smtp` は認証までで、メールを送信しない。`--apply` がなければ環境設定を変更しない。適用時は未使用の非公開ディレクトリへ元の `.env` を保存し、3項目だけを原子的に更新する。適用済みのバックアップ名は再使用できない。続いて必ず下記のconfig cache再生成・実効設定確認を行う。

商用適用時はbackendを `~/famie/backend`、想定元を `noreply@famie.ka2.org`、バックアップを `~/famie-release-backups/mail-v0161-new-account` に変える。公開後のstaging旧アカウント化では、新アカウントを想定元とし、元商用の非公開バックアップから旧アカウントの3項目だけを安全に抽出して使う。現在の商用 `.env` を丸ごとstagingへコピーしてはならない。

## 実施順

1. 対象環境・バックエンドパス・現在のfrom・SMTP username・TLS設定・制限の有効状態を読取確認する。秘密値や `.env` 全文を出力しない。
2. 対象の `.env` を非公開の700ディレクトリへ600でバックアップし、復旧先と日時を記録する。旧アカウントは安定確認まで残す。
3. DNSのMX・SPF・DMARCと、設定したDKIM selector等を確認する。MX設定だけで送信ドメイン認証が合格したとは扱わず、配送後の認証結果も確認する。
4. 対象 `.env` のfromと必要なSMTP認証情報だけを編集する。通常配置では `.env` が保持されるため、アプリ公開だけでは変更されない。

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=s140.coreserver.jp
MAIL_PORT=587
MAIL_FROM_ADDRESS=noreply@famie.co
MAIL_USERNAME=noreply@famie.co
# MAIL_PASSWORDは新アカウントの秘密値を非公開で設定。ここに実値を記載しない。
```

5. CoreServer SSH上で、対象backendを確認してキャッシュを再生成する。以下は商用の例。stagingを対象にする場合は `~/famie-stg/backend` を用い、対象を混同しない。

```sh
cd "$HOME/famie/backend"
/usr/local/bin/php84cli artisan config:cache --no-interaction
```

6. 実効from・mailer・SMTP接続先・制限を確認する。パスワードや認証URLをログへ出さない。APP_URL・FAMIE_FRONTEND_URLは商用 `https://famie.co`、stagingは従来URLを維持する。

   **許可宛先を追加した場合もconfig cacheを再生成する。** `.env` の `FAMIE_MAIL_ALLOWED_RECIPIENTS` だけを変更しても、設定キャッシュが有効なら送信処理の許可一覧には反映されない。制限はtrueのまま、追加先が実効許可一覧に含まれることを値を公開せず確認する。

7. ユーザーと連携し、既存のメール登録／確認・再設定・変更通知のフローで許可した宛先へ配送する。Fromと送信元表示名、リンク先、SPF/DKIM/DMARCの受信側結果、迷惑メール扱いの有無を確認する。実トークン・個人情報は記録しない。
8. 確認メールのリンクや再設定が正常に処理され、旧発行リンクの扱い・再申請も正常であることを確認する。メール配送設定の変更でトークン・DBを初期化しない。
9. 日時・環境・変更したキー名・秘密値を除く確認結果・バックアップ先をchange-logへ記録する。新アカウントが未準備なら切替しない。

## 切り戻し

配送・認証・送信権限に問題がある場合は、対象のfrom／SMTP username／passwordを旧設定へまとめて戻し、config cacheを再生成する。他の設定変更が発生していれば、古い `.env` 全体を無条件に上書きせず該当項目だけを戻す。旧送信元での配送を本人と確認する。DB復元・APP_KEY変更・メール宛先制限の緩和は不要。

## 記録状況

新アカウント作成済み、stagingで一時検証→商用適用→公開確認後のstaging旧送信元化の方針をユーザー承認済み。新パスワードを非公開で受領し、TLS・SMTP認証に成功。stagingを新アカウントへ切替し、実効設定・宛先制限・既存環境ガードを確認した。バックアップは `~/famie-stg-release-backups/mail-v0161-new-account`。実配送と商用適用は未実施。

2026-10-17の公開DNS読取：MXは `10 famie.co.`、SPFは `v=spf1 ip4:202.172.28.141 include:mxr.valueserver.jp ~all`、DMARCは `v=DMARC1; p=none;`。DNS変更は行っていない。DKIMはselector未確認のため未判定で、実配送後の受信側認証結果と分けて扱う。

### 実配送の一次確認と許可宛先の反映（2026-10-17）

ユーザーより、新送信元からパスワード再設定メールを受信し、stagingリンクで正常完了、PWA名・通常起動も合格との報告。受信側の認証結果はSPF／DKIM／DMARCすべてpass。DKIMは `d=famie.co`・selector `default`。実メール本文・署名全文・トークンは保存しない。

新しく追加した宛先への確認メールは未着と報告された。読取調査で `.env` の許可一覧4件に対し有効な設定キャッシュは3件で、新申請先が実効一覧から外れていることを確認。ログにもallowlist拒否があった。`users.pending_email` は送信前の申請保存であり、配送成功の証跡にはならない。

`~/famie-stg-release-backups/mail-v0161-allowlist-cache` へ環境設定と旧設定キャッシュを非公開保存し、config cacheを再生成。制限true、実効4件・`.env`との一致、新申請先の許可、既存の環境／DBガード合格を確認した。DB・トークン・許可宛先そのものは変更せず、自動でメールを再送していない。ユーザーに確認メール再送と受信・確認完了を依頼中。

追加確認：再送後の受信・確認完了をユーザーが確認し、staging検証合格。商用も新アカウントへ切替・config cache再生成済み。SMTP TLS認証と環境／DBガードに合格。商用旧設定の保存先は ~/famie-release-backups/mail-v0161-new-account。商用アプリ公開と実機確認、公開後のstaging旧送信元化は引き続き実施する。

### 最終結果

商用0.16.1のメール受信・確認／再設定完了、送信元noreply@famie.co・商用リンク、PWAをユーザー確認し合格。公開後のstagingはnoreply@famie.ka2.orgへ切替済み。旧商用バックアップからメール3項目だけを抽出し、SMTP TLS認証・config cache再生成・実効from／username・環境／DBガードに合格。宛先制限trueを維持し、メール3項目以外の環境設定が切替前と一致することも確認。切替前保存先は ~/famie-stg-release-backups/mail-v0161-old-production-account。旧アカウント化後は実メール送信を行わず、認証・設定を検査した。上記の未実施・確認待ち表現は当時の時系列記録。
