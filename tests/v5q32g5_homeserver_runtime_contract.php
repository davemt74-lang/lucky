<?php
declare(strict_types=1);
/** Source/wiring guardrails only. Native Windows behavior has its own CI gate. */
$checks=0;
function runtimeContract(bool $ok,string $name):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$name);
 ++$checks;echo 'PASS '.$name."\n";
}
function runtimeSource(string $relative):string {
 $source=file_get_contents(__DIR__.'/../'.$relative);
 if(!is_string($source))throw new RuntimeException('Missing runtime certification source '.$relative);
 return $source;
}
$launcher=runtimeSource('homeserver/start.ps1');
$code=preg_replace('/^\s*#.*$/m','',$launcher);
$test=runtimeSource('tests/homeserver_runtime_windows.ps1');
$workflow=runtimeSource('.github/workflows/windows-homeserver.yml');
$build=runtimeSource('homeserver/build-windows.ps1');
$installer=runtimeSource('homeserver/installer.iss');
runtimeContract(is_string($code),'Supervisor source is readable');
foreach(['PHP_FCGI_MAX_REQUESTS','PHP_FCGI_CHILDREN'] as $name){
 runtimeContract(str_contains($code,"[Environment]::GetEnvironmentVariable('".$name."','Process')"),$name.' inherits only through an explicitly captured process setting');
 runtimeContract(str_contains($code,"[Environment]::SetEnvironmentVariable('".$name."','0','Process')"),$name.' is explicitly disabled for the single worker spawn');
}
runtimeContract(str_contains($code,"SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS',\$previousMax,'Process')")&&
 str_contains($code,"SetEnvironmentVariable('PHP_FCGI_CHILDREN',\$previousChildren,'Process')"),'Both previous process settings are restored after child creation');
runtimeContract(!preg_match('/Start-Process[^\r\n]*\s-Environment\b/i',$code),'Supervisor does not require the PowerShell 7-only Start-Process environment parameter');
runtimeContract(str_contains($code,'if(!$script:phpProc.HasExited)')&&str_contains($code,"StartOwnedCgi 'replacement'"),
 'Replacement is explicitly gated on the prior owned CGI having exited');
runtimeContract(str_contains($code,'$null=$p.Handle')&&str_contains($code,'$proc.Kill()')&&str_contains($code,'$proc.WaitForExit(3000)'),
 'Cleanup retains native child handles and bounds its exit waits');
runtimeContract(!preg_match('/(?:Stop-Process|Get-Process)\s+-(?:Name|ProcessName)\b|\btaskkill\b/i',$code),
 'Supervisor has no executable-name sweep or taskkill fallback');
runtimeContract(str_contains($code,'Get-NetTCPConnection -State Listen -ErrorAction Stop')&&
 str_contains($code,'CGI port conflict port=9017 owner_pid=')&&str_contains($code,'$_.OwningProcess -ne $script:phpProc.Id'),
 'Listener ownership inspection fails closed and identifies conflicting PIDs');
runtimeContract(str_contains($code,"'System.Collections.Generic.Queue[double]'")&&str_contains($code,'$script:cgiClock.Elapsed.TotalSeconds'),
 'Restart accounting uses a monotonic clock and explicit history');
runtimeContract(str_contains($code,'$script:cgiRestarts.Peek() -le ($now-60)')&&str_contains($code,'$script:cgiRestarts.Count -ge 3')&&
 str_contains($code,'$delay=@(1000,2000,4000)[$attempt-1]'),'Source policy has three attempts per rolling 60 seconds and bounded backoff');
runtimeContract(str_contains($code,'CGI recovery exhausted attempts=3 window_seconds=60')&&str_contains($code,'exit 1'),
 'Exhausted recovery is explicit and has a nonzero terminal path');
$start=strpos($code,'function RecoverOwnedCgi{');$end=strpos($code,'$dbProc=Launch',$start===false?0:$start);
runtimeContract($start!==false&&$end!==false&&$end>$start,'Recovery function is independently identifiable');
$recovery=substr($code,$start,$end-$start);
runtimeContract(!str_contains($recovery,'Invoke-WebRequest')&&!str_contains($recovery,'Invoke-RestMethod')&&
 !str_contains($recovery,'initialize.php')&&!str_contains($recovery,'migrate.php')&&!str_contains($recovery,'$dbProc=Launch')&&
 !str_contains($recovery,'$caddyProc=Launch'),'CGI recovery neither uses slow HTTP health as a kill signal nor reruns foundation initialization');
runtimeContract(str_contains($code,'lb_retries 0')&&str_contains($code,'lb_try_duration 0'),
 'Generated gateway configuration disables automatic request retries');
runtimeContract(str_contains($code,"@('validate','--config',\$caddyfile,'--adapter','caddyfile')")&&
 str_contains($code,'$validation.WaitForExit(15000)')&&str_contains($code,'$validation.ExitCode -ne 0'),
 'Bundled Caddy validates generated configuration with a bounded native subprocess');
