# Deterministic tests of the actual installed launcher helpers, not a rewritten
# policy model. The full launcher/socket lifecycle is certified by the existing
# homeserver_runtime_windows.ps1 gate. No user network/firewall/trust changes.
[CmdletBinding()]
param(
 [Parameter(Mandatory=$true)][string]$Installation,
 [Parameter(Mandatory=$true)][string]$EvidenceDir
)
Set-StrictMode -Version 2.0
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
if($env:OS -ne 'Windows_NT' -or $env:GITHUB_ACTIONS -ne 'true' -or
   $PSVersionTable.PSVersion.Major -ne 5 -or $PSVersionTable.PSVersion.Minor -ne 1){
 throw 'Network helper certification requires isolated CI and actual Windows PowerShell 5.1.'
}
$Installation=(Resolve-Path -LiteralPath $Installation).Path
$runnerRoot=(Resolve-Path -LiteralPath $env:RUNNER_TEMP).Path.TrimEnd('\')+'\'
if(!$Installation.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)){
 throw 'Network certification refuses an installation outside RUNNER_TEMP.'
}
$EvidenceDir=[IO.Path]::GetFullPath($EvidenceDir)
if(!$EvidenceDir.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)){throw 'Evidence must remain under RUNNER_TEMP.'}
New-Item -ItemType Directory -Force -Path $EvidenceDir | Out-Null
$launcher=Join-Path $Installation 'homeserver\start.ps1'
$builder=Join-Path $Installation 'app\homeserver\build-windows.ps1'
$caddy=Join-Path $Installation 'runtime\caddy.exe'
$fixtureParent=$runnerRoot.TrimEnd('\')
$fixture=Join-Path $fixtureParent ('lucky-g7a-network-'+[Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture | Out-Null
$script:checks=@();$script:publicationSnapshots=@();$result='failed';$failure=''
function Assert-G7A([bool]$Condition,[string]$Name){
 if(!$Condition){throw ('FAIL '+$Name)}
 $script:checks+=@($Name);Write-Host ('PASS '+$Name)
}
function Reject-G7A([scriptblock]$Operation,[string]$Name){
 $rejected=$false;try{& $Operation}catch{$rejected=$true};Assert-G7A $rejected $Name
}
function Snapshot-G7APublication([string]$Phase,[string]$Path){
 $acl=Get-Acl -LiteralPath $Path
 $owner=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
 $rules=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
 $snapshot=[ordered]@{phase=$Phase;utc=[DateTime]::UtcNow.ToString('o');
  modifiedUtc=[IO.File]::GetLastWriteTimeUtc($Path).ToString('o');
  protected=$acl.AreAccessRulesProtected;ownerMatchesCurrent=($acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ceq $owner);
  inheritedRules=@($rules | Where-Object {$_.IsInherited}).Count;
  unexpectedRules=@($rules | Where-Object {$_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or $_.IdentityReference.Value -notin @($owner,'S-1-5-18')}).Count}
 $script:publicationSnapshots+=@($snapshot)
 Write-Host ('NETWORK_HELPER_SNAPSHOT '+($snapshot | ConvertTo-Json -Compress))
}
function Assert-G7APrivatePublication([string]$Path,[string]$Label){
 $acl=Get-Acl -LiteralPath $Path;$owner=[Security.Principal.WindowsIdentity]::GetCurrent().User.Value
 $rules=@($acl.GetAccessRules($true,$true,[Security.Principal.SecurityIdentifier]))
 Assert-G7A ($acl.AreAccessRulesProtected -and $acl.GetOwner([Security.Principal.SecurityIdentifier]).Value -ceq $owner -and
  @($rules | Where-Object {$_.IsInherited -or $_.AccessControlType -ne [Security.AccessControl.AccessControlType]::Allow -or $_.IdentityReference.Value -notin @($owner,'S-1-5-18')}).Count -eq 0) $Label
}
function Address-G7A([string]$Address,[string]$Alias='Ethernet',[string]$State='Preferred',[int]$Index=4){
 return [pscustomobject]@{IPAddress=$Address;InterfaceAlias=$Alias;AddressState=$State;InterfaceIndex=$Index}
}
function Listener-G7A([int]$Port,[string]$Address,[int]$Owner=125){
 return [pscustomobject]@{LocalPort=$Port;LocalAddress=$Address;OwningProcess=$Owner}
}
function Validate-G7AConfig([string]$Path,[string]$Label){
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName=$caddy;$info.Arguments='validate --config "'+$Path+'" --adapter caddyfile'
 $info.UseShellExecute=$false;$info.CreateNoWindow=$true
 $info.RedirectStandardOutput=$true;$info.RedirectStandardError=$true
 $info.EnvironmentVariables['XDG_DATA_HOME']=Join-Path $fixture ('caddy-data-'+$Label)
 $info.EnvironmentVariables['XDG_CONFIG_HOME']=Join-Path $fixture ('caddy-config-'+$Label)
 $process=New-Object Diagnostics.Process
 $process.StartInfo=$info
 try{
  if(!$process.Start()){throw 'Isolated Caddy validation failed to start.'}
  $stdout=$process.StandardOutput.ReadToEndAsync();$stderr=$process.StandardError.ReadToEndAsync()
  if(!$process.WaitForExit(15000)){$process.Kill();$null=$process.WaitForExit(3000);throw 'Bounded isolated Caddy validation timed out.'}
  $text=$stdout.GetAwaiter().GetResult()+$stderr.GetAwaiter().GetResult()
  [IO.File]::WriteAllText((Join-Path $EvidenceDir ('network-caddy-'+$Label+'.log')),$text)
  Assert-G7A ($process.ExitCode -eq 0) ('Bundled Caddy validates actual '+$Label+' generated configuration')
 }finally{try{if(!$process.HasExited){$process.Kill();$null=$process.WaitForExit(3000)}}catch{};$process.Dispose()}
}
try{
 $tokens=$null;$errors=$null
 $ast=[Management.Automation.Language.Parser]::ParseFile($launcher,[ref]$tokens,[ref]$errors)
 Assert-G7A ($errors.Count -eq 0) 'Installed launcher parses under actual Windows PowerShell 5.1'
 $names=@('Test-HomeServerPrivateIPv4','Select-HomeServerLanInterface','New-HomeServerCaddyConfiguration',
  'Get-HomeServerNetworkEntry','Remove-HomeServerNetworkPublication','Write-HomeServerNetworkPublication',
  'Test-HomeServerLanListeners','Test-HomeServerLanReady')
 $definitions=@()
 foreach($name in $names){
  $nodes=@($ast.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq $name},$true))
  Assert-G7A ($nodes.Count -eq 1) ('Extract exactly one production helper '+$name)
  $definitions+=@($nodes[0].Extent.Text)
 }
 . ([scriptblock]::Create(($definitions -join "`n")))
 # Parse and extract only the production path guard. Executing the installed
 # builder itself would download runtimes and is deliberately not part of this
 # isolated native test. The workflow already checks installed source parity.
 $buildTokens=$null;$buildErrors=$null
 $buildAst=[Management.Automation.Language.Parser]::ParseFile($builder,[ref]$buildTokens,[ref]$buildErrors)
 Assert-G7A ($buildErrors.Count -eq 0) 'Installed packaging source parses under Windows PowerShell 5.1'
 $guard=@($buildAst.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq 'Assert-HomeServerPackageDataBoundary'},$true))
 Assert-G7A ($guard.Count -eq 1) 'Extract exactly one actual production packaging data-boundary helper'
 . ([scriptblock]::Create($guard[0].Extent.Text))
 foreach($path in @('storage/config.php','Storage/config.php','STORAGE/homeserver-network.json',
  'storage/homeserver-network.json','storage','Storage/.htaccess','storage/INDEX.HTML',
  'storage/Claim-Media/.htaccess','storage/unrecognized/index.html','storage/.setup-attempt.json',
  '.env','.ENV.PRODUCTION','nested/.Env.local','nested/.env/private.key')){
  Reject-G7A {Assert-HomeServerPackageDataBoundary $path} ('Private/mixed-case packaged path is rejected: '+$path)
 }
 foreach($prefix in @('storage/','storage/claim-media/','storage/prize-videos/','storage/renders/')){
  foreach($leaf in @('.htaccess','index.html')){
   Assert-HomeServerPackageDataBoundary ($prefix+$leaf)
   Assert-G7A $true ('Exact canonical public placeholder is allowed: '+$prefix+$leaf)
  }
 }
 foreach($path in @('lib/InstallationUrls.php','public/installation-urls.js','homeserver/start.ps1',
  'storage-example/config.php','public/.environment.css')){
  Assert-HomeServerPackageDataBoundary $path
  Assert-G7A $true ('Unrelated source path is not mistaken for private runtime data: '+$path)
 }
 foreach($address in @('10.0.0.1','10.255.255.254','172.16.0.1','172.31.255.254','192.168.0.1','192.168.255.254')){
  Assert-G7A (Test-HomeServerPrivateIPv4 $address) ('Canonical RFC1918 address accepted: '+$address)
 }
 foreach($address in @('','127.0.0.1','169.254.1.1','0.0.0.0','8.8.8.8','172.15.1.1','172.32.0.1',
  '192.169.1.1','100.64.0.1','224.0.0.1','255.255.255.255','10.0.0.256','10.0.1','010.0.0.1',
  '10.00.0.1','0x0a000001','::1','::ffff:10.0.0.1','10.0.0.1:8843','10.0.0.1/24',' 10.0.0.1',"10.0.0.1`n")){
  Assert-G7A (!(Test-HomeServerPrivateIPv4 $address)) ('Unsafe/noncanonical address rejected: '+$address.Replace("`n",'<LF>'))
 }
 $addresses=@((Address-G7A '8.8.8.8'),(Address-G7A '10.2.3.4' 'Virtual Ethernet'),
  (Address-G7A '192.168.20.4' 'Wi-Fi' 'Tentative'),(Address-G7A '172.20.3.4' 'Ethernet' 'Preferred' 8),
  (Address-G7A '192.168.20.5' 'Wi-Fi' 'Preferred' 9))
 $selected=Select-HomeServerLanInterface $addresses
 Assert-G7A ($selected.IPAddress -ceq '172.20.3.4' -and $selected.InterfaceIndex -eq 8) 'First eligible real private adapter is selected without accepting public/virtual/tentative addresses'
 Assert-G7A ($null -eq (Select-HomeServerLanInterface @())) 'Missing LAN produces no candidate rather than breaking local gameplay'
 Assert-G7A ($null -eq (Select-HomeServerLanInterface @((Address-G7A '10.0.0.1' 'VPN'),(Address-G7A '192.168.1.1' 'Wi-Fi' 'Preferred' 0)))) 'Excluded and invalid interface identities are not advertised'
 $lan='192.168.20.5';$assigned=@((Address-G7A $lan 'Wi-Fi' 'Preferred' 9))
 $listeners=@((Listener-G7A 8833 '127.0.0.1'),(Listener-G7A 8843 $lan))
 Assert-G7A (Test-HomeServerLanListeners $lan 9 125 $assigned $listeners) 'Production listener policy accepts fixture inputs with the selected interface and exact owned sockets'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 8 125 $assigned $listeners)) 'Same address on a different interface cannot renew the selected interface lease'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 9 125 @() $listeners)) 'Removed LAN interface cannot renew a stale origin'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 9 999 $assigned $listeners)) 'Unrelated listener PID cannot be adopted for publication'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 9 125 $assigned @((Listener-G7A 8833 '0.0.0.0'),(Listener-G7A 8843 $lan)))) 'Wildcard desktop exposure fails the listener gate'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 9 125 $assigned @((Listener-G7A 8833 '127.0.0.1'),(Listener-G7A 8843 '0.0.0.0')))) 'Wildcard LAN binding fails the listener gate'
 Assert-G7A (!(Test-HomeServerLanListeners $lan 9 125 $assigned @((Listener-G7A 8833 '127.0.0.1')))) 'A working desktop socket alone cannot advertise an absent LAN socket'
 Assert-G7A (!(Test-HomeServerLanReady $lan 9 $null)) 'Missing owned Caddy process fails closed without a network probe'
 $webRoot=(Join-Path $Installation 'app').Replace('\','/')
 $withLan=New-HomeServerCaddyConfiguration $webRoot $lan
 $withoutLan=New-HomeServerCaddyConfiguration $webRoot ''
 Assert-G7A ($withLan.Contains("http://127.0.0.1:8833 {`n  bind 127.0.0.1") -and $withLan.Contains("https://$($lan):8843 {`n  bind $lan")) 'Generated sites explicitly bind loopback desktop and selected private LAN addresses'
 Assert-G7A ($withLan.Contains('tls internal') -and $withLan.Contains('skip_install_trust') -and $withLan.Contains('@setup path /first-admin.php') -and $withLan.Contains('respond @setup 403')) 'Local TLS retains manual trust and blocks first-admin on the LAN site'
 Assert-G7A ($withLan.Contains('lb_retries 0') -and $withLan.Contains('lb_try_duration 0') -and $withLan.Contains('@private path /storage/*')) 'No-replay and private-storage protections survive the networking refactor'
 Assert-G7A ($withoutLan.Contains('bind 127.0.0.1') -and !$withoutLan.Contains(':8843') -and !$withoutLan.Contains('tls internal')) 'No-LAN configuration still serves the local console without a LAN TLS site'
 Reject-G7A {New-HomeServerCaddyConfiguration $webRoot '8.8.8.8' | Out-Null} 'Public address cannot enter generated Caddy configuration'
 Reject-G7A {New-HomeServerCaddyConfiguration "C:/invalid`nroot" $lan | Out-Null} 'Newline injection into generated web root is rejected'
 foreach($entry in @(@{label='lan';value=$withLan},@{label='local-only';value=$withoutLan})){
  $path=Join-Path $fixture ('Caddyfile-'+$entry.label)
  [IO.File]::WriteAllText($path,$entry.value,(New-Object Text.UTF8Encoding($false)))
  Validate-G7AConfig $path $entry.label
 }
 $private=Join-Path $fixture 'storage';New-Item -ItemType Directory -Path $private | Out-Null
 $target=Join-Path $private 'homeserver-network.json'
 $sentinel=Join-Path $private 'config.php';[IO.File]::WriteAllText($sentinel,'unrelated configuration bytes')
 Write-HomeServerNetworkPublication $private $lan
 Snapshot-G7APublication 'initial-create' $target
 $raw=[IO.File]::ReadAllBytes($target);$metadata=[IO.File]::ReadAllText($target) | ConvertFrom-Json
 Assert-G7A ($metadata.version -eq 1 -and $metadata.origin -ceq ('https://'+$lan+':8843') -and @($metadata.PSObject.Properties).Count -eq 2) 'Private publication contains exactly the agreed version/origin schema'
 Assert-G7A ($raw.Length -lt 8192 -and !($raw[0] -eq 239 -and $raw[1] -eq 187 -and $raw[2] -eq 191)) 'Publication is bounded UTF-8 without a BOM'
 Assert-G7APrivatePublication $target 'Native metadata ACL is private to the launcher identity and SYSTEM from creation'
 [IO.File]::SetLastWriteTimeUtc($target,[DateTime]::UtcNow.AddMinutes(-2))
 Snapshot-G7APublication 'different-origin-before-replace' $target
 Write-HomeServerNetworkPublication $private '10.20.30.40'
 Snapshot-G7APublication 'different-origin-after-replace' $target
 $renewed=[IO.File]::ReadAllText($target) | ConvertFrom-Json
 Assert-G7A ($renewed.origin -ceq 'https://10.20.30.40:8843' -and ([DateTime]::UtcNow-[IO.File]::GetLastWriteTimeUtc($target)).TotalSeconds -lt 5) 'Atomic native renewal changes the origin and advances the freshness lease'
 Assert-G7APrivatePublication $target 'Atomic native replacement preserves the private owner, protection and access rules'
 foreach($round in @(1,2,3)){
  $sameBytes=[IO.File]::ReadAllText($target)
  [IO.File]::SetLastWriteTimeUtc($target,[DateTime]::UtcNow.AddMinutes(-2))
  Snapshot-G7APublication ('same-origin-'+$round+'-before') $target
  Write-HomeServerNetworkPublication $private '10.20.30.40'
  Snapshot-G7APublication ('same-origin-'+$round+'-after') $target
  Assert-G7A ([IO.File]::ReadAllText($target) -ceq $sameBytes -and
   ([DateTime]::UtcNow-[IO.File]::GetLastWriteTimeUtc($target)).TotalSeconds -lt 5) ('Same-origin atomic renewal '+$round+' advances the lease without changing bytes')
  Assert-G7APrivatePublication $target ('Same-origin atomic renewal '+$round+' preserves its private ACL for the next heartbeat')
 }
 # Model the observed Windows PowerShell 5.1 Security-module autoload failure
 # in this child scope only. The same unchanged production writer must renew
 # using the native descriptor API without resolving the unavailable cmdlet.
 & {
  function Get-Acl {
   [CmdletBinding()]param([string]$LiteralPath)
   throw [System.Management.Automation.CommandNotFoundException]::new('Isolated Get-Acl autoload failure fixture.')
  }
  $unavailable=$false
  try{Get-Acl -LiteralPath $target -ErrorAction Stop | Out-Null}
  catch [System.Management.Automation.CommandNotFoundException]{$unavailable=$true}
  Assert-G7A $unavailable 'Negative control reproduces an unavailable Get-Acl command before renewal'
  $sameBytes=[IO.File]::ReadAllText($target)
  [IO.File]::SetLastWriteTimeUtc($target,[DateTime]::UtcNow.AddMinutes(-2))
  Write-HomeServerNetworkPublication $private '10.20.30.40'
  Assert-G7A ([IO.File]::ReadAllText($target) -ceq $sameBytes -and
   ([DateTime]::UtcNow-[IO.File]::GetLastWriteTimeUtc($target)).TotalSeconds -lt 5) 'Atomic renewal succeeds with the Security-module cmdlet unavailable'
 }
 Snapshot-G7APublication 'unavailable-get-acl-after-renewal' $target
 Assert-G7APrivatePublication $target 'Direct native ACL access preserves exact owner, protection and rules after cmdlet-independent renewal'
 Assert-G7A (@(Get-ChildItem -LiteralPath $private -Filter 'homeserver-network-*.tmp').Count -eq 0) 'Successful atomic publication leaves no temporary stages'
 $saved=[IO.File]::ReadAllText($target)
 Reject-G7A {Write-HomeServerNetworkPublication $private '127.0.0.1'} 'Loopback address cannot be published as a phone origin'
 Assert-G7A ([IO.File]::ReadAllText($target) -ceq $saved) 'Rejected publication leaves the prior valid file unchanged'
 Remove-HomeServerNetworkPublication $private
 Remove-HomeServerNetworkPublication $private
 Assert-G7A (!(Test-Path -LiteralPath $target) -and [IO.File]::ReadAllText($sentinel) -ceq 'unrelated configuration bytes') 'Repeated withdrawal removes only the reserved publication, never configuration'
 New-Item -ItemType Directory -Path $target | Out-Null
 Reject-G7A {Write-HomeServerNetworkPublication $private $lan} 'A directory in the metadata slot is never replaced'
 Reject-G7A {Remove-HomeServerNetworkPublication $private} 'Withdrawal refuses recursive removal of an unexpected directory'
 [IO.Directory]::Delete($target)
 $outside=Join-Path $fixture 'unrelated';New-Item -ItemType Directory -Path $outside | Out-Null
 $outsideFile=Join-Path $outside 'keep.txt';[IO.File]::WriteAllText($outsideFile,'outside data survives')
 New-Item -ItemType Junction -Path $target -Target $outside | Out-Null
 try{
  Reject-G7A {Write-HomeServerNetworkPublication $private $lan} 'A reparse/junction metadata slot is never followed for publication'
  Reject-G7A {Remove-HomeServerNetworkPublication $private} 'Withdrawal refuses to traverse a reparse metadata slot'
  Assert-G7A ([IO.File]::ReadAllText($outsideFile) -ceq 'outside data survives') 'Reparse refusal preserves its external target data'
 }finally{[IO.Directory]::Delete($target)}
 Write-HomeServerNetworkPublication $private $lan;Remove-HomeServerNetworkPublication $private
 Assert-G7A ([IO.File]::ReadAllText($sentinel) -ceq 'unrelated configuration bytes') 'Normal publication recovers after a rejected metadata path without touching app configuration'
 $result='passed'
 Write-Host ('HOMESERVER G7A NETWORK HELPERS PASSED: '+$script:checks.Count+' checks')
}catch{$failure=$_.Exception.Message;throw}
finally{
 # Only this uniquely-created fixture. Reparse entries are removed as links;
 # never use a recursive delete that can traverse the test's junction target.
 function Remove-G7AFixture([string]$Path){
  $item=Get-Item -LiteralPath $Path -Force -ErrorAction SilentlyContinue
  if($null -eq $item){return}
  if($item.Attributes -band [IO.FileAttributes]::ReparsePoint){
   if($item.PSIsContainer){[IO.Directory]::Delete($Path)}else{[IO.File]::Delete($Path)};return
  }
  if(!$item.PSIsContainer){[IO.File]::Delete($Path);return}
  foreach($child in Get-ChildItem -LiteralPath $Path -Force){Remove-G7AFixture $child.FullName}
  [IO.Directory]::Delete($Path)
 }
 try{
  [ordered]@{result=$result;checks=$script:checks;publicationSnapshots=$script:publicationSnapshots;failure=$failure;launcherSha256=(Get-FileHash -LiteralPath $launcher -Algorithm SHA256).Hash} |
   ConvertTo-Json -Depth 8 | Set-Content -LiteralPath (Join-Path $EvidenceDir 'homeserver-network-g7a.json') -Encoding UTF8
 }finally{
  if(![string]::Equals([IO.Path]::GetDirectoryName($fixture),$fixtureParent,[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($fixture) -notmatch '^lucky-g7a-network-[a-f0-9]{32}$'){
   throw 'Refusing cleanup outside the exact owned network fixture.'
  }
  Remove-G7AFixture $fixture
 }
}
