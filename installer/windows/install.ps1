<#
.SYNOPSIS
  Installs the business server (SES Labs) on a Windows PC (setup A: one PC; setup B: shop server; branch servers),
  doing what Chapter 4 of the installation guide describes by hand. Run as administrator. Safe to run again (update).

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File install.ps1 -Release C:\Downloads\ses-labs-shop-B1234.zip -Setup B
#>
param(
  [Parameter(Mandatory = $true)][string]$Release,              # the release ZIP, or a folder holding server\
  [ValidateSet('A', 'B')][string]$Setup = 'B',                 # A: only this PC uses it; B: tills on the network use it
  [ValidateSet('hq', 'branch')][string]$Role = 'hq',
  [string]$NodeId = 'HQ',
  [string]$Brand = 'SES Labs',                                 # the product name, as people see it (task and firewall names)
  [string]$Slug = 'SESLabs',                                   # the same without spaces (folder and Windows service)
  [string]$Dir = "C:\$Slug",
  [string]$Domain = '',                                        # internet name (e.g. shop.example.com): a free Let's Encrypt certificate
  [string]$Email = '',                                         # for Let's Encrypt expiry notices (with -Domain)
  [int]$Workers = 4,                                           # PHP worker processes (each serves one request at a time)
  [string]$CaddyVersion = '2.10.0',                            # Caddy web server build from GitHub (checksum-verified)
  [string]$PhpDir = 'C:\php',
  [string]$PgVersion = '17.6-1',                               # PostgreSQL build from get.enterprisedb.com (binaries ZIP)
  [string]$PgDir = "$Dir\pgsql",
  [string]$PgData = "$Dir\pgdata",
  [int]$PgPort = 5432,
  [switch]$Ci,                                                 # automated test: no company creation, no firewall
  [switch]$SkipCompany,
  [switch]$SkipBackup                                          # updates only: do not take the backup first (not recommended)
)
$ErrorActionPreference = 'Stop'
[Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12
$ProgressPreference = 'SilentlyContinue'
function Say($m) { Write-Host "`n==> $m" -ForegroundColor Cyan }
function Die($m) { Write-Host "`nStopped: $m" -ForegroundColor Red; exit 1 }
function Set-EnvValue($file, $key, $value) {  # replace KEY=... in .env, or add it
  $t = Get-Content $file -Raw
  if ($t -match "(?m)^$key=") { $t = $t -replace "(?m)^$key=.*$", "$key=$value" } else { $t = $t.TrimEnd() + "`n$key=$value`n" }
  Set-Content $file $t -NoNewline
}
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
Say '1/10 PostgreSQL 17 (why: the database where everything the business records is kept)'
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
  Run "$pgBin\pg_ctl.exe" @('register', '-N', "$($Slug)DB", '-D', $PgData, '-S', 'auto', '-o', "`"-p $PgPort`"")
}
Start-Service "$($Slug)DB" -ErrorAction SilentlyContinue
icacls $secrets /inheritance:r /grant:r 'Administrators:F' 'SYSTEM:F' | Out-Null

# ---------------------------------------------------------------------------------------------------------------
Say '2/10 PHP 8.4 (why: the language the server is written in)'
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
# Speed: PHP keeps the compiled application in memory (OPcache) instead of recompiling it on every request, and
# remembers file locations. Checked for changed files once a minute, so an update shows within a minute.
$iniText = $iniText -replace '(?m)^;zend_extension=opcache\s*$', 'zend_extension=opcache'
if ($iniText -notmatch '(?m)^; SyncLytics speed settings') {
  $iniText += "`r`n; SyncLytics speed settings`r`nopcache.enable=1`r`nopcache.memory_consumption=256`r`nopcache.interned_strings_buffer=16`r`nopcache.max_accelerated_files=20000`r`nopcache.validate_timestamps=1`r`nopcache.revalidate_freq=60`r`nrealpath_cache_size=4096K`r`nrealpath_cache_ttl=600`r`n"
}
Set-Content $ini $iniText
$php = "$PhpDir\php.exe"
if (-not (& $php -m | Select-String -Pattern '^pdo_pgsql$')) { Die 'PHP cannot load pdo_pgsql — check php.ini.' }
$env:Path = "$PhpDir;$pgBin;$env:Path"
[Environment]::SetEnvironmentVariable('Path', (([Environment]::GetEnvironmentVariable('Path', 'Machine') -split ';' | Where-Object { $_ -and $_ -notin @($PhpDir, $pgBin) }) + $PhpDir + $pgBin) -join ';', 'Machine')

# ---------------------------------------------------------------------------------------------------------------
Say '3/10 Composer (why: downloads the libraries the server builds on, at tested versions)'
$composer = "$PhpDir\composer.phar"
if (-not (Test-Path $composer)) { Invoke-WebRequest 'https://getcomposer.org/download/latest-stable/composer.phar' -OutFile $composer }

# ---------------------------------------------------------------------------------------------------------------
Say '4/10 Caddy web server (why: serves the system over HTTPS, so passwords and sales cannot be read on the network)'
# Stop what an earlier installation started (its files are in use while it runs; PHP's own test server is retired).
Get-ScheduledTask -TaskName "$Brand *" -ErrorAction SilentlyContinue | ForEach-Object { Stop-ScheduledTask -InputObject $_ -ErrorAction SilentlyContinue }
Get-Process caddy, php, php-cgi -ErrorAction SilentlyContinue | Where-Object { $_.Path -like "$Dir\*" -or $_.Path -like "$PhpDir\*" } | Stop-Process -Force
$caddy = "$Dir\caddy\caddy.exe"
if (-not (Test-Path $caddy) -or -not ((& $caddy version) -match "^v$([regex]::Escape($CaddyVersion))\b")) {
  $name = "caddy_$($CaddyVersion)_windows_amd64.zip"
  $base = "https://github.com/caddyserver/caddy/releases/download/v$CaddyVersion"
  $zip = "$dl\$name"; $sums = "$dl\caddy_$($CaddyVersion)_checksums.txt"
  if (-not (Test-Path $zip)) { Invoke-WebRequest "$base/$name" -OutFile $zip }
  if (-not (Test-Path $sums)) { Invoke-WebRequest "$base/caddy_$($CaddyVersion)_checksums.txt" -OutFile $sums }
  $want = ((Get-Content $sums | Where-Object { $_ -match "\s$([regex]::Escape($name))$" }) -split '\s+')[0]
  if (-not $want -or (Get-FileHash $zip -Algorithm SHA512).Hash.ToLower() -ne $want.ToLower()) { Remove-Item $zip, $sums -Force; Die 'The Caddy download is damaged (checksum differs). Run again.' }
  Expand-Archive $zip -DestinationPath "$Dir\caddy" -Force
}
# The web server's data holds this server's certificate authority key: administrators and the system only.
New-Item -ItemType Directory -Force "$Dir\caddy\data" | Out-Null
icacls "$Dir\caddy\data" /inheritance:r /grant:r 'Administrators:(OI)(CI)F' 'SYSTEM:(OI)(CI)F' | Out-Null
# Where the system is reached: an internet name (Let's Encrypt), this PC only, or the shop network (IP address and
# computer name). On the shop network Caddy is its own certificate authority; devices trust it once (Chapter 4.9).
$lanIp = (Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.PrefixOrigin -in 'Dhcp', 'Manual' -and $_.IPAddress -notlike '169.*' } | Select-Object -First 1).IPAddress
$hostName = $env:COMPUTERNAME.ToLower()
if ($Domain) { $sites = @($Domain.ToLower()) }
elseif ($Setup -eq 'A' -or $Ci) { $sites = @('localhost') }
else { $sites = @(@($lanIp, $hostName) | Where-Object { $_ }) }
$siteUrl = "https://$($sites[0])"
$pub = "$Dir\server\public" -replace '\\', '/'; $data = "$Dir\caddy\data" -replace '\\', '/'
$global = @('admin off', 'skip_install_trust', "storage file_system `"$data`"",
  # The web server's own log (it runs without a window): kept small, read by support and by the check below.
  "log {`n`t`toutput file `"$(($Dir -replace '\\', '/'))/caddy/caddy.log`" {`n`t`t`troll_size 10MiB`n`t`t`troll_keep 5`n`t`t}`n`t}")
