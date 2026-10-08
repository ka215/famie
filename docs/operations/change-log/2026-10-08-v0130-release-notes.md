# v0.13.0 リリース記録

2026-10-08 JST、v0.13.0を[商用環境](https://famie.ka2.org)へ公開した。

対応内容：家族の活動へのいいね・取消、受信件数表示、本人の敬称設定、管理者向けカテゴリ編集案内、初期アイコン修正、英語READMEとサンプル画像。詳細は[v0.13.0要件・検証記録](../../issues/v0.13.0.md)を参照。

- main SHA: `97def0d6dcefe93520f8b91b61771e290328dfad`
- 成果物SHA256: `94af5675a3e56621a699d946b45603136168a586e2ef0a5677583ceb493d442a`
- [ステージングworkflow](https://github.com/ka215/famie/actions/runs/37719947782)：配置・リモートブラウザ検証20件成功
- [商用workflow](https://github.com/ka215/famie/actions/runs/37767550628)：配置・リモートブラウザ検証20件成功
- ステージング・商用の自動検証および実機確認: 合格

ステージングと同じ成果物を再ビルドせず配置した。両環境でDBバックアップ後にいいねテーブル・敬称設定のmigrationを適用し、既存データと環境設定を保持した。レート制限は設定変更せず初期値2件／秒を維持する。

[GitHub Release](https://github.com/ka215/famie/releases/tag/v0.13.0)へアーカイブ・SHA256・manifestを登録し、登録先のdigestが配置成果物のSHA256と一致することを確認した。[同期PR #75](https://github.com/ka215/famie/pull/75)でmainをdevへ同期。[記録更新PR #76](https://github.com/ka215/famie/pull/76)で公開記録とversion.jsonを更新する（current: 0.13.0、next: null）。

同期PRの最初のCIはUbuntu依存パッケージのダウンロード遅延により中断し、別runnerで再実行して合格した。アプリのテスト失敗ではない。

詳細なバックアップ、配置ログ、検証結果は各workflow runと `.release/sessions/v0.13.0.json` を参照する。商用バックアップの証跡ディレクトリ名は `20261008-200400-v0.13.0-dce894d70772`。
