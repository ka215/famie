# API静的メンテナンス遮断の修正・検証

## 発生事象と原因

v0.4.0本番配置は、コード切り替え・DB migrationより前のメンテナンスHTTP確認で停止した。本番はv0.3.0へ通常復帰済み。ステージングでLaravelを稼働させたまま `.maintenance` を設置すると、画面は503だがAPIは200だった。

APIリンク先のLaravel `.htaccess` が独自のRewriteルールを持ち、親のRewriteによる停止判定がAPIへ適用されていなかった。従来テストにはAPIリンク先の `.htaccess` がなく、ステージングの配置確認もLaravel停止後だったため、新Laravelの503応答で問題が隠れていた。

## 修正

- 親の停止判定を条件付き `Redirect 503 /` に変更。mod_aliasで静的503を返し、Laravelのルーティングに依存しない。
- デプロイはLaravel停止前にも静的503を確認。JSONは公開先の静的ファイルと完全一致することを検証し、不一致の理由をログに残す。
- ApacheテストにAPIディレクトリへのジャンクションとLaravelの実際の `.htaccess` を追加。Windowsのテスト用URL解決に限り `RewriteBase /api/` を補う。

Apacheの仕様: [mod_rewriteのディレクトリ単位の継承](https://httpd.apache.org/docs/2.4/rewrite/htaccess.html)、[mod_alias RedirectのHTTPステータス指定](https://httpd.apache.org/docs/2.4/mod/mod_alias.html#redirect)。

## 検証結果

- Windows/XAMPP: 修正前はAPI GETが200で回帰テスト失敗。修正後は画面503、APIのGET/POST静的JSON503、no-store、Retry-After、許可外IP403、解除後の画面/API復帰が成功。フロントコントローラーを一時退避した状態でもAPIの静的503が成功。
- CoreServerステージング: Laravelを稼働させたまま設定を一時差し替え、`/api/v1/status`、`/api/v1/auth/login`、`/api/index.php` のGET/POST全6組で503・静的JSON完全一致・no-store・Retry-Afterを確認。画面の静的HTML完全一致も確認。
- ステージング検証後は元の `.htaccess` に復元しフラグを削除。画面200、status APIの200/ok、空ログイン422、設定ファイルの復元一致を確認。検証用SSH入力の末尾CRで終了コード1となったため、別接続でもフラグ不在・設定一致・API正常を再確認した。
- ステージング証跡: `/virtual/ka2/famie-stg-release-backups/maintenance-fix.2SqxkM`。
- CoreServer隔離fixtureで `test-deploy.sh` 成功: 静的JSON正常・旧/新Laravel応答の拒否、5失敗/復旧ケース、静的資産検証、公開705/604・バックアップ700/600、IP設定/APIリンク保持。fixture: `/tmp/famie-deploy-test.bLk384`。実アプリ・DBの配置は実施していない。
- `test-guards.ps1`: 構文・6拒否ケース成功。

## リリース再開時

本変更は本番未適用。ステージングの設定も検証前へ戻している。既存 `v0.4.0` タグは変更していない。

修正PRをdevへ取り込み、リリース対象のバージョン・タグ方針を確定してから手動リリースを再開する。既存タグを無断で付け替えない。修正版の管理ブロックとデプロイスクリプトを導入し、修正を含む固定コミットでステージング検証、本番事前確認、新規DBバックアップ取得を実施する。

元の手動実行ログ `.temp/v040-deploy-logs.md` は本対応で更新しない。
