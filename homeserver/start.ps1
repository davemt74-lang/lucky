# Offline Lucky Game Hub HomeServer. Bundled runtimes only; no runtime downloads.
[CmdletBinding()]
param([switch]$Smoke,[switch]$NoBrowser)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$installRoot=Split-Path -Parent $PSScriptRoot
$app=Join-Path $installRoot 'app'
$runtime=Join-Path $installRoot 'runtime'
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
foreach($path in @($data,$storage,$dbdata,$logs,$state)){New-Item -ItemType Directory -Force -Path $path | Out-Null}
$logFile=Join-Path $logs 'launcher.log'
$env:PATH=(Join-Path $runtime 'ffmpeg\bin')+';'+(Join-Path $runtime 'mariadb\bin')+';'+$env:PATH
function Record([string]$message){Add-Content -Path $logFile -Value ("$(Get-Date -Format o) "+$message)}
function Test-HomeServerPrivateIPv4([string]$Address){
 # IPAddress.TryParse alone accepts shorthand, octal and other noncanonical
 # forms. Only a literal, canonical RFC1918 IPv4 address can become a URL/bind.
 if($Address -cnotmatch '\A(?:0|[1-9][0-9]{0,2})(?:\.(?:0|[1-9][0-9]{0,2})){3}\z'){return $false}
 $parts=@($Address.Split('.') | ForEach-Object {[int]$_})
 if(@($parts | Where-Object {$_ -gt 255}).Count -ne 0){return $false}
 return $parts[0] -eq 10 -or ($parts[0] -eq 172 -and $parts[1] -ge 16 -and $parts[1] -le 31) -or
  ($parts[0] -eq 192 -and $parts[1] -eq 168)
}
function Select-HomeServerLanInterface([object[]]$Addresses){
 foreach($entry in $Addresses){
  if((Test-HomeServerPrivateIPv4 ([string]$entry.IPAddress)) -and $entry.AddressState -eq 'Preferred' -and
     [int]$entry.InterfaceIndex -gt 0 -and $entry.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|Tailscale|VPN'){
   return $entry
  }
 }
 return $null
}
function New-HomeServerCaddyConfiguration([string]$WebRoot,[string]$LanAddress){
 if([string]::IsNullOrWhiteSpace($WebRoot) -or $WebRoot -match '[\x00-\x1f"]'){throw 'Unsafe local web root.'}
 if($LanAddress -ne '' -and !(Test-HomeServerPrivateIPv4 $LanAddress)){throw 'Invalid private LAN address.'}
 # Site addresses match Host headers; explicit bind restricts the actual
 # listening sockets. Never expose the cleartext desktop site on the LAN.
 # A supervisor replacement must never replay an uncertain game/upload POST.
 $common="root * `"$WebRoot`"`n  @private path /storage/* /lib/* /homeserver/* /bin/* /tests/* /docs/* /.github/*`n  respond @private 403`n  php_fastcgi 127.0.0.1:9017 {`n    lb_retries 0`n    lb_try_duration 0`n  }`n  file_server"
 $spec="{`n  admin off`n  auto_https disable_redirects`n  skip_install_trust`n}`nhttp://127.0.0.1:8833 {`n  bind 127.0.0.1`n  $common`n}`n"
 if($LanAddress -ne ''){$spec+="https://$($LanAddress):8843 {`n  bind $LanAddress`n  tls internal`n  @setup path /first-admin.php`n  respond @setup 403`n  $common`n}`n"}
 return $spec
}
function Get-HomeServerNetworkEntry([string]$Path){
 try{$entry=Get-Item -LiteralPath $Path -Force -ErrorAction Stop}
 catch [System.Management.Automation.ItemNotFoundException]{return $null}
 if($entry.PSIsContainer -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)){
  throw 'HomeServer network publication is not a regular file; no existing path was replaced.'
 }
 return $entry
}
function Remove-HomeServerNetworkPublication([string]$Storage){
 $path=Join-Path $Storage 'homeserver-network.json'
 if($null -ne (Get-HomeServerNetworkEntry $path)){
  # Only this reserved regular leaf; never recursively delete or follow links.
  [IO.File]::Delete($path)
 }
}
function Write-HomeServerNetworkPublication([string]$Storage,[string]$LanAddress){
 if(!(Test-HomeServerPrivateIPv4 $LanAddress)){throw 'Refusing to advertise a non-private address.'}
 $target=Join-Path $Storage 'homeserver-network.json'
 $temporary=Join-Path $Storage ('homeserver-network-'+[Guid]::NewGuid().ToString('N')+'.tmp')
 $identity=[Security.Principal.WindowsIdentity]::GetCurrent().User
 $system=New-Object Security.Principal.SecurityIdentifier('S-1-5-18')
 $acl=New-Object Security.AccessControl.FileSecurity
 $acl.SetOwner($identity);$acl.SetAccessRuleProtection($true,$false)
 foreach($principal in @($identity,$system)){
  $acl.AddAccessRule((New-Object Security.AccessControl.FileSystemAccessRule($principal,[Security.AccessControl.FileSystemRights]::FullControl,[Security.AccessControl.AccessControlType]::Allow)))
 }
 $json=([ordered]@{version=1;origin=('https://'+$LanAddress+':8843')} | ConvertTo-Json -Compress)
 $encoding=New-Object Text.UTF8Encoding($false)
 $bytes=$encoding.GetBytes($json)
 $stream=$null;$created=$false
 try{
  # CreateNew + a protected descriptor make the stage private from creation,
  # not only after a later chmod/ACL update. This is the .NET Framework API
  # bundled Windows PowerShell 5.1 supports; no PowerShell 7 dependency.
  $stream=[IO.FileStream]::new($temporary,[IO.FileMode]::CreateNew,[Security.AccessControl.FileSystemRights]::FullControl,
   [IO.FileShare]::None,4096,[IO.FileOptions]::WriteThrough,$acl)
  $created=$true;$stream.Write($bytes,0,$bytes.Length);$stream.Flush($true);$stream.Dispose();$stream=$null
  if([IO.File]::ReadAllText($temporary,$encoding) -cne $json){throw 'Network publication verification failed.'}
  $entry=Get-HomeServerNetworkEntry $target
  if($null -eq $entry){[IO.File]::Move($temporary,$target)}
  else{
   # A renewal may replace only a private regular publication owned by this
   # user. Unexpected ACLs/links are not adopted, repaired, or traversed.
   $existing=Get-Acl -LiteralPath $target -ErrorAction Stop
   $rules=@($existing.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
   if(!$existing.AreAccessRulesProtected -or $existing.GetOwner([Security.Principal.SecurityIdentifier]).Value -cne $identity.Value -or
      @($rules | Where-Object {$_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or
       $_.IdentityReference.Value -notin @($identity.Value,$system.Value)}).Count -ne 0){throw 'Network publication ownership changed.'}
   # PowerShell 5.1 binds $null to an empty string here. File.Replace needs a
   # real CLR null backup path to retain atomic replacement without a backup.
   [IO.File]::Replace($temporary,$target,[System.Management.Automation.Language.NullString]::Value)
  }
  $created=$false
 }finally{
  if($null -ne $stream){$stream.Dispose()}
  if($created -and $null -ne (Get-HomeServerNetworkEntry $temporary)){[IO.File]::Delete($temporary)}
 }
}
function Test-HomeServerLanListeners([string]$LanAddress,[int]$InterfaceIndex,[int]$CaddyId,[object[]]$Addresses,[object[]]$Listeners){
 if($CaddyId -lt 1 -or $InterfaceIndex -lt 1 -or !(Test-HomeServerPrivateIPv4 $LanAddress)){return $false}
 $assigned=@($Addresses | Where-Object {$_.IPAddress -ceq $LanAddress -and $_.AddressState -eq 'Preferred' -and
  [int]$_.InterfaceIndex -eq $InterfaceIndex -and $_.InterfaceAlias -notmatch 'Loopback|vEthernet|Virtual|Tailscale|VPN'})
 if($assigned.Count -eq 0){return $false}
 $owned=@($Listeners | Where-Object {[int]$_.OwningProcess -eq $CaddyId -and [int]$_.LocalPort -in @(8833,8843)})
 $desktop=@($owned | Where-Object {[int]$_.LocalPort -eq 8833 -and $_.LocalAddress -ceq '127.0.0.1'})
 $lan=@($owned | Where-Object {[int]$_.LocalPort -eq 8843 -and $_.LocalAddress -ceq $LanAddress})
 return $owned.Count -eq 2 -and $desktop.Count -eq 1 -and $lan.Count -eq 1
}
function Test-HomeServerLanReady([string]$LanAddress,[int]$InterfaceIndex,[Diagnostics.Process]$CaddyProcess){
 if($null -eq $CaddyProcess -or $CaddyProcess.HasExited){return $false}
 $networkClock=[Diagnostics.Stopwatch]::StartNew()
 try{$addresses=@(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop)}
 finally{if($networkClock.ElapsedMilliseconds -ge 1000){try{Record ('LAN slow stage=addresses duration_ms='+$networkClock.ElapsedMilliseconds)}catch{}}}
 $networkClock.Restart()
 try{$listeners=@(Get-NetTCPConnection -State Listen -ErrorAction Stop)}
 finally{if($networkClock.ElapsedMilliseconds -ge 1000){try{Record ('LAN slow stage=listeners duration_ms='+$networkClock.ElapsedMilliseconds)}catch{}}}
 return !( $CaddyProcess.HasExited ) -and (Test-HomeServerLanListeners $LanAddress $InterfaceIndex $CaddyProcess.Id $addresses $listeners)
}
$mutex=New-Object System.Threading.Mutex($false,'Local\LuckyGameHubHomeServer')
$held=$false
$processes=@()
$dbProc=$null
$phpProc=$null
$caddyProc=$null
$renderProc=$null
$cgiGeneration=0
$cgiClock=[Diagnostics.Stopwatch]::StartNew()
$cgiRestarts=New-Object 'System.Collections.Generic.Queue[double]'
$networkAdvertised=$false
try{
 try{$held=$mutex.WaitOne(0,$false)}
 catch [Threading.AbandonedMutexException]{$held=$true;Record 'Previous launcher did not release its mutex; checking local process ownership before startup.'}
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
 # The previous launch may have been killed without running finally. Withdraw
 # its reserved publication before initialization or any new readiness claim.
 try{Remove-HomeServerNetworkPublication $storage}
 catch{try{Record 'LAN publication could not be cleared; phones remain subject to its short freshness lease.'}catch{}}
 if(!(Test-Path $php) -or !(Test-Path $phpcgi) -or !(Test-Path $mariadb) -or !(Test-Path $caddy)){throw 'Bundled HomeServer runtime is incomplete.'}
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
 function Launch([string]$binary,[string[]]$arguments,[string]$name,[switch]$AllowStartFailure){
  Record ("Starting "+$name)
  $quoted=@($arguments|ForEach-Object {if($_ -match '[\s"]'){'"'+($_ -replace '"','\"')+'"'}else{$_}})
  $slug=($name -replace '[^A-Za-z0-9-]','-').ToLowerInvariant()
  try{$p=Start-Process -FilePath $binary -ArgumentList ($quoted -join ' ') -PassThru -WindowStyle Hidden -RedirectStandardError (Join-Path $logs "$slug.stderr.log") -RedirectStandardOutput (Join-Path $logs "$slug.stdout.log")}
  catch{if($AllowStartFailure){return $null};throw}
  $script:processes+=@($p)
  # Keep the native handle attached to this exact child, including after exit;
  # cleanup must not resolve a subsequently reused numeric PID.
  $null=$p.Handle
  Record ("Process started name="+$name+" pid="+$p.Id)
  return $p
 }
 function PruneExitedProcesses{
  $live=@()
  foreach($owned in $script:processes){
   # Foundation failure is handled in finally. The current CGI handle remains
   # usable until RetireExitedCgi observes its exit and clears the reference.
   $retained=[object]::ReferenceEquals($owned,$script:dbProc) -or [object]::ReferenceEquals($owned,$script:caddyProc) -or [object]::ReferenceEquals($owned,$script:phpProc)
   if($owned.HasExited -and !$retained){
    if([object]::ReferenceEquals($owned,$script:renderProc)){$script:renderProc=$null}
    $owned.Dispose()
   }else{$live+=@($owned)}
  }
  $script:processes=$live
 }
 function CgiListeners{
  # Query failures are not proof that the port is free. Never adopt or stop a
  # listener found by port number: only Launch-created Process objects are ours.
  return @(Get-NetTCPConnection -State Listen -ErrorAction Stop | Where-Object {$_.LocalPort -eq 9017})
 }
 function AssertCgiPortFree{
  $listeners=@(CgiListeners)
  if($listeners.Count -gt 0){
   $owners=(@($listeners | Select-Object -ExpandProperty OwningProcess -Unique) -join ',')
   Record ("CGI port conflict port=9017 owner_pid="+$owners)
   throw 'PHP FastCGI port 9017 is already owned by another process. Close that application before starting Lucky.'
  }
 }
 function StartOwnedCgi([string]$reason){
  if($null -ne $script:phpProc){throw 'The previous CGI process must exit before a replacement can start.'}
  AssertCgiPortFree
  $script:cgiGeneration++
  # Start-Process has no -Environment switch in Windows PowerShell 5.1. Save
  # and restore only this launcher's process environment around the child spawn.
  $previousMax=[Environment]::GetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','Process')
  $previousChildren=[Environment]::GetEnvironmentVariable('PHP_FCGI_CHILDREN','Process')
  try{
   [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','0','Process')
   [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN','0','Process')
   $script:phpProc=Launch $phpcgi @('-c',$phpIni,'-b','127.0.0.1:9017') 'PHP FastCGI' -AllowStartFailure
   if($null -eq $script:phpProc){
    Record ("CGI launch failed generation="+$script:cgiGeneration+" reason="+$reason)
    return $false
   }
  }finally{
   [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS',$previousMax,'Process')
   [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN',$previousChildren,'Process')
  }
  Record ("CGI starting pid="+$script:phpProc.Id+" generation="+$script:cgiGeneration+" reason="+$reason)
  $deadline=$script:cgiClock.Elapsed.TotalSeconds+10
  do{
   if($script:phpProc.HasExited){return $false}
   $listeners=@(CgiListeners)
   $foreign=@($listeners | Where-Object {$_.OwningProcess -ne $script:phpProc.Id -or $_.LocalAddress -ne '127.0.0.1'})
   if($foreign.Count -gt 0){
    Record ("CGI listener ownership mismatch expected_pid="+$script:phpProc.Id+" generation="+$script:cgiGeneration)
    throw 'PHP FastCGI listener ownership could not be verified. No other listener will be adopted or stopped.'
   }
   if($listeners.Count -gt 0 -and !$script:phpProc.HasExited){
    Record ("CGI ready pid="+$script:phpProc.Id+" generation="+$script:cgiGeneration+" reason="+$reason)
    return $true
   }
   Start-Sleep -Milliseconds 100
  }while($script:cgiClock.Elapsed.TotalSeconds -lt $deadline)
  if($script:phpProc.HasExited){return $false}
  # A living, unbound child is a startup failure, not permission to launch a
  # second worker. HTTP responsiveness is never a recovery/kill criterion.
  throw 'PHP FastCGI stayed alive but did not bind its owned listener during startup.'
 }
 function RetireExitedCgi{
  if($null -ne $script:phpProc){
   if(!$script:phpProc.HasExited){throw 'Refusing to replace a living PHP FastCGI worker.'}
   Record ("CGI exited pid="+$script:phpProc.Id+" generation="+$script:cgiGeneration+" code="+$script:phpProc.ExitCode)
   $script:phpProc=$null
  }
  PruneExitedProcesses
 }
 function RecoverOwnedCgi{
  if($null -ne $script:phpProc -and !$script:phpProc.HasExited){return}
  RetireExitedCgi
  while($true){
   $now=$script:cgiClock.Elapsed.TotalSeconds
   while($script:cgiRestarts.Count -gt 0 -and $script:cgiRestarts.Peek() -le ($now-60)){$null=$script:cgiRestarts.Dequeue()}
   if($script:cgiRestarts.Count -ge 3){
    Record 'CGI recovery exhausted attempts=3 window_seconds=60'
    throw 'PHP FastCGI repeatedly stopped. Recovery limit reached; check the local logs and restart from the desktop shortcut.'
   }
   # Failed Start-Process calls consume the same budget as short-lived workers.
   $script:cgiRestarts.Enqueue($now)
   $attempt=$script:cgiRestarts.Count
   $delay=@(1000,2000,4000)[$attempt-1]
   Record ("CGI restart attempt="+$attempt+" backoff_ms="+$delay)
   Start-Sleep -Milliseconds $delay
   if($dbProc.HasExited -or ($null -ne $caddyProc -and $caddyProc.HasExited)){
    throw 'A HomeServer foundation process stopped during CGI recovery; restart from the desktop shortcut.'
   }
   if(StartOwnedCgi 'replacement'){return}
   RetireExitedCgi
  }
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
extension=zip
extension=curl
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
 # Keep fresh and upgraded offline installs on the same schema as Cloud.
 & $php -c $phpIni (Join-Path $app 'homeserver\migrate.php')
 if($LASTEXITCODE -ne 0){throw "Local HomeServer database migration failed ($LASTEXITCODE)."}
 $env:LUCKY_DB_ROOT_PASSWORD=''
 $env:LUCKY_DB_APP_PASSWORD=''
 $env:LUCKY_BOOTSTRAP=''
 if(!(StartOwnedCgi 'initial')){throw 'PHP FastCGI exited or failed to launch during startup.'}
 # Caddy internal CA is generated locally and stored with private user data.
 $env:XDG_DATA_HOME=Join-Path $data 'caddy-data'
 $env:XDG_CONFIG_HOME=Join-Path $data 'caddy-config'
 $selectedLan=Select-HomeServerLanInterface @(Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue)
 $ip='';$lanInterfaceIndex=0
 if($null -ne $selectedLan){$ip=[string]$selectedLan.IPAddress;$lanInterfaceIndex=[int]$selectedLan.InterfaceIndex}
 $webRoot=$app.Replace('\','/')
 $caddyfile=Join-Path $data 'Caddyfile'
 $spec=New-HomeServerCaddyConfiguration $webRoot $ip
 [IO.File]::WriteAllText($caddyfile,$spec,(New-Object System.Text.UTF8Encoding($false)))
 # Redirect native stderr to a file: a successful Caddy validation writes logs
 # there, which PowerShell 5.1 must not misinterpret as a terminating error.
 $validation=Launch $caddy @('validate','--config',$caddyfile,'--adapter','caddyfile') 'Caddy Validation'
 if(!$validation.WaitForExit(15000)){throw 'Local web configuration validation timed out.'}
 if($validation.ExitCode -ne 0){throw 'Generated local web configuration failed Caddy validation. Check caddy-validation.stderr.log.'}
 Record 'Caddy configuration validated.'
 PruneExitedProcesses
 $caddyProc=Launch $caddy @('run','--config',$caddyfile,'--adapter','caddyfile') 'Caddy HTTPS'
 # Preserve the initial application-page startup check. It is not a recurring
 # health check: after initial readiness, a busy living CGI is never restarted.
 $ready=$false
 for($i=0;$i -lt 35;$i++){
  Start-Sleep -Milliseconds 500
  if($dbProc.HasExited -or $caddyProc.HasExited){throw 'Local web server exited during startup.'}
  if($phpProc.HasExited){RecoverOwnedCgi}
  try{
   $response=Invoke-WebRequest -Uri $startupAddress -UseBasicParsing -TimeoutSec 3
   if($response.StatusCode -eq 200 -or $response.StatusCode -eq 302){$ready=$true;break}
  }catch{if($i -eq 0 -or $i % 8 -eq 7){Record ("HTTP startup probe "+$i+": "+$_.Exception.Message)}}
 }
 if(!$ready){throw 'Local game page did not become ready.'}
 function RefreshNetworkAdvertisement{
  $refreshClock=[Diagnostics.Stopwatch]::StartNew();$publicationStage='readiness'
  try{
   if($ip -and (Test-HomeServerLanReady $ip $lanInterfaceIndex $caddyProc)){
    $publicationStage='publish';$publicationClock=[Diagnostics.Stopwatch]::StartNew()
    try{Write-HomeServerNetworkPublication $storage $ip}
    finally{if($publicationClock.ElapsedMilliseconds -ge 1000){try{Record ('LAN slow stage=publish duration_ms='+$publicationClock.ElapsedMilliseconds)}catch{}}}
    if(!$script:networkAdvertised){Record ('LAN origin advertised: https://'+$ip+':8843')}
    $script:networkAdvertised=$true
   }else{
    $publicationStage='withdraw'
    Remove-HomeServerNetworkPublication $storage
    if($script:networkAdvertised){Record 'LAN origin withdrawn: selected interface or owned listener is unavailable.'}
    $script:networkAdvertised=$false
   }
  }catch{
   $publicationError=$_.Exception
   $errorIdentity=' type='+$publicationError.GetType().FullName+' hresult='+$publicationError.HResult
   if($null -ne $publicationError.InnerException){$errorIdentity+=' inner_type='+$publicationError.InnerException.GetType().FullName+' inner_hresult='+$publicationError.InnerException.HResult}
   try{Remove-HomeServerNetworkPublication $storage}catch{}
   $script:networkAdvertised=$false
   try{Record ('LAN publication unavailable stage='+$publicationStage+$errorIdentity+'; local gameplay remains available. Check network/storage permissions.')}catch{}
  }finally{
   if($refreshClock.ElapsedMilliseconds -ge 1000){try{Record ('LAN slow stage=refresh duration_ms='+$refreshClock.ElapsedMilliseconds)}catch{}}
  }
 }
 RefreshNetworkAdvertisement
 $nextNetworkLease=[DateTime]::UtcNow.AddSeconds(10)
 Record ("Local HomeServer ready: "+$startupAddress+ $(if($ip){" LAN https://$($ip):8843"}else{''}))
 if($Smoke){Write-Output 'HOMESERVER_SMOKE_OK';exit 0}
 if(!$NoBrowser){Start-Process $startupAddress}
 # Process one queued video job per pass without blocking the local web server.
 # The CLI worker is self-locking and also handles multicamera exports.
 $renderWorker=Join-Path $app 'bin\render-worker.php'
 $renderProc=$null
 $nextRender=[DateTime]::UtcNow.AddSeconds(5)
 while($true){
  Start-Sleep -Milliseconds 500
  if($dbProc.HasExited -or $caddyProc.HasExited){throw 'A HomeServer foundation process stopped; restart from the desktop shortcut.'}
  if($phpProc.HasExited){RecoverOwnedCgi}
  # No remote probe and no restart based on HTTP latency. Renew only while the
  # selected interface and exact owned listeners exist. Readers reject a file
  # older than 30 seconds if a hard-killed launcher cannot run cleanup.
  if([DateTime]::UtcNow -ge $nextNetworkLease){
   $leaseLateMs=[Math]::Round(([DateTime]::UtcNow-$nextNetworkLease).TotalMilliseconds)
   if($leaseLateMs -ge 1000){try{Record ('LAN slow stage=schedule late_ms='+$leaseLateMs)}catch{}}
   RefreshNetworkAdvertisement
   $nextNetworkLease=[DateTime]::UtcNow.AddSeconds(10)
  }
  if([DateTime]::UtcNow -ge $nextRender -and ($null -eq $renderProc -or $renderProc.HasExited)){
   $renderProc=$null
   PruneExitedProcesses
   $renderProc=Launch $php @('-c',$phpIni,$renderWorker) 'Render Worker'
   $nextRender=[DateTime]::UtcNow.AddSeconds(15)
  }
 }
}catch{
 Record ("HomeServer error: "+$_.Exception.Message)
 Write-Error -ErrorRecord $_ -ErrorAction Continue
 exit 1
}finally{
 # Retained Process objects identify only children this launcher created. Never
 # rediscover a PID by executable name or port, and never kill a foreign listener.
 try{
  # A second launcher that did not acquire the mutex must never revoke the
  # still-running launcher's publication. Configuration/secrets are not touched.
  if($held){try{Remove-HomeServerNetworkPublication $storage}catch{try{Record 'LAN publication cleanup failed; the reader freshness lease will expire.'}catch{}}}
  foreach($proc in $processes){
   try{if(!$proc.HasExited){$proc.Kill();$null=$proc.WaitForExit(3000)}}catch{}
   finally{$proc.Dispose()}
  }
 }finally{
  if($held){$mutex.ReleaseMutex()}
  $mutex.Dispose()
 }
}
