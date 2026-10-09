<#
.SYNOPSIS
  Synclytics ERP — installs the server on a Windows PC (setup A: one PC; setup B: shop server; branch servers),
  doing what Chapter 4 of the installation guide describes by hand. Run as administrator. Safe to run again (update).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File install.ps1 -Release C:\Downloads\synclytics-shop-B1234.zip -Setup B
#>
param(
  [Parameter(Mandatory = $true)][string]$Release,              # the release ZIP, or a folder holding server\
  [ValidateSet('A', 'B')][string]$Setup = 'B',                 # A: only this PC uses it; B: tills on the network use it
  [ValidateSet('hq', 'branch')][string]$Role = 'hq',
  [string]$NodeId = 'HQ',
  [string]$Dir = 'C:\Synclytics',
  [int]$Port = 8010,
  [string]$PhpDir = 'C:\php',
  [string]$PgVersion = '17.6-1',                               # PostgreSQL build from get.enterprisedb.com (binaries ZIP)
  [string]$PgDir = 'C:\Synclytics\pgsql',
  [string]$PgData = 'C:\Synclytics\pgdata',
  [int]$PgPort = 5432,
  [switch]$Ci,                                                 # automated test: no company creation, no firewall
  [switch]$SkipCompany
)
$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
function Say($m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Die($m) { Write-Host "`nStopped: $m" -ForegroundColor Red; exit 1 }
function Run($exe, [string[]]$argv, $cwd = $null) {
  if (-not $cwd) { $cwd = (Get-Location).Path }  # Windows PowerShell 5.1 compatible (no ??)
  $p = Start-Process -FilePath $exe -ArgumentList $argv -WorkingDirectory $cwd -NoNewWindow -Wait -PassThru
  if ($p.ExitCode -ne 0) { Die "$exe $($argv -join ' ') failed (exit $($p.ExitCode))" }
}
$admin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $admin) { Die 'Right-click PowerShell and choose "Run as administrator", then run this again.' }
New-Item -ItemType Directory -Force $Dir, "$Dir\tmp", "$Dir\downloads" | Out-Null
$dl = "$Dir\downloads"

