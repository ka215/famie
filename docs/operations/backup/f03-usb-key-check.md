# F03：鍵の予備をUSBに保存し、別のPCで開封を確認する

作成日：2026-11-06。同日、運営者による予備作成・USBコピー照合・別Windows PCでの開封確認が完了。対象はステージング用鍵 `staging-20261104-f02`。

確認記録：手順1〜4は運営者が成功を報告。PC②では当初Windows PowerShell 5.1で操作しており、作業フォルダー内にファイルがなく、開封前に停止した。USBのZIPには必要な6ファイルが揃っていることを一覧で確認した。PowerShell 7.6.0へ切り替え、新規フォルダーへZIPのハッシュ照合・展開・6ファイル存在確認を行った後、予備鍵のハッシュ照合とVerifyを実行し、運営者が「手順6 OK」と報告した。初回取込みが完了しなかった原因は未確定であり、ZIP破損や鍵不良とは判定しない。アシスタントは実鍵・USB・DBを操作していない。

今回行うのは、ステージング用の鍵の予備を作り、別PCで使えるか確認する作業です。DBの操作はありません。元の鍵と元のパスワード保管ファイルは残します。商用鍵・台帳のUSB保存・通知メールは、この手順の対象に含みません。それらは別途確認します。

## 作業する場所

| 呼び方 | 使うPC・アプリ |
| --- | --- |
| PC① | `C:\xampp\htdocs\famie` がある、普段開発に使っているPC |
| PC② | 今回用意した、別のWindows PC |
| 入力する画面 | 両PCとも「PowerShell 7」。コマンド名は `pwsh`。管理者としての起動は不要 |

コマンドは、各枠を上から順に、枠ごとにまとめて貼り付けて実行してください。枠の途中だけを実行しないでください。同じPCでの作業中はPowerShellを閉じないでください。途中で閉じた場合や赤いエラーが出た場合は、その場で止めて状況を連絡してください。作成済みファイルの削除や同じ作業の連続実行はしないでください。

開始前に、操作する画面で `$PSVersionTable.PSVersion.ToString()` を実行し、7.2以上であることを確認してください。5.1と出た場合は `pwsh -NoProfile` を実行してから再確認します。`pwsh --version` だけでは、現在操作中の画面がPowerShell 7であることを確認できません。

新しく決める鍵用パスワードは、アプリへログインするパスワードとは別のものです。半角英数字・記号で16文字以上とし、パスワード管理ソフトなどへ保存してください。USBには保存せず、チャットにも送らないでください。

## 1. PC①で元ファイルがあるか確認する（読取りだけ）

PC①でPowerShell 7を開き、次を実行します。元の鍵を作成したときと同じWindowsユーザーで作業してください。

```powershell
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath 'C:\xampp\htdocs\famie'
$repo = (Get-Location).Path
$source = Join-Path $repo '.temp\v0.20.0\staging-lifecycle-secrets'
$keyId = 'staging-20261104-f02'
$sourceKey = Join-Path $source 'encrypted-private-key.json'
$sourcePublic = Join-Path $source 'public-config.json'
$sourcePassword = Join-Path $source 'passphrase.clixml'
foreach ($file in @($sourceKey, $sourcePublic, $sourcePassword)) {
    if (-not (Test-Path -LiteralPath $file -PathType Leaf)) {
        throw "必要なファイルがありません。ここで停止してください：$file"
    }
}
$sourceHash = (Get-FileHash -LiteralPath $sourceKey -Algorithm SHA256).Hash.ToLowerInvariant()
pwsh -NoProfile -File .\scripts\lifecycle-key-recovery.ps1 -Mode Plan -KeyFile $sourceKey -PublicFile $sourcePublic -KeyId $keyId -ExpectedSourceSha256 $sourceHash
if ($LASTEXITCODE -ne 0) { throw '元ファイルの確認に失敗しました。ここで停止してください。' }
```