runtimeContract(str_contains($code,'[object]::ReferenceEquals($owned,$script:renderProc)){$script:renderProc=$null}')&&
 str_contains($code,'[object]::ReferenceEquals($owned,$script:phpProc)'),
 'Process pruning clears an exited render reference and retains the active CGI handle');
runtimeContract(str_contains($code,'$mutex.ReleaseMutex()')&&str_contains($code,'$mutex.Dispose()'),
 'Terminal cleanup retains the existing named-mutex release');
runtimeContract(str_contains($code,'max_execution_time=300')&&str_contains($code,'upload_max_filesize=512M')&&str_contains($code,'post_max_size=520M'),
 'Existing long-media-operation time and upload limits are not shortened');
runtimeContract(substr_count($code,"Join-Path \$app 'homeserver\\migrate.php'")===1&&
 substr_count($code,"Join-Path \$app 'homeserver\\initialize.php'")===1,'Full application initialization/migration remain startup-only call sites');

runtimeContract(str_contains($installer,'{sys}\\WindowsPowerShell\\v1.0\\powershell.exe')&&
 str_contains($test,"System32\\WindowsPowerShell\\v1.0\\powershell.exe"),'Native tests use the same Windows PowerShell family as installed shortcuts');
runtimeContract(str_contains($test,"$"."version -match '^5\\.1\\.'")&&str_contains($test,'Start-Process -FilePath $systemPowerShell'),
 'Runtime suite verifies PowerShell 5.1 and launches the actual installed supervisor');
runtimeContract(str_contains($test,"$"."env:GITHUB_ACTIONS -ne 'true'")&&str_contains($test,'outside RUNNER_TEMP'),
 'Runtime test refuses ordinary user installations and non-CI execution');
runtimeContract(str_contains($test,"getenv('GITHUB_ACTIONS')!=='true'")&&str_contains($test,"hash_equals('__TOKEN__'")&&
 str_contains($test,"['127.0.0.1','::1']"),'Disposable PHP probes require CI, a random token, and loopback before any operation');
runtimeContract(str_contains($test,"getenv('PHP_FCGI_MAX_REQUESTS')")&&str_contains($test,"getenv('PHP_FCGI_CHILDREN')")&&
 str_contains($test,"SetEnvironmentVariable('PHP_FCGI_MAX_REQUESTS','2','Process')")&&
 str_contains($test,"SetEnvironmentVariable('PHP_FCGI_CHILDREN','3','Process')"),'Native test observes actual child environment under conflicting inherited settings');
runtimeContract(str_contains($test,'$requestIndex -le 600')&&str_contains($test,"Invoke-G5 ('health&n='+\$requestIndex)")&&
 str_contains($test,'[int]$reply.pid -ne $cgi.Id'),'Longevity certification sends 600 real HTTP requests and checks every responding PID');
runtimeContract(str_contains($test,'sleep(15)')&&str_contains($test,'$shortClient.Timeout=[TimeSpan]::FromSeconds(1)')&&
 str_contains($test,'Complete-G5Request $busy $true'),'Busy-worker certification awaits a real 15-second response across short probe timeouts');
runtimeContract(str_contains($test,"Start-G5Request 'write-and-wait' 'POST'")&&str_contains($test,'$db->commit();')&&
 str_contains($test,"'/post-committed'")&&str_contains($test,'$receipt.writes -eq 1'),
 'Interrupted-write certification proves commit before worker death and checks one durable receipt');
runtimeContract(str_contains($test,'$row.ExecutablePath -ieq $Identity.Path')&&str_contains($test,'$Identity.Created')&&
 str_contains($test,'Stop-Process -Id $Identity.Id -Force'),'Fault injection checks PID, executable, parent, and creation identity before termination');
runtimeContract(str_contains($test,'Start-G5ForeignCgi $sentinelPort')&&str_contains($test,'Start-G5ForeignCgi 9017')&&
 str_contains($test,'Test-G5Listener 9017 $blocker.Id'),'Native suite protects same-name foreign controls on both a separate port and the actual CGI port');
runtimeContract(str_contains($test,"$"."attemptNumbers -ceq '1,2,3'")&&str_contains($test,'TotalSeconds -lt 55')&&
 str_contains($test,'Wait-G5Exit $run 15'),'Native suite proves the fixed recovery budget inside one real time window');
runtimeContract(str_contains($test,'$launchFailures.Count -eq 3')&&str_contains($test,'Wait-G5Exit $failedSpawnRun 25')&&
 str_contains($test,'CGI launch failed generation='),'A separate native phase proves failed process launches consume the same bounded budget');
runtimeContract(str_contains($test,'$script:originalCgiAcl=Get-Acl -LiteralPath $cgiPath')&&
 str_contains($test,'$deniedAcl.SetSecurityDescriptorBinaryForm($script:originalCgiAcl.GetSecurityDescriptorBinaryForm())')&&
 str_contains($test,'[Security.AccessControl.FileSystemRights]::ExecuteFile'),
 'Failed-spawn injection edits only an isolated executable ACL clone and preserves the untouched original descriptor');
