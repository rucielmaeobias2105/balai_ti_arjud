@echo off
REM ============================================================
REM  Balai ti Arjud - one-command dev startup
REM
REM  Starts MySQL (on a working port), syncs the schema + demo
REM  data, builds assets if needed, and serves the app in this
REM  window (Ctrl+C to stop).
REM
REM  Usage:  start-dev.bat
REM  Then open http://localhost:8000
REM
REM  The browser is opened for you, but only once the site really
REM  answers, so you never see a "can't connect" page.
REM
REM  Press Ctrl+C in this window to stop.
REM
REM  Prefer a server that survives closing this window? Use
REM  dev-up.bat instead - it runs detached and restarts the app
REM  automatically if it dies.
REM
REM  Already have XAMPP's Apache running? You do not need this
REM  script at all - just open http://localhost/BTA/public/
REM ============================================================

setlocal enabledelayedexpansion
cd /d "%~dp0"

echo.
echo  ==========================================================
echo   Balai ti Arjud - dev startup
echo  ==========================================================
echo.

REM ------------------------------------------------------------
REM  1. MySQL
REM ------------------------------------------------------------
REM  DB_PORT in .env decides which server we talk to.
for /f "tokens=2 delims==" %%p in ('findstr /b "DB_PORT=" .env') do set DB_PORT=%%p
if "%DB_PORT%"=="" set DB_PORT=3306

echo [1/4] Checking MySQL on port %DB_PORT% ...

"%XAMPP%\mysql\bin\mysql.exe" -u root -P %DB_PORT% --connect-timeout=5 -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
    echo       MySQL is NOT reachable on port %DB_PORT%.
    echo.
    echo       If DB_PORT is 3306, your XAMPP MySQL data folder is damaged.
    echo       Either:
    echo         a] Repair it - see README.md, section "Fixing a damaged XAMPP MySQL"
    echo         b] Run the isolated instance instead:
    echo.
    echo                tools\start-mysql-isolated.bat
    echo.
    echo       then set DB_PORT in .env to the port it prints.
    echo.
    exit /b 1
)
echo       MySQL is up.

REM ------------------------------------------------------------
REM  2. Database name
REM ------------------------------------------------------------
for /f "tokens=2 delims==" %%d in ('findstr /b "DB_DATABASE=" .env') do set DB_NAME=%%d
echo.
echo [2/4] Creating database "%DB_NAME%" if needed ...
"%XAMPP%\mysql\bin\mysql.exe" -u root -P %DB_PORT% -e "CREATE DATABASE IF NOT EXISTS %DB_NAME% CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
if errorlevel 1 (
    echo       ERROR: could not create the database. Check DB_USERNAME / DB_PASSWORD in .env
    exit /b 1
)
echo       Done.

REM ------------------------------------------------------------
REM  3. Schema + demo data
REM ------------------------------------------------------------
echo.
echo [3/4] Running migrations and seeders ...
php artisan optimize:clear -q
php artisan migrate --seed --force
if errorlevel 1 (
    echo       ERROR: migrate --seed failed.
    exit /b 1
)
REM No `storage:link` here, and none needed: uploads are written directly into
REM public/storage, which is the directory the server serves. See README.
echo       Done.

REM ------------------------------------------------------------
REM  4. Assets + serve
REM ------------------------------------------------------------
echo.
echo [4/4] Building frontend assets ...
call npm run build
if errorlevel 1 (
    echo       WARNING: asset build failed. The app will look unstyled.
)

REM A background waiter opens the browser only after the site really answers.
REM Booting Laravel on this machine can take the best part of a minute, so
REM opening the browser immediately would show "can't connect to
REM 127.0.0.1:8000" on every run.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoProfile','-ExecutionPolicy','Bypass','-File','%~dp0tools\wait-for-site.ps1','-Port','8000','-TimeoutSeconds','240','-OpenBrowser' -WindowStyle Hidden" >nul 2>&1

echo.
echo  ==========================================================
echo  Ready.
echo.
echo  Customer site   http://localhost:8000
echo  Admin panel     http://localhost:8000/admin/login
echo.
echo  Admin login     username: admin   password: password
echo  Customer login  email: juan@example.test   password: password
echo.
echo  Your browser will open by itself as soon as the site answers, so you
echo  will never land on a "can't connect" page. Do not close this window.
echo.
echo  Note: XAMPP's Apache serves the same app in parallel at
echo        http://localhost/BTA/public/ - both work at once.
echo  ==========================================================
echo.

REM --host and --port are passed explicitly on purpose. Without them,
REM "artisan serve" silently falls back to 8001, 8002, ... when 8000 is busy,
REM which leaves http://localhost:8000 dead while the app actually runs
REM somewhere else - the exact symptom this script is meant to avoid.
php artisan serve --host=127.0.0.1 --port=8000
