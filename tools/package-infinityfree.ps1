#Requires -Version 5.1
<#
    Builds an upload-ready InfinityFree bundle.

    InfinityFree gives you FTP, a file manager and a MySQL database. There is no
    SSH, so there is no `composer install`, no `php artisan migrate` and no
    `npm run build` on the server - every step that would normally run there has
    to run here instead, and this script does all of them and hands back one
    archive.

    What it produces:

        bta-infinityfree.zip
          DEPLOY.md                  step by step, read this first
          htdocs/                    upload the CONTENTS of this into htdocs
            index.php  .htaccess  build/  images/  vendor/  favicon.ico
            robots.txt  storage/            <- the web root: the old public/
            laravel/                        <- the framework, not web reachable
              app/ bootstrap/ config/ database/ resources/ routes/
              storage/ vendor/ artisan/ composer.*  .env  .env.example

    `public/` is hoisted into `htdocs/` because the document root is fixed and
    cannot be pointed at `public/`; the framework moves to `htdocs/laravel` so
    that `.env`, `storage/logs` and `config/` are not left sitting in the web
    root. bootstrap/app.php notices the missing `laravel/public` directory and
    repoints `public_path()` at the parent, which is what keeps `@vite` finding
    the build manifest and keeps uploads landing in the directory the server
    actually serves.

    Usage:
        powershell -NoProfile -ExecutionPolicy Bypass -File tools\package-infinityfree.ps1
        powershell -NoProfile -ExecutionPolicy Bypass -File tools\package-infinityfree.ps1 -AppUrl https://salon.infinityfreeapp.com -DbHost sql123.infinityfree.com -DbDatabase if0_12345_bta -DbUsername if0_12345 -DbPassword secret

    -SkipComposer reuses the vendor/ already in the working tree instead of
    resolving a fresh --no-dev set. Faster, no network, but it ships the dev
    packages (phpunit and friends) too.
#>
[CmdletBinding()]
param(
    [string]$OutputPath = '',
    [string]$AppUrl = 'https://CHANGE-ME.infinityfreeapp.com',
    [string]$DbHost = 'sqlXXX.infinityfree.com',
    [string]$DbDatabase = 'CHANGE_ME_bta',
    [string]$DbUsername = 'CHANGE-ME',
    [string]$DbPassword = '',
    [string]$AppKey = '',
    [string]$ComposerPhar = '',
    [switch]$SkipComposer
)

$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
$stagingRoot = Join-Path $env:TEMP 'bta-infinityfree'
$bundle = Join-Path $stagingRoot 'bta-infinityfree'
$webRoot = Join-Path $bundle 'htdocs'
$appRoot = Join-Path $webRoot 'laravel'

# Windows PowerShell's `Set-Content -Encoding UTF8` writes a BOM. Apache then
# fails on the first line of an .htaccess, and a BOM in front of `<?php` is at
# best a wasted byte in the response, so every file this script writes is UTF-8
# without one.
$utf8NoBom = New-Object System.Text.UTF8Encoding($false)

function Write-Step {
    param([string]$Message)
    Write-Host "==> $Message"
}

function Write-File {
    param([string]$Path, [string]$Content)

    New-Item -ItemType Directory -Force -Path (Split-Path -Parent $Path) | Out-Null
    [System.IO.File]::WriteAllText($Path, $Content, $utf8NoBom)
}

function Copy-Tree {
    param([string]$From, [string]$To, [string[]]$ExcludeDir = @())

    New-Item -ItemType Directory -Force -Path $To | Out-Null

    $roboArgs = @($From, $To, '/E', '/NFL', '/NDL', '/NJH', '/NJS', '/NP', '/R:1', '/W:1')
    if ($ExcludeDir.Count -gt 0) {
        $roboArgs += '/XD'
        $roboArgs += $ExcludeDir
    }

    & robocopy @roboArgs | Out-Null
    $code = $LASTEXITCODE

    # robocopy reports success with anything below 8; 8 and up are real errors.
    if ($code -ge 8) {
        throw "robocopy failed (exit $code) copying '$From' to '$To'"
    }
}

# ---------------------------------------------------------------------------
# 1. Prerequisites
# ---------------------------------------------------------------------------

