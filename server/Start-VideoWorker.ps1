param([string]$PhpPath = '')
$ErrorActionPreference = 'Stop'
if (!$PhpPath) {
    $phpCommand = Get-Command php -ErrorAction SilentlyContinue
    if ($phpCommand) { $PhpPath = $phpCommand.Source }
    elseif (Test-Path 'C:/xampp/php/php.exe') { $PhpPath = 'C:/xampp/php/php.exe' }
    else { throw 'PHP belum ditemukan. Berikan -PhpPath C:/xampp/php/php.exe atau path PHP yang sesuai.' }
}
$repoPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$ffmpegPath = Join-Path $repoPath '.local-server/tools/ffmpeg.exe'
if (!(Test-Path $ffmpegPath)) { throw 'Jalankan server/Install-FFmpeg.ps1 terlebih dahulu.' }
& $PhpPath (Join-Path $PSScriptRoot 'VideoWorker.php') $ffmpegPath
