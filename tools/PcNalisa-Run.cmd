@echo off
setlocal
title PcNalisa

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

echo Menjalankan PcNalisa:
echo %SCRIPT%
echo.
echo Jangan tutup jendela ini sampai proses selesai.
echo.

powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%"
set "EXITCODE=%ERRORLEVEL%"

echo.
if "%EXITCODE%"=="0" (
    echo PcNalisa selesai.
) else (
    echo PcNalisa selesai dengan error code %EXITCODE%.
    echo Cek file log PcNalisa-*.log atau fallback JSON di folder ini.
)
echo.
pause
exit /b %EXITCODE%
