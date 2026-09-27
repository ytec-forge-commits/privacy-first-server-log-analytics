[CmdletBinding(SupportsShouldProcess)]
param(
    [Parameter(Mandatory)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$DatabasePath,

    [Parameter(Mandatory)]
    [string]$DestinationDirectory,

    [ValidateRange(1, 120)]
    [int]$Keep = 12,

    [switch]$RemovePlaintext
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$source = (Resolve-Path -LiteralPath $DatabasePath).Path
$destination = [System.IO.Path]::GetFullPath($DestinationDirectory)
[System.IO.Directory]::CreateDirectory($destination) | Out-Null

$timestamp = Get-Date -Format 'yyyyMMdd-HHmmss'
$encryptedPath = Join-Path $destination "privacy-log-analytics-$timestamp.sqlite.dpapi"
$manifestPath = "$encryptedPath.json"
$plainBytes = [System.IO.File]::ReadAllBytes($source)
$encryptedBytes = [System.Security.Cryptography.ProtectedData]::Protect(
    $plainBytes,
    [System.Text.Encoding]::UTF8.GetBytes('Privacy-first Server Log Analytics backup v1'),
    [System.Security.Cryptography.DataProtectionScope]::CurrentUser
)
[System.IO.File]::WriteAllBytes($encryptedPath, $encryptedBytes)

$roundTrip = [System.Security.Cryptography.ProtectedData]::Unprotect(
    [System.IO.File]::ReadAllBytes($encryptedPath),
    [System.Text.Encoding]::UTF8.GetBytes('Privacy-first Server Log Analytics backup v1'),
    [System.Security.Cryptography.DataProtectionScope]::CurrentUser
)
$plainHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($plainBytes))
$roundTripHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($roundTrip))
if ($plainHash -ne $roundTripHash) {
    throw 'DPAPI round-trip verification failed.'
}

$manifest = [ordered]@{
    format = 'privacy-first-server-log-analytics-dpapi-v1'
    createdAt = (Get-Date).ToString('o')
    sourceSha256 = $plainHash
    encryptedSha256 = (Get-FileHash -LiteralPath $encryptedPath -Algorithm SHA256).Hash
    protectionScope = 'CurrentUser'
}
$manifest | ConvertTo-Json | Set-Content -LiteralPath $manifestPath -Encoding UTF8

$backups = Get-ChildItem -LiteralPath $destination -Filter 'privacy-log-analytics-*.sqlite.dpapi' -File | Sort-Object LastWriteTimeUtc -Descending
foreach ($oldBackup in ($backups | Select-Object -Skip $Keep)) {
    if ($PSCmdlet.ShouldProcess($oldBackup.FullName, 'Remove expired encrypted analytics backup')) {
        Remove-Item -LiteralPath $oldBackup.FullName -Force
        $oldManifest = "$($oldBackup.FullName).json"
        if (Test-Path -LiteralPath $oldManifest -PathType Leaf) {
            Remove-Item -LiteralPath $oldManifest -Force
        }
    }
}

if ($RemovePlaintext -and $PSCmdlet.ShouldProcess($source, 'Remove verified plaintext transfer copy')) {
    Remove-Item -LiteralPath $source -Force
}

[pscustomobject]@{
    EncryptedBackup = $encryptedPath
    Manifest = $manifestPath
    SourceSha256 = $plainHash
    PlaintextRemoved = [bool]$RemovePlaintext
}