runtimeContract(str_contains($test,'Set-Acl -LiteralPath $cgiPath -AclObject $script:originalCgiAcl')&&
 str_contains($test,'Test-G5RestoredDescriptor $script:originalCgiBinary')&&str_contains($test,'(Get-G5InjectedDenyCount $restored) -ne 0')&&substr_count($test,'Restore-G5CgiAcl')>=3&&
 str_contains($test,'Failed to restore isolated CGI permissions'),
 'Full permission restoration and injected-deny removal are checked, retried in emergency cleanup, and failure blocks certification');
runtimeContract(str_contains($test,'$actualFlags -ne ($expectedFlags -bor $autoInherited)')&&
 str_contains($test,'($expectedFlags -band $autoInherited) -ne 0')&&str_contains($test,'Get-G5DescriptorBytes $expectedRaw')&&
 str_contains($test,'Get-G5DescriptorBytes $actualRaw'),'Descriptor comparison permits only the observed one-way DACL auto-inheritance metadata addition');
runtimeContract(str_contains($test,'($expectedFlags -band $daclPresent) -eq 0')&&str_contains($test,'$null -eq $expectedRaw.DiscretionaryAcl')&&
 str_contains($test,'($expectedFlags -band $daclProtected) -ne 0'),'Metadata normalization is limited to an unprotected existing nonnull DACL');
foreach(['a different owner','a different group','changed access rights','changed ACE order','changed ACE inheritance','changed inheritance protection','any other descriptor control-flag change','the remaining injected ExecuteFile denial',
 'removal of existing auto-inheritance metadata','metadata addition to a protected DACL','metadata addition to a null DACL','metadata addition to an absent DACL'] as $case)
 runtimeContract(str_contains($test,'ACL negative control rejects '.$case),'Native descriptor negative control covers '.$case);
runtimeContract(str_contains($test,"Write-G5AclEvidence 'original'")&&str_contains($test,"Write-G5AclEvidence 'deny-applied'")&&
 str_contains($test,"Write-G5AclEvidence 'restore-after'")&&str_contains($test,'controlValue=[int]$raw.ControlFlags')&&
 str_contains($test,'cgi-acl-diagnostics.json'),'Exact ACL, owner/group, inheritance, control flags and ACE evidence survive any restoration mismatch');
runtimeContract(str_contains($test,'$exitCode -is [int] -and $exitCode -ne 0')&&str_contains($test,'$Process.WaitForExit(2000)')&&str_contains($test,'$remaining.Count -eq 0')&&
 str_contains($test,'Assert-G5MutexFree'),'Terminal certification checks failure status, no owned children, and mutex release');
runtimeContract(str_contains($test,'$restart=Start-G5Launcher')&&str_contains($test,'$configHash')&&str_contains($test,'$mediaHash')&&
 str_contains($test,'$receipt.marker -ceq $suffix'),'Subsequent restart checks durable DB/configuration/media preservation');
runtimeContract(str_contains($test,'if($fixtureReserved)')&&str_contains($test,'Remove-Item -LiteralPath $file -Force')&&
 str_contains($test,"Invoke-G5 'cleanup' 'POST'"),'Only reserved disposable probe/media identities are removed and fixture DB cleanup is explicit');
runtimeContract(str_contains($build,"'^(tests/|")&&!str_contains($build,'ci-hs-g5-'),'Probe/test endpoints are excluded from the product installer');

$nativeGate=strpos($workflow,'- name: Certify native worker longevity');
$parity=strpos($workflow,'CLOUD_HOMESERVER_APPLICATION_PARITY_PASS');
$smoke=strpos($workflow,'WINDOWS_OFFLINE_INSTALL_AND_RESTART_PASS');
$encoder=strpos($workflow,'WINDOWS_MULTICAMERA_MP4_CERTIFIED');
runtimeContract($nativeGate!==false&&$parity!==false&&$smoke!==false&&$encoder!==false&&
 $nativeGate>$parity&&$nativeGate>$smoke&&$nativeGate>$encoder,'Real Windows runtime gate follows source parity, clean/restart smoke, and installed encoder checks');
runtimeContract(str_contains($workflow,'.\\tests\\homeserver_runtime_windows.ps1 -Installation $installation -EvidenceDir $evidence')&&
 str_contains($workflow,"if(\$LASTEXITCODE -ne 0){throw 'Native HomeServer worker recovery certification failed'}"),
 'Windows workflow invokes the native test and treats failure as blocking');
runtimeContract(str_contains($workflow,"'Lucky HomeServer Installed'")&&str_contains($workflow,'Installed launcher differs from reviewed source'),
 'Installed-source verification includes the launcher and an installation path containing spaces');
runtimeContract(str_contains($workflow,'Preserve native worker certification evidence')&&str_contains($workflow,'if: always()')&&
 str_contains($test,'homeserver-runtime-g5.json'),'Native assertions and failure evidence remain available on unsuccessful runs');
echo 'HOMESERVER G5 SOURCE/WIRING CONTRACT: '.$checks.'/'.$checks."\n";