Write-Step 'Checking the working tree'

if (-not (Test-Path (Join-Path $root 'public\build\manifest.json'))) {
    throw 'public\build\manifest.json is missing. Run `npm run build` first.'
}

$sqlDump = Join-Path $root 'balai_ti_arjud.sql'

if (-not (Test-Path $sqlDump)) {
    throw 'balai_ti_arjud.sql is missing, so there would be nothing to import into phpMyAdmin.'
}

if (Test-Path $stagingRoot) {
    Remove-Item -LiteralPath $stagingRoot -Recurse -Force
}

New-Item -ItemType Directory -Force -Path $bundle | Out-Null

# ---------------------------------------------------------------------------
# 2. The framework, into htdocs/laravel
# ---------------------------------------------------------------------------

Write-Step 'Copying the framework'

foreach ($dir in @('app', 'config', 'resources', 'routes')) {
    Copy-Tree -From (Join-Path $root $dir) -To (Join-Path $appRoot $dir)
}

# `bootstrap/cache` is skipped wholesale. It holds the developer's compiled
# config and route caches, which bake their APP_URL and their database
# credentials into the bundle; packages.php and services.php are written again
# by composer install below.
Copy-Tree -From (Join-Path $root 'bootstrap') -To (Join-Path $appRoot 'bootstrap') -ExcludeDir @('cache')

Copy-Tree -From (Join-Path $root 'database') -To (Join-Path $appRoot 'database')

Remove-Item -LiteralPath (Join-Path $appRoot 'database\database.sqlite') -Force -ErrorAction SilentlyContinue

foreach ($file in @('artisan', 'composer.json', 'composer.lock', '.editorconfig', '.env.example', 'README.md')) {
    $source = Join-Path $root $file

    if (Test-Path $source) {
        Copy-Item -LiteralPath $source -Destination (Join-Path $appRoot $file)
    }
}

New-Item -ItemType Directory -Force -Path (Join-Path $appRoot 'bootstrap\cache') | Out-Null

# ---------------------------------------------------------------------------
# 3. A clean storage tree
# ---------------------------------------------------------------------------

Write-Step 'Laying out storage'

# Rebuilt rather than copied. A copy would drag along every compiled Blade view
# and every line of the developer's laravel.log. Each directory carries a
# .gitignore placeholder for the same reason it does in the repository: git
# cannot track an empty directory, so without one a fresh checkout would have
# nowhere for Laravel to write.
foreach ($dir in @(
    'app\private',
    'app\public',
    'framework\cache\data',
    'framework\sessions',
    'framework\testing',
    'framework\views',
    'logs'
)) {
    $path = Join-Path $appRoot "storage\$dir"

    New-Item -ItemType Directory -Force -Path $path | Out-Null
    Write-File -Path (Join-Path $path '.gitignore') -Content "*`n!.gitignore"
}

# ---------------------------------------------------------------------------
# 4. The web root, hoisted out of public/
# ---------------------------------------------------------------------------

Write-Step 'Hoisting public/ into htdocs/'

Copy-Tree -From (Join-Path $root 'public\build') -To (Join-Path $webRoot 'build')
Copy-Tree -From (Join-Path $root 'public\images') -To (Join-Path $webRoot 'images')
Copy-Tree -From (Join-Path $root 'public\storage') -To (Join-Path $webRoot 'storage')
Copy-Item -LiteralPath (Join-Path $root 'public\favicon.ico') -Destination $webRoot
Copy-Item -LiteralPath (Join-Path $root 'public\robots.txt') -Destination $webRoot

# public/vendor is the vendored Cropper.js, not Composer's. It cannot be merged
# into laravel/vendor and it has to stay reachable at /vendor/cropper/, which is
# where resources/js/app.js looks for window.Cropper.
if (Test-Path (Join-Path $root 'public\vendor')) {
    Copy-Tree -From (Join-Path $root 'public\vendor') -To (Join-Path $webRoot 'vendor')
}

Write-File -Path (Join-Path $webRoot 'index.php') -Content @'
<?php

use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

// The framework sits in htdocs/laravel because InfinityFree fixes the document
// root at htdocs and serves nothing outside it. These three paths are the only
// difference from the stock public/index.php.

