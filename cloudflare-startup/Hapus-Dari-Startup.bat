@echo off
title Hapus Cloudflare Tunnel dari Startup Windows
color 0C

echo ============================================================
echo   MENGHAPUS CLOUDFLARE TUNNEL DARI STARTUP WINDOWS
echo ============================================================
echo.

set "SHORTCUT_PATH=%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\CloudflareTunnel.lnk"

if exist "%SHORTCUT_PATH%" (
    del /f /q "%SHORTCUT_PATH%"
    echo [BERHASIL] Shortcut di Startup sudah dihapus!
    echo PC tidak akan lagi otomatis menjalankan tunnel saat baru dinyalakan.
) else (
    echo [INFO] Shortcut Cloudflare Tunnel tidak ditemukan di folder Startup.
)

echo.
pause
