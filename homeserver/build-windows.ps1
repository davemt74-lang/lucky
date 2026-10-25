# Build on a clean Windows GitHub runner. Downloads are build-time only.
# Installer contains complete runtimes and never downloads after installation.
[CmdletBinding()]
param([string]$OutputDir=$env:RUNNER_TEMP)
$ErrorActionPreference='Stop'
$ProgressPreference='SilentlyContinue'
$repo=(Resolve-Path (Join-Path $PSScriptRoot '..')).Path
$stage=Join-Path $env:RUNNER_TEMP 'LuckyHomeServerStage'
$downloads=Join-Path $env:RUNNER_TEMP 'LuckyHomeServerDownloads'
$release=Join-Path $OutputDir 'lucky-homeserver-output'
foreach($dir in @($stage,$downloads,$release)){New-Item -ItemType Directory -Force -Path $dir | Out-Null}
function Download([string]$name,[string]$url,[string]$hash='', [string]$algorithm='SHA256'){
 $target=Join-Path $downloads $name
 Write-Host "Downloading pinned build dependency: $name"
 Invoke-WebRequest -Uri $url -OutFile $target -UseBasicParsing -MaximumRedirection 8 -TimeoutSec 360
 if((Get-Item $target).Length -lt 1000000){throw "Downloaded artifact too small: $name"}
 if($hash){
  $actual=(Get-FileHash $target -Algorithm $algorithm).Hash.ToLowerInvariant()
  if($actual -ne $hash.ToLowerInvariant()){throw "Build input checksum mismatch: $name"}
 }
 return $target
}
$phpZip=Download 'php.zip' 'https://downloads.php.net/~windows/releases/archives/php-8.4.26-nts-Win32-vs17-x64.zip' 'da68394f9193b7f6b89d0c76861a4034ae10efee7fd55a7255d8118c2acf70d7'
$dbZip=Download 'mariadb.zip' 'https://archive.mariadb.org/mariadb-11.8.5/winx64-packages/mariadb-11.8.5-winx64.zip' '75332dc1f437d9ecee253c2d751d02a69628b109ddd031006f8a8d9ba59dbe0d'
$caddyZip=Download 'caddy.zip' 'https://github.com/caddyserver/caddy/releases/download/v2.10.2/caddy_2.10.2_windows_amd64.zip' '88347875f0cd4b5e26bb39cd1f359613f932d54158d560e03244004d1ba6e61aae0cd625ba7c913bd46df096ef973fef2249396b0bb81143414378cb4447aeb8' 'SHA512'
$ffmpegZip=Download 'ffmpeg.zip' 'https://github.com/BtbN/FFmpeg-Builds/releases/download/autobuild-2026-10-16-13-04/ffmpeg-n9.0.2-28-g669f5ab083-win64-gpl-shared-9.0.zip'
$ffmpegDigest=(Get-FileHash $ffmpegZip -Algorithm SHA256).Hash.ToLowerInvariant()
# Verify the pinned FFmpeg GitHub release digest using an authenticated CI token.
if(!$env:GITHUB_TOKEN){throw 'GITHUB_TOKEN required for authenticated FFmpeg asset verification.'}
$headers=@{'User-Agent'='LuckyGameHubBuild';'Authorization'="Bearer $env:GITHUB_TOKEN";'Accept'='application/vnd.github+json'}
$ffrelease=Invoke-RestMethod -Headers $headers -Uri 'https://api.github.com/repos/BtbN/FFmpeg-Builds/releases/tags/autobuild-2026-10-16-13-04'
$asset=$ffrelease.assets|Where-Object {$_.name -eq 'ffmpeg-n9.0.2-28-g669f5ab083-win64-gpl-shared-9.0.zip'}|Select-Object -First 1
if(!$asset -or !$asset.digest -or !$asset.digest.StartsWith('sha256:')){throw 'Pinned FFmpeg asset digest unavailable'}
if($ffmpegDigest -ne $asset.digest.Substring(7)){throw 'FFmpeg digest mismatch'}
# Build the application and runtime directories.
$runtime=Join-Path $stage 'runtime'
$app=Join-Path $stage 'app'
New-Item -ItemType Directory -Force -Path $runtime,$app | Out-Null
Push-Location $repo
try{
 $tracked=(& git ls-files)
 foreach($file in $tracked){
  if($file -match '^(tests/|\.github/|dist/|\.git/|homeserver/installer\.iss$)'){continue}
  $src=Join-Path $repo $file
  $dst=Join-Path $app $file
  $parent=Split-Path -Parent $dst
  if(!(Test-Path $parent)){New-Item -ItemType Directory -Force -Path $parent|Out-Null}
  Copy-Item -LiteralPath $src -Destination $dst -Force
 }
}finally{Pop-Location}
# The supervisor launches from {app}\homeserver while the PHP initializer
# remains private under {app}\app\homeserver.
New-Item -ItemType Directory -Force -Path (Join-Path $stage 'homeserver') | Out-Null
Copy-Item -LiteralPath (Join-Path $repo 'homeserver\start.ps1') -Destination (Join-Path $stage 'homeserver\start.ps1')
Copy-Item -LiteralPath (Join-Path $repo 'homeserver\OFFLINE_NOTICE.txt') -Destination (Join-Path $stage 'homeserver\OFFLINE_NOTICE.txt')
Expand-Archive -LiteralPath $phpZip -DestinationPath (Join-Path $runtime 'php') -Force
Expand-Archive -LiteralPath $dbZip -DestinationPath (Join-Path $downloads 'db-extracted') -Force
$dbroot=Get-ChildItem (Join-Path $downloads 'db-extracted') -Directory |Where-Object {Test-Path (Join-Path $_.FullName 'bin\mariadbd.exe')}|Select-Object -First 1
if(!$dbroot){throw 'Extracted MariaDB portable distribution is invalid'}
Copy-Item -LiteralPath $dbroot.FullName -Destination (Join-Path $runtime 'mariadb') -Recurse -Force
Expand-Archive -LiteralPath $caddyZip -DestinationPath (Join-Path $downloads 'caddy-extracted') -Force
Copy-Item -LiteralPath (Join-Path $downloads 'caddy-extracted\caddy.exe') -Destination (Join-Path $runtime 'caddy.exe')
Expand-Archive -LiteralPath $ffmpegZip -DestinationPath (Join-Path $downloads 'ffmpeg-extracted') -Force
$ffbin=Get-ChildItem (Join-Path $downloads 'ffmpeg-extracted') -Directory -Recurse |Where-Object {Test-Path (Join-Path $_.FullName 'ffmpeg.exe')}|Select-Object -First 1
if(!$ffbin){throw 'Extracted FFmpeg binaries are missing'}
$fftarget=Join-Path $runtime 'ffmpeg\bin'
New-Item -ItemType Directory -Force -Path $fftarget | Out-Null
Copy-Item -Path (Join-Path $ffbin.FullName '*') -Destination $fftarget -Recurse -Force
# Preserve dependency provenance so the binary installer is auditable.
$manifest=[ordered]@{
 gitCommit=$env:GITHUB_SHA
 phpVersion='8.4.26'; phpSha256=(Get-FileHash $phpZip -Algorithm SHA256).Hash.ToLowerInvariant()
 mariadbVersion='11.8.5'; mariadbSha256=(Get-FileHash $dbZip -Algorithm SHA256).Hash.ToLowerInvariant()
 caddyVersion='2.10.2'; caddySha512=(Get-FileHash $caddyZip -Algorithm SHA512).Hash.ToLowerInvariant()
 ffmpegBuild='n9.0.2-28-g669f5ab083'; ffmpegSha256=$ffmpegDigest
}
$manifest |ConvertTo-Json -Depth 3 |Set-Content -LiteralPath (Join-Path $stage 'RUNTIME_MANIFEST.json') -Encoding UTF8
$required=@('php\php.exe','php\php-cgi.exe','mariadb\bin\mariadbd.exe','caddy.exe','ffmpeg\bin\ffmpeg.exe')
foreach($p in $required){if(!(Test-Path (Join-Path $runtime $p))){throw "Missing bundled runtime: $p"}}
& (Join-Path $runtime 'php\php.exe') -v |Select-Object -First 2
& (Join-Path $runtime 'mariadb\bin\mariadbd.exe') --version
& (Join-Path $runtime 'caddy.exe') version
& (Join-Path $runtime 'ffmpeg\bin\ffmpeg.exe') -version 2>&1 |Select-Object -First 1
$compiler=@('C:\Program Files (x86)\Inno Setup 6\ISCC.exe','C:\Program Files\Inno Setup 6\ISCC.exe')|Where-Object {Test-Path $_}|Select-Object -First 1
if(!$compiler){
 choco install innosetup --version=6.4.3 --yes --no-progress
 $compiler=@('C:\Program Files (x86)\Inno Setup 6\ISCC.exe','C:\Program Files\Inno Setup 6\ISCC.exe')|Where-Object {Test-Path $_}|Select-Object -First 1
}
if(!$compiler){throw 'Inno Setup compiler unavailable'}
& $compiler "/DStageDir=$stage" "/DOutputDir=$release" (Join-Path $repo 'homeserver\installer.iss')
if($LASTEXITCODE -ne 0){throw "Installer compile failed: $LASTEXITCODE"}
$exe=Join-Path $release 'Lucky_Game_Hub_HomeServer_V5Q29E1_Setup.exe'
if(!(Test-Path $exe)){throw 'Installer output missing'}
$hash=(Get-FileHash $exe -Algorithm SHA256).Hash.ToLowerInvariant()
"$hash  Lucky_Game_Hub_HomeServer_V5Q29E1_Setup.exe" |Set-Content -LiteralPath (Join-Path $release 'SHA256SUMS.txt') -Encoding ASCII
Copy-Item (Join-Path $stage 'RUNTIME_MANIFEST.json') $release
Write-Host "EXE_READY=$exe"
Write-Host "EXE_SHA256=$hash"
