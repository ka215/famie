Famie 運用通知 / Operations notification

環境 / Environment: {{ $environment }}
項目 / Check: {{ $check }}
状態 / Severity: {{ $severity }}
検知時刻 / Detected at (UTC): {{ $detectedAt }}
追跡ID / Run ID: {{ $runId }}
@if ($check === 'access-log-retention')

アクセスログの更新停止・設定・対象ファイルを確認してください。自動削除はしていません。
Check access log rotation, configuration and expected files. No logs were automatically deleted.
警告は更新日時の25日超過等、重大通知は30日超過を示します。本文内の保存期間を判定したものではありません。
Warning includes modification times older than 25 days; critical means older than 30 days. This does not determine the age of individual log entries.
@endif

対応手順 / Runbook: docs/operations/runbooks/internal-monitoring-notes.md
詳細は非公開の運用ログで確認してください。
Inspect private operation logs for details.
