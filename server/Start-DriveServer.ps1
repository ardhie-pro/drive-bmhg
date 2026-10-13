param(
    [switch]$Production,
    [switch]$PrepareOnly,
    [string]$ApacheRoot = 'C:/laragon/bin/apache/httpd-2.4.54-win64-VS16',
    [string]$PhpRoot = 'C:/laragon/bin/php/php-8.3.35-Win32-vs16-x64',
    [int]$Port = 8085
)
$ErrorActionPreference = 'Stop'
$repoPath = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path.Replace('\', '/')
$runtimePath = Join-Path $repoPath '.local-server'
New-Item -ItemType Directory -Force $runtimePath | Out-Null
$runtimePath = $runtimePath.Replace('\', '/')
New-Item -ItemType Directory -Force "$runtimePath/sessions" | Out-Null
if (!(Test-Path "$ApacheRoot/bin/httpd.exe") -or !(Test-Path "$PhpRoot/php8apache2_4.dll")) {
    throw 'Apache dan PHP Thread Safe belum ditemukan. Berikan -ApacheRoot dan -PhpRoot yang sesuai.'
}
$phpIni = Get-Content -Raw "$PhpRoot/php.ini"
$phpIni += "`nextension_dir = `"$PhpRoot/ext`"`nmemory_limit = 192M`nupload_max_filesize = 3M`npost_max_size = 4M`ndisplay_errors = Off`nlog_errors = On`nerror_log = `"$runtimePath/php-error.log`"`n"
$phpIni += "session.save_path = `"$runtimePath/sessions`"`nsession.cookie_httponly = 1`nsession.cookie_samesite = Lax`nsession.use_strict_mode = 1`n"
if ($phpIni -notmatch '(?m)^\s*zend_extension\s*=.*opcache' -and (Test-Path "$PhpRoot/ext/php_opcache.dll")) {
    $phpIni += "zend_extension = `"$PhpRoot/ext/php_opcache.dll`"`nopcache.enable = 1`nopcache.memory_consumption = 64`nopcache.validate_timestamps = 1`nopcache.revalidate_freq = 2`n"
}
$phpIni | Set-Content -LiteralPath "$runtimePath/php.ini" -Encoding ascii
$devMode = if ($Production) { '0' } else { '1' }
$configuration = @"
ServerRoot "$ApacheRoot"
Listen 127.0.0.1:$Port
ServerName 127.0.0.1:$Port
PidFile "$runtimePath/httpd.pid"
ErrorLog "$runtimePath/apache-error.log"
LogLevel warn
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule authz_host_module modules/mod_authz_host.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
LoadModule headers_module modules/mod_headers.so
LoadModule env_module modules/mod_env.so
LoadModule php_module "$PhpRoot/php8apache2_4.dll"
PHPIniDir "$runtimePath"
TypesConfig "$ApacheRoot/conf/mime.types"
AddType application/x-httpd-php .php
DirectoryIndex index.php
DocumentRoot "$repoPath"
ThreadsPerChild 32
ThreadLimit 64
MaxConnectionsPerChild 0
Timeout 60
KeepAlive On
KeepAliveTimeout 2
MaxKeepAliveRequests 100
LimitRequestBody 4194304
SetEnv DRIVE_LOCAL_DEV $devMode
<Directory "$repoPath">
    AllowOverride FileInfo
    Options -Indexes
    Require all granted
</Directory>
<Directory "$runtimePath">
    Require all denied
</Directory>
<Directory "$repoPath/server">
    Require all denied
</Directory>
<Directory "$repoPath/tests">
    Require all denied
</Directory>
<FilesMatch "^(config\.php|media\.php|upload-metadata\.php|\.user\.ini|\.gitignore|README.*)$">
    Require all denied
</FilesMatch>
<DirectoryMatch "/\.git">
    Require all denied
</DirectoryMatch>
<FilesMatch "\.(css|js|svg|png|ico)$">
    Header set Cache-Control "private, max-age=300"
</FilesMatch>
"@
$configuration | Set-Content -LiteralPath "$runtimePath/httpd.conf" -Encoding ascii
& "$ApacheRoot/bin/httpd.exe" -t -f "$runtimePath/httpd.conf"
if ($LASTEXITCODE -ne 0) { throw 'Konfigurasi Apache tidak valid.' }
if ($PrepareOnly) { return }
& "$PhpRoot/php.exe" "$PSScriptRoot/Maintain.php"
Write-Host "Drive BMHG: http://127.0.0.1:$Port | mode $(if ($Production) {'SSO aktif'} else {'development localhost'})"
Write-Host 'Ctrl+C untuk berhenti. Untuk Cloudflare gunakan -Production.'
& "$ApacheRoot/bin/httpd.exe" -f "$runtimePath/httpd.conf"
