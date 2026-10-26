# Famie API契約

[openapi.yaml](openapi.yaml)が現行HTTP APIの具体的なIFを記載する正本。OpenAPI 3.1.1、アプリ対象版v0.18.1、APIプレフィックスはv1。商用は `https://famie.co/api/v1`、ステージングは `https://stg-famie.ka2.org/api/v1`。要件の正本は版別仕様・正式要件を参照する。旧`reference/backend/`のサンプルは契約ではない。[v0.18.1](../issues/v0.18.1.md)では新規usernameの禁止名検証と、獲得一覧の全期間累計`meta.trophy_totals`を追加した。

## 検証

WindowsではPHP・Node・pnpm・`yq` v4を利用する。backendのComposer依存も必要。

```powershell
Set-Location frontend
pnpm install --frozen-lockfile
pnpm test:api
```

固定版のRedocly CLIで構文、参照、OpenAPI型を検査し、`scripts/check-api-contract.mjs`がLaravelの実ルートとmethod/pathを双方向照合する。operationIdの欠落・重複も検出する。CIではyq v4.53.3を明示して同じコマンドを実行する。文書を変更する際のYAML操作にはyqを使う。

Laravel直下の`/v1`を公開側の`/api/v1`と区別し、HEADはGETからの自動派生として除外する。v0.16.0時点の48個のLaravelルートはPUT/PATCHの複合ルートを展開すると49操作になる。さらに、活動の画像更新に使うPOST＋`_method=PUT`を文書化しており、この1操作は`x-method-override: PUT`で実ルートとの違いを明示する。通常のPOSTルートの欠落として扱わず、対応するPUTルートの存在を検査する。

## 契約の読み方

- 認証が必要な操作はBearerトークンを送る。`user_id`と`membership_id`／リワードの`member_id`・`target_ids`は異なるID。
- データは配列直返し、`data`ラップ、ページングなど操作ごとに異なる。任意の追加プロパティは原則許容し、必須フィールドを実装に合わせて定義している。
- 画像は認証付きバイナリ取得。公開ファイルURLではない。JSONのファイル名指定ではアップロードできない。
- 作成の`client_request_id`再送は200、新規作成は201。リワード編集はrevision必須、活動編集は任意。
- エラーは`Accept: application/json`を前提とする。IP制限やメンテナンスは横断応答。送信失敗の503と`code=maintenance`を区別する。Webサーバー自身の413等はJSON応答を保証しない。
- カテゴリ・メンバーの上限や画像ページ数は環境設定、リワード枠は家族DB設定に依存する。固定の最大値として誤読しない。
- `activity_date`は活動実施日。リワードの`starts_on`／`ends_on`は定義の固定timezoneにおける対象日で、`ends_on`は最終日を含む。DB内部の終了境界は翌日0時。
- 通知取得はPOSTで既読を確定する。取得の再送で同じ通知が返る保証はない。獲得履歴APIで確認する。

## 保守

ルート・入力検証・レスポンス・権限を変更するPRでは、本契約と該当Featureテストを同時更新する。ルート追加時は`pnpm test:api`の双方向比較が漏れを検出する。

`tests/e2e/api-contract.spec.ts`はテスト専用DBの実APIへ接続し、認証・家族・メンバー・カテゴリ・一覧・活動作成／詳細・件数・リワード作成／有効化／進捗／管理／履歴／通知と422応答をAjv（JSON Schema 2020-12）で契約と照合する。`pnpm test:e2e tests/e2e/api-contract.spec.ts`で単独実行でき、CIの全体E2Eにも含む。モック応答をスキーマに合わせただけのテストではない。

構文・ルート検査と代表応答テストだけでは、全フィールド・全エラー経路の意味的一致を保証しない。画像バイナリ、メール配送、権限・競合などは既存Featureテストを併用する。今回の確認元は`ProfileTest`・`RegistrationTest`、メール・メンバー・カテゴリ・活動・画像・いいね・リワードのFeatureテストと各Controller。Swagger UIの公開、コード生成、実行時バリデーション置換は行わない。

ドメイン切替時はserversと本書を更新する。v0.16.0で旧本番URLから `https://famie.co` へ移管済み。旧画面は302転送、旧APIは410の移管案内を返す。ステージングのURLは維持する。秘密情報や実際のトークンをexampleに記載しない。
