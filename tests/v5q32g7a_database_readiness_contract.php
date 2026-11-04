<?php
declare(strict_types=1);
/** Source/wiring guardrails only; the installed Windows 5.1 gate proves native behavior. */
$checks=0;
function readinessCheck(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo 'PASS '.$label."\n";
}
function readinessSource(string $relative):string {
 $source=file_get_contents(__DIR__.'/../'.$relative);
 if(!is_string($source))throw new RuntimeException('Missing database-readiness certification source '.$relative);
 return $source;
}
function readinessCode(string $source):string {
 $code=preg_replace('/^\h*#.*$/m','',$source);
 if(!is_string($code))throw new RuntimeException('Cannot inspect readiness source.');
 return $code;
}
function readinessFunction(string $source,string $name):string {
 $declaration='/^function\h+'.preg_quote($name,'/').'\b/m';
 readinessCheck(preg_match_all($declaration,$source)===1,'Exactly one production '.$name.' helper is present');
 // These two top-level helpers have indented inner blocks, not here-strings.
 // Native CI additionally extracts their real PowerShell AST definitions.
 $pattern='/^function\h+'.preg_quote($name,'/').'\b[^\r\n]*\{\R.*?^\}/ms';
 if(preg_match($pattern,$source,$match)!==1)throw new RuntimeException('Cannot isolate readiness helper '.$name);
 return $match[0];
}
function readinessBefore(string $source,string $first,string $second):bool {
 $a=strpos($source,$first);$b=strpos($source,$second);
 return $a!==false&&$b!==false&&$a<$b;
}

$launcher=readinessCode(readinessSource('homeserver/start.ps1'));
$probe=readinessFunction($launcher,'Invoke-HomeServerDatabaseProbe');
$wait=readinessFunction($launcher,'Wait-HomeServerDatabase');
$native=readinessCode(readinessSource('tests/homeserver_database_readiness_windows.ps1'));
$workflow=readinessCode(readinessSource('.github/workflows/windows-homeserver.yml'));

readinessCheck(str_contains($probe,'[string]$Binary,[string[]]$Arguments')&&
 str_contains($probe,'New-Object Diagnostics.ProcessStartInfo')&&str_contains($probe,'$info.FileName=$Binary'),
 'Probe launches the supplied bundled client through an explicit process start record');
readinessCheck(str_contains($probe,'$info.UseShellExecute=$false')&&str_contains($probe,'$info.CreateNoWindow=$true'),
 'Probe uses no shell and creates no interactive client window');
readinessCheck(str_contains($probe,'$info.RedirectStandardOutput=$true')&&str_contains($probe,'$info.RedirectStandardError=$true'),
 'Both native streams are captured privately rather than entering PowerShell error handling');
readinessCheck(str_contains($probe,'$info.Arguments=$quoted -join')&&str_contains($probe,'$probe.StartInfo=$info')&&
 str_contains($probe,'if(!$probe.Start())'), 'Quoted arguments belong only to the explicitly created client process');
readinessCheck(readinessBefore($probe,'$null=$probe.Handle','$probe.WaitForExit(3000)'),
 'Probe pins its exact native child handle before waiting');
readinessCheck(readinessBefore($probe,'$stdout=$probe.StandardOutput.ReadToEndAsync()','$probe.WaitForExit(3000)')&&
 readinessBefore($probe,'$stderr=$probe.StandardError.ReadToEndAsync()','$probe.WaitForExit(3000)'),
 'Concurrent asynchronous drains start before waiting on either pipe or process');
readinessCheck(str_contains($probe,'if(!$probe.WaitForExit(3000)){return $false}')&&
 str_contains($probe,'return $probe.ExitCode -eq 0'),
 'Only a completed zero exit succeeds; the three-second readiness timeout returns false');
readinessCheck(str_contains($probe,'if($started -and !$probe.HasExited){$probe.Kill();')&&
 str_contains($probe,"}finally{\$probe.Dispose()}"),
 'Finally terminates only its still-living retained child and disposes the process');
readinessCheck(substr_count($probe,'$probe.WaitForExit(3000)')===2&&!str_contains($probe,'WaitForExit()'),
 'Normal exit and owned timeout cleanup both have bounded waits');
foreach(['stdout','stderr'] as $stream){
 readinessCheck(str_contains($probe,'$'.$stream.'.Wait(1000)')&&
  str_contains($probe,'$null=$'.$stream.'.GetAwaiter().GetResult()'),
  'Final '.$stream.' drain is bounded and its contents are discarded');
}
readinessCheck(!preg_match('/\b(?:Write-(?:Host|Output|Error|Warning|Verbose|Debug|Information)|Record|Out-File|Set-Content|Add-Content|Stop-Process|Get-Process|taskkill)\b/i',$probe),
 'Probe never logs native output or credentials and never sweeps other processes');