if (file_exists($maintenance = __DIR__.'/laravel/storage/framework/maintenance.php')) {
    require $maintenance;
}

require __DIR__.'/laravel/vendor/autoload.php';

(require_once __DIR__.'/laravel/bootstrap/app.php')
    ->handleRequest(Request::capture());
'@

Write-File -Path (Join-Path $webRoot '.htaccess') -Content @'
<IfModule mod_rewrite.c>
    <IfModule mod_negotiation.c>
        Options -MultiViews -Indexes
    </IfModule>

    RewriteEngine On

    # Handle Authorization Header
    RewriteCond %{HTTP:Authorization} .
    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]

    # Handle X-XSRF-Token Header
    RewriteCond %{HTTP:x-xsrf-token} .
    RewriteRule .* - [E=HTTP_X_XSRF_TOKEN:%{HTTP:X-XSRF-Token}]

    # The framework is a sibling of the web root, not a child of it, so this is
    # the only thing standing between a browser and laravel/.env,
    # laravel/storage/logs/laravel.log, config/ and database/. Without it the
    # front controller rule below would serve every one of them happily, because
    # `RewriteCond !-f` only skips files that are not there.
    RewriteRule ^laravel(/|$) - [F,L]

    # Redirect Trailing Slashes If Not A Folder...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_URI} (.+)/$
    RewriteRule ^ %1 [L,R=301]

    # Send Requests To Front Controller...
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^ index.php [L]
</IfModule>

# Second lock on the same door. htdocs/laravel/.htaccess refuses the directory
# outright, which covers the case where mod_rewrite is unavailable; this covers
# a dotfile that somehow lands in the web root itself.
<IfModule mod_authz_core.c>
    <FilesMatch "^\.(env|htaccess|htpasswd)">
        Require all denied
    </FilesMatch>
</IfModule>
'@

Write-File -Path (Join-Path $appRoot '.htaccess') -Content @'
# Refuses every HTTP request into the framework directory. PHP still reads these
# files off disk, so nothing about the application breaks - it only stops a
# browser being able to ask for them. Belt and braces with the RewriteRule in
# the web root's own .htaccess.
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
</IfModule>
'@

# ---------------------------------------------------------------------------
# 5. Vendor
# ---------------------------------------------------------------------------

if ($SkipComposer) {
    Write-Step 'Copying the existing vendor/ (-SkipComposer)'

    Copy-Tree -From (Join-Path $root 'vendor') -To (Join-Path $appRoot 'vendor')
} else {
    Write-Step 'Resolving a --no-dev vendor/ with composer'

    if (-not $ComposerPhar) {
        $ComposerPhar = Join-Path $stagingRoot '..\composer.phar'
    }

    if (-not (Test-Path $ComposerPhar)) {
        throw "composer.phar not found at '$ComposerPhar'. Fetch it from https://getcomposer.org/download/, or pass -SkipComposer."
    }

    Push-Location $appRoot

    try {
        & php $ComposerPhar install --no-dev --optimize-autoloader --no-interaction --no-progress --no-ansi
        $code = $LASTEXITCODE
    } finally {
        Pop-Location
    }

    if ($code -ne 0) {
        throw "composer install failed (exit $code). Pass -SkipComposer to ship the vendor/ already in the working tree instead."
    }
}

# ---------------------------------------------------------------------------
# 6. .env
# ---------------------------------------------------------------------------

Write-Step 'Writing laravel/.env'

if (-not $AppKey) {
    $AppKey = (& php (Join-Path $root 'artisan') key:generate --show --no-interaction | Select-Object -Last 1).Trim()
}

if (-not $AppKey.StartsWith('base64:')) {
    throw "Could not generate an APP_KEY (got '$AppKey')."
}

$from = '"{APP_NAME}"'

$env = @"
APP_NAME="Balai ti Arjud"
APP_ENV=production
APP_KEY=$AppKey
APP_DEBUG=false
APP_TIMEZONE=Asia/Manila
APP_URL=$AppUrl

APP_LOCALE=en
APP_FALLBACK_LOCALE=en
APP_FAKER_LOCALE=en_US

APP_MAINTENANCE_DRIVER=file

BCRYPT_ROUNDS=12

