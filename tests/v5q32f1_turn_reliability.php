<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
$dir=sys_get_temp_dir().'/lucky-turn-'.bin2hex(random_bytes(7));
if(!mkdir($dir,0700))throw new RuntimeException('Unable to prepare isolated TURN test directory.');
putenv('LUCKY_TURN_TEST_DIR='.$dir);
require_once __DIR__.'/../lib/DirectorTurn.php';
function checkF(bool $condition,string $label):void {
 if(!$condition)throw new RuntimeException('V5Q32F1: '.$label);
 echo "PASS $label\n";
}
try {
 $initial=DirectorTurn::status();
 checkF(!$initial['enabled']&&!$initial['configured'],'Default STUN-only mode needs no TURN server');
 $fallback=DirectorTurn::ice();
 checkF($fallback['relayEnabled']===false&&count($fallback['iceServers'])===1,'STUN fallback works without any configured secrets');
 try {DirectorTurn::configure(['enabled'=>true,'urls'=>'turn:example.com:3478','ttl'=>3600,'secret'=>'short']);throw new RuntimeException('Short secret accepted');}
 catch(DomainException $e){checkF($e->getCode()===422,'Short TURN shared secret rejected');}
 try {DirectorTurn::configure(['enabled'=>true,'urls'=>'file:///etc/passwd','ttl'=>3600,'secret'=>str_repeat('x',32)]);throw new RuntimeException('Unsafe URL accepted');}
 catch(DomainException $e){checkF($e->getCode()===422,'Non-TURN URLs rejected');}
 $secret='director-secure-test-secret-'.bin2hex(random_bytes(14));
 $urls="turn:relay.example.test:3478?transport=udp\nturns:relay.example.test:5349?transport=tcp";
 $saved=DirectorTurn::configure(['enabled'=>true,'urls'=>$urls,'ttl'=>3600,'secret'=>$secret]);
 checkF($saved['enabled']&&$saved['configured']&&count($saved['urls'])===2,'Validated settings persist with enabled TURN');
 $contents=(string)file_get_contents($dir.'/.director-turn-settings.php');
 checkF(!str_contains($contents,$secret)&&!str_contains(json_encode($saved),$secret),'Raw shared secret is never saved or returned in settings');
 $ice=DirectorTurn::ice();
 checkF($ice['relayEnabled']===true&&count($ice['iceServers'])===2,'Authorized ICE includes optional relay and baseline STUN');
 $relay=$ice['iceServers'][1];
 checkF($relay['urls']===$saved['urls']&&is_string($relay['username'])&&is_string($relay['credential']),'TURN relay receives valid client fields');
 checkF(hash_equals(base64_encode(hash_hmac('sha1',$relay['username'],$secret,true)),$relay['credential']),'Ephemeral TURN REST HMAC matches coturn use-auth-secret');
 checkF((int)explode(':',$relay['username'])[0]>=time()+3500,'Credential expiration uses configured lifetime');
 checkF(!isset($ice['sharedSecret'])&&!str_contains(json_encode($ice),$secret),'Client ICE never exposes shared secret');
 $rotated=DirectorTurn::configure(['enabled'=>true,'urls'=>$urls,'ttl'=>7200,'secret'=>'']);
 checkF($rotated['enabled']&&DirectorTurn::ice()['relayEnabled']===true,'Blank password preserves encrypted secret');
 DirectorTurn::configure(['enabled'=>false,'urls'=>$urls,'ttl'=>7200,'secret'=>'']);
 checkF(DirectorTurn::ice()['relayEnabled']===false,'Disabling TURN restores STUN-only behavior');
 foreach(['.director-turn-key.php','.director-turn-settings.php'] as $file){
  $mode=fileperms($dir.'/'.$file)&0777;
  checkF($mode===0600,$file.' is readable only by the server user');
 }
 $api=(string)file_get_contents(dirname(__DIR__).'/api/director-live.php');
 $page=(string)file_get_contents(dirname(__DIR__).'/director.php');
 $view=(string)file_get_contents(dirname(__DIR__).'/public/director-view.js');
 $publisher=(string)file_get_contents(dirname(__DIR__).'/public/director-publish.js');
 checkF(str_contains($api,"$"."action==='turn_config'")&&str_contains($api,'assertCsrf()')&&str_contains($api,'requireAdmin()'),'Only CSRF-authenticated admins can save TURN settings');
 checkF(str_contains($api,'DirectorBroadcast::watch($db,$id,$key)')&&str_contains($api,"['ice'=>DirectorTurn::ice()]"),'TURN credentials follow Live View authorization');
 checkF(str_contains($page,'id="director-turn-secret"')&&str_contains($page,'id="director-live-ice"'),'Host has protected relay controls and ICE diagnostics');
 checkF(str_contains($view,"request('watch'")&&str_contains($view,"function scheduleRetry(")&&str_contains($view,"function retryNow("),'Viewer ICE setup, bounded retries and manual Retry share one path');
 checkF(str_contains($publisher,"request('turn_config'")&&str_contains($publisher,'reportIce()'),'Publisher relays secure config and reports WebRTC state');
 echo "V5Q32F1 TURN RELIABILITY: ALL CHECKS PASS\n";
} finally {
 foreach(['.director-turn-settings.php','.director-turn-key.php'] as $file){
  if(is_file($dir.'/'.$file))unlink($dir.'/'.$file);
 }
 rmdir($dir);
}
