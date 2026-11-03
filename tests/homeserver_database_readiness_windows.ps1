# Real native-process coverage of the installed readiness helpers. No database
# initialization, paid service, installed-config edits, or production bypasses.
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
 throw 'Database readiness certification requires isolated CI and actual Windows PowerShell 5.1.'
}
$Installation=(Resolve-Path -LiteralPath $Installation).Path
$runnerRoot=(Resolve-Path -LiteralPath $env:RUNNER_TEMP).Path.TrimEnd('\')+'\'
if(!$Installation.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)){throw 'Readiness certification refuses an installation outside RUNNER_TEMP.'}
$EvidenceDir=[IO.Path]::GetFullPath($EvidenceDir)
if(!$EvidenceDir.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)){throw 'Evidence must remain under RUNNER_TEMP.'}
New-Item -ItemType Directory -Force -Path $EvidenceDir | Out-Null
$launcher=Join-Path $Installation 'homeserver\start.ps1'
$php=Join-Path $Installation 'runtime\php\php.exe'
if(!(Test-Path -LiteralPath $php)){throw 'Installed bundled PHP is missing.'}
$fixtureParent=$runnerRoot.TrimEnd('\')
$fixture=Join-Path $fixtureParent ('lucky-db-readiness-'+[Guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $fixture | Out-Null
$scriptPath=Join-Path $fixture 'native probe fixture.php'
$token='PRIVATE-PROBE-'+[Guid]::NewGuid().ToString('N')
$script:checks=@();$script:owned=@();$result='failed';$failure=''
function Assert-DbReadiness([bool]$Condition,[string]$Name){
 if(!$Condition){throw ('FAIL '+$Name)}
 $script:checks+=@($Name);Write-Host ('PASS '+$Name)
}
function New-ProbeArguments([string]$Mode,[string]$Label){
 return @('-n',$scriptPath,$Mode,(Join-Path $fixture ($Label+'.count')),(Join-Path $fixture ($Label+'.pid')),$token)
}
function Start-OwnedFixture([string]$Mode,[string]$Label){
 $info=New-Object Diagnostics.ProcessStartInfo
 $info.FileName=$php;$info.UseShellExecute=$false;$info.CreateNoWindow=$true
 $info.Arguments='-n "'+$scriptPath+'" '+$Mode+' "'+(Join-Path $fixture ($Label+'.count'))+'" "'+(Join-Path $fixture ($Label+'.pid'))+'" '+$token
 $process=New-Object Diagnostics.Process;$process.StartInfo=$info
 try{
  if(!$process.Start()){throw 'Owned readiness fixture did not start.'}
  $null=$process.Handle;$script:owned+=@($process)
  return $process
 }catch{$process.Dispose();throw 'Owned readiness fixture did not start safely.'}
}
function Read-ProbeCount([string]$Label){
 $path=Join-Path $fixture ($Label+'.count')
 if(!(Test-Path -LiteralPath $path)){return 0}
 return [int][IO.File]::ReadAllText($path)
}
function Test-ProbeGone([string]$Label){
 $path=Join-Path $fixture ($Label+'.pid')
 if(!(Test-Path -LiteralPath $path)){throw 'Native probe did not record its identity.'}
 $probeId=[int][IO.File]::ReadAllText($path)
 $process=$null
 try{$process=[Diagnostics.Process]::GetProcessById($probeId);return $process.HasExited}
 catch [ArgumentException]{return $true}
 finally{if($null -ne $process){$process.Dispose()}}
}
try{
 $tokens=$null;$errors=$null
 $ast=[Management.Automation.Language.Parser]::ParseFile($launcher,[ref]$tokens,[ref]$errors)
 Assert-DbReadiness ($errors.Count -eq 0) 'Installed launcher parses under Windows PowerShell 5.1'
 $definitions=@()
 foreach($name in @('Invoke-HomeServerDatabaseProbe','Wait-HomeServerDatabase')){
  $nodes=@($ast.FindAll({param($node) $node -is [Management.Automation.Language.FunctionDefinitionAst] -and $node.Name -ceq $name},$true))
  Assert-DbReadiness ($nodes.Count -eq 1) ('Extract exactly one installed production helper '+$name)
  $definitions+=@($nodes[0].Extent.Text)
 }
 . ([scriptblock]::Create(($definitions -join "`n")))
 $fixturePhp=@'
<?php
declare(strict_types=1);
if (getenv('GITHUB_ACTIONS') !== 'true' || count($argv) !== 5) { exit(97); }
[$unused, $mode, $counter, $pid, $private] = $argv;
file_put_contents($pid, (string)getmypid());
$count = is_file($counter) ? (int)file_get_contents($counter) + 1 : 1;
file_put_contents($counter, (string)$count);
if ($mode === 'hold') { sleep(180); exit(0); }
if ($mode === 'exit') { exit(0); }
if ($mode === 'hang') { fwrite(STDERR, $private); sleep(30); exit(0); }
if ($mode === 'flood-success') {
    fwrite(STDERR, str_repeat($private, 4096));
    fwrite(STDOUT, str_repeat($private, 4096));
    exit(0);
}
fwrite(STDOUT, $private);
fwrite(STDERR, $private);
if ($mode === 'refuse-then-ready') { exit($count < 3 ? 1 : 0); }
if ($mode === 'success') { exit(0); }
exit(1);
'@
 # -n prevents reliance on the application's php.ini, extensions, setup or DB.
 [IO.File]::WriteAllText($scriptPath,$fixturePhp,(New-Object Text.UTF8Encoding($false)))
 $database=Start-OwnedFixture 'hold' 'database'
 $sentinel=Start-OwnedFixture 'hold' 'unrelated'
 $legacyArguments=New-ProbeArguments 'refuse' 'legacy'
 $legacyFailed=$false
 try{& $php @legacyArguments 2>$null | Out-Null}catch{$legacyFailed=$true}
 Assert-DbReadiness ($legacyFailed -and (Read-ProbeCount 'legacy') -eq 1) 'Negative control reproduces terminating native stderr in the original PowerShell pipeline'
 $captured=@(Invoke-HomeServerDatabaseProbe $php (New-ProbeArguments 'refuse' 'nonzero') 2>&1)
 Assert-DbReadiness ($captured.Count -eq 1 -and $captured[0] -is [bool] -and !$captured[0]) 'Nonzero native stderr is a false readiness result, not a terminating PowerShell error'
 Assert-DbReadiness (!(($captured | Out-String).Contains($token))) 'Probe stdout, stderr and private argument markers do not escape the helper'
 Assert-DbReadiness (Test-ProbeGone 'nonzero') 'Exited nonzero readiness client is not left running'
 $captured=@(Invoke-HomeServerDatabaseProbe $php (New-ProbeArguments 'flood-success' 'stderr-success') 2>&1)
 Assert-DbReadiness ($captured.Count -eq 1 -and $captured[0] -is [bool] -and $captured[0]) 'Exit zero alone establishes readiness even with both native pipes larger than their buffers'
 Assert-DbReadiness (!(($captured | Out-String).Contains($token))) 'Successful stderr and stdout are drained privately without leaking the argument marker'
 Assert-DbReadiness (Test-ProbeGone 'stderr-success') 'Successful native client has exited before the helper returns'
 Wait-HomeServerDatabase $database $php (New-ProbeArguments 'refuse-then-ready' 'retry')
 Assert-DbReadiness ((Read-ProbeCount 'retry') -eq 3) 'Actual startup loop retries two real stderr refusals and accepts the third successful probe'
 Assert-DbReadiness (!$database.HasExited -and !$sentinel.HasExited) 'Readiness retries preserve the live database fixture and unrelated process'
 $clock=[Diagnostics.Stopwatch]::StartNew()
 $timedOut=Invoke-HomeServerDatabaseProbe $php (New-ProbeArguments 'hang' 'timeout')
 $clock.Stop()
 Assert-DbReadiness (!$timedOut -and $clock.Elapsed.TotalSeconds -ge 2.5 -and $clock.Elapsed.TotalSeconds -lt 10) 'Hung native client fails within the fixed bounded probe budget'
 Assert-DbReadiness (Test-ProbeGone 'timeout') 'Timeout cleanup terminates only the exact owned readiness client'
 Assert-DbReadiness (!$database.HasExited -and !$sentinel.HasExited) 'Client timeout leaves the live database and unrelated process untouched'
 $launchError=''
 try{Invoke-HomeServerDatabaseProbe (Join-Path $fixture ('missing-'+$token+'.exe')) @($token) | Out-Null}catch{$launchError=$_.Exception.Message}
 Assert-DbReadiness ($launchError -ceq 'Local database readiness probe could not complete safely.' -and !$launchError.Contains($token)) 'Native launch uncertainty fails closed without exposing private paths or arguments'
 $dead=Start-OwnedFixture 'exit' 'dead'
 Assert-DbReadiness ($dead.WaitForExit(5000)) 'Dead-database negative control really exits before readiness'
 $deadError=''
 try{Wait-HomeServerDatabase $dead $php (New-ProbeArguments 'success' 'should-not-run')}catch{$deadError=$_.Exception.Message}
 Assert-DbReadiness ($deadError -ceq 'Local MariaDB exited during startup.' -and (Read-ProbeCount 'should-not-run') -eq 0) 'A dead owned database fails before launching or accepting any readiness probe'
 $clock.Restart();$budgetError=''
 try{Wait-HomeServerDatabase $database $php (New-ProbeArguments 'refuse' 'budget')}catch{$budgetError=$_.Exception.Message}
 $clock.Stop()
 Assert-DbReadiness ($budgetError -ceq 'Local MariaDB did not become ready.' -and (Read-ProbeCount 'budget') -eq 75) 'Actual startup policy stops after exactly75 failed native probes'
 Assert-DbReadiness ($clock.Elapsed.TotalSeconds -ge 37 -and $clock.Elapsed.TotalSeconds -lt 100) 'The75-attempt policy retains its500ms interval and a bounded measured runtime'
 Assert-DbReadiness (Test-ProbeGone 'budget') 'Exhausted startup budget leaves no last readiness client running'
 Assert-DbReadiness (!$database.HasExited -and !$sentinel.HasExited) 'Exhaustion never kills the live database fixture or unrelated process'
 Assert-DbReadiness ($ErrorActionPreference -ceq 'Stop') 'Native handling never weakens the caller error-action policy'
 $result='passed'
 Write-Host ('HOMESERVER DATABASE READINESS PASSED: '+$script:checks.Count+' checks')
}catch{
 # Fixture failures report assertions/types only, never native output or tokens.
 if($_.Exception.Message.StartsWith('FAIL ')){$failure=$_.Exception.Message}else{$failure=$_.Exception.GetType().FullName}
 throw [InvalidOperationException]::new($failure)
}finally{
 try{
  $cleanupFailed=$false
  foreach($owned in $script:owned){
   try{if(!$owned.HasExited){$owned.Kill();if(!$owned.WaitForExit(3000)){throw 'Owned fixture cleanup timed out.'}}}
   catch{$cleanupFailed=$true}
   finally{$owned.Dispose()}
  }
  if($cleanupFailed){$result='failed';$failure='Owned readiness fixture cleanup failed.'}
  [ordered]@{result=$result;checks=$script:checks;failure=$failure;launcherSha256=(Get-FileHash -LiteralPath $launcher -Algorithm SHA256).Hash} |
   ConvertTo-Json -Depth 5 | Set-Content -LiteralPath (Join-Path $EvidenceDir 'homeserver-database-readiness.json') -Encoding UTF8
  if($cleanupFailed){throw $failure}
 }finally{
  if(![string]::Equals([IO.Path]::GetDirectoryName($fixture),$fixtureParent,[StringComparison]::OrdinalIgnoreCase) -or [IO.Path]::GetFileName($fixture) -notmatch '^lucky-db-readiness-[a-f0-9]{32}$'){
   throw 'Refusing cleanup outside the exact owned readiness fixture.'
  }
  foreach($entry in Get-ChildItem -LiteralPath $fixture -Force){
   if($entry.PSIsContainer -or ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint)){throw 'Unexpected readiness fixture path; refusing recursive cleanup.'}
   [IO.File]::Delete($entry.FullName)
  }
  [IO.Directory]::Delete($fixture)
 }
}