if ($Setup -eq 'A' -or $Ci) { $global += 'default_bind 127.0.0.1' }
# Ports 80 and 443 must be free for the web server (other web servers, IIS or Windows' own http.sys services use them).
function PortOwners($port) {
  Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | ForEach-Object {
    $n = (Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).Name
    if ($n -ne 'caddy') { if ($_.OwningProcess -eq 4) { 'a Windows service (http.sys, e.g. IIS)' } else { "$n (process $($_.OwningProcess))" } }
  } | Select-Object -Unique
}
$busy443 = PortOwners 443
if ($busy443) { Die "Port 443 (https) is already used by: $($busy443 -join ', '). Stop that program or service, then run this again." }
# Port 80 is only needed to send visitors on to https and, on a shop network, to hand out the certificate. This PC
# alone needs neither; if another program holds port 80, the server runs on https only and the certificate file is
# copied to devices instead (Chapter 4.9).
$busy80 = PortOwners 80
$use80 = -not ($Setup -eq 'A' -or $Ci -or $busy80)
if (-not $use80) { $global += 'auto_https disable_redirects' }
if ($busy80 -and $Setup -eq 'B') { Write-Warning "Port 80 is used by $($busy80 -join ', '): the server runs on https only, and devices get its certificate as a file (Chapter 4.9)." }
if ($Domain -and $Email) { $global += "email $Email" }
$tls = if ($Domain) { '' } else { "`ttls internal" }
$fpm = (1..$Workers | ForEach-Object { "127.0.0.1:$(9000 + $_)" }) -join ' '
$caddyfile = @"
# Written by install.ps1: changes are lost on the next update. Installation guide, Chapter 4.
{
`t$($global -join "`n`t")
`tpki {
`t`tca local {
`t`t`tname "$Brand on $hostName"
`t`t`troot_cn "$Brand $hostName Root"
`t`t`tintermediate_cn "$Brand $hostName Intermediate"
`t`t}
`t}
}
"@
if (-not $Domain -and $use80) {
  # Plain http only hands out this server's certificate (so tills and tablets can trust it) and sends everything else to https.
  $caddyfile += @"

$(($sites | ForEach-Object { "http://$_" }) -join ', ') {
`thandle /ca.crt {
`t`troot * "$data/pki/authorities/local"
`t`trewrite * /root.crt
`t`theader Content-Type application/x-x509-ca-cert
`t`tfile_server
`t}
`thandle {
`t`tredir https://{host}{uri} permanent
`t}
}
"@
}
$caddyfile += @"