成功すると、`"mode":"plan"`、`"files_created":0` が含まれる1行が出ます。`"key_verified":false` は正常です。この段階では鍵を開いていません。パスワード入力もありません。

ファイルがない場合、古い鍵作成スクリプトを実行して作り直さないでください。

## 2. PC①の中に、予備の保存場所を作る

この手順以降は新しいフォルダー・ファイルを作成します。保存先はPC①のユーザー専用領域で、元の鍵とは別の場所です。次を実行します。

```powershell
$stamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$work = Join-Path $env:LOCALAPPDATA "FamieRecovery\f03-$stamp"
if (Test-Path -LiteralPath $work) { throw '保存先が既にあります。ここで停止してください。' }
New-Item -ItemType Directory -Path $work | Out-Null
$identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls $work /inheritance:r /grant:r "${identity}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw '保存先のアクセス制限に失敗しました。ここで停止してください。' }
$backup = Join-Path $work 'key-backup'
$audit = Join-Path $work 'audit'
New-Item -ItemType Directory -Path $backup, $audit | Out-Null
Write-Host "予備の保存先：$backup"
```

成功すると `予備の保存先：C:\Users\…\AppData\Local\FamieRecovery\f03-…\key-backup` が出ます。この場所をメモしてください。

## 3. PC①で鍵の予備を作る（新しいパスワードを入力）

元の鍵は変更しません。次を実行してください。

```powershell
pwsh -NoProfile -File .\scripts\lifecycle-key-recovery.ps1 -Mode Create -Apply -KeyFile $sourceKey -PublicFile $sourcePublic -KeyId $keyId -ExpectedSourceSha256 $sourceHash -DpapiPassphraseFile $sourcePassword -DestinationDirectory $backup -AuditDirectory $audit -Operator 'owner'
if ($LASTEXITCODE -ne 0) { throw '予備の作成に失敗しました。ここで停止してください。' }
if ((Get-FileHash -LiteralPath $sourceKey -Algorithm SHA256).Hash.ToLowerInvariant() -ne $sourceHash) {
    throw '元ファイルの照合に失敗しました。ここで停止してください。'
}
```

入力を求められたら、次のように操作します。

| 画面の表示 | 入力するもの |
| --- | --- |
| `New portable key passphrase (at least 16 characters)` | 今回新しく決めた鍵用パスワードを入力し、Enter |
| `Confirm new portable key passphrase` | 同じパスワードをもう一度入力し、Enter |

入力した文字は画面に表示されません。元の鍵のパスワードは、同じPC・Windowsユーザーに保管してある情報から自動で読みます。

成功すると `"key_verified":true`、`"files_created":2` が出ます。保存先には次の2ファイルができます。

```text
staging-20261104-f02.key.json
staging-20261104-f02.public.json
```

失敗した場合、元の鍵を消したり上書きしたりしないでください。保存先にファイルが残っても、確認済みの予備としては扱いません。

## 4. PC①で持ち出すファイルをまとめ、USBへコピーする

まず次を実行します。確認用プログラム4ファイルと、手順3の予備2ファイルだけをZIPにまとめます。元のパスワード保管ファイルやDBは含めません。

