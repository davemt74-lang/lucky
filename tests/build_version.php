<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/BuildVersion.php';
function buildCheck(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('BUILD VERSION: '.$message);
 echo "PASS $message\n";
}
$fixture=sys_get_temp_dir().'/lucky-build-version-'.bin2hex(random_bytes(8));
if(!mkdir($fixture,0700))throw new RuntimeException('Cannot create build-version fixture');
$commit=str_repeat('a',40);$tree=str_repeat('b',40);
function buildFixture(string $path,mixed $value):void {
 if(file_put_contents($path,json_encode($value,JSON_THROW_ON_ERROR))===false)
  throw new RuntimeException('Cannot write build-version fixture');
}
try{
 buildCheck(BuildVersion::label($fixture)==='Version unavailable','Missing manifest is explicit, not a hardcoded release');
 buildFixture($fixture.'/release.json',['version'=>'V9Q1']);
 buildCheck(BuildVersion::label($fixture)==='V9Q1 · source build','Source checkout never invents a source commit');
 buildFixture($fixture.'/build-info.json',['version'=>'V9Q1','commit'=>$commit,'tree'=>$tree]);
 buildCheck(BuildVersion::info($fixture)===['version'=>'V9Q1','commit'=>$commit,'tree'=>$tree],'Matching generated metadata retains complete source identity');
 buildCheck(BuildVersion::label($fixture)==='V9Q1 · aaaaaaa','Footer uses manifest version and seven-character source commit');
 buildFixture($fixture.'/build-info.json',['version'=>'V9Q2','commit'=>$commit,'tree'=>$tree]);
 buildCheck(BuildVersion::label($fixture)==='V9Q1 · source build','Metadata from another release cannot claim this build');
 foreach([
  ['version'=>'V9Q1','commit'=>'<script>alert(1)</script>','tree'=>$tree],
  ['version'=>'V9Q1','commit'=>$commit,'tree'=>'not-a-tree'],
  ['version'=>'V9Q1','commit'=>$commit],
  ['version'=>'V9Q1','commit'=>123,'tree'=>$tree]
 ] as $invalid){
  buildFixture($fixture.'/build-info.json',$invalid);
  buildCheck(BuildVersion::info($fixture)['commit']===null,'Malformed build identity is ignored safely');
 }
 foreach([['version'=>'<svg/onload=alert(1)>'],['version'=>['V9Q1']],[],null] as $invalid){
  buildFixture($fixture.'/release.json',$invalid);
  buildCheck(BuildVersion::label($fixture)==='Version unavailable','Malformed release cannot become HTML or a version label');
 }
 file_put_contents($fixture.'/release.json','{broken');
 buildCheck(BuildVersion::label($fixture)==='Version unavailable','Invalid JSON does not break application rendering');
 file_put_contents($fixture.'/release.json',str_repeat(' ',8193));
 buildCheck(BuildVersion::label($fixture)==='Version unavailable','Release metadata reads are bounded');
 $release=json_decode((string)file_get_contents(__DIR__.'/../release.json'),true,16,JSON_THROW_ON_ERROR);
 buildCheck(BuildVersion::info()['version']===$release['version'],'Application consumes the authoritative repository release manifest');
 echo "BUILD VERSION: all checks completed\n";
}finally{
 foreach(['release.json','build-info.json'] as $file)if(is_file($fixture.'/'.$file))unlink($fixture.'/'.$file);
 rmdir($fixture);
}