$($sites -join ', ') {
$tls
`troot * "$pub"
`tencode zstd gzip
`trequest_body {
`t`tmax_size 25MB
`t}
`theader -Server
`t# Files whose names or links change with each release (the till's code, the screens' styles and scripts) are kept by
`t# the browser for a year instead of being asked for again on every page.
`t@versioned path /pos/assets/* /build/* /css/* /js/* /fonts/* /vendor/*
`theader @versioned Cache-Control `"public, max-age=31536000, immutable`"
`t# One handle runs per request (Caddy puts handle before respond/reverse_proxy, so each case is its own handle).
`t# Hidden files (.env and the like) never leave the server.
`t@hidden {
`t`tpath_regexp /\.
`t`tnot path /.well-known/*
`t}
`thandle @hidden {
`t`trespond 404
`t}
`t# Live updates (Reverb) on the same https address, so no other port is open.
`t@live path /app/* /apps/*
`thandle @live {
`t`treverse_proxy 127.0.0.1:8080
`t}
`t# The till app (single-page, works offline): unknown paths fall back to its index.
`thandle /pos/* {
`t`theader /pos/sw.js Cache-Control no-cache
`t`ttry_files {path} /pos/index.html
`t`tfile_server
`t}
`thandle {
`t`tphp_fastcgi $fpm {
`t`t`tlb_policy least_conn
`t`t`tlb_try_duration 10s
`t`t`tread_timeout 300s
`t`t}
`t`tfile_server
`t}
}
"@
Set-Content "$Dir\Caddyfile" $caddyfile
# Caddy logs to stderr; Windows PowerShell 5.1 would treat that as an error, so its output is collected as text.
$prev = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
$validation = & $caddy validate --config "$Dir\Caddyfile" --adapter caddyfile 2>&1 | Out-String
$valid = $LASTEXITCODE -eq 0
$ErrorActionPreference = $prev
if (-not $valid) { Write-Host $validation; Die 'The web server settings are not valid (see above).' }
# PHP workers: php-cgi serves one request at a time and must not stop after 500 requests; the loop restarts it if it ever exits.
Set-Content "$Dir\php-worker.cmd" @"
@echo off
rem Written by install.ps1: one PHP worker for the web server, on the port given. Restarts PHP if it stops.
set PHP_FCGI_MAX_REQUESTS=0
:run
"$PhpDir\php-cgi.exe" -b 127.0.0.1:%1
ping -n 3 127.0.0.1 >nul
goto run
"@

# ---------------------------------------------------------------------------------------------------------------
Say "5/10 The software in $Dir\server"
$src = $Release
if ($Release -like '*.zip') {
  $src = "$Dir\release-extract"; if (Test-Path $src) { Remove-Item $src -Recurse -Force }
  Expand-Archive $Release -DestinationPath $src -Force
  $artisan = Get-ChildItem $src -Recurse -Filter artisan -Depth 3 | Where-Object { $_.Directory.Name -eq 'server' } | Select-Object -First 1
  if (-not $artisan) { Die 'No server\artisan in the release ZIP.' }
  $src = $artisan.Directory.Parent.FullName
}
if (-not (Test-Path "$src\server\artisan")) { Die "No server\artisan under $src." }
# An update: back up the business first, with the version still installed, so a failed update can be undone.
if ((Test-Path "$Dir\server\.env") -and (Test-Path "$Dir\server\artisan") -and -not $SkipBackup) {
  Say '   Backing up before the update (why: so the update can be undone)'
  Push-Location "$Dir\server"; & $php artisan erp:backup --no-interaction; $ok = $LASTEXITCODE -eq 0; Pop-Location
  if (-not $ok) { Die 'The backup before the update failed, so nothing was changed. Fix it (System -> Backups), or run the update again with -SkipBackup after taking a backup yourself.' }
}
# Code is replaced; .env and storage (uploads, backups, logs) are kept on updates.
robocopy "$src\server" "$Dir\server" /MIR /XD "$src\server\storage" "$src\server\vendor" /XF .env /NFL /NDL /NJH /NJS /NP | Out-Null
if ($LASTEXITCODE -ge 8) { Die 'Copying the software failed.' }
foreach ($d in 'storage\app\private', 'storage\framework\cache', 'storage\framework\sessions', 'storage\framework\views', 'storage\logs', 'bootstrap\cache') {
  New-Item -ItemType Directory -Force "$Dir\server\$d" | Out-Null
}
Set-Location "$Dir\server"

# ---------------------------------------------------------------------------------------------------------------
Say '6/10 Database user and settings (.env)'
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
  $envText = Get-Content .env.example -Raw
  $set = @{ APP_ENV = 'production'; APP_DEBUG = 'false'; DB_HOST = '127.0.0.1'; DB_PORT = "$PgPort"; DB_PASSWORD = $dbPw;
    ERP_NODE_ROLE = $Role; ERP_NODE_ID = $NodeId; BACKUP_PG_BIN = $pgBin; LOG_LEVEL = 'warning'; CACHE_STORE = 'file' }
  foreach ($k in $set.Keys) { $envText = $envText -replace "(?m)^$k=.*$", "$k=$($set[$k])" }
  Set-Content .env $envText -NoNewline
}
# Every run (also updates): the https address, secure cookies, and live updates through the web server.
# Live updates: browsers and tills connect through the web server (https, port 443); the server itself sends to the
# live-updates service on this PC. The app keys are made once, like the other keys.
foreach ($kv in @(@('APP_URL', $siteUrl), @('SESSION_SECURE_COOKIE', 'true'), @('REVERB_HOST', $sites[0]), @('REVERB_PORT', '443'), @('REVERB_SCHEME', 'https'),
    @('REVERB_PUBLISH_HOST', '127.0.0.1'), @('REVERB_PUBLISH_PORT', '8080'), @('REVERB_PUBLISH_SCHEME', 'http'))) {
  Set-EnvValue .env $kv[0] $kv[1]
}
foreach ($k in 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET') {
  if (-not (Select-String -Path .env -Pattern "^$k=[A-Za-z0-9]{16,}" -Quiet)) {
    Set-EnvValue .env $k (-join ((48..57) + (97..122) | Get-Random -Count 24 | ForEach-Object { [char]$_ }))
  }
}

# ---------------------------------------------------------------------------------------------------------------
Say '7/10 Libraries and keys (created once, never changed — they are inside every backup)'
Run $php @($composer, 'install', '--no-dev', '--optimize-autoloader', '--no-interaction', '--no-progress') "$Dir\server"
if (-not (Select-String -Path .env -Pattern '^APP_KEY=base64' -Quiet)) { Run $php @('artisan', 'key:generate', '--force', '--no-interaction') }
if (-not (Select-String -Path .env -Pattern '^ERP_AUDIT_KEY=.+' -Quiet)) { Run $php @('artisan', 'erp:audit-key', '--no-interaction') }
$passphrase = & $php artisan erp:backup:key --no-interaction

# ---------------------------------------------------------------------------------------------------------------
Say '8/10 Database structure and standard data'
Run $php @('artisan', 'migrate', '--force', '--no-interaction')
Run $php @('artisan', 'db:seed', '--force', '--no-interaction')
if (-not (Test-Path 'public\pos\index.html')) { Write-Warning 'The release has no till app (public\pos). Build it as in Chapter 4.7, or ask for a release built with the till.' }
Run $php @('artisan', 'optimize', '--no-interaction')
Run $php @('artisan', 'filament:optimize', '--no-interaction') # caches the screens' building blocks and icons

# ---------------------------------------------------------------------------------------------------------------
Say '9/10 Start automatically with Windows (web server, PHP workers, scheduler, live updates)'
$settings = New-ScheduledTaskSettingsSet -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries
$principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
$trigger = New-ScheduledTaskTrigger -AtStartup
# name => program, arguments, folder. Live updates listen on this PC only; the web server passes them on.
$tasks = [ordered]@{
  "$Brand Scheduler" = @($php, 'artisan schedule:work', "$Dir\server")
  "$Brand Live" = @($php, 'artisan reverb:start --host=127.0.0.1 --port=8080', "$Dir\server")
}
foreach ($i in 1..$Workers) { $tasks["$Brand PHP $i"] = @("$env:SystemRoot\System32\cmd.exe", "/c `"`"$Dir\php-worker.cmd`" $(9000 + $i)`"", "$Dir\server") }
$tasks["$Brand Web"] = @($caddy, "run --config `"$Dir\Caddyfile`" --adapter caddyfile", "$Dir\caddy")
Get-ScheduledTask -TaskName "$Brand *" -ErrorAction SilentlyContinue | Where-Object { -not $tasks.Contains($_.TaskName) } | Unregister-ScheduledTask -Confirm:$false
foreach ($name in $tasks.Keys) {
  Unregister-ScheduledTask -TaskName $name -Confirm:$false -ErrorAction SilentlyContinue
  Register-ScheduledTask -TaskName $name -Trigger $trigger -Settings $settings -Principal $principal `
    -Action (New-ScheduledTaskAction -Execute $tasks[$name][0] -Argument $tasks[$name][1] -WorkingDirectory $tasks[$name][2]) | Out-Null
  Start-ScheduledTask -TaskName $name
}
# Trust this server's own certificate authority on this PC (shop network / this PC only), and keep a copy to give to devices.
if (-not $Domain) {
  $root = "$Dir\caddy\data\pki\authorities\local\root.crt"
  foreach ($i in 1..30) { if (Test-Path $root) { break }; Start-Sleep 2 }
  if (-not (Test-Path $root)) { Die "The web server did not start: run `"$caddy`" run --config `"$Dir\Caddyfile`" in a window to see why." }
  Import-Certificate -FilePath $root -CertStoreLocation Cert:\LocalMachine\Root | Out-Null
  Copy-Item $root "$Dir\$Slug-certificate.crt" -Force
}
# Firewall: only the web server's ports (http hands out the certificate and redirects; https is everything else).
Remove-NetFirewallRule -DisplayName "$Brand server" -ErrorAction SilentlyContinue
if (($Setup -eq 'B' -or $Domain) -and -not $Ci) {
  $profiles = if ($Domain) { 'Any' } else { @('Private', 'Domain') }
  $ports = if ($use80) { @(80, 443) } else { @(443) }
  New-NetFirewallRule -DisplayName "$Brand server" -Direction Inbound -Protocol TCP -LocalPort $ports -Action Allow -Profile $profiles | Out-Null
}

# ---------------------------------------------------------------------------------------------------------------
Say '10/10 Check'
$ok = $false
# A public name may not point here yet, so that case is checked on this PC by name, without the certificate check.
$check = if ($Domain) { "https://127.0.0.1/up" } else { "$siteUrl/up" }
if ($Domain) { [Net.ServicePointManager]::ServerCertificateValidationCallback = { $true } }
foreach ($i in 1..30) {
  try {
    $req = [Net.HttpWebRequest]::Create($check); $req.Timeout = 5000; if ($Domain) { $req.Host = $sites[0] }
    $res = $req.GetResponse(); $code = [int]$res.StatusCode; $res.Close()
    if ($code -eq 200) { $ok = $true; break }
  } catch { Start-Sleep 2 }
}
[Net.ServicePointManager]::ServerCertificateValidationCallback = $null
if (-not $ok) {
  # What a technician needs to see first: which tasks run, what listens, and the last lines of both logs.
  Write-Host "`nTasks:"; Get-ScheduledTask -TaskName "$Brand *" | ForEach-Object { $i = $_ | Get-ScheduledTaskInfo; Write-Host "  $($_.TaskName): $($_.State), last result $($i.LastTaskResult)" }
  Write-Host 'Listening:'; Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object { $_.LocalPort -in (@(80, 443, 8080) + (1..$Workers | ForEach-Object { 9000 + $_ })) } |
    ForEach-Object { Write-Host "  $($_.LocalAddress):$($_.LocalPort) (process $($_.OwningProcess) $((Get-Process -Id $_.OwningProcess -ErrorAction SilentlyContinue).Name))" }
  foreach ($log in "$Dir\caddy\caddy.log", "$Dir\server\storage\logs\laravel.log") { if (Test-Path $log) { Write-Host "Last lines of ${log}:"; Get-Content $log -Tail 15 | ForEach-Object { Write-Host "  $_" } } }
  Die "The server does not answer on $check (details above). Logs: $Dir\caddy\caddy.log and $Dir\server\storage\logs\laravel.log."
}
Write-Host "`nDone. Back office: $((Select-String -Path .env -Pattern '^APP_URL=(.*)$').Matches[0].Groups[1].Value)/admin" -ForegroundColor Green
Write-Host "Passwords made for this installation are in $secrets (administrators only)."
if (-not $Domain -and $Setup -eq 'B') {
  $how = if ($use80) { "open http://$($sites[0])/ca.crt once and trust it" } else { "copy $Dir\$Slug-certificate.crt to each device once and trust it" }
  Write-Host "Tills, tablets and other PCs: $how (installation guide, Chapter 4.9)." -ForegroundColor Yellow
}
if (-not ($passphrase -match 'already set')) { Write-Host ($passphrase -join "`n") -ForegroundColor Yellow }
if ($newInstall -and $Role -eq 'hq' -and -not $Ci -and -not $SkipCompany) {
  Say 'Last step: create your company and the owner account'
  & $php artisan erp:install
}