LOG_CHANNEL=stack
LOG_STACK=single
LOG_DEPRECATIONS_CHANNEL=null
LOG_LEVEL=error

# InfinityFree hands out MySQL on a remote host, and the control panel's MySQL
# section shows these exact four values. 127.0.0.1 never works: that is the
# account's own loopback interface, where no database is listening.
DB_CONNECTION=mysql
DB_HOST=$DbHost
DB_PORT=3306
DB_DATABASE=$DbDatabase
DB_USERNAME=$DbUsername
DB_PASSWORD="$DbPassword"

# InfinityFree terminates TLS in its own frontend and hands Apache a plain HTTP
# request carrying X-Forwarded-Proto: https. Laravel ignores that header unless
# the address it came from is trusted, so without this every generated URL comes
# back as http:// and the browser refuses the stylesheet and the script bundle as
# mixed content. See config/trustedproxy.php.
TRUSTED_PROXIES=*

SESSION_DRIVER=database
SESSION_LIFETIME=120
SESSION_ENCRYPT=true
SESSION_PATH=/
SESSION_DOMAIN=null
# Left unset on purpose: config/session.php switches the Secure attribute on
# when APP_ENV=production, so you must load the site over https:// or nothing
# stays signed in. InfinityFree issues a free certificate - turn it on in the
# control panel before testing a login. Set this to false only if you are
# deliberately testing over http://.
# SESSION_SECURE_COOKIE=

BROADCAST_CONNECTION=log
FILESYSTEM_DISK=public
QUEUE_CONNECTION=database

CACHE_STORE=database

# InfinityFree blocks outbound SMTP, so mail is written to
# laravel/storage/logs/laravel.log instead of being sent. The password reset and
# the contact form will report success and deliver nothing; the link is in the
# log. See DEPLOY.md.
MAIL_MAILER=log
MAIL_FROM_ADDRESS="balaitiarjud@gmail.com"
MAIL_FROM_NAME=$from

VITE_APP_NAME=$from
"@

Write-File -Path (Join-Path $appRoot '.env') -Content $env

# ---------------------------------------------------------------------------
# 7. The database dump
# ---------------------------------------------------------------------------

Write-Step 'Copying the database dump'

Copy-Item -LiteralPath $sqlDump -Destination (Join-Path $appRoot 'database')

# ---------------------------------------------------------------------------
# 8. DEPLOY.md
# ---------------------------------------------------------------------------

Write-Step 'Writing DEPLOY.md'

Write-File -Path (Join-Path $bundle 'DEPLOY.md') -Content @'
# Deploying to InfinityFree

InfinityFree gives you FTP, a file manager and a MySQL database. There is no
SSH, so there is no `composer install`, no `php artisan migrate` and no
`npm run build` on the server - all of that is already done in this archive.

## What is in here

    DEPLOY.md              this file
    htdocs/                upload the *contents* of this folder into htdocs

Inside `htdocs`:

| Path | What it is |
| --- | --- |
| `index.php`, `.htaccess` | the front controller, repointed at `laravel/` |
| `build/` | the compiled Tailwind bundle and JavaScript |
| `storage/` | uploaded images, served at `/storage/...` |
| `images/`, `vendor/`, `favicon.ico`, `robots.txt` | static assets |
| `laravel/` | the framework. Not reachable over HTTP. |

`public/` is hoisted into `htdocs/` because InfinityFree fixes the document root
at `htdocs` and offers no way to point it at `public/` instead. The framework
moves down into `laravel/` so that `.env`, `storage/logs` and `config/` are not
sitting inside the web root. `bootstrap/app.php` notices that `laravel/public`
does not exist and repoints `public_path()` at `htdocs`, which is what keeps
`@vite` reading the build manifest and keeps uploads landing in the directory
the server actually serves.

## Steps

### 1. PHP version

Control panel -> **PHP Version**. Choose **8.3** (8.2 also works). Laravel 11
refuses to boot on anything below 8.2.

### 2. Database

Control panel -> **MySQL Databases** -> create one, then open **phpMyAdmin**,
select it and **Import** `htdocs/laravel/database/balai_ti_arjud.sql`.

The dump carries the full schema *and* the demo data, including accounts whose
password is `password`. Change or delete them before this is a real shop.

### 3. `.env`