readinessCheck(!str_contains($probe,'$_.Exception')&&!str_contains($probe,'$LASTEXITCODE')&&
 substr_count($probe,"throw [InvalidOperationException]::new('Local database readiness probe")===2,
 'Start and cleanup failures use fixed safe errors without ambient exit status or native exception details');

readinessCheck(str_contains($wait,'[Diagnostics.Process]$DatabaseProcess,[string]$ProbeBinary,[string[]]$ProbeArguments')&&
 str_contains($wait,"if(\$null -eq \$DatabaseProcess){throw 'Local MariaDB process is unavailable.'}"),
 'Wait helper requires the owned database process and rejects a missing handle');
readinessCheck(str_contains($wait,'for($attempt=0;$attempt -lt 75;$attempt++)')&&
 str_contains($wait,'Start-Sleep -Milliseconds 500'), 'Startup retains its 75 attempts and 500 ms retry interval');
$invoke='$ready=Invoke-HomeServerDatabaseProbe $ProbeBinary $ProbeArguments';
$invokeAt=strpos($wait,$invoke);
readinessCheck($invokeAt!==false&&substr_count($wait,'if($DatabaseProcess.HasExited)')===2&&
 str_contains(substr($wait,0,$invokeAt),'if($DatabaseProcess.HasExited)')&&
 str_contains(substr($wait,$invokeAt+strlen($invoke)),'if($DatabaseProcess.HasExited)'),
 'Database liveness is checked before and after each owned readiness client');
readinessCheck(str_contains($wait,'if($ready){return}')&&
 str_contains($wait,"throw 'Local MariaDB did not become ready.'")&&!str_contains($wait,'.Kill(')&&!str_contains($wait,'Start-Process'),
 'Budget exhaustion fails closed without replacing or killing the database from the wait helper');
readinessCheck(!preg_match('/&\s*\$dbAdmin\b/i',$launcher),
 'The old PowerShell-native dbAdmin stderr pipeline is absent');
readinessCheck(substr_count($launcher,'Wait-HomeServerDatabase $dbProc $dbAdmin')===1&&
 str_contains($launcher,"@('--protocol=tcp','--host=127.0.0.1','--port=3307','--user=root',(\"--password=\"+\$rootSecret),'ping')"),
 'Actual startup delegates once with unchanged local protocol, port, root credentials, and ping command');
readinessCheck(readinessBefore($launcher,"\$dbProc=Launch \$mariadb",'Wait-HomeServerDatabase $dbProc $dbAdmin')&&
 readinessBefore($launcher,'Wait-HomeServerDatabase $dbProc $dbAdmin',"\$phpIni=Join-Path \$data 'php.ini'"),
 'Readiness still gates PHP initialization after starting the original MariaDB process');

readinessCheck(str_contains($native,"\$env:GITHUB_ACTIONS -ne 'true'")&&str_contains($native,"\$env:OS -ne 'Windows_NT'")&&
 str_contains($native,'$PSVersionTable.PSVersion.Major -ne 5')&&str_contains($native,'$PSVersionTable.PSVersion.Minor -ne 1'),
 'Native regression suite requires isolated Windows CI running actual PowerShell 5.1');
readinessCheck(str_contains($native,"'homeserver\\start.ps1'")&&str_contains($native,'[Management.Automation.Language.Parser]::ParseFile')&&
 str_contains($native,'[Management.Automation.Language.FunctionDefinitionAst]')&&
 str_contains($native,'Invoke-HomeServerDatabaseProbe')&&str_contains($native,'Wait-HomeServerDatabase'),
 'Native regression suite extracts the installed production helper ASTs rather than a rewritten policy');
readinessCheck(str_contains($native,'$Installation.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)')&&
 str_contains($native,'$EvidenceDir.StartsWith($runnerRoot,[StringComparison]::OrdinalIgnoreCase)')&&
 str_contains($native,"'lucky-db-readiness-'+[Guid]::NewGuid().ToString('N')"),
 'Native fixtures and evidence are restricted to random CI-owned temporary paths');
readinessCheck(str_contains($native,'try{& $php @legacyArguments 2>$null | Out-Null}catch{$legacyFailed=$true}')&&
 str_contains($native,"Assert-DbReadiness (\$legacyFailed -and (Read-ProbeCount 'legacy') -eq 1)"),
 'Native suite includes a behavioral negative control for the original stderr pipeline defect');
readinessCheck(str_contains($native,"New-ProbeArguments 'refuse' 'nonzero'")&&
 str_contains($native,'$captured.Count -eq 1 -and $captured[0] -is [bool] -and !$captured[0]'),
 'Nonzero stderr rejection asserts exactly one false boolean rather than an empty or mixed result');
readinessCheck(str_contains($native,"fwrite(STDERR, str_repeat(\$private, 4096))")&&
 str_contains($native,"fwrite(STDOUT, str_repeat(\$private, 4096))")&&
 str_contains($native,"New-ProbeArguments 'flood-success' 'stderr-success'")&&
 str_contains($native,'$captured.Count -eq 1 -and $captured[0] -is [bool] -and $captured[0]'),
 'Zero exit with both native pipes larger than their buffers must still return exactly true');
