# F03：ステージング台帳をUSBへ保存する

2026-11-06作成。同日、運営者が手順1〜4の成功を報告し、ステージング台帳の取得・USB保存・別Windows PCでの照合が完了した。

確認対象は本手順に固定したSHA256の台帳1件（1,973 bytes・8行）。PC①で取得前後のサーバー台帳と取得ファイルを照合し、USBコピー照合後、PC②でZIPと内部台帳のハッシュ一致を確認した。台帳本文・秘密情報の共有は受けていない。これは取得時点のコピーの確認であり、その後の更新を含む最新版の確認や、同期記録v2・cleanup完了・実データ復旧の合格とはしない。

今回はステージングでの退会・削除記録を1ファイル保存します。DB・元の台帳を変更せず、商用環境・秘密鍵・暗号化退避データは扱いません。

## 使うPCと成功の条件

PC①は開発PC、PC②は先ほど鍵を確認した別のWindows PCです。両方でPowerShell 7を使います。各枠は全体をまとめて実行し、エラーが出たら次へ進まず連絡してください。やり直しのための削除や上書きは不要です。

開始時に `$PSVersionTable.PSVersion.ToString()` が7.2以上か確認します。5.1なら `pwsh -NoProfile` を実行して再確認してください。

確認するのは、サーバーから取得したファイルとUSB経由で別PCへ渡したファイルが一致することです。同期記録v2の業務上の検証、台帳の全イベントの整合性、実データ復旧の合格とは分けて記録します。

## 1. PC①：取得元が変わっていないか確認する（読取りのみ）

PC①でPowerShell 7を開き、次をまとめて実行します。既存のSSH接続設定を使います。接続できない場合、設定変更や新しい鍵の作成はせず連絡してください。

```powershell
& {
    $ErrorActionPreference = 'Stop'
    if ($PSVersionTable.PSVersion -lt [version]'7.2') { throw 'PowerShell 7.2以上で実行してください。' }
    $expected = '5ca40ca3c70bc4c0c39ced188f40682de807d3ad86b55cf84bc48382510ddd2c'
    $result = @(ssh -o BatchMode=yes -o ConnectTimeout=10 s140.coreserver.jp 'sha256sum -- "$HOME/.famie-lifecycle/staging/ledger/account-lifecycle.jsonl"')
    if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or $result[0] -notmatch '\A([a-f0-9]{64})\s') {
        throw 'サーバー上の台帳を確認できません。ここで停止します。'
    }
    if ($Matches[1] -ne $expected) { throw '台帳が事前確認時から更新されています。コピーせず連絡してください。' }
    Write-Host '取得元確認OK（ステージング台帳）'
}
```

`取得元確認OK（ステージング台帳）` と出れば手順1の成功です。パスワードや台帳本文は表示しません。

事前確認時の台帳は1,973 bytes・8行（intent 4、committed 4）で、参照する画像証跡は0件です。これは過去の画像消去やcleanup完了を証明するものではありません。取得元が更新された場合には、この条件とコピー対象を再確認してから手順を更新します。

## 2. PC①：台帳をPC内の新しい保存先へ取得する

次を実行します。新しい私有フォルダーを作り、台帳1件だけをダウンロードします。サーバー上のファイルは変更しません。

```powershell
& {
    $ErrorActionPreference = 'Stop'
    if ($PSVersionTable.PSVersion -lt [version]'7.2') { throw 'PowerShell 7.2以上で実行してください。' }
    $expected = '5ca40ca3c70bc4c0c39ced188f40682de807d3ad86b55cf84bc48382510ddd2c'
    function Confirm-SourceLedger {
        $result = @(ssh -o BatchMode=yes -o ConnectTimeout=10 s140.coreserver.jp 'sha256sum -- "$HOME/.famie-lifecycle/staging/ledger/account-lifecycle.jsonl"')
        if ($LASTEXITCODE -ne 0 -or $result.Count -ne 1 -or $result[0] -notmatch '\A([a-f0-9]{64})\s') { throw '取得元の確認に失敗しました。' }
        if ($Matches[1] -ne $expected) { throw '取得元が更新されました。このコピーを確認済みとせず停止してください。' }
    }
    Confirm-SourceLedger
    $folder = Join-Path $env:LOCALAPPDATA ('FamieRecovery\ledger-' + (Get-Date -Format 'yyyyMMdd-HHmmss'))
    if (Test-Path -LiteralPath $folder) { throw '保存先が既にあります。上書きせず停止します。' }
    New-Item -ItemType Directory -Path $folder | Out-Null
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().Name
    icacls $folder /inheritance:r /grant:r "${identity}:(OI)(CI)F" '*S-1-5-18:(OI)(CI)F' | Out-Null
    if ($LASTEXITCODE -ne 0) { throw '保存先のアクセス制限に失敗しました。' }
    $ledger = Join-Path $folder 'account-lifecycle.jsonl'
    scp -o BatchMode=yes -o ConnectTimeout=10 's140.coreserver.jp:.famie-lifecycle/staging/ledger/account-lifecycle.jsonl' $ledger
    if ($LASTEXITCODE -ne 0) { throw '台帳の取得に失敗しました。' }
    if ((Get-FileHash -LiteralPath $ledger -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) { throw '取得した台帳が一致しません。' }
    Confirm-SourceLedger
    Write-Host '台帳の取得・照合OK'
    Write-Host "保存先：$folder"
}
```

