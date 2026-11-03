# Actual installed-runtime certification; never run against a user's installation.
# The workflow runs this only AFTER unchanged-source parity and installation smoke.
[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][string]$Installation,
 [Parameter(Mandatory=$true)][string]$EvidenceDir
)
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
if($env:OS -ne 'Windows_NT' -or $env:GITHUB_ACTIONS -ne 'true'){
 throw 'HomeServer runtime certification requires an isolated GitHub Actions Windows installation.'
}
Add-Type -AssemblyName System.Net.Http
$Installation=(Resolve-Path -LiteralPath $Installation).Path
$runnerRoot=(Resolve-Path -LiteralPath $env:RUNNER_TEMP).Path.TrimEnd('\')+'\'
if(!$Installation.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)){
 throw 'Runtime certification refuses an installation outside RUNNER_TEMP.'
}
$app=Join-Path $Installation 'app'
$launcherPath=Join-Path $Installation 'homeserver\start.ps1'
$cgiPath=Join-Path $Installation 'runtime\php\php-cgi.exe'
$cliPath=Join-Path $Installation 'runtime\php\php.exe'
$dbPath=Join-Path $Installation 'runtime\mariadb\bin\mariadbd.exe'
$caddyPath=Join-Path $Installation 'runtime\caddy.exe'
$systemPowerShell=Join-Path $env:SystemRoot 'System32\WindowsPowerShell\v1.0\powershell.exe'
$data=Join-Path $env:LOCALAPPDATA 'LuckyGameHub'
$storage=Join-Path $data 'storage'
$ini=Join-Path $data 'php.ini'
$config=Join-Path $storage 'config.php'
$networkMetadata=Join-Path $storage 'homeserver-network.json'
$supervisorLog=Join-Path $data 'logs\launcher.log'
foreach($required in @($launcherPath,$cgiPath,$cliPath,$dbPath,$caddyPath,$systemPowerShell,$ini,$config)){
 if(!(Test-Path -LiteralPath $required -PathType Leaf)){throw "Missing installed certification dependency: $required"}
}
New-Item -ItemType Directory -Force -Path $EvidenceDir | Out-Null
$EvidenceDir=(Resolve-Path -LiteralPath $EvidenceDir).Path
$script:checks=@()
$script:owned=@()
$script:launchers=@()
$script:pending=@()
$script:current=$null
$script:runNumber=0
$script:logStart=0
$script:result='failed'
$script:failure=''
$script:originalCgiAcl=$null
$script:originalCgiSddl=$null
$script:originalCgiBinary=$null
$script:injectedCgiSid=$null
$script:cgiAclChanged=$false
$script:aclEvidence=@()
$suffix=[Guid]::NewGuid().ToString('N')
$token=[Guid]::NewGuid().ToString('N')+[Guid]::NewGuid().ToString('N')
$probeName='ci-hs-g5-'+$suffix+'.php'
$probePath=Join-Path $app $probeName
$fixturePath=Join-Path $storage ('ci-hs-g5-'+$suffix)
$mediaPath=Join-Path $storage ('claim-media\'+$suffix+'.bin')
$endpoint='http://127.0.0.1:8833/'+$probeName
$savedMax=[Environment]::GetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','Process')
$savedChildren=[Environment]::GetEnvironmentVariable('PHP_FCGI_CHILDREN','Process')
$configHash=(Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash
$client=New-Object System.Net.Http.HttpClient
$client.Timeout=[TimeSpan]::FromSeconds(35)
$shortClient=New-Object System.Net.Http.HttpClient
$shortClient.Timeout=[TimeSpan]::FromSeconds(1)

function Assert-G5([bool]$Condition,[string]$Name){
 if(!$Condition){throw ('FAIL '+$Name)}
 $script:checks+=@([ordered]@{name=$Name;utc=[DateTime]::UtcNow.ToString('o');result='pass'})
 Write-Host ('PASS '+$Name)
}
function Wait-G5([scriptblock]$Condition,[int]$Seconds,[string]$Name){
 $until=[DateTime]::UtcNow.AddSeconds($Seconds)
 do{
  if(& $Condition){return}
  if([DateTime]::UtcNow -ge $until){throw ('Timed out: '+$Name)}
  Start-Sleep -Milliseconds 150
 }while($true)
}
function Read-G5Log {
 if(!(Test-Path -LiteralPath $supervisorLog)){return ''}
 return [IO.File]::ReadAllText($supervisorLog)
}
function Read-G5RunLog {
 $text=Read-G5Log
 if($text.Length -lt $script:logStart){throw 'Supervisor log was unexpectedly truncated.'}
 return $text.Substring($script:logStart)
}
function Get-G5Identity([int]$ProcessId,[string]$ExpectedPath,[int]$ExpectedParent){
 $rows=@(Get-CimInstance Win32_Process -Filter ("ProcessId = "+$ProcessId))
 if($rows.Count -ne 1){throw "Expected live owned process $ProcessId"}
 $row=$rows[0]
 if($row.ExecutablePath -ine $ExpectedPath -or [int]$row.ParentProcessId -ne $ExpectedParent){
  throw "Process $ProcessId does not match its expected executable and parent; refusing ownership."
 }
 return [pscustomobject]@{Id=$ProcessId;Path=$ExpectedPath;Parent=$ExpectedParent;Created=([DateTime]$row.CreationDate).ToUniversalTime().Ticks}
}
function Test-G5Identity($Identity){
 if($null -eq $Identity){return $false}
 $rows=@(Get-CimInstance Win32_Process -Filter ("ProcessId = "+$Identity.Id) -ErrorAction SilentlyContinue)
 if($rows.Count -ne 1){return $false}
 $row=$rows[0]
 return $row.ExecutablePath -ieq $Identity.Path -and [int]$row.ParentProcessId -eq $Identity.Parent -and
  ([DateTime]$row.CreationDate).ToUniversalTime().Ticks -eq $Identity.Created
}
function Stop-G5Verified($Identity){
 if(!(Test-G5Identity $Identity)){throw 'Refusing to stop a process whose recorded identity no longer matches.'}
 Stop-Process -Id $Identity.Id -Force -ErrorAction Stop
 Wait-G5 {!(Test-G5Identity $Identity)} 10 'verified process exit'
}
function Get-G5Child([string]$ExpectedPath){
 $rows=@(Get-CimInstance Win32_Process -Filter ("ParentProcessId = "+$script:current.Id) |
  Where-Object {$_.ExecutablePath -ieq $ExpectedPath})
 if($rows.Count -ne 1){throw ('Expected exactly one owned child: '+$ExpectedPath)}
 $identity=Get-G5Identity ([int]$rows[0].ProcessId) $ExpectedPath $script:current.Id
 $script:owned+=@($identity)
 return $identity
}
function Test-G5Listener([int]$Port,[int]$ProcessId){
 $listeners=@(Get-NetTCPConnection -State Listen -LocalPort $Port -ErrorAction SilentlyContinue)
 return $listeners.Count -ge 1 -and @($listeners | Where-Object {[int]$_.OwningProcess -ne $ProcessId}).Count -eq 0
}
function Assert-G7APublicMetadata([string]$ExpectedOrigin){
 # Use the untouched installed PHP endpoint through actual Caddy/FastCGI. In
 # particular, this proves its Windows fstat mode/mtime reader accepts the
 # launcher's protected atomic publication, not just a PowerShell JSON reader.
 $request=New-Object System.Net.Http.HttpRequestMessage
 $request.Method=[System.Net.Http.HttpMethod]::Get
 $request.RequestUri=[Uri]'http://127.0.0.1:8833/api/installation-urls.php'
 $request.Headers.Add('Cache-Control','no-store')
 $response=$null
 try{
  $response=$client.SendAsync($request).GetAwaiter().GetResult()
  $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
  Assert-G5 ([int]$response.StatusCode -eq 200) 'G7A installed PHP metadata endpoint succeeds without an administrator login'
  $metadata=$body | ConvertFrom-Json
  $names=@($metadata.PSObject.Properties | ForEach-Object {$_.Name} | Sort-Object)
  Assert-G5 (($names -join ',') -ceq 'deployment,lanOrigin,leaseSeconds,ok' -and $metadata.ok -is [bool] -and
   $metadata.ok -eq $true -and $metadata.deployment -ceq 'homeserver' -and $metadata.lanOrigin -ceq $ExpectedOrigin) 'G7A installed PHP returns exactly the expected deployment/origin/lease metadata and no account or secret fields'
  $integerLease=$metadata.leaseSeconds -is [int] -or $metadata.leaseSeconds -is [long]
  if($ExpectedOrigin -eq ''){
   Assert-G5 ($integerLease -and $metadata.leaseSeconds -eq 0) 'G7A installed PHP preserves local-only availability without inventing a LAN lease'
  }else{
   Assert-G5 ($integerLease -and $metadata.leaseSeconds -gt 0 -and $metadata.leaseSeconds -le 30) 'G7A installed PHP reads the actual Windows publication as a fresh bounded LAN lease'
  }
  Assert-G5 ($null -ne $response.Headers.CacheControl -and $response.Headers.CacheControl.NoStore -and
   !$response.Headers.Contains('Access-Control-Allow-Origin')) 'G7A installed metadata response is no-store and does not grant cross-origin browser access'
 }finally{if($null -ne $response){$response.Dispose()};$request.Dispose()}
 $request=New-Object System.Net.Http.HttpRequestMessage
 $request.Method=[System.Net.Http.HttpMethod]::Get
 $request.RequestUri=[Uri]'http://127.0.0.1:8833/storage/homeserver-network.json'
 $response=$null
 try{
  $response=$client.SendAsync($request).GetAwaiter().GetResult()
  Assert-G5 ([int]$response.StatusCode -eq 403) 'G7A actual Caddy denies direct HTTP access to private launcher metadata'
 }finally{if($null -ne $response){$response.Dispose()};$request.Dispose()}
}
function Assert-G7ALiveNetwork([int]$WebProcessId){
 Wait-G5 {(Read-G5RunLog).Contains('Local HomeServer ready:')} 15 'completed application/network startup readiness'
 $desktop=@(Get-NetTCPConnection -State Listen -LocalPort 8833 -ErrorAction SilentlyContinue)
 Assert-G5 ($desktop.Count -eq 1 -and $desktop[0].LocalAddress -ceq '127.0.0.1' -and [int]$desktop[0].OwningProcess -eq $WebProcessId) 'G7A actual desktop socket is bound only to loopback by the owned Caddy'
 $configText=[IO.File]::ReadAllText((Join-Path $data 'Caddyfile'))
 $sites=[regex]::Matches($configText,'(?m)^https://([0-9.]+):8843 \{')
 Assert-G5 ($sites.Count -le 1) 'G7A generated runtime selects at most one LAN origin'
 $lan=@(Get-NetTCPConnection -State Listen -LocalPort 8843 -ErrorAction SilentlyContinue)
 if($sites.Count -eq 0){
  Assert-G5 ($lan.Count -eq 0 -and !(Test-Path -LiteralPath $networkMetadata)) 'G7A local-only runtime has no LAN listener or advertised origin'
  Assert-G7APublicMetadata ''
  return [pscustomobject]@{Lan=$false;Origin='';Modified=[DateTime]::MinValue}
 }
 $address=$sites[0].Groups[1].Value
 Assert-G5 ($lan.Count -eq 1 -and $lan[0].LocalAddress -ceq $address -and [int]$lan[0].OwningProcess -eq $WebProcessId) 'G7A actual HTTPS socket binds only the selected LAN address, never a wildcard'
 $record=[IO.File]::ReadAllText($networkMetadata) | ConvertFrom-Json
 $modified=[IO.File]::GetLastWriteTimeUtc($networkMetadata)
 Assert-G5 ($record.version -eq 1 -and @($record.PSObject.Properties).Count -eq 2 -and $record.origin -ceq ('https://'+$address+':8843') -and
  ([DateTime]::UtcNow-$modified).TotalSeconds -ge -5 -and ([DateTime]::UtcNow-$modified).TotalSeconds -lt 30) 'G7A ready publication matches the actual owned HTTPS socket and has a fresh bounded lease'
 Assert-G7APublicMetadata ([string]$record.origin)
 return [pscustomobject]@{Lan=$true;Origin=[string]$record.origin;Modified=$modified}
}
function Assert-G7ASecondLauncher($Network){
 $arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$launcherPath+'" -Smoke -NoBrowser'
 $second=Start-Process -FilePath $systemPowerShell -ArgumentList $arguments -PassThru -WindowStyle Hidden
 $null=$second.Handle
 try{
  Assert-G5 ($second.WaitForExit(10000) -and $second.ExitCode -eq 0) 'G7A second launcher exits harmlessly while the existing launcher owns its mutex'
  if($Network.Lan){
   $record=[IO.File]::ReadAllText($networkMetadata) | ConvertFrom-Json
   Assert-G5 ($record.origin -ceq $Network.Origin) 'G7A non-owning launcher does not withdraw the live phone origin'
  }else{Assert-G5 (!(Test-Path -LiteralPath $networkMetadata)) 'G7A non-owning launcher does not invent a LAN origin'}
 }finally{
  # Only descendants of the exact child created above could be faulty here.
  # Retain native ownership even if a regression incorrectly ignored the mutex.
  if(!$second.HasExited){
   foreach($row in @(Get-CimInstance Win32_Process -Filter ("ParentProcessId = "+$second.Id) | Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($Installation+'\',[StringComparison]::OrdinalIgnoreCase)})){
    $identity=Get-G5Identity ([int]$row.ProcessId) $row.ExecutablePath $second.Id
    if(Test-G5Identity $identity){Stop-G5Verified $identity}
   }
   if(!$second.HasExited){$second.Kill();$null=$second.WaitForExit(3000)}
  }
  $second.Dispose()
 }
}
function Assert-G5MutexFree {
 $mutex=New-Object System.Threading.Mutex($false,'Local\LuckyGameHubHomeServer')
 $held=$false
 try{
  try{$held=$mutex.WaitOne(0,$false)}catch [System.Threading.AbandonedMutexException]{$held=$true;throw 'Launcher abandoned its mutex instead of releasing it.'}
  Assert-G5 $held 'Production mutex is released after terminal cleanup'
 }finally{if($held){$mutex.ReleaseMutex()};$mutex.Dispose()}
}
function Start-G5Launcher {
 Assert-G5 ([Environment]::GetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','Process') -ceq '2' -and
  [Environment]::GetEnvironmentVariable('PHP_FCGI_CHILDREN','Process') -ceq '3') 'Launcher is really started under conflicting inherited FastCGI settings'
 $script:runNumber++
 $script:logStart=(Read-G5Log).Length
 $stdOut=Join-Path $EvidenceDir ('launcher-'+$script:runNumber+'.stdout.log')
 $stdErr=Join-Path $EvidenceDir ('launcher-'+$script:runNumber+'.stderr.log')
 $arguments='-NoProfile -NonInteractive -ExecutionPolicy Bypass -File "'+$launcherPath+'" -NoBrowser'
 $process=Start-Process -FilePath $systemPowerShell -ArgumentList $arguments -PassThru -WindowStyle Hidden -RedirectStandardOutput $stdOut -RedirectStandardError $stdErr
 $null=$process.Handle
 $identity=Get-G5Identity $process.Id $systemPowerShell $PID
 $script:owned+=@($identity);$script:launchers+=@($process);$script:current=$process
 return $process
}
function New-G5Request([string]$Action,[string]$Method='GET'){
 $request=New-Object System.Net.Http.HttpRequestMessage
 $request.Method=New-Object System.Net.Http.HttpMethod($Method)
 $request.RequestUri=[Uri]($endpoint+'?action='+$Action)
 $request.Headers.Add('X-Lucky-Runtime-Test',$token)
 $request.Headers.Add('Cache-Control','no-store')
 if($Method -eq 'POST'){$request.Content=New-Object System.Net.Http.StringContent('runtime-fixture=1')}
 return $request
}
function Invoke-G5([string]$Action,[string]$Method='GET'){
 $request=New-G5Request $Action $Method
 $response=$null
 try{
  $response=$client.SendAsync($request).GetAwaiter().GetResult()
  $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
  if([int]$response.StatusCode -ne 200){throw ('Probe HTTP status '+[int]$response.StatusCode)}
  return $body | ConvertFrom-Json
 }finally{if($null -ne $response){$response.Dispose()};$request.Dispose()}
}
function Start-G5Request([string]$Action,[string]$Method='GET'){
 $request=New-G5Request $Action $Method
 $operation=[pscustomobject]@{Request=$request;Task=$client.SendAsync($request);Done=$false}
 $script:pending+=@($operation)
 return $operation
}
function Complete-G5Request($Operation,[bool]$ExpectSuccess){
 $response=$null
 try{
  $response=$Operation.Task.GetAwaiter().GetResult()
  $body=$response.Content.ReadAsStringAsync().GetAwaiter().GetResult()
  if($ExpectSuccess){
   Assert-G5 ([int]$response.StatusCode -eq 200) 'Busy live request finishes successfully on its original worker'
   return $body | ConvertFrom-Json
  }
  Assert-G5 ([int]$response.StatusCode -ge 500) 'Interrupted committed POST returns a gateway failure, not an invented success'
  return [pscustomobject]@{outcome='http-failure';status=[int]$response.StatusCode}
 }catch{
  if($ExpectSuccess){throw}
  # A socket/transport failure is also an honest ambiguous-delivery outcome.
  # The later DB receipt assertion determines whether the POST committed once.
  if($_.Exception.Message.StartsWith('FAIL ')){throw}
  Assert-G5 $true 'Interrupted committed POST reports a transport failure without client replay'
  return [pscustomobject]@{outcome='transport-failure';status=0}
 }finally{if($null -ne $response){$response.Dispose()};$Operation.Request.Dispose();$Operation.Done=$true}
}
function Wait-G5Health([int]$OldCgi=0){
 $until=[DateTime]::UtcNow.AddSeconds(40)
 do{
  $script:current.Refresh()
  if($script:current.HasExited){throw ('Production launcher exited before health: '+(Read-G5RunLog))}
  try{
   $health=Invoke-G5 'health'
   if($health.ok -eq $true -and ($OldCgi -eq 0 -or [int]$health.pid -ne $OldCgi)){return $health}
  }catch{
   if([DateTime]::UtcNow -ge $until){throw}
  }
  if([DateTime]::UtcNow -ge $until){throw 'Timed out waiting for a healthy replacement CGI.'}
  Start-Sleep -Milliseconds 200
 }while($true)
}
function Wait-G5Exit($Process,[int]$Seconds,[int]$ForeignCgi=0){
 Wait-G5 {$Process.Refresh();$Process.HasExited} $Seconds 'production launcher terminal exit'
 if(!$Process.WaitForExit(2000)){throw 'Exited launcher did not complete its bounded native wait.'}
 $exitCode=$Process.ExitCode
 Assert-G5 ($exitCode -is [int] -and $exitCode -ne 0) 'Terminal supervisor failure has a present nonzero exit status'
 # Includes any render worker active during the failure, not only known CGI IDs.
 $remaining=@(Get-CimInstance Win32_Process -Filter ("ParentProcessId = "+$Process.Id) |
  Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($Installation+'\',[StringComparison]::OrdinalIgnoreCase)})
 Assert-G5 ($remaining.Count -eq 0) 'Terminal cleanup leaves no owned bundled runtime children'
 foreach($identity in $script:owned | Where-Object {$_.Parent -eq $Process.Id}){
  if(Test-G5Identity $identity){throw ('Owned process survived terminal cleanup: '+$identity.Id)}
 }
 foreach($port in @(3307,8833,8843)){
  Assert-G5 (@(Get-NetTCPConnection -State Listen -LocalPort $port -ErrorAction SilentlyContinue).Count -eq 0) ('Terminal cleanup removes its listener on port '+$port)
 }
 Assert-G5 (!(Test-Path -LiteralPath $networkMetadata)) 'G7A terminal cleanup withdraws its phone-origin publication'
 if($ForeignCgi -eq 0){
  Assert-G5 (@(Get-NetTCPConnection -State Listen -LocalPort 9017 -ErrorAction SilentlyContinue).Count -eq 0) 'Terminal cleanup removes its owned CGI listener'
 }else{
  Assert-G5 (Test-G5Listener 9017 $ForeignCgi) 'Terminal cleanup leaves only the explicitly foreign CGI listener'
 }
 Assert-G5MutexFree
}
function Start-G5ForeignCgi([int]$Port){
 $arguments='-c "'+$ini+'" -b 127.0.0.1:'+$Port
 $priorMax=[Environment]::GetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','Process')
 $priorChildren=[Environment]::GetEnvironmentVariable('PHP_FCGI_CHILDREN','Process')
 try{
  # The foreign control is itself one stable process, not an accidental pool.
  [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','0','Process')
  [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN','0','Process')
  $process=Start-Process -FilePath $cgiPath -ArgumentList $arguments -PassThru -WindowStyle Hidden
 }finally{
  [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS',$priorMax,'Process')
  [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN',$priorChildren,'Process')
 }
 $identity=Get-G5Identity $process.Id $cgiPath $PID
 $script:owned+=@($identity)
 Wait-G5 {Test-G5Listener $Port $identity.Id} 10 'test-owned foreign CGI listener'
 return $identity
}
function Write-G5AclEvidence([string]$Phase,[Security.AccessControl.FileSecurity]$Acl){
 # Only the disposable CI executable's security descriptor is recorded: no
 # configuration, environment, secrets, user-profile paths, or account names.
 $binary=$Acl.GetSecurityDescriptorBinaryForm()
 $raw=[Security.AccessControl.RawSecurityDescriptor]::new($binary,0)
 $daclBytes=$null
 if($null -ne $raw.DiscretionaryAcl){
  $daclBytes=New-Object byte[] $raw.DiscretionaryAcl.BinaryLength
  $raw.DiscretionaryAcl.GetBinaryForm($daclBytes,0)
 }
 $rules=@($Acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]) | ForEach-Object {
  [ordered]@{sid=$_.IdentityReference.Value;type=$_.AccessControlType.ToString();rights=[int]$_.FileSystemRights;
   inherited=$_.IsInherited;inheritance=$_.InheritanceFlags.ToString();propagation=$_.PropagationFlags.ToString()}
 })
 $snapshot=[ordered]@{phase=$Phase;utc=[DateTime]::UtcNow.ToString('o');
  sddl=$Acl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::All);
  owner=$Acl.GetOwner([Security.Principal.SecurityIdentifier]).Value;group=$Acl.GetGroup([Security.Principal.SecurityIdentifier]).Value;
  protected=$Acl.AreAccessRulesProtected;canonical=$Acl.AreAccessRulesCanonical;
  controlFlags=$raw.ControlFlags.ToString();controlValue=[int]$raw.ControlFlags;
  daclBase64=$(if($null -eq $daclBytes){$null}else{[Convert]::ToBase64String($daclBytes)});rules=$rules}
 $script:aclEvidence+=@($snapshot)
 $script:aclEvidence | ConvertTo-Json -Depth 10 | Set-Content -LiteralPath (Join-Path $EvidenceDir 'cgi-acl-diagnostics.json') -Encoding UTF8
 Write-Host ('ACL_DIAGNOSTIC phase='+$Phase+' control='+$snapshot.controlValue+' sddl='+$snapshot.sddl)
}
function Get-G5DescriptorBytes([Security.AccessControl.RawSecurityDescriptor]$Descriptor){
 $bytes=New-Object byte[] $Descriptor.BinaryLength
 $Descriptor.GetBinaryForm($bytes,0)
 return ,$bytes
}
function Test-G5RestoredDescriptor([byte[]]$Expected,[byte[]]$Actual){
 $expectedRaw=[Security.AccessControl.RawSecurityDescriptor]::new($Expected,0)
 $actualRaw=[Security.AccessControl.RawSecurityDescriptor]::new($Actual,0)
 $expectedFlags=[int]$expectedRaw.ControlFlags;$actualFlags=[int]$actualRaw.ControlFlags
 # Native evidence (job125992844987): Set-Acl removed the injected Deny and
 # restored all ordered ACEs/owner/group, but Windows converted0x8004→0x8404.
 # Microsoft documents this automatic-inheritance conversion:
 # https://learn.microsoft.com/en-us/windows/win32/secauthz/automatic-propagation-of-inheritable-aces
 # Permit only that one-way metadata addition. No SDDL stripping, ACE sorting,
 # owner/group ignoring, protection changes, or generic control-bit masking.
 $autoInherited=[int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited
 if($actualFlags -ne $expectedFlags){
  $daclPresent=[int][Security.AccessControl.ControlFlags]::DiscretionaryAclPresent
  $daclProtected=[int][Security.AccessControl.ControlFlags]::DiscretionaryAclProtected
  if(($expectedFlags -band $daclPresent) -eq 0 -or $null -eq $expectedRaw.DiscretionaryAcl -or ($expectedFlags -band $daclProtected) -ne 0){return $false}
  if(($expectedFlags -band $autoInherited) -ne 0 -or $actualFlags -ne ($expectedFlags -bor $autoInherited)){return $false}
  $expectedRaw.SetFlags([Security.AccessControl.ControlFlags]($expectedFlags -bor $autoInherited))
 }
 return [Convert]::ToBase64String((Get-G5DescriptorBytes $expectedRaw)) -ceq [Convert]::ToBase64String((Get-G5DescriptorBytes $actualRaw))
}
function Assert-G5AclComparator([byte[]]$Original){
 Assert-G5 (Test-G5RestoredDescriptor $Original $Original) 'ACL comparator accepts the exact untouched descriptor'
 # The real installer may already carry AI on another Windows image. Create a
 # synthetic in-memory baseline with only AI cleared so add/remove negatives
 # always change a bit. This local array is never written to disk and never
 # replaces the separately captured original used for actual restoration.
 $controlBaseline=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $controlBaseline.SetFlags([Security.AccessControl.ControlFlags]([int]$controlBaseline.ControlFlags -band (-bnot [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited)))
 $Original=Get-G5DescriptorBytes $controlBaseline
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $changed.SetFlags([Security.AccessControl.ControlFlags]([int]$changed.ControlFlags -bor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited))
 Assert-G5 (Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed)) 'ACL comparator accepts only the observed Windows auto-inheritance metadata addition'
 $alreadyInherited=Get-G5DescriptorBytes $changed
 Assert-G5 (!(Test-G5RestoredDescriptor $alreadyInherited $Original)) 'ACL negative control rejects removal of existing auto-inheritance metadata'
 $protected=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $protected.SetFlags([Security.AccessControl.ControlFlags]([int]$protected.ControlFlags -bor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclProtected))
 $protectedOriginal=Get-G5DescriptorBytes $protected
 $protected.SetFlags([Security.AccessControl.ControlFlags]([int]$protected.ControlFlags -bor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited))
 Assert-G5 (!(Test-G5RestoredDescriptor $protectedOriginal (Get-G5DescriptorBytes $protected))) 'ACL negative control rejects metadata addition to a protected DACL'
 $nullDacl=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $nullDacl.DiscretionaryAcl=$null
 $nullOriginal=Get-G5DescriptorBytes $nullDacl
 $nullDacl.SetFlags([Security.AccessControl.ControlFlags]([int]$nullDacl.ControlFlags -bor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited))
 Assert-G5 (!(Test-G5RestoredDescriptor $nullOriginal (Get-G5DescriptorBytes $nullDacl))) 'ACL negative control rejects metadata addition to a null DACL'
 $absentDacl=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $absentDacl.DiscretionaryAcl=$null
 $absentDacl.SetFlags([Security.AccessControl.ControlFlags]([int]$absentDacl.ControlFlags -band (-bnot [int][Security.AccessControl.ControlFlags]::DiscretionaryAclPresent)))
 $absentOriginal=Get-G5DescriptorBytes $absentDacl
 $absentDacl.SetFlags([Security.AccessControl.ControlFlags]([int]$absentDacl.ControlFlags -bor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclAutoInherited))
 Assert-G5 (!(Test-G5RestoredDescriptor $absentOriginal (Get-G5DescriptorBytes $absentDacl))) 'ACL negative control rejects metadata addition to an absent DACL'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $ownerSid=if($changed.Owner.Value -eq 'S-1-5-18'){'S-1-5-32-544'}else{'S-1-5-18'}
 $changed.Owner=[Security.Principal.SecurityIdentifier]::new($ownerSid)
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects a different owner'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $groupSid=if($changed.Group.Value -eq 'S-1-5-32-545'){'S-1-5-32-544'}else{'S-1-5-32-545'}
 $changed.Group=[Security.Principal.SecurityIdentifier]::new($groupSid)
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects a different group'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $changed.DiscretionaryAcl[0].AccessMask=$changed.DiscretionaryAcl[0].AccessMask -bxor 1
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects changed access rights'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 if($changed.DiscretionaryAcl.Count -lt 2){throw 'Native ACL ordering control requires two distinct inherited rules.'}
 $first=$changed.DiscretionaryAcl[0].Copy();$second=$changed.DiscretionaryAcl[1].Copy()
 $changed.DiscretionaryAcl[0]=$second;$changed.DiscretionaryAcl[1]=$first
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects changed ACE order'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $changed.DiscretionaryAcl[0].AceFlags=[Security.AccessControl.AceFlags]([int]$changed.DiscretionaryAcl[0].AceFlags -bxor [int][Security.AccessControl.AceFlags]::Inherited)
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects changed ACE inheritance'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $changed.SetFlags([Security.AccessControl.ControlFlags]([int]$changed.ControlFlags -bxor [int][Security.AccessControl.ControlFlags]::DiscretionaryAclProtected))
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects changed inheritance protection'
 $changed=[Security.AccessControl.RawSecurityDescriptor]::new($Original,0)
 $changed.SetFlags([Security.AccessControl.ControlFlags]([int]$changed.ControlFlags -bxor [int][Security.AccessControl.ControlFlags]::GroupDefaulted))
 Assert-G5 (!(Test-G5RestoredDescriptor $Original (Get-G5DescriptorBytes $changed))) 'ACL negative control rejects any other descriptor control-flag change'
}
function Get-G5InjectedDenyCount([Security.AccessControl.FileSecurity]$Acl){
 return @($Acl.GetAccessRules($true,$false,[Security.Principal.SecurityIdentifier]) | Where-Object {
  $_.IdentityReference.Value -eq $script:injectedCgiSid.Value -and $_.AccessControlType -eq [Security.AccessControl.AccessControlType]::Deny -and
  (([int]$_.FileSystemRights -band [int][Security.AccessControl.FileSystemRights]::ExecuteFile) -ne 0)
 }).Count
}
function Restore-G5CgiAcl {
 if(!$script:cgiAclChanged){return}
 if($null -eq $script:originalCgiAcl -or $null -eq $script:originalCgiBinary -or $null -eq $script:injectedCgiSid){throw 'Original isolated CGI permissions are unavailable for restoration.'}
 Write-G5AclEvidence 'restore-expected' $script:originalCgiAcl
 Write-G5AclEvidence 'restore-before' (Get-Acl -LiteralPath $cgiPath -ErrorAction Stop)
 Set-Acl -LiteralPath $cgiPath -AclObject $script:originalCgiAcl -ErrorAction Stop
 $restored=Get-Acl -LiteralPath $cgiPath -ErrorAction Stop
 Write-G5AclEvidence 'restore-after' $restored
 if(!(Test-G5RestoredDescriptor $script:originalCgiBinary ($restored.GetSecurityDescriptorBinaryForm())) -or (Get-G5InjectedDenyCount $restored) -ne 0){
  throw 'Isolated CGI owner/group, ordered ACLs, permissions and protection were not restored.'
 }
 $script:cgiAclChanged=$false
}

# This endpoint is never shipped. Random token + CI-only environment + loopback
# gates precede all actions. There are no arbitrary paths, SQL, or subprocesses.
$probe=@'
<?php
declare(strict_types=1);
if(getenv('GITHUB_ACTIONS')!=='true'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||
 !hash_equals('__TOKEN__',(string)($_SERVER['HTTP_X_LUCKY_RUNTIME_TEST']??''))){http_response_code(403);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');
$action=(string)($_GET['action']??'');$root=__DIR__.'/storage/ci-hs-g5-__SUFFIX__';
if($action==='health'){echo json_encode(['ok'=>true,'pid'=>getmypid(),'maxRequests'=>getenv('PHP_FCGI_MAX_REQUESTS'),'children'=>getenv('PHP_FCGI_CHILDREN')],JSON_THROW_ON_ERROR);exit;}
if($action==='busy'){
 file_put_contents($root.'/busy-started',(string)getmypid(),LOCK_EX);sleep(15);
 echo json_encode(['ok'=>true,'pid'=>getmypid()],JSON_THROW_ON_ERROR);exit;
}
require __DIR__.'/lib/Bootstrap.php';$db=db();$table='ci_runtime_g5___SUFFIX__';
if($action==='seed'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
 if(!mkdir($root,0700)&&!is_dir($root))throw new RuntimeException('Fixture directory unavailable.');
 $db->exec('CREATE TABLE `'.$table.'` (id INT NOT NULL PRIMARY KEY,marker CHAR(32) NOT NULL,writes INT NOT NULL DEFAULT 0) ENGINE=InnoDB');
 $db->exec("INSERT INTO `".$table."`(id,marker,writes) VALUES (1,'__SUFFIX__',0)");
 $media=__DIR__.'/storage/claim-media';if(!is_dir($media)&&!mkdir($media,0700,true))throw new RuntimeException('Fixture media unavailable.');
 file_put_contents($media.'/__SUFFIX__.bin',random_bytes(2048),LOCK_EX);
 echo json_encode(['ok'=>true,'writes'=>0],JSON_THROW_ON_ERROR);exit;
}
if($action==='write-and-wait'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
 $db->beginTransaction();$db->exec('UPDATE `'.$table.'` SET writes=writes+1 WHERE id=1');$db->commit();
 releaseSessionLock();file_put_contents($root.'/post-committed',(string)getmypid(),LOCK_EX);sleep(20);
 echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);exit;
}
if($action==='read'){
 $row=$db->query('SELECT marker,writes FROM `'.$table.'` WHERE id=1')->fetch(PDO::FETCH_ASSOC);
 echo json_encode(['ok'=>true,'marker'=>$row['marker'],'writes'=>(int)$row['writes']],JSON_THROW_ON_ERROR);exit;
}
if($action==='cleanup'&&($_SERVER['REQUEST_METHOD']??'')==='POST'){
 $db->exec('DROP TABLE IF EXISTS `'.$table.'`');echo json_encode(['ok'=>true],JSON_THROW_ON_ERROR);exit;
}
http_response_code(405);echo json_encode(['error'=>'Unsupported fixture action']);
'@

$sentinel=$null;$blocker=$null;$seeded=$false;$fixtureReserved=$false
try{
 $version=(& $systemPowerShell -NoProfile -NonInteractive -Command '$PSVersionTable.PSVersion.ToString()' | Out-String).Trim()
 Assert-G5 ($LASTEXITCODE -eq 0 -and $version -match '^5\.1\.') 'The production subprocess uses actual Windows PowerShell 5.1'
 Assert-G5 (!(Test-Path -LiteralPath $probePath) -and !(Test-Path -LiteralPath $fixturePath) -and !(Test-Path -LiteralPath $mediaPath)) 'Disposable probe identities do not overwrite existing files'
 $fixtureReserved=$true
 [IO.File]::WriteAllText($probePath,$probe.Replace('__TOKEN__',$token).Replace('__SUFFIX__',$suffix),(New-Object Text.UTF8Encoding($false)))
 [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','2','Process')
 [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN','3','Process')
 # Same executable name, unrelated parent and port. It must never be swept up by
 # supervisor recovery/cleanup. Only this harness removes it in its own finally.
 $portPicker=New-Object Net.Sockets.TcpListener([Net.IPAddress]::Loopback,0)
 $portPicker.Start();$sentinelPort=([Net.IPEndPoint]$portPicker.LocalEndpoint).Port;$portPicker.Stop()
 $sentinel=Start-G5ForeignCgi $sentinelPort
 # An interrupted old launch cannot leave a fresh-looking loopback QR behind.
 # This exact reserved runtime leaf is disposable in the isolated installation.
 [IO.File]::WriteAllText($networkMetadata,'{"version":1,"origin":"https://127.0.0.1:8843"}')
 $run=Start-G5Launcher
 $health=Wait-G5Health
 Assert-G5 ($health.maxRequests -ceq '0' -and $health.children -ceq '0') 'Inherited conflicting FastCGI settings are overridden in the actual PHP process'
 $cgi=Get-G5Child $cgiPath;$database=Get-G5Child $dbPath;$web=Get-G5Child $caddyPath
 Assert-G5 ([int]$health.pid -eq $cgi.Id -and (Test-G5Listener 9017 $cgi.Id)) 'Health is served by the verified owned CGI listener'
 $network=Assert-G7ALiveNetwork $web.Id
 Assert-G7ASecondLauncher $network
 if($network.Lan){
  Wait-G5 {[IO.File]::GetLastWriteTimeUtc($networkMetadata) -gt $network.Modified} 20 'actual LAN lease renewal by the live launcher'
  Assert-G5 (([IO.File]::ReadAllText($networkMetadata) | ConvertFrom-Json).origin -ceq $network.Origin) 'G7A actual heartbeat atomically renews the same validated LAN origin'
 }
 $seed=Invoke-G5 'seed' 'POST';$seeded=$true
 Assert-G5 ($seed.ok -and $seed.writes -eq 0) 'Persistent test data is seeded through the installed application database'
 $mediaHash=(Get-FileHash -LiteralPath $mediaPath -Algorithm SHA256).Hash
 $timer=[Diagnostics.Stopwatch]::StartNew()
 for($requestIndex=1;$requestIndex -le 600;$requestIndex++){
  $reply=Invoke-G5 ('health&n='+$requestIndex)
  if(!$reply.ok -or [int]$reply.pid -ne $cgi.Id -or $reply.maxRequests -cne '0' -or $reply.children -cne '0'){
   throw ('CGI lifetime or policy changed at request '+$requestIndex)
  }
 }
 $timer.Stop()
 Assert-G5 (Test-G5Identity $cgi) ('600 real HTTP health requests preserve the original CGI PID ('+[Math]::Round($timer.Elapsed.TotalSeconds,2)+'s)')
 Wait-G5 {
  (Read-G5RunLog).Contains('Starting Render Worker') -and
   @(Get-CimInstance Win32_Process -Filter ("ParentProcessId = "+$run.Id) | Where-Object {$_.ExecutablePath -ieq $cliPath}).Count -eq 0
 } 25 'first scheduled render worker has exited before CGI recovery'
 $budgetStart=[DateTime]::UtcNow
 Stop-G5Verified $cgi
 $replacement=Wait-G5Health $cgi.Id;$cgi=Get-G5Child $cgiPath
 Assert-G5 ([int]$replacement.pid -eq $cgi.Id) 'Killing only the owned CGI produces a newly owned healthy CGI PID'
 Assert-G5 ((Test-G5Identity $database) -and (Test-G5Identity $web)) 'CGI recovery preserves the existing database and Caddy processes'
 Assert-G5 ((Test-G5Identity $sentinel) -and (Test-G5Listener $sentinelPort $sentinel.Id)) 'Unrelated same-name CGI survives replacement'
 $beforeBusyLease=[IO.File]::GetLastWriteTimeUtc($networkMetadata)
 $busy=Start-G5Request 'busy'
 Wait-G5 {Test-Path -LiteralPath (Join-Path $fixturePath 'busy-started')} 5 'busy request start'
 $busyPid=[int][IO.File]::ReadAllText((Join-Path $fixturePath 'busy-started'))
 Assert-G5 ($busyPid -eq $cgi.Id) 'The long-running request occupies the current owned worker'
 for($probeIndex=0;$probeIndex -lt 3;$probeIndex++){
  $short=New-G5Request 'health';$timedOut=$false;$shortResponse=$null
  try{$shortResponse=$shortClient.SendAsync($short).GetAwaiter().GetResult()}
  catch{$timedOut=$_.Exception.ToString() -match 'TaskCanceledException|TimeoutException|timed out|canceled'}
  finally{if($null -ne $shortResponse){$shortResponse.Dispose()};$short.Dispose()}
  Assert-G5 $timedOut 'A short health probe times out while the single CGI remains legitimately busy'
  Assert-G5 (Test-G5Identity $cgi) 'A short health timeout does not kill the living CGI worker'
 }
 $busyReply=Complete-G5Request $busy $true
 Assert-G5 ([int]$busyReply.pid -eq $cgi.Id -and (Test-G5Identity $cgi)) 'The full 15-second busy request retains its original CGI identity'
 if($network.Lan){Assert-G5 ([IO.File]::GetLastWriteTimeUtc($networkMetadata) -gt $beforeBusyLease) 'G7A LAN freshness renews while a legitimate long PHP request is still running'}
 Assert-G5 ((Test-G5Identity $database) -and (Test-G5Identity $web)) 'Multiple recovery supervisor ticks preserve database/Caddy and tolerate an exited render worker'
 $post=Start-G5Request 'write-and-wait' 'POST'
 Wait-G5 {Test-Path -LiteralPath (Join-Path $fixturePath 'post-committed')} 5 'committed POST receipt before CGI death'
 Assert-G5 ([int][IO.File]::ReadAllText((Join-Path $fixturePath 'post-committed')) -eq $cgi.Id) 'Interrupted POST is proven committed by the same owned CGI before the kill'
 $oldId=$cgi.Id;Stop-G5Verified $cgi
 $postOutcome=Complete-G5Request $post $false
 $replacement=Wait-G5Health $oldId;$cgi=Get-G5Child $cgiPath
 $receipt=Invoke-G5 'read'
 Assert-G5 ($receipt.marker -ceq $suffix -and $receipt.writes -eq 1) 'A committed POST is never automatically replayed after CGI recovery'
 Assert-G5 ((Test-G5Identity $database) -and (Test-G5Identity $web)) 'Interrupted-request recovery does not restart database or Caddy'
 $oldId=$cgi.Id;Stop-G5Verified $cgi
 $replacement=Wait-G5Health $oldId;$cgi=Get-G5Child $cgiPath
 Assert-G5 (([DateTime]::UtcNow-$budgetStart).TotalSeconds -lt 55) 'Three replacement attempts occur inside one rolling 60-second budget window'
 Stop-G5Verified $cgi
 Wait-G5Exit $run 15
 $terminalLog=Read-G5RunLog
 $attempts=[regex]::Matches($terminalLog,'CGI restart attempt=([1-3])\b')
 $attemptNumbers=($attempts | ForEach-Object {$_.Groups[1].Value}) -join ','
 Assert-G5 ($attempts.Count -eq 3 -and $attemptNumbers -ceq '1,2,3') 'Exactly three bounded CGI replacement attempts are logged'
 Assert-G5 ($terminalLog -match 'CGI recovery exhausted') 'Fourth worker failure produces an explicit exhausted-recovery result'
 Assert-G5 ((Test-G5Identity $sentinel) -and (Test-G5Listener $sentinelPort $sentinel.Id)) 'Unrelated same-name CGI survives terminal runtime cleanup'
 Assert-G5 ((Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash -ceq $configHash -and (Get-FileHash -LiteralPath $mediaPath -Algorithm SHA256).Hash -ceq $mediaHash) 'Terminal cleanup preserves existing configuration and media bytes'
 [IO.File]::WriteAllText((Join-Path $EvidenceDir 'recovery-supervisor.log'),$terminalLog,(New-Object Text.UTF8Encoding($false)))
 # A different process owns the real CGI port. Production must refuse it, not
 # adopt it or terminate all php-cgi processes by name.
 $blocker=Start-G5ForeignCgi 9017
 $collisionRun=Start-G5Launcher
 Wait-G5Exit $collisionRun 50 $blocker.Id
 $collisionLog=Read-G5RunLog
 Assert-G5 ($collisionLog -match ('CGI port conflict port=9017 owner_pid='+$blocker.Id+'\b')) 'An unrelated 9017 listener causes a clear ownership failure'
 Assert-G5 ((Test-G5Identity $blocker) -and (Test-G5Listener 9017 $blocker.Id)) 'The unrelated occupied-port CGI is neither killed nor adopted'
 Assert-G5 (Test-G5Identity $sentinel) 'Separate same-name sentinel also survives port-collision cleanup'
 Stop-G5Verified $blocker;$blocker=$null
 # A separate fault proves failed Start-Process calls consume the same budget.
 # Deny only ExecuteFile on this one CI-installed leaf, not its directory or any
 # system/user setting. Keep the untouched original descriptor separate from the
 # edited clone, restore in this inner finally, and retry restoration in outer
 # emergency cleanup if any assertion/operation fails.
 $failedSpawnRun=Start-G5Launcher
 $health=Wait-G5Health;$cgi=Get-G5Child $cgiPath;$database=Get-G5Child $dbPath;$web=Get-G5Child $caddyPath
 $script:originalCgiAcl=Get-Acl -LiteralPath $cgiPath -ErrorAction Stop
 $script:originalCgiSddl=$script:originalCgiAcl.GetSecurityDescriptorSddlForm([Security.AccessControl.AccessControlSections]::All)
 $script:originalCgiBinary=$script:originalCgiAcl.GetSecurityDescriptorBinaryForm()
 Write-G5AclEvidence 'original' $script:originalCgiAcl
 Assert-G5AclComparator $script:originalCgiBinary
 $deniedAcl=New-Object Security.AccessControl.FileSecurity
 $deniedAcl.SetSecurityDescriptorBinaryForm($script:originalCgiAcl.GetSecurityDescriptorBinaryForm())
 $script:injectedCgiSid=[Security.Principal.WindowsIdentity]::GetCurrent().User
 Assert-G5 ((Get-G5InjectedDenyCount $script:originalCgiAcl) -eq 0) 'Original CGI descriptor contains no instance of the injected ExecuteFile denial'
 $denyExecute=New-Object Security.AccessControl.FileSystemAccessRule($script:injectedCgiSid,[Security.AccessControl.FileSystemRights]::ExecuteFile,[Security.AccessControl.AccessControlType]::Deny)
 $deniedAcl.AddAccessRule($denyExecute)
 Write-G5AclEvidence 'deny-candidate' $deniedAcl
 Assert-G5 (!(Test-G5RestoredDescriptor $script:originalCgiBinary ($deniedAcl.GetSecurityDescriptorBinaryForm())) -and (Get-G5InjectedDenyCount $deniedAcl) -eq 1) 'ACL negative control rejects the remaining injected ExecuteFile denial'
 try{
  $script:cgiAclChanged=$true
  Set-Acl -LiteralPath $cgiPath -AclObject $deniedAcl -ErrorAction Stop
  Write-G5AclEvidence 'deny-applied' (Get-Acl -LiteralPath $cgiPath -ErrorAction Stop)
  $stillAlive=Invoke-G5 'health'
  Assert-G5 ([int]$stillAlive.pid -eq $cgi.Id -and (Test-G5Identity $cgi)) 'ExecuteFile fault injection does not terminate the already running owned CGI'
  Stop-G5Verified $cgi
  Wait-G5Exit $failedSpawnRun 25
  $failedSpawnLog=Read-G5RunLog
  $failedAttempts=[regex]::Matches($failedSpawnLog,'CGI restart attempt=([1-3])\b')
  $failedNumbers=($failedAttempts | ForEach-Object {$_.Groups[1].Value}) -join ','
  $launchFailures=[regex]::Matches($failedSpawnLog,'CGI launch failed generation=\d+ reason=replacement')
  Assert-G5 ($failedAttempts.Count -eq 3 -and $failedNumbers -ceq '1,2,3' -and $launchFailures.Count -eq 3) 'Three actual denied CGI launches consume the complete replacement budget'
  Assert-G5 ($failedSpawnLog -match 'CGI recovery exhausted' -and $failedSpawnLog -notmatch 'CGI ready pid=\d+ generation=\d+ reason=replacement') 'Failed spawns terminate without claiming a healthy replacement'
  Assert-G5 ((Test-G5Identity $sentinel) -and (Test-G5Listener $sentinelPort $sentinel.Id)) 'Foreign same-name sentinel survives failed-spawn exhaustion and cleanup'
  [IO.File]::WriteAllText((Join-Path $EvidenceDir 'failed-spawn-supervisor.log'),$failedSpawnLog,(New-Object Text.UTF8Encoding($false)))
 }finally{
  Restore-G5CgiAcl
 }
 Assert-G5 (!$script:cgiAclChanged) 'Original CGI owner/group, ordered permissions and protection are restored before the normal restart'
 $restart=Start-G5Launcher
 $health=Wait-G5Health;$cgi=Get-G5Child $cgiPath;$database=Get-G5Child $dbPath;$web=Get-G5Child $caddyPath
 $null=Assert-G7ALiveNetwork $web.Id
 $receipt=Invoke-G5 'read'
 Assert-G5 ($receipt.marker -ceq $suffix -and $receipt.writes -eq 1) 'A subsequent full restart preserves the original durable DB receipt exactly once'
 Assert-G5 ((Get-FileHash -LiteralPath $config -Algorithm SHA256).Hash -ceq $configHash -and (Get-FileHash -LiteralPath $mediaPath -Algorithm SHA256).Hash -ceq $mediaHash) 'A subsequent full restart preserves configuration and uploaded-media bytes'
 Assert-G5 ($health.maxRequests -ceq '0' -and $health.children -ceq '0') 'FastCGI lifetime policy also holds after a complete restart'
 $cleanup=Invoke-G5 'cleanup' 'POST';$seeded=$false
 Assert-G5 $cleanup.ok 'Disposable database fixture is removed through the live installed runtime'
 Stop-G5Verified $web
 Wait-G5Exit $restart 15
 Assert-G5 (Test-G5Identity $sentinel) 'Unrelated sentinel survives the final owned-process shutdown'
 $script:result='passed'
}catch{
 $script:failure=$_.Exception.Message
 throw
}finally{
 $aclRestoreFailure=$null
 try{Restore-G5CgiAcl}catch{
  $aclRestoreFailure=$_.Exception.Message;$script:result='failed';$script:failure='ACL restoration failed: '+$aclRestoreFailure
 }
 # Request cancellation is not replay. Only processes with the exact captured
 # PID/path/parent/creation identity may be terminated during emergency cleanup.
 try{$client.CancelPendingRequests();$shortClient.CancelPendingRequests()}catch{}
 if($seeded -and $null -ne $script:current){
  try{$script:current.Refresh();if(!$script:current.HasExited){Invoke-G5 'cleanup' 'POST' | Out-Null}}catch{}
 }
 foreach($process in $script:launchers){
  try{
   $process.Refresh()
   if(!$process.HasExited){
    $children=@(Get-CimInstance Win32_Process -Filter ("ParentProcessId = "+$process.Id) | Where-Object {$_.ExecutablePath -and $_.ExecutablePath.StartsWith($Installation+'\',[StringComparison]::OrdinalIgnoreCase)})
    foreach($row in $children){
     $identity=Get-G5Identity ([int]$row.ProcessId) $row.ExecutablePath $process.Id
     if(Test-G5Identity $identity){Stop-G5Verified $identity}
    }
    try{Wait-G5 {$process.Refresh();$process.HasExited} 8 'emergency supervisor exit'}catch{}
   }
  }catch{Write-Warning ('Bounded cleanup: '+$_.Exception.Message)}
 }
 foreach($identity in @($script:owned | Select-Object -Unique Id,Path,Parent,Created)){
  try{if(Test-G5Identity $identity){Stop-G5Verified $identity}}catch{Write-Warning ('Verified cleanup: '+$_.Exception.Message)}
 }
 foreach($operation in $script:pending){if(!$operation.Done){try{$operation.Request.Dispose()}catch{}}}
 $client.Dispose();$shortClient.Dispose()
 [Environment]::SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS',$savedMax,'Process')
 [Environment]::SetEnvironmentVariable('PHP_FCGI_CHILDREN',$savedChildren,'Process')
 if($fixtureReserved){
  foreach($file in @($probePath,$mediaPath,(Join-Path $fixturePath 'busy-started'),(Join-Path $fixturePath 'post-committed'))){
   if(Test-Path -LiteralPath $file -PathType Leaf){Remove-Item -LiteralPath $file -Force}
  }
  if(Test-Path -LiteralPath $fixturePath -PathType Container){Remove-Item -LiteralPath $fixturePath -Force}
 }
 $report=[ordered]@{result=$script:result;checks=$script:checks.Count;assertions=$script:checks;failure=$script:failure;launcher='Windows PowerShell 5.1';requests=600;busySeconds=15;processes=$script:owned}
 $report | ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $EvidenceDir 'homeserver-runtime-g5.json') -Encoding UTF8
 if($null -ne $aclRestoreFailure){throw ('Failed to restore isolated CGI permissions: '+$aclRestoreFailure)}
}
Write-Host ('WINDOWS_HOMESERVER_RUNTIME_G5_PASS ('+$script:checks.Count+' checks)')