readinessCheck(str_contains($native,"Wait-HomeServerDatabase \$database \$php (New-ProbeArguments 'refuse-then-ready' 'retry')")&&
 str_contains($native,"Assert-DbReadiness ((Read-ProbeCount 'retry') -eq 3)"),
 'Real native retry coverage requires two failed clients before the third succeeds');
readinessCheck(str_contains($native,"\$timedOut=Invoke-HomeServerDatabaseProbe \$php (New-ProbeArguments 'hang' 'timeout')")&&
 str_contains($native,'!$timedOut -and $clock.Elapsed.TotalSeconds -ge 2.5 -and $clock.Elapsed.TotalSeconds -lt 10')&&
 str_contains($native,"Assert-DbReadiness (Test-ProbeGone 'timeout')"),
 'Timeout coverage measures the real bounded wait and confirms that native client is gone');
readinessCheck(str_contains($native,"\$sentinel=Start-OwnedFixture 'hold' 'unrelated'")&&
 substr_count($native,'Assert-DbReadiness (!$database.HasExited -and !$sentinel.HasExited)')===3,
 'Retry, timeout, and exhaustion each verify a live database and same-binary unrelated sentinel');
readinessCheck(str_contains($native,"Assert-DbReadiness (\$dead.WaitForExit(5000))")&&
 str_contains($native,"\$deadError -ceq 'Local MariaDB exited during startup.' -and (Read-ProbeCount 'should-not-run') -eq 0"),
 'The native dead-database control proves exit before asserting no probe was launched');
readinessCheck(str_contains($native,"\$budgetError -ceq 'Local MariaDB did not become ready.' -and (Read-ProbeCount 'budget') -eq 75")&&
 str_contains($native,'$clock.Elapsed.TotalSeconds -ge 37 -and $clock.Elapsed.TotalSeconds -lt 100')&&
 str_contains($native,"Assert-DbReadiness (Test-ProbeGone 'budget')"),
 'Exhaustion coverage checks all 75 real probes, the retry timing, and no surviving last client');
readinessCheck(substr_count($native,'Assert-DbReadiness (!(($captured | Out-String).Contains($token)))')===2&&
 str_contains($native,"\$launchError -ceq 'Local database readiness probe could not complete safely.' -and !\$launchError.Contains(\$token)"),
 'Both success and refusal output plus failed-launch details are checked for private-marker leakage');
readinessCheck(str_contains($native,'foreach($owned in $script:owned)')&&str_contains($native,'$owned.Kill()')&&
 str_contains($native,'$owned.WaitForExit(3000)')&&str_contains($native,'$owned.Dispose()')&&
 str_contains($native,'$entry.Attributes -band [IO.FileAttributes]::ReparsePoint')&&
 str_contains($native,"'^lucky-db-readiness-[a-f0-9]{32}$'"),
 'Native teardown uses retained owned processes and validates its exact nonrecursive fixture boundary');
readinessCheck(str_contains($native,'homeserver-database-readiness.json')&&str_contains($native,'launcherSha256=(Get-FileHash -LiteralPath $launcher -Algorithm SHA256).Hash')&&
 str_contains($native,"if(\$cleanupFailed){\$result='failed'"),
 'Native evidence records installed source identity and cleanup failures cannot certify success');

$fixture='.\\tests\\homeserver_database_readiness_windows.ps1 -Installation $installation -EvidenceDir $readinessEvidence';
readinessCheck(str_contains($workflow,'& $windowsPowerShell -NoProfile -NonInteractive -ExecutionPolicy Bypass -File '.$fixture)&&
 str_contains($workflow,"System32\\WindowsPowerShell\\v1.0\\powershell.exe"),
 'Workflow invokes the readiness regression fixture through system Windows PowerShell');
readinessCheck(readinessBefore($workflow,'CLOUD_HOMESERVER_APPLICATION_PARITY_PASS',$fixture)&&
 readinessBefore($workflow,'INSTALLED_BUILD_IDENTITY_PASS',$fixture)&&
 readinessBefore($workflow,$fixture,'-File $start -Smoke -NoBrowser'),
 'Readiness certification follows installed source identity and precedes the first real startup smoke');
readinessCheck(str_contains($workflow,"if(\$LASTEXITCODE -ne 0){throw 'Native database-readiness subprocess certification failed'}"),
 'Any native readiness regression failure blocks Windows certification');
readinessCheck(str_contains($workflow,'lucky-homeserver-runtime-evidence')&&str_contains($workflow,'if: always()')&&
 str_contains($workflow,'Preserve native worker certification evidence'),
 'The existing always-run artifact gate retains readiness evidence even on failure');
echo 'HOMESERVER G7A DATABASE READINESS SOURCE/WIRING CONTRACT: '.$checks.'/'.$checks."\n";
