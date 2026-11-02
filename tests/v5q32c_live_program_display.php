<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';

function assertDisplay(bool $ok,string $message):void {
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 echo "PASS $message\n";
}
$id=str_repeat('a',32);
$state=GameEngine::newSession(GameEngine::defaults(),$id);
$state['multiplayer']=['players'=>[['id'=>'player-1','name'=>'Ada']],
 'hosts'=>[['id'=>'host-1','name'=>'Host 1']],'turnIndex'=>0];
$state['director']['presentation']=['id'=>'cue-1','cue'=>'celebration',
 'headline'=>'Congratulations, Ada!','detail'=>'Great spin','expiresAtMs'=>9999999999999];
$public=GameEngine::publicView($state);
assertDisplay(!isset($public['director'])&&!isset($public['showProgram'])&&
 isset($public['showPresentation'])&&!array_key_exists('key',$public['showPresentation']),
 'Public game state contains only the safe cue and no Program bearer credentials');
$api=file_get_contents(__DIR__.'/../api/director-live.php');
assertDisplay(is_string($api)&&str_contains($api,"$"."action==='watch'&&in_array("),
 'Director signaling accepts viewer-initiated POST watch alongside legacy GET');
assertDisplay(is_string($api)&&str_contains($api,"$"."action==='poll'&&in_array("),
 'Director signaling accepts viewer-initiated POST poll alongside legacy GET');
$viewer=file_get_contents(__DIR__.'/../public/display-program.js');
assertDisplay(is_string($viewer)&&str_contains($viewer,"request('watch',{id:connection.id,key:connection.key},'POST')")&&
 str_contains($viewer,"request('poll',{id:connection.id,key:connection.key,viewer},'POST')"),
 'Embedded viewer sends bearer credentials in request bodies, not watch/poll URLs');
assertDisplay(is_string($viewer)&&!str_contains($viewer,'localStorage')&&!str_contains($viewer,'sessionStorage')&&
 !str_contains($viewer,'showPresentation')&&!str_contains($viewer,'director_context'),
 'Embedded viewer neither persists invitations nor reads them from game state');
$loader=file_get_contents(__DIR__.'/../public/client.js');
assertDisplay(is_string($loader)&&str_contains($loader,"document.body.dataset.page==='display'||$('start-game')")&&
 str_contains($loader,'public/display-program.js?v=5q32c'),
 'Host gameplay and TV display both load the opt-in viewer');
echo "V5Q32C LIVE PROGRAM DISPLAY CORE: 10/10\n";
