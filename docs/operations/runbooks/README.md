# 運用手順書

デプロイ、ロールバック、証明書更新、IP 許可リスト変更、障害一次対応などの再現可能な手順を管理する。

各手順には、前提条件、実行者、実行コマンド、確認方法、失敗時の戻し方を含める。

## リリース手順

- [通常リリースの自動化](./release-script-notes.md)：通常リリースと切り戻しの操作上の正本。
- [ステージングの運用・デプロイ](./staging-deploy-notes.md)：ステージング固有の前提、確認、失敗時対応。
- [フロントエンドのリリース手順](./howto_release_frontend.md)：初期構築と環境復旧の補助手順。
- [バックエンドのリリース手順](./howto_release_backend.md)：初期構築と環境復旧の補助手順。
- [PWA検証手順・結果](./pwa-verification-notes.md)：PWA固有の確認方法と過去の実機記録。
- [環境別IP制限](./ip-access-notes.md)：v0.9.0の商用解除、ステージング維持、旧版への復旧。
- [内部監視・安全なログ・日次バックアップ](./internal-monitoring-notes.md)：v0.19.0候補の設定・通知・保持・隔離復元。サーバー運用開始前の確認を含む。

リリース自動化の設計上の正本は[リリース自動化仕様](../release-automation-spec.md)である。日常の操作は`release-script-notes.md`から開始し、初期構築手順や過去の記録を通常リリースの代わりに使用しない。

## ドメイン・メール移管

- [famie.coへの移管計画](domain-migration-plan.md)：v0.15.1時点の準備資料。v0.16.0で切替済み。
- [ドメイン移管の実施記録](../change-log/2026-10-17-domain-migration-notes.md)：新公開先、旧側転送、データ保持、切り戻しの実施条件。
- [メール送信元移管](mail-domain-migration.md)：v0.16.1で準備中。新SMTPアカウント・環境設定・実配送・復旧手順。
