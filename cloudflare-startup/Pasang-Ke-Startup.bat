@echo off
title Pasang Cloudflare Tunnel ke Startup Windows
color 0A

echo ============================================================
echo   MEMASANG CLOUDFLARE TUNNEL KE STARTUP WINDOWS
echo ============================================================
echo.

set "TARGET_EXE=%~dp0RunCloudflareTunnel.exe"
set "SHORTCUT_PATH=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\CloudflareTunnel.lnk"

if not exist "%TARGET_EXE%" (
    echo [ERROR] File RunCloudflareTunnel.exe tidak ditemukan di folder ini!
    pause
    exit /b
)

echo Membuat shortcut startup di:
echo "%SHORTCUT_PATH%"
echo.

powershell -NoProfile -ExecutionPolicy Bypass -Command "$ws = New-Object -ComObject WScript.Shell; $s = $ws.CreateShortcut('%SHORTCUT_PATH%'); $s.TargetPath = '%TARGET_EXE%'; $s.WorkingDirectory = '%~dp0'; $s.Save()"

if exist "%SHORTCUT_PATH%" (
    echo ============================================================
    echo [BERHASIL] Cloudflare Tunnel sudah terpasang di Startup!
    echo Setiap kali PC dinyalakan/login, CMD tunnel akan otomatis jalan.
    echo ============================================================
) else (
    echo [GAGAL] Gagal membuat shortcut startup.
)

echo.
pause