`台帳の取得・照合OK` と保存先が出たら成功です。保存先をメモしてください。途中で更新やエラーを検出したら、残ったコピーを確認済みとして扱わず、そのまま残して連絡してください。

## 3. PC①：台帳をUSBへコピーする

USBを接続し、エクスプローラーの「PC」でドライブ文字を確認します。次の質問に、手順2の保存先とUSBのドライブ（例 `E:\`）を入力してください。

```powershell
& {
    $ErrorActionPreference = 'Stop'
    if ($PSVersionTable.PSVersion -lt [version]'7.2') { throw 'PowerShell 7.2以上で実行してください。' }
    $folder = (Read-Host '手順2で表示された保存先').Trim('"')
    $ledger = Join-Path $folder 'account-lifecycle.jsonl'
    $expected = '5ca40ca3c70bc4c0c39ced188f40682de807d3ad86b55cf84bc48382510ddd2c'
    if ((Get-FileHash -LiteralPath $ledger -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expected) { throw '保存した台帳が一致しません。' }
    $usb = Read-Host 'USBのドライブ（例 E:\）'
    if ($usb -notmatch '\A[A-Za-z]:\\\z' -or -not (Test-Path -LiteralPath $usb -PathType Container)) { throw 'USBの指定を確認してください。' }
    if ([IO.Path]::GetPathRoot($folder) -ieq $usb) { throw '保存元と同じドライブです。USBか確認してください。' }
    $name = 'famie-staging-ledger-' + (Get-Date -Format 'yyyyMMdd-HHmmss') + '.zip'
    $zip = Join-Path $folder $name
    $usbZip = Join-Path $usb $name
    if ((Test-Path -LiteralPath $zip) -or (Test-Path -LiteralPath $usbZip)) { throw '同名ファイルがあります。上書きせず停止します。' }
    Compress-Archive -LiteralPath $ledger -DestinationPath $zip
    $zipHash = (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant()
    Copy-Item -LiteralPath $zip -Destination $usbZip
    if ((Get-FileHash -LiteralPath $usbZip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $zipHash) { throw 'USBコピーが一致しません。' }
    Write-Host '台帳のUSBコピー照合OK'
    Write-Host "USB内のZIP：$usbZip"
    Write-Host "ZIPの照合番号：$zipHash"
}
```

USB内のZIP名と「ZIPの照合番号」をUSB以外の自分用メモに保存してください。USBには台帳の識別子が含まれるため、自分の管理するUSBを使い、他人へ渡さず保管します。今回はZIPにパスワードを設定していません。鍵予備のZIPは変更しません。

成功したらUSBを安全に取り外します。

## 4. PC②：USB内のZIPと台帳を照合する（読取りのみ）

PC②へUSBを接続し、PowerShell 7を開きます。次を実行します。展開・DB接続・鍵の開封は行いません。

```powershell
& {
    $ErrorActionPreference = 'Stop'
    if ($PSVersionTable.PSVersion -lt [version]'7.2') { throw 'PowerShell 7.2以上で実行してください。' }
    $zip = (Read-Host 'USB内の台帳ZIPのフルパス').Trim('"')
    $expectedZip = (Read-Host '別にメモした台帳ZIPの照合番号').Trim().ToLowerInvariant()
    if ($expectedZip -notmatch '\A[a-f0-9]{64}\z' -or (Get-FileHash -LiteralPath $zip -Algorithm SHA256).Hash.ToLowerInvariant() -ne $expectedZip) { throw 'ZIPが一致しません。' }
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [IO.Compression.ZipFile]::OpenRead($zip)
    try {
        if ($archive.Entries.Count -ne 1 -or $archive.Entries[0].FullName -cne 'account-lifecycle.jsonl' -or $archive.Entries[0].Length -ne 1973) { throw 'ZIPの内容が想定と異なります。' }
        $stream = $archive.Entries[0].Open()
        $sha = [Security.Cryptography.SHA256]::Create()
        try { $actual = [Convert]::ToHexString($sha.ComputeHash($stream)).ToLowerInvariant() }
        finally { $stream.Dispose(); $sha.Dispose() }
        if ($actual -ne '5ca40ca3c70bc4c0c39ced188f40682de807d3ad86b55cf84bc48382510ddd2c') { throw '台帳が取得元と一致しません。' }
    }
    finally { $archive.Dispose() }
    Write-Host '別PCでの台帳照合OK'
}
```

`別PCでの台帳照合OK` と出たら、その結果だけチャットで報告してください。台帳本文や照合番号の共有は不要です。USBは安全に取り外して保管し、PC①の保存ファイルも残してください。

この確認は記載時点のコピーに限ります。その後に発生した退会・削除記録が自動でUSBへ追記されるわけではありません。定期コピーと最新版の確認、同期記録v2の作成・照合、cleanup証跡、商用側の運用は引き続き残件です。
