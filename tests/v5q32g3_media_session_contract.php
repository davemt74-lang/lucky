<?php
declare(strict_types=1);
/** Source boundary checks only; real auth/range/concurrency behavior is covered by the HTTP suite. */
$checks=0;
function mediaSessionContract(bool $ok,string $label):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$label);
 ++$checks;echo "PASS $label\n";
}
function mediaContractSource(string $path):string {
 $out='';
 foreach(token_get_all((string)file_get_contents(__DIR__.'/../'.$path)) as $token){
  if(is_array($token)){
   if(in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))continue;
   $out.=$token[1];
  }else $out.=$token;
 }
 return $out;
}
/** Find a named stream function without confusing quoted braces or comments with code. */
function mediaContractFunction(string $path,string $name):string {
 $tokens=token_get_all((string)file_get_contents(__DIR__.'/../'.$path));
 for($i=0,$count=count($tokens);$i<$count;$i++){
  if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
  $j=$i+1;
  while($j<$count&&(!is_array($tokens[$j])||$tokens[$j][0]!==T_STRING))++$j;
  if($j===$count||$tokens[$j][1]!==$name)continue;
  while($j<$count&&$tokens[$j]!=='{')++$j;
  $depth=0;$body='';
  for(;$j<$count;$j++){
   $token=$tokens[$j];
   if($token==='{')++$depth;
   if($token==='}')--$depth;
   if(is_array($token)){
    if(!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT],true))$body.=$token[1];
   }else $body.=$token;
   if($depth===0)return $body;
  }
 }
 throw new RuntimeException('Missing stream function '.$name);
}

$sources=[];
foreach(['api/recording-media.php'=>1,'api/recording-export.php'=>1,'api/claim-media.php'=>1,'api/montage.php'=>2] as $path=>$expected){
 $sources[$path]=mediaContractSource($path);
 mediaSessionContract(substr_count($sources[$path],'releaseSessionLock();')===$expected,
  $path.' releases only its audited media read path(s)');
}
$streams=[
 ['api/recording-media.php',null,false,true],
 ['api/recording-export.php','recordingExportFile',true,true],
 ['api/claim-media.php','mediaStream',true,true],
 ['api/montage.php','renderDownload',true,true],
 ['api/montage.php','renderPoster',true,false],
];
foreach($streams as [$path,$function,$head,$range]){
 $label=$function??'recording-media';
 $body=$function===null?$sources[$path]:mediaContractFunction($path,$function);
 mediaSessionContract(substr_count($body,'releaseSessionLock();')===1,$label.' has exactly one release at its read boundary');
 [$before,$after]=explode('releaseSessionLock();',$body,2);
 mediaSessionContract(str_contains($before,'is_file(')&&str_contains($before,'filesize('),
  $label.' validates file availability and size before releasing');
 mediaSessionContract(!str_contains($before,'Content-Length:')&&!str_contains($before,'Content-Range:')&&
  !str_contains($before,'Content-Type:')&&!str_contains($before,'Content-Disposition:')&&str_contains($after,'Content-Length:'),
  $label.' does not contaminate a failed session-close JSON response with media headers');
 if($range)mediaSessionContract(str_contains($after,'HTTP_RANGE')&&str_contains($after,'416')&&str_contains($after,'fseek('),
  $label.' retains range checks and seek after releasing');
 if($head){
  $headExit=strpos($after,'if($head)exit;');
  $read=strpos($after,$range?'fread(':'readfile(');
  mediaSessionContract($headExit!==false&&$read!==false&&$headExit<$read,$label.' preserves HEAD as a bodyless short exit');
 }
 mediaSessionContract((str_contains($after,'fread(')||str_contains($after,'readfile('))&&str_contains($after,'exit;'),
  $label.' releases before byte output and terminates without returning to mutation routes');
 mediaSessionContract(!str_contains($before,'echo')&&!str_contains($before,'readfile('),
  $label.' emits no media bytes if session release fails');
 mediaSessionContract(!str_contains($after,'$_SESSION')&&!preg_match('/\b(?:session_start|csrf|assertCsrf|audit)\(/',$after),
  $label.' does not mutate or reopen session state after release');
}

$recording=$sources['api/recording-media.php'];
[$before]=explode('releaseSessionLock();',$recording,2);
mediaSessionContract(str_contains($before,'requireAdmin();')&&str_contains($before,'s.owner_admin_id=?')&&
 str_contains($before,"!=='GET'")&&str_contains($before,'Recording file integrity mismatch.'),
 'Recording media retains administrator, owner, GET-only and byte-integrity gates before release');
foreach([
 ['api/recording-export.php','recordingExportFile(RecordingExports::get($db,$owner,'],
 ['api/claim-media.php','mediaStream(mediaRow($db,'],
 ['api/montage.php','renderDownload(renderRow($db,'],
 ['api/montage.php','renderPoster(renderRow($db,'],
] as [$path,$entry]){
 $auth=strpos($sources[$path],'requireAdmin();');$route=strpos($sources[$path],$entry);
 mediaSessionContract($auth!==false&&$route!==false&&$auth<$route,
  $path.' validates admin and resolves the requested row before '.$entry);
}
echo "V5Q32G3 MEDIA SESSION CONTRACT: $checks/$checks\n";
