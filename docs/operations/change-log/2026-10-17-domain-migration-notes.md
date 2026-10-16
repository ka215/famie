# famie.coへの商用ドメイン移管記録

実施日：2026-10-17。詳細仕様の正本は [v0.16.0](../../issues/v0.16.0.md)。**現行0.15.2のドメイン切替済み・自動検査合格、ユーザー実機確認合格**。0.16.0の通常リリースは別工程。

## 対応と保持範囲

ユーザーの指示により、CoreServerの新docroot `~/public_html/famie.co` に現在の商用フロントを複製した。新側の `api` は既存 `~/famie/backend/public` へのリンク。旧docroot `~/public_html/famie.ka2.org` の公開物・apiリンクを残し、旧側の `.htaccess` だけを移管用に変更し、案内JSONを追加した。

旧画面GET/HEADは302、新側の同一path/queryへ転送。旧API全メソッドと画面への更新要求は410。転送・案内応答はno-store。本番 `.env` のAPP_URL・FAMIE_FRONTEND_URL・CORS_ALLOWED_ORIGINSを `https://famie.co` へ変更し、config cacheを再生成した。DB・storage・APP_KEY・メール配送・stagingは変更していない。メンテナンス、migration、Seeder、アプリ置換、DB復元は実行していない。

## 維持した公開成果物

| 項目 | 値 |
| --- | --- |
| 実配置版 | 0.15.2 |
| source commit | `6a13dae2e43ea985daf4f336ec9313430bd49178` |
| source tree | `25717acbc6ca0ddded20976d04c90f1af63994ee` |
| 元成果物workflow run | `38879184721` |
| bundle SHA256 | `9a38632136203c2ceef5e34bc22e1771a0d7e12a34a11948e6a93f24321f5575` |
| payload SHA256 | `1a97ad00141c6df198ef243f0f0f4d232341309b17c03e6018b657292c32ed9a` |

今回は元成果物を再ビルドせず、実配置済みフロントを複製し、rsyncのchecksum検査で公開物の一致を確認した。`.htaccess` とapiリンクは環境設定として別管理。現在のガイド内URL・QRは0.15.2の旧URLのままで、302で新側へ到達する。新URLを直接示す変更は0.16.0の成果物へ含める。

## 実行とバックアップ

サーバー側のスクリプトと設定候補は `~/famie-release-incoming/domain-migration-v0160` に配置。状態ディレクトリは `~/famie-release-backups/domain-migration-v0160-20261017`（非公開・700）。設定・DB等のバックアップには秘密情報が含まれるため、公開先やリポジトリへコピーしない。

実施コマンド（CoreServer SSH上のBash）：

```sh
export PATH="$HOME/bin:$PATH"
bash "$HOME/famie-release-incoming/domain-migration-v0160/migrate-domain.sh" \
  --prepare "$HOME/famie-release-backups/domain-migration-v0160-20261017"
bash "$HOME/famie-release-incoming/domain-migration-v0160/migrate-domain.sh" \
  --switch "$HOME/famie-release-backups/domain-migration-v0160-20261017"
```

初回prepareはディレクトリ時刻差分で停止。差分の扱いとCoreServer固有のAPIリンク制御を修正し、同じ状態ディレクトリからprepareを再開して成功。switchは2026-10-17 03:14頃JSTに成功。最新のスクリプトをサーバーへ再配置して保管する。

保存内容：旧`.env`、旧`.htaccess`、旧公開物、元manifest、新側仮ページ、DBバックアップ・SHA256・テーブル一覧、prepared/switchedマーカー。DBバックアップは `ka2_famie-20261017-031229.dump`（89,370 bytes）。チェックサムとpg_restore一覧の必須テーブル検査に成功。旧公開物の複製・仮ページバックアップも公開不要。

切替後の設定SHA256：

- 旧側`.htaccess`：`aa2ff525b74ef9845f9114b243eaca4cfc8ae0e3cf7e3f178e0b9036adbb8bbb`
- 新側`.htaccess`（HTTPS転送追加後）：`c766d92affd6ff2550b2d1ff28d66b550a0c4f15c996cd59b53e64f74607abdc`

## 検証結果

- 新旧TLS・新側主要画面・JS MIME・SW・manifest：成功。新側statusはok、空のログイン要求は422、CORSは新origin。
- 旧側API全7メソッドの410、JSON MIME、no-store、画面302とquery保持：CoreServerで成功。symlink配下も検証。
- 商用ブラウザ検査：Chromium／iPhone相当WebKitで計24件成功・4件スキップ。SWを有効にした検査も成功。スキップはローカル候補専用のテーマ検査。
- 旧メールURLの認証／再設定ページ：両ブラウザで計4ケース、転送後のpath/queryと架空fragmentの受信に成功。実トークン・メールは送信していない。
- 実効URL3項目とメンテナンス無効を確認。stagingのHTTP200と変更対象外であることを確認。
- 新側HTTPは当初200だったため、03:18頃JSTに `production-https.htaccess` を追加。HTTPはAPI配下も含め307で同一path/queryのHTTPSへ転送、旧HTTPは新HTTPSへ302。HTTPSの画面・statusは正常。stagingの既存DB・IP制限・メール制限ガードも合格。

ユーザー実機確認は全項目合格：新側での再ログイン・既存活動／画像／リワード履歴、活動の登録／編集／画像添付、新PWA追加・起動、メール実配送の正常動作を確認いただいた。現行0.15.2のドメイン移管は受入完了。0.16.0の通常リリース検証・実機確認は別途実施する。

## ドメインの切り戻し

0.16.0等の別成果物をまだ配置していない間は、次のコマンドを使う。

```sh
bash "$HOME/famie-release-incoming/domain-migration-v0160/migrate-domain.sh" \
  --revert "$HOME/famie-release-backups/domain-migration-v0160-20261017"
```

新側APIを止め、保存したURL設定・旧`.htaccess`へ戻してconfig cacheを再生成し、旧APIを検査する。その後、新画面は旧URLへの302とする。DB・画像・切替後の履歴は保持し、DBバックアップを復元しない。新PWAは自動で旧側へ戻らないため、家族には通常ブラウザで旧URLを開くよう案内する。

別成果物が配置された場合はmanifestガードで停止する。最新環境に合わせてドメイン設定・公開物の互換性を確認し、通常のアプリrollbackと区別して復旧する。復旧機能は準備・構文・設定更新のローカル検査を行ったが、商用での実切り戻しは行っていない。
