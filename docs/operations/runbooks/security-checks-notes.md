# v0.19.0 セキュリティ検査の運用

対象はR03。2026-10-26（JST）時点ではローカル検証段階。CIへの反映・限定動的検査の実サーバー実行は、フェーズ2のローカル確認合格後に行う。進捗と判断は[Issue #165](https://github.com/ka215/famie/issues/165)に記録する。

## 検査ツールと実行

| ツール | 固定版 | ライセンス | 用途 |
| --- | --- | --- | --- |
| Gitleaks | 8.30.1 | MIT | 現在ツリー・全履歴・PR差分の秘密情報検査 |
| actionlint | 1.7.12 | MIT | Actionsの構文・型・権限・入力の静的検査 |
| Opengrep | 1.32.0 | LGPL-2.1 | リポジトリ管理のPHP／JS／TS／Vue／workflow規則 |
| pnpm / Composer | CIで11.20.0 / 2.8.10 | 各ツールのライセンスに従う | lockfileの脆弱性検査。開発依存を含む |

公式配布物：[Gitleaks](https://github.com/gitleaks/gitleaks/releases/tag/v8.30.1)、[actionlint](https://github.com/rhysd/actionlint/releases/tag/v1.7.12)、[Opengrep](https://github.com/opengrep/opengrep/releases/tag/v1.32.0)。Windows／Linux x64の配布物SHA256は`install-tools.ps1`へ固定し、実行前に検証する。自動的にlatestへ追随しない。WindowsでDocker・WSL・Pythonは使用しない。Linux版のCI実行結果はPR工程で確認する。

```powershell
pwsh scripts/security/install-tools.ps1
pwsh scripts/security/test-rules.ps1
node --test scripts/security/staging-check.test.mjs
pwsh scripts/security/scan.ps1 -History
pwsh scripts/security/audit.ps1
# PR差分を追加する場合は実在する完全SHAを渡す
pwsh scripts/security/scan.ps1 -History -BaseCommit <base-full-sha>
```

秘密・静的検査はローカル規則で実行し、クラウド検査へソースをアップロードしない。Opengrepの自動規則取得・バージョン照会は使わない。依存監査ではパッケージ名・版をnpm／Packagist等の監査先へ照会する。アプリログ・メール宛先・利用者データは送信しない。

`security-checks.yml`をRelease CIの必須jobとして呼び、失敗・cancel・skip時は最終`verify`が成果物生成を拒否する。別途、毎週月曜06:23 JSTと手動で同じ検査を行う。週次・Security checks単体の手動実行では、認証・家族間分離・画像認可・ログ秘匿の既存PHP回帰も実行する。通常PRでは既存checks jobの全体試験を使う。

新設workflowの単体手動起動・定期実行は、デフォルトブランチmainへの反映後に有効になる。商用承認前にmainへ先行反映しない。開発中の静的検査は既存Release CIから同じコミットの再利用workflowを呼び、限定動的検査は既存Deploy Release Artifactのstaging apply成功後から呼ぶ。[GitHubの起動条件](https://docs.github.com/en/actions/reference/workflows-and-actions/events-that-trigger-workflows)に従い、未実施を成功と誤記しない。

ツール異常・レポート欠落・JSON不正・監査先取得失敗を成功扱いしない。high／critical、不明な深刻度を必須失敗にする（末尾の承認済みnode-forge例外だけを除く）。中以下はpackage・advisory・影響・担当・期限をIssueへ記録する。生レポートにはソースや秘密値が含まれ得るため、公開ログ・GitHub artifactに出さない。通常ログは終了コード・件数・規則ID・リポジトリ内パス・行番号等に限定し、一時レポートを削除する。詳細調査は運用者が非公開環境で個別ツールを実行する。

`.gitleaks.toml`は公開済みlocalhost専用TLSテスト鍵`frontend/tests/support/tls/key.pem`の`private-key`規則のみを例外にする。他の鍵や同じファイルの別種の秘密は除外しない。v0.21.0までに再確認する。新規例外は規則・対象・理由・期限・承認を限定する。

Opengrepは自作8規則であり、網羅的なSAST製品の全規則ではない。PHPの入力からコマンド／raw SQLへの同一関数内の伝播、eval・オブジェクト復元、JS動的評価・DOM HTML、Vueのv-html、Actionsの未信頼イベント文字列を対象とする。Vue内のscriptを一時TSへ抽出してJS規則も適用する。関数をまたぐ伝播、動的呼び出し、テンプレート全体の安全性は保証しない。安全・危険fixtureで8規則の実検出とバインドSQLの非検出を毎回確認する。ShellCheck／Pyflakesは今回導入しておらず、actionlintからの呼び出しも無効にする。

## 限定動的検査

`staging-security.yml`は信頼できる`dev`／`main`からの手動実行と、毎週月曜06:41 JSTだけを対象にする。PRでは起動しない。手動入力は`environment=staging`と完全候補SHA。週次はリポジトリ変数`FAMIE_SECURITY_CANDIDATE`の固定SHAを使う。候補更新時は運用者が値を更新し、古い値を自動的に最新候補として扱わない。

固定成果物のstaging配置時にも、検証済みartifact manifestのsource_commitを渡して同じworkflowを呼ぶ。fixture未設定・動的検査失敗時は配置workflow全体を失敗とし、サーバーへ配置済みであっても検証合格として記録しない。初回配置前に専用fixtureを準備する。production配置とapply=falseの事前検査からは呼ばない。

1. GitHub hosted jobでSHA形式とdev履歴内の候補であることを確認する。
2. 許可済みWindows runnerでstagingの既存SSH設定を使い、`famie-stg-release-backups/current-manifest.json`のsource_commitと照合する。不一致ではHTTP検査を開始しない。検査後にも再照合し、途中の候補変更は失敗にする。
3. HTTP到達先は`https://stg-famie.ka2.org`と明示したAPIパスだけ。任意URL・商用URL・リダイレクト追随は不可。TLS検証・IP制限を維持する。
4. 合計30リクエスト、1件10秒、全体120秒、応答2 MBを上限にする。並列送信・クローラー・連打・ファイルアップロードは行わない。
5. 以下の専用データで認証・認可・画像・CORS・追跡ID・トークン失効を確認し、終了時に今回のトークンを失効する。結果は件数と候補SHAのみ。Cookie・本文・画像・トークン・内部構成をartifactへ保存しない。

Windows runner停止時のqueued／未実行は合格ではない。運用者は週次にworkflowの結果を確認し、未実施をIssueへ残し、runner起動後に固定SHAで手動実行する。job timeoutは実行開始後の上限であり、queue待ち時間の保証ではない。最終候補の受入では実行済み成功記録を必須にする。

### 専用fixtureの準備（ステージング工程）

一般利用者を使わず、親・子を持つ専用家族と、別の親を持つ別家族を作る。ログと小さなダミー画像を各家族に用意する。全ログイン名は`security_`始まり、各パスワードは16文字以上の十分に強い別値とする。メールは設定しない。初回実行前に、担当・作成対象・保持／削除方針をIssueへ記録する。

GitHubの**staging Environment secret** `FAMIE_SECURITY_FIXTURE`に次のJSON構造を非公開設定する。値・ID・パスワードをチャット・Git・Issueへ貼らない。アプリの一般利用者データを複製しない。

```json
{
  "family": {
    "group_id": 101,
    "log_id": 201,
    "image_id": 301,
    "parent": {"login": "security_parent", "password": "REPLACE_WITH_PRIVATE_STRONG_PASSWORD"},
    "child": {"login": "security_child", "password": "REPLACE_WITH_DIFFERENT_STRONG_PASSWORD"}
  },
  "other": {
    "group_id": 102,
    "image_id": 302,
    "parent": {"login": "security_other", "password": "REPLACE_WITH_ANOTHER_STRONG_PASSWORD"}
  }
}
```

数値は形式例であり実環境値ではない。fixture未設定・ロール不一致・対象不一致は失敗とする。ログインはその専用ユーザーの既存トークンも失効するため、通常の操作確認と同時に使わない。子のメンバー作成拒否試験は空bodyだけを送り、認可が退行しても有効なメンバーを作れないようにする。fixtureデータは週次用に非公開で保持し、検査終了・廃止時には専用家族・画像・secret・候補変数を整理する。途中失敗でトークン回収が確認できないときは、専用ユーザーのセッションを運用者が失効する。

## 検査範囲と残る確認

| 対象 | 今回の自動検証・確認根拠 | 未対応／次回 |
| --- | --- | --- |
| XSS | eval／HTML sink／v-htmlの静的規則、既存入力・描画試験 | 全入力のブラウザー動的攻撃はv0.21.0 |
| SQLi | raw SQLへの入力伝播、既存APIの認証・入力回帰 | 全パラメータの攻撃走査はv0.21.0 |
| トラバーサル | 既存ActivityImageTestの画像認可・格納境界 | 全エンコード変形の動的走査はv0.21.0 |
| なりすまし・家族間分離 | GroupIsolationTest、親・子・別家族の限定動的検査 | 全ロール×操作の網羅はv0.21.0 |
| セッション・トークン失効 | LoginSecurityTest等、実APIのlogout後401 | 認証方式変更は別途合意 |
| 辞書攻撃・制限 | LoginSecurityTestの上限・ロック回帰 | 実サーバー連打は実施しない |
| CSRF | Bearer認証とCookieの使用箇所をレビュー | XSSでJS可読トークンが盗まれる残存リスク。HttpOnly方式への移行は別途合意 |
| コマンド注入 | PHP入力伝播規則、配列引数での既存ジョブ実行を確認 | 関数横断・全シェル経路の監査はv0.21.0 |
| 画像認可 | ActivityImageTest、匿名・同家族・別家族の実API照合 | 全画像形式のfuzzingはv0.21.0 |
| 情報漏えい | OperationLoggingTest、追跡ID・例外詳細不在・画像no-store | サーバー設定全体の総合診断はv0.21.0 |
| CORS／CSP | 実APIの不許可Origin拒否、既存構成レビュー | CSP導入・全ページ検証は別途設計。現状を合格済みとしない |
| PWA混在 | 同一固定成果物の既存release smokeと実機回帰 | 全更新順序の競合試験はv0.21.0 |
| 競合 | 既存PostgreSQLの認証・メール・リワード回帰を維持 | 全競合操作の総合検証はv0.21.0 |
| 供給網・秘密 | lock監査、全履歴／現在／差分、ツールchecksum、Actions入力検査 | 初回指摘と例外は下記・Issueで管理 |
| 同意 | v0.20.0の実装後に追加 | 本版では未実装 |

## 初回依存指摘と承認された限定例外

2026-10-26、初回pnpm監査はhigh3／moderate7／low3、Composerは0件。ユーザー承認によりNuxt4.6.2・Zod4.6.7へ更新し、undici8.12.3、fast-uri3.1.9、serialize-javascript7.1.3へ更新。再検査は**high1（node-forge1.4.0）のみ**。ソフトウェア依存全体を「指摘0」と表示しない。

[GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv)はRSA署名検証の問題。npm監査が示す`>=1.4.1`は、同日の公開npm版一覧に存在しない。GitHub Advisoryの修正版欄もNone。Nuxt→nitropack→listhenの開発用HTTPS関連で導入され、listhenでの利用は証明書生成／読込など。アプリの認証にnode-forgeを利用していない。静的ビルドは成功し、配信JSでnode-forge／DigestAlgorithm等の既知識別子は検出しなかったが、文字列検索だけを完全な到達性証明とはしない。

2026-10-26、当初は修正版公開まで監査失敗を維持した。その後、ユーザーが「ステージング／商用に影響なしであれば例外化」と方針を変更した。両実環境の静的document root、Node runtime directory不在、開発サーバーへの転送不在、PHP APIへのリンクを読み取り確認。成果物作成はfrontend公開資産とbackendだけで、frontendのnode_modules・Nodeサーバーは含まない。アプリソースからnode-forgeを参照していない。npm上の`dev=false`という分類だけで判断せず、配布・実行経路を根拠とする。

`audit-policy.jq`で当該advisory・high・node-forge1.4.0・上記依存経路だけを例外化した。2026-11-09 JST末まで有効で、2026-11-10 00:00 JST以降は監査失敗に戻す。別版・別経路・別指摘・critical・不明な深刻度は除外しない。9件の境界試験をCIでも実行する。毎回の監査ログにはhigh1件と`accepted_exceptions`を別々に表示し、「脆弱性0」としない。担当は運用者、公式修正版公開時に更新して例外を削除する。

`check-forge-scope.mjs`で静的SPA構成、アプリからの参照不在、公開資産限定の包装を確認する。ビルド後・成果物作成前にはNode実行用ディレクトリと既知識別子の混入も確認する。これらは現在の構成を維持するための検査であり、任意の構成変更の安全性を証明するものではない。静的配信以外へ移行する場合やアプリ用途へ利用を広げる場合は例外を再承認する。A08へこの承認例外を明記したが、フェーズ2のローカル合格・CI・実サーバー動的検査・商用リリースの承認を代替しない。