Control panel -> **File Manager** -> `htdocs/laravel/.env`. Fill in the four
database values from the MySQL section of the control panel, and set `APP_URL`
to your real domain with no trailing slash:

    APP_URL=https://youraccount.infinityfreeapp.com
    DB_HOST=sqlXXX.infinityfree.com
    DB_DATABASE=if0_12345_bta
    DB_USERNAME=if0_12345
    DB_PASSWORD=the password the control panel gave you

`APP_KEY` is already filled in. Do not replace it. Changing it invalidates every
existing session, and a backup taken later will only restore correctly if it
still matches.

### 4. Upload

Upload the **contents** of `htdocs/` into your account's `htdocs` folder. Not
the `htdocs` folder itself, or you end up with `htdocs/htdocs/index.php` and a
404.

The file manager is fine for a few hundred files; `vendor/` is several thousand,
so use an FTP client (FileZilla) for this one. There is no shell, so there is no
`chmod` either - the permissions that arrive over FTP are already correct.

### 5. HTTPS

Control panel -> **SSL** -> use the free certificate. Load the site over
`https://` before testing a login: `APP_ENV=production` makes the session cookie
`Secure`, so over plain `http://` you get bounced straight back to the login
screen with no error anywhere.

## What will not work, and why

**Password reset and the contact form deliver nothing.** InfinityFree blocks
outbound SMTP, so `MAIL_MAILER=log` writes the message to
`laravel/storage/logs/laravel.log` and calls it a day. The flow reports success
because from the app's point of view it did. Read the reset link out of the log.

**The nightly auto-archive never runs.** `routes/console.php` schedules
`ArchiveCompletedAppointmentsCommand` for 02:00, which needs `php artisan
schedule:run` on a cron. The free plan has no cron. Settled appointments are
archived by hand, or not at all.

**No `php artisan`.** That rules out `config:cache` and `route:cache`, so the app
reads its config from files on every request - slower, but correct. It also rules
out `migrate`: re-import the dump rather than migrating after a code change.

**Upload size limits.** InfinityFree caps `upload_max_filesize` well below the
10 MB the profile photo form accepts, so a large photo is rejected by PHP before
Laravel's validation ever runs. Nothing to configure; it surfaces as an "upload
error".

## If something breaks

- **White screen, no styling, no JavaScript.** Check `APP_URL` - `asset()` builds
  from it, and a wrong value points every stylesheet at a host that is not you.
  If `APP_URL` is right, check that `htdocs/build/manifest.json` exists.
- **Signed in, then bounced straight back to the login screen.** You are on
  `http://` and the cookie is `Secure`. Turn on the free SSL certificate.
- **`/laravel/.env` is downloadable.** The `.htaccess` guards were not applied.
  Check that `htdocs/.htaccess` was actually uploaded - FTP clients skip dotfiles
  by default, and FileZilla needs "Force transfer of hidden files" or a per-file
  "always use this" rule.
- **`storage/framework/views/...` returns 403.** Working as intended: that is the
  guard in `htdocs/laravel/.htaccess`.
- **500 with a database error.** `DB_HOST` is `127.0.0.1` or `localhost`.
  InfinityFree's database is a remote host.
- **Slow first request.** Normal. There is no config cache and nothing primes
  opcache; the second request is much faster.
'@

# ---------------------------------------------------------------------------
# 9. Zip
# ---------------------------------------------------------------------------

Write-Step 'Zipping'

if (-not $OutputPath) {
    $OutputPath = Join-Path $stagingRoot 'bta-infinityfree.zip'
}

if (Test-Path $OutputPath) {
    Remove-Item -LiteralPath $OutputPath -Force
}

Add-Type -AssemblyName System.IO.Compression.FileSystem

[System.IO.Compression.ZipFile]::CreateFromDirectory(
    $bundle,
    $OutputPath,
    [System.IO.Compression.CompressionLevel]::Optimal,
    $false
)

$zipSize = [math]::Round((Get-Item $OutputPath).Length / 1MB, 1)

Write-Host ''
Write-Host "Bundle:  $OutputPath ($zipSize MB)"
Write-Host "Upload:  contents of $webRoot"
Write-Host "Read:     $bundle\DEPLOY.md"