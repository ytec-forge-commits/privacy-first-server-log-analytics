[CmdletBinding(SupportsShouldProcess)]
param(
    [Parameter(Mandatory)]
    [ValidateScript({ Test-Path -LiteralPath $_ -PathType Leaf })]
    [string]$EncryptedBackupPath,

    [Parameter(Mandatory)]
    [string]$DestinationPath,

    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$source = (Resolve-Path -LiteralPath $EncryptedBackupPath).Path
$destination = [System.IO.Path]::GetFullPath($DestinationPath)
if ((Test-Path -LiteralPath $destination) -and -not $Force) {
    throw 'Destination already exists. Use -Force only after confirming the exact recovery target.'
}

$manifestPath = "$source.json"
if (-not (Test-Path -LiteralPath $manifestPath -PathType Leaf)) {
    throw 'Backup manifest is missing.'
}
$manifest = Get-Content -LiteralPath $manifestPath -Raw | ConvertFrom-Json
$encryptedHash = (Get-FileHash -LiteralPath $source -Algorithm SHA256).Hash
if ($encryptedHash -ne $manifest.encryptedSha256) {
    throw 'Encrypted backup hash does not match its manifest.'
}

$plainBytes = [System.Security.Cryptography.ProtectedData]::Unprotect(
    [System.IO.File]::ReadAllBytes($source),
    [System.Text.Encoding]::UTF8.GetBytes('Privacy-first Server Log Analytics backup v1'),
    [System.Security.Cryptography.DataProtectionScope]::CurrentUser
)
$plainHash = [Convert]::ToHexString([System.Security.Cryptography.SHA256]::HashData($plainBytes))
if ($plainHash -ne $manifest.sourceSha256) {
    throw 'Decrypted database hash does not match its manifest.'
}

if ($PSCmdlet.ShouldProcess($destination, 'Write decrypted analytics database')) {
    [System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($destination)) | Out-Null
    [System.IO.File]::WriteAllBytes($destination, $plainBytes)
}

[pscustomobject]@{ RestoredDatabase = $destination; Sha256 = $plainHash }
