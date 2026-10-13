$ErrorActionPreference = 'Stop'
$repoPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$toolsPath = Join-Path $repoPath '.local-server/tools'
New-Item -ItemType Directory -Force $toolsPath | Out-Null
$archivePath = Join-Path $toolsPath 'ffmpeg.zip'
$checksumPath = Join-Path $toolsPath 'ffmpeg.sha256'
# Windows distributor linked by https://ffmpeg.org/download.html.
Invoke-WebRequest 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip' -OutFile $archivePath
Invoke-WebRequest 'https://www.gyan.dev/ffmpeg/builds/ffmpeg-release-essentials.zip.sha256' -OutFile $checksumPath
$expectedHash = (Get-Content -Raw $checksumPath).Trim().Split(' ')[0]
if ((Get-FileHash $archivePath -Algorithm SHA256).Hash -ne $expectedHash) { throw 'FFmpeg checksum mismatch.' }
Expand-Archive -LiteralPath $archivePath -DestinationPath (Join-Path $toolsPath 'package') -Force
$binaryPath = Get-ChildItem (Join-Path $toolsPath 'package') -Filter ffmpeg.exe -Recurse | Select-Object -First 1
Copy-Item -LiteralPath $binaryPath.FullName -Destination (Join-Path $toolsPath 'ffmpeg.exe') -Force
Write-Host 'FFmpeg terpasang untuk aplikasi ini. Restart Start-DriveServer.ps1 untuk mengaktifkan mode hemat.'
