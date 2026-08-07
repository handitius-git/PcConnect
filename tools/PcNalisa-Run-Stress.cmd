@echo off
setlocal
title PcNalisa Stress Test

cd /d "%~dp0"

set "SCRIPT="
for %%F in (PcNalisa-PC*.ps1 PcNalisa-Agent.ps1) do (
    if not defined SCRIPT set "SCRIPT=%%~fF"
)

if not defined SCRIPT (
    echo PcNalisa script tidak ditemukan.
    echo Letakkan file PcNalisa-PCxxxxx.ps1 atau PcNalisa-Agent.ps1 di folder yang sama dengan file ini.
    echo.
    pause
    exit /b 2
)

echo Menjalankan PcNalisa dengan stress test offline CPU/RAM/Storage:
echo %SCRIPT%
echo.
echo Catatan:
echo - Proses lebih berat daripada analisa normal.
echo - Tutup aplikasi kerja penting sebelum menjalankan.
echo - Default stress test sekitar 30 detik.
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -StressTest -StressSeconds 30
set "EXITCODE=%ERRORLEVEL%"

echo.
if "%EXITCODE%"=="0" (
    echo PcNalisa stress test selesai.
) else (
    echo PcNalisa stress test selesai dengan error code %EXITCODE%.
    echo Cek file log PcNalisa-*.log atau fallback JSON di folder ini.
)
echo.
pause
exit /b %EXITCODE%