```powershell
$transfer = Join-Path $work 'transfer'
New-Item -ItemType Directory -Path (Join-Path $transfer 'scripts'), (Join-Path $transfer 'backend\app\Services'), (Join-Path $transfer 'keys') | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'scripts\lifecycle-key-recovery.ps1'), (Join-Path $repo 'scripts\lifecycle-key-recovery.php') -Destination (Join-Path $transfer 'scripts')
Copy-Item -LiteralPath (Join-Path $repo 'backend\app\Services\LifecycleOperatorKey.php'), (Join-Path $repo 'backend\app\Services\LifecycleAudit.php') -Destination (Join-Path $transfer 'backend\app\Services')
$backupKey = Join-Path $backup "$keyId.key.json"
Copy-Item -LiteralPath $backupKey, (Join-Path $backup "$keyId.public.json") -Destination (Join-Path $transfer 'keys')
$zipName = "famie-f03-key-check-$stamp.zip"
$localZip = Join-Path $work $zipName
Compress-Archive -Path (Join-Path $transfer '*') -DestinationPath $localZip
$zipHash = (Get-FileHash -LiteralPath $localZip -Algorithm SHA256).Hash.ToLowerInvariant()
$keyHash = (Get-FileHash -LiteralPath $backupKey -Algorithm SHA256).Hash.ToLowerInvariant()
Write-Host "ZIPの照合番号：$zipHash"
Write-Host "予備鍵の照合番号：$keyHash"
```

表示された2つの「照合番号」を、紙またはUSB以外の自分用メモに、それぞれの名前とセットで保存します。各64文字です。PC②で入力します。パスワードとは別の番号です。

