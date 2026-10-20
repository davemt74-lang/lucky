# Offline Lucky Game Hub HomeServer. Bundled runtimes only; no runtime downloads.
[CmdletBinding()]
param([switch]$Smoke,[switch]$NoBrowser)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$home=Split-Path -Parent $PSScriptRoot
$app=Join-Path $home 'app'
$runtime=Join-Path $home 'runtime'
$data=Join-Path $env:LOCALAPPDATA 'LuckyGameHub'
$storage=Join-Path $data 'storage'
$dbdata=Join-Path $data 'db'
$logs=Join-Path $data 'logs'
$state=Join-Path $data 'secrets'
$php=Join-Path $runtime 'php\php.exe'
$phpcgi=Join-Path $runtime 'php\php-cgi.exe'
$mariadb=Join-Path $runtime 'mariadb\bin\mariadbd.exe'
$caddy=Join-Path $runtime 'caddy.exe'
$startupAddress='http://127.0.0.1:8833/first-admin.php'
if(!(Test-Path $php) -or !(Test-Path $phpcgi) -or !(Test-Path $mariadb) -or !(Test-Path $caddy)){throw 'Bundled HomeServer runtime is incomplete.'}
foreach($path in @($data,$storage,$dbdata,$logs,$state)){New-Item -ItemType Directory -Force -Path $path | Out-Null}
$logFile=Join-Path $logs 'launcher.log'
function Record([string]$message){Add-Content -Path $logFile -Value ("$(Get-Date -Format o) "+$message)}
$mutex=New-Object System.Threading.Mutex($false,'Local\LuckyGameHubHomeServer')
$held=$false
$processes=@()
try{
 $held=$mutex.WaitOne(0,$false)
 if(!$held){
  if(!$NoBrowser -and !$Smoke){Start-Process $startupAddress}
  exit 0
 }
 # Keep mutable assets outside {app} so uninstall and upgrades preserve user data.
 $mount=Join-Path $app 'storage'
 if(Test-Path $mount){
  $entry=Get-Item $mount -Force
  if(-not ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)){
   Get-ChildItem $mount -Force | ForEach-Object {Copy-Item -LiteralPath $_.FullName -Destination $storage -Recurse -Force}
   Remove-Item -LiteralPath $mount -Recurse -Force
  }
 }
 if(!(Test-Path $mount)){New-Item -ItemType Junction -Path $mount -Target $storage | Out-Null}
 if(!((Get-Item $mount -Force).Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Persistent storage must be mounted as a junction.'}
 function GetSecret([string]$name){
  $file=Join-Path $state $name
  if(Test-Path $file){return (Get-Content -LiteralPath $file -Raw).Trim()}
  $bytes=New-Object byte[] 32
  [Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
  $value=[BitConverter]::ToString($bytes).Replace('-','').ToLowerInvariant()
  [IO.File]::WriteAllText($file,$value,[Text.Encoding]::ASCII)
  return $value
 }
 $rootSecret=GetSecret 'db-root.key'
 $appSecret=GetSecret 'db-app.key'
 $dbconfig=Join-Path $data 'my.ini'
 $dbbasedir=(Join-Path $runtime 'mariadb').Replace('\','/')
 $dbfolder=$dbdata.Replace('\','/')
 @"
[mysqld]
basedir=$dbbasedir
datadir=$dbfolder
port=3307
bind-address=127.0.0.1
skip-name-resolve
character-set-server=utf8mb4
default-time-zone=+00:00
log-error=$($logs.Replace('\','/'))/mariadb-error.log
"@ | Set-Content -LiteralPath $dbconfig -Encoding ASCII
 if(!(Test-Path (Join-Path $dbdata 'mysql'))){
  Record 'Preparing private local MariaDB data directory.'
  $initializer=@('mariadb-install-db.exe','mysql_install_db.exe') | ForEach-Object {Join-Path $runtime ('mariadb\bin\'+$_)} | Where-Object {Test-Path $_} | Select-Object -First 1
  if(!$initializer){throw 'MariaDB initialization utility is missing.'}
  & $initializer "--datadir=$dbdata" "--password=$rootSecret" "--port=3307"
  if($LASTEXITCODE -ne 0){throw "MariaDB initialization failed: $LASTEXITCODE"}
 }
 function Launch([string]$binary,[string[]]$arguments,[string]$name){
  Record ("Starting "+$name)
  $p=Start-Process -FilePath $binary -ArgumentList $arguments -PassThru -WindowStyle Hidden
  $script:processes+=@($p)
  return $p
 }
 $dbProc=Launch $mariadb @("--defaults-file=$dbconfig","--console") 'MariaDB'
 $dbAdmin=Join-Path $runtime 'mariadb\bin\mariadb-admin.exe'
 if(!(Test-Path $dbAdmin)){$dbAdmin=Join-Path $runtime 'mariadb\bin\mysqladmin.exe'}
 $dbReady=$false
 for($i=0;$i -lt 75;$i++){
  Start-Sleep -Milliseconds 500
  if($dbProc.HasExited){throw "MariaDB exited during startup (code $($dbProc.ExitCode))."}
  & $dbAdmin --protocol=tcp --host=127.0.0.1 --port=3307 --user=root "--password=$rootSecret" ping 2>$null | Out-Null
  if($LASTEXITCODE -eq 0){$dbReady=$true;break}
 }
 if(!$dbReady){throw 'Local MariaDB did not become ready.'}
 $phpIni=Join-Path $data 'php.ini'
 $ext=(Join-Path $runtime 'php\ext').Replace('\','/')
 @"
extension_dir="$ext"
extension=pdo_mysql
extension=mysqli
extension=mbstring
extension=fileinfo
extension=gd
extension=openssl
cgi.fix_pathinfo=0
display_errors=Off
log_errors=On
error_log="$($logs.Replace('\','/'))/php-error.log"
upload_max_filesize=512M
post_max_size=520M
max_execution_time=300
memory_limit=512M
date.timezone=UTC
"@ | Set-Content -LiteralPath $phpIni -Encoding ASCII
 $env:PHPRC=$data
 $env:LUCKY_BOOTSTRAP='homeserver'
 $env:LUCKY_DB_PORT='3307'
 $env:LUCKY_DB_ROOT_PASSWORD=$rootSecret
 $env:LUCKY_DB_APP_PASSWORD=$appSecret
 if(!(Test-Path (Join-Path $storage 'config.php'))){
  & $php -c $phpIni (Join-Path $app 'homeserver\initialize.php')
  if($LASTEXITCODE -ne 0){throw "Failed to initialize local game database ($LASTEXITCODE)."}
 }
 $env:LUCKY_DB_ROOT_PASSWORD=''
 $env:LUCKY_DB_APP_PASSWORD=''
 $env:LUCKY_BOOTSTRAP=''
 $phpProc=Launch $phpcgi @('-c',$phpIni,'-b','127.0.0.1:9017') 'PHP FastCGI'
 # Caddy internal CA is generated locally and stored with private user data.
 $env:XDG_DATA_HOME=Join-Path $data 'caddy-data'
 $env:XDG_CONFIG_HOME=Join-Path $data 'caddy-config'
 $ip=Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue |
  Where-Object {$_.IPAddress -notmatch '^(127\.|169\.254\.)' -and $_.AddressState -eq 'Preferred' -and $_.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|Tailscale|VPN'} |
  Select-Object -ExpandProperty IPAddress -First 1
 $webRoot=$app.Replace('\','/')
 $privateRoutes='@private path /storage/* /lib/* /homeserver/* /bin/* /tests/* /docs/* /.github/* /first-admin.php'
 $common="root * `"$webRoot`"`n  @private path /storage/* /lib/* /homeserver/* /bin/* /tests/* /docs/* /.github/*`n  respond @private 403`n  php_fastcgi 127.0.0.1:9017`n  file_server"
 $caddyfile=Join-Path $data 'Caddyfile'
 $spec="{`n  admin off`n  auto_https disable_redirects`n}`nhttp://127.0.0.1:8833 {`n  $common`n}`n"
 if($ip){$spec+="https://$($ip):8843 {`n  tls internal`n  @setup path /first-admin.php`n  respond @setup 403`n  $common`n}`n"}
 Set-Content -LiteralPath $caddyfile -Value $spec -Encoding UTF8
 $caddyProc=Launch $caddy @('run','--config',$caddyfile,'--adapter','caddyfile') 'Caddy HTTPS'
 $ready=$false
 for($i=0;$i -lt 90;$i++){
  Start-Sleep -Milliseconds 500
  if($phpProc.HasExited -or $caddyProc.HasExited){throw 'Local web server exited during startup.'}
  try{
   $response=Invoke-WebRequest -Uri $startupAddress -UseBasicParsing -TimeoutSec 3
   if($response.StatusCode -eq 200 -or $response.StatusCode -eq 302){$ready=$true;break}
  }catch{}
 }
 if(!$ready){throw 'Local game page did not become ready.'}
 Record ("Local HomeServer ready: "+$startupAddress+ $(if($ip){" LAN https://$($ip):8843"}else{''}))
 if($Smoke){Write-Output 'HOMESERVER_SMOKE_OK';exit 0}
 if(!$NoBrowser){Start-Process $startupAddress}
 while($true){
  Start-Sleep -Seconds 3
  if($dbProc.HasExited -or $phpProc.HasExited -or $caddyProc.HasExited){throw 'A HomeServer process stopped; restart from the desktop shortcut.'}
 }
}catch{
 Record ("HomeServer error: "+$_.Exception.Message)
 Write-Error $_
 exit 1
}finally{
 foreach($proc in $processes){try{if(!$proc.HasExited){Stop-Process -Id $proc.Id -Force -ErrorAction SilentlyContinue}}catch{}}
 if($held){$mutex.ReleaseMutex()}
 $mutex.Dispose()
}
