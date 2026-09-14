@echo off
setlocal
cd /d "%~dp0"
if not exist "EmployeeBridgeApi-Config.json" (
  echo File EmployeeBridgeApi-Config.json belum ada.
  echo Copy dulu EmployeeBridgeApi-Config.example.json menjadi EmployeeBridgeApi-Config.json lalu edit isinya.
  pause
  exit /b 1
)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0EmployeeBridgeApi.ps1" -ConfigPath "%~dp0EmployeeBridgeApi-Config.json"
pause