次にUSBをPC①へ接続し、エクスプローラーの「PC」でUSBのドライブ文字を確認します。例えば `USBドライブ (E:)` なら入力するのは `E:\` です。次を実行し、質問にUSBのドライブを入力します。

```powershell
$usb = Read-Host 'USBのドライブを入力してください（例 E:\）'
if ($usb -notmatch '\A[A-Za-z]:\\\z' -or -not (Test-Path -LiteralPath $usb -PathType Container)) {
    throw 'ドライブの指定を確認してください。ここで停止してください。'
}
if ([IO.Path]::GetPathRoot($work) -ieq $usb) { throw 'PC内の保存先と同じドライブです。USBか確認してください。' }
$usbZip = Join-Path $usb $zipName
if (Test-Path -LiteralPath $usbZip) { throw '同じ名前のZIPがあります。上書きせず停止してください。' }
Copy-Item -LiteralPath $localZip -Destination $usbZip
if ((Get-FileHash -LiteralPath $usbZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $zipHash) {
    throw 'USBへのコピー照合に失敗しました。ここで停止してください。'
}
Write-Host "USBコピー照合OK：$usbZip"
```

このコマンドはドライブが実際にUSBかまでは判定できません。入力前に、エクスプローラーで対象を確認してください。

`USBコピー照合OK` が出たら、Windowsの「ハードウェアを安全に取り外してメディアを取り出す」でUSBを取り外します。

## 5. PC②でUSBから取り込み、コピーを照合する

USBをPC②に接続し、PowerShell 7を開きます。エクスプローラーでUSBのZIPを右クリックし、「パスのコピー」で場所を確認できます。次を実行し、質問に答えます。

```powershell
$ErrorActionPreference = 'Stop'
$usbZip = (Read-Host 'USB内のZIPのフルパスを入力してください').Trim('"')
if (-not (Test-Path -LiteralPath $usbZip -PathType Leaf)) { throw 'ZIPが見つかりません。ここで停止してください。' }
$expectedZip = (Read-Host 'PC①で別にメモした「ZIPの照合番号」を入力してください').Trim().ToLowerInvariant()
if ($expectedZip -notmatch '\A[a-f0-9]{64}\z' -or (Get-FileHash -LiteralPath $usbZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedZip) {
    throw 'USBのZIPがメモと一致しません。ここで停止してください。'
}
$work2 = Join-Path $env:LOCALAPPDATA ('FamieRecovery\check-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
if (Test-Path -LiteralPath $work2) { throw '保存先が既にあります。ここで停止してください。' }
New-Item -ItemType Directory -Path $work2 | Out-Null
$identity2 = [Security.Principal.WindowsIdentity]::GetCurrent().Name
icacls $work2 /inheritance:r /grant:r "${identity2}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
if ($LASTEXITCODE -ne 0) { throw '保存先のアクセス制限に失敗しました。ここで停止してください。' }
$zip2 = Join-Path $work2 'received.zip'
Copy-Item -LiteralPath $usbZip -Destination $zip2
if ((Get-FileHash -LiteralPath $zip2 -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedZip) { throw 'PC②へのコピー照合に失敗しました。' }
$package2 = Join-Path $work2 'package'
Expand-Archive -LiteralPath $zip2 -DestinationPath $package2
foreach ($relativeFile in @('scripts\lifecycle-key-recovery.ps1', 'scripts\lifecycle-key-recovery.php', 'backend\app\Services\LifecycleOperatorKey.php', 'backend\app\Services\LifecycleAudit.php', 'keys\staging-20261104-f02.key.json', 'keys\staging-20261104-f02.public.json')) {
    if (-not (Test-Path -LiteralPath (Join-Path $package2 $relativeFile) -PathType Leaf)) {
        throw "展開後の必要ファイルが見つかりません。以降の操作をせず連絡してください：$relativeFile"
    }
}
$audit2 = Join-Path $work2 'audit'
New-Item -ItemType Directory -Path $audit2 | Out-Null
Write-Host 'ZIP照合・取込みOK'
```

`ZIP照合・取込みOK` が出れば次へ進みます。ここではUSBから読み取ったコピーをPC②へ取り込みます。USB上のファイルは変更しません。

## 6. PC②で予備鍵を開けるか確認する

続けて、同じPowerShell画面で次を実行します。

```powershell
$keyId2 = 'staging-20261104-f02'
$key2 = Join-Path $package2 "keys\$keyId2.key.json"
$public2 = Join-Path $package2 "keys\$keyId2.public.json"
$helper2 = Join-Path $package2 'scripts\lifecycle-key-recovery.ps1'
foreach ($requiredFile in @($key2, $public2, $helper2)) {
    if (-not (Test-Path -LiteralPath $requiredFile -PathType Leaf)) {
        throw "必要なファイルが見つかりません。パスワードは入力せず、ここで停止してください：$requiredFile"
    }
}
$expectedKey = (Read-Host 'PC①で別にメモした「予備鍵の照合番号」を入力してください').Trim().ToLowerInvariant()
if ($expectedKey -notmatch '\A[a-f0-9]{64}\z' -or (Get-FileHash -LiteralPath $key2 -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedKey) {
    throw '予備鍵がメモと一致しません。ここで停止してください。'
}
pwsh -NoProfile -File $helper2 -Mode Verify -KeyFile $key2 -PublicFile $public2 -KeyId $keyId2 -ExpectedSourceSha256 $expectedKey -AuditDirectory $audit2 -Operator 'owner'
if ($LASTEXITCODE -ne 0) { throw '別PCでの鍵確認に失敗しました。ここで停止してください。' }
```

`Portable key passphrase` と出たら、手順3で新しく決めたパスワードを入力してEnterを押します。入力文字は表示されません。

`"mode":"verify"`、`"key_verified":true`、`"files_created":0` が出れば成功です。結果に含まれる `"physical_medium_verified":false` と `"separate_computer_verified":false` は、このプログラムだけでは実施場所を判定できないという意味です。今回どちらのPCで何を実行したかは、運営者が本手順に沿って確認します。

成功したら、次の3点だけチャットで報告してください。ファイル本文・パスワード・照合番号は送る必要がありません。

```text
PC①：予備作成OK
USB：コピー照合OK
PC②：key_verified=true を確認
```

終了後はUSBを安全に取り外し、パスワードとは別の場所へ保管してください。両PCに作ったフォルダー・元ファイル・監査記録はそのまま残します。失敗時は、手順番号とエラー表示を知らせてください。個人名を含むパスは伏せて構いません。鍵やパスワードの内容は送らないでください。

この作業の成功は「ステージング用の鍵の予備がUSB経由で別PCでも開けた」という確認です。実データの復旧、台帳の別媒体保存、商用鍵、通知メールの確認はまだ残ります。