# ---------------------------------------------------------------------------------------------------------------
Say '1/9 PostgreSQL 17 (why: the database where everything the business records is kept)'
$pgBin = "$PgDir\bin"
if (-not (Test-Path "$pgBin\pg_ctl.exe")) {
  $zip = "$dl\postgresql-$PgVersion-binaries.zip"
  if (-not (Test-Path $zip)) { Invoke-WebRequest "https://get.enterprisedb.com/postgresql/postgresql-$PgVersion-windows-x64-binaries.zip" -OutFile $zip }
  Expand-Archive $zip -DestinationPath "$Dir\pg-extract" -Force
  Move-Item "$Dir\pg-extract\pgsql" $PgDir -Force
  Remove-Item "$Dir\pg-extract" -Recurse -Force
}
$secrets = "$Dir\installer-secrets.txt"  # this installation's database passwords; kept beside the software, readable by administrators only
if (-not (Test-Path "$PgData\PG_VERSION")) {
  $superPw = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
  $pwFile = "$Dir\tmp\pw.txt"; Set-Content $pwFile $superPw -NoNewline
  Run "$pgBin\initdb.exe" @('-D', $PgData, '-U', 'postgres', '--pwfile', $pwFile, '-E', 'UTF8', '--locale=C', '-A', 'scram-sha-256')
  Remove-Item $pwFile
  Add-Content $secrets "postgres superuser password: $superPw"
  Run "$pgBin\pg_ctl.exe" @('register', '-N', 'SynclyticsDB', '-D', $PgData, '-S', 'auto', '-o', "`"-p $PgPort`"")
}
Start-Service SynclyticsDB -ErrorAction SilentlyContinue
icacls $secrets /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null

# ---------------------------------------------------------------------------------------------------------------
Say '2/9 PHP 8.4 (why: the language the server is written in)'
if (-not (Test-Path "$PhpDir\php.exe")) {
  $rel = Invoke-RestMethod 'https://windows.php.net/downloads/releases/releases.json'
  $build = $rel.'8.4'.'nts-vs17-x64'
  $zip = "$dl\$($build.zip.path)"
  if (-not (Test-Path $zip)) { Invoke-WebRequest "https://windows.php.net/downloads/releases/$($build.zip.path)" -OutFile $zip }
  if ((Get-FileHash $zip -Algorithm SHA256).Hash.ToLower() -ne $build.zip.sha256.ToLower()) { Die 'The PHP download is damaged (checksum differs). Run again.' }
  Expand-Archive $zip -DestinationPath $PhpDir -Force
}
$ini = "$PhpDir\php.ini"
if (-not (Test-Path $ini)) { Copy-Item "$PhpDir\php.ini-production" $ini }
$iniText = Get-Content $ini -Raw
foreach ($ext in 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_pgsql', 'pgsql', 'sodium', 'zip') {
  $iniText = $iniText -replace "(?m)^;extension=$ext\s*$", "extension=$ext"
}
$iniText = $iniText -replace '(?m)^;?extension_dir = "ext"', 'extension_dir = "ext"' -replace '(?m)^memory_limit = .*', 'memory_limit = 512M' `
  -replace '(?m)^upload_max_filesize = .*', 'upload_max_filesize = 20M' -replace '(?m)^post_max_size = .*', 'post_max_size = 25M' `
  -replace '(?m)^;?date\.timezone =.*', 'date.timezone = Africa/Accra' -replace '(?m)^;?upload_tmp_dir =.*', "upload_tmp_dir = `"$Dir\tmp`"" `
  -replace '(?m)^;?sys_temp_dir =.*', "sys_temp_dir = `"$Dir\tmp`""
Set-Content $ini $iniText
$php = "$PhpDir\php.exe"
if (-not (& $php -m | Select-String -Pattern '^pdo_pgsql$')) { Die 'PHP cannot load pdo_pgsql — check php.ini.' }
$env:Path = "$PhpDir;$pgBin;$env:Path"
[Environment]::SetEnvironmentVariable('Path', (([Environment]::GetEnvironmentVariable('Path', 'Machine') -split ';' | Where-Object { $_ -and $_ -notin @($PhpDir, $pgBin) }) + $PhpDir + $pgBin) -join ';', 'Machine')

# ---------------------------------------------------------------------------------------------------------------
Say '3/9 Composer (why: downloads the libraries the server builds on, at tested versions)'
$composer = "$PhpDir\composer.phar"
if (-not (Test-Path $composer)) { Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $composer }

# ---------------------------------------------------------------------------------------------------------------
Say "4/9 The software in $Dir\server"
$src = $Release
if ($Release -like '*.zip') {
  $src = "$Dir\release-extract"; if (Test-Path $src) { Remove-Item $src -Recurse -Force }
  Expand-Archive $Release -DestinationPath $src -Force
  $artisan = Get-ChildItem $src -Recurse -Filter artisan -Depth 3 | Where-Object { $_.Directory.Name -eq 'server' } | Select-Object -First 1
  if (-not $artisan) { Die 'No server\artisan in the release ZIP.' }
  $src = $artisan.Directory.Parent.FullName
}
if (-not (Test-Path "$src\server\artisan")) { Die "No server\artisan under $src." }
# Code is replaced; .env and storage (uploads, backups, logs) are kept on updates.
robocopy "$src\server" "$Dir\server" /MIR /XD "$src\server\storage" "$src\server\vendor" /XF .env /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Die 'Copying the software failed.' }
foreach ($d in 'storage\app\private', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
  New-Item -ItemType Directory -Force "$Dir\server\$d" | Out-Null
}
Set-Location "$Dir\server"

# ---------------------------------------------------------------------------------------------------------------
Say '5/9 Database user and settings (.env)'
$newInstall = -not (Test-Path .env)
if ($newInstall) {
  $dbPw = -join ((48..57) + (65..90) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ })
  $super = ((Get-Content $secrets | Where-Object { $_ -like 'postgres superuser password:*' } | Select-Object -Last 1) -split ': ', 2)[1]
  $env:PGPASSWORD = $super
  & "$pgBin\psql.exe" -h 127.0.0.1 -p $PgPort -U postgres -qc "DO `$`$BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='erp') THEN CREATE ROLE erp LOGIN PASSWORD '$dbPw' CREATEDB; ELSE ALTER ROLE erp PASSWORD '$dbPw' CREATEDB; END IF; END`$`$;"
  if (-not (& "$pgBin\psql.exe" -h 127.0.0.1 -p $PgPort -U postgres -tAc "SELECT 1 FROM pg_database WHERE datname='erp'")) {
    & "$pgBin\createdb.exe" -h 127.0.0.1 -p $PgPort -U postgres -O erp erp
  }
  Remove-Item Env:PGPASSWORD
  Add-Content $secrets "erp database password: $dbPw"
  $address = if ($Setup -eq 'A' -or $Ci) { 'localhost' } else { (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.PrefixOrigin -in 'Dhcp', 'Manual' -and $_.IPAddress -notlike '169.*' } | Select-Object -First 1).IPAddress }
  $envText = Get-Content .env.example -Raw
  $set = @{ APP_ENV = 'production'; APP_DEBUG = 'false'; APP_URL = "http://${address}:$Port"; DB_HOST = '127.0.0.1'; DB_PORT = "$PgPort"; DB_PASSWORD = $dbPw;
    ERP_NODE_ROLE = $Role; ERP_NODE_ID = $NodeId; BACKUP_PG_BIN = $pgBin }
  foreach ($k in $set.Keys) { $envText = $envText -replace "(?m)^$k=.*$", "$k=$($set[$k])" }
  Set-Content .env $envText -NoNewline
}

# ---------------------------------------------------------------------------------------------------------------
Say '6/9 Libraries and keys (created once, never changed — they are inside every backup)'
Run $php @($composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress') "$Dir\server"
if (-not (Select-String -Path .env -Pattern '^APP_KEY=base64' -Quiet)) { Run $php @('artisan', 'key:generate', '--force', '--no-interaction') }
if (-not (Select-String -Path .env -Pattern '^ERP_AUDIT_KEY=.+' -Quiet)) { Run $php @('artisan', 'erp:audit-key', '--no-interaction') }
$passphrase = & $php artisan erp:backup:key --no-interaction

# ---------------------------------------------------------------------------------------------------------------
Say '7/9 Database structure and standard data'
Run $php @('artisan', 'migrate', '--force', '--no-interaction')
Run $php @('artisan', 'db:seed', '--force', '--no-interaction')
if (-not (Test-Path 'public\pos\index.html')) { Write-Warning 'The release has no till app (public\pos). Build it as in Chapter 4.7, or ask for a release built with the till.' }
Run $php @('artisan', 'optimize', '--no-interaction')

# ---------------------------------------------------------------------------------------------------------------
Say '8/9 Start automatically with Windows (web server, scheduler, live updates)'
$listen = if ($Setup -eq 'A' -or $Ci) { '127.0.0.1' } else { '0.0.0.0' }
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$trigger = New-ScheduledTaskTrigger -AtStartup
$tasks = @{
  'Synclytics Web' = "artisan serve --host=$listen --port=$Port"
  'Synclytics Scheduler' = 'artisan schedule:work'
  'Synclytics Live' = 'artisan reverb:start --host=0.0.0.0 --port=8080'
}
foreach ($name in $tasks.Keys) {
  Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
  Register-ScheduledTask -TaskName $name -Trigger $trigger -Settings $settings -Principal $principal `
    -Action (New-ScheduledTaskAction -Execute $php -Argument $tasks[$name] -WorkingDirectory "$Dir\server") | Out-Null
  Start-ScheduledTask -TaskName $name
}
if ($Setup -eq 'B' -and -not $Ci) {
  if (-not (Get-NetFirewallRule -DisplayName 'Synclytics server' -ErrorAction SilentlyContinue)) {
    New-NetFirewallRule -DisplayName 'Synclytics server' -Direction Inbound -Protocol TCP -LocalPort $Port, 8080 -Action Allow -Profile Private, Domain | Out-Null
  }
}

# ---------------------------------------------------------------------------------------------------------------
Say '9/9 Check'
$ok = $false
foreach ($i in 1..30) { try { if ((Invoke-WebRequest "http://127.0.0.1:$Port/up" -UseBasicParsing -TimeoutSec 5).StatusCode -eq 200) { $ok = $true; break } } catch { Start-Sleep 2 } }
if (-not $ok) { Die "The server does not answer on http://127.0.0.1:$Port/up — see $Dir\server\storage\logs\laravel.log" }
Write-Host "`nDone. Back office: $((Select-String -Path .env -Pattern '^APP_URL=(.*)$').Matches[0].Groups[1].Value)/admin" -ForegroundColor Green
Write-Host "Passwords made for this installation are in $secrets (administrators only)."
if (-not ($passphrase -match 'already set')) { Write-Host ($passphrase -join "`n") -ForegroundColor Yellow }
if ($newInstall -and $Role -eq 'hq' -and -not $Ci -and -not $SkipCompany) {
  Say 'Last step: create your company and the owner account'
  & $php artisan erp:install
}
