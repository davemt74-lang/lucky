<?php
declare(strict_types=1);
require __DIR__.'/../lib/ControllerPairing.php';
$pass=0;$fail=0;
function ok(bool $yes,string $name):void{global $pass,$fail;echo ($yes?'PASS ':'FAIL ').$name."\n";$yes?$pass++:$fail++;}
function bad(callable $f):bool{try{$f();return false;}catch(DomainException){return true;}}
$id=str_repeat('a',32);$token=str_repeat('b',64);$hash=ControllerPairing::tokenHash($token);
ok(strlen($hash)===64,'token hashed to SHA-256');ok(!str_contains($hash,$token),'token never stored raw');
ok(hash_equals($hash,hash('sha256',hex2bin($token))),'hash matches binary secret');
ok(bad(fn()=>ControllerPairing::tokenHash('password')),'reject low-entropy token');
ok(bad(fn()=>ControllerPairing::checkId('../../etc/passwd')),'reject invalid pairing ID');
ok(bad(fn()=>ControllerPairing::checkId(str_repeat('a',31))),'reject short ID');
ok(bad(fn()=>ControllerPairing::checkSecret(str_repeat('z',64))),'reject invalid token charset');
ok(ControllerPairing::label('  Living Room  ')==='Living Room','clean whitespace in names');
ok(bad(fn()=>ControllerPairing::label('')),'reject empty name');
ok(bad(fn()=>ControllerPairing::label(str_repeat('a',49))),'reject oversize name');
ok(bad(fn()=>ControllerPairing::label("Host\nadmin")),'reject control characters in names');
ok(ControllerPairing::command('spin')==='spin','allow spin');
ok(ControllerPairing::command('music-next')==='music-next','allow music controls');
ok(bad(fn()=>ControllerPairing::command('delete_prize')),'block unauthorized command');
ok(bad(fn()=>ControllerPairing::command('admin')),'block admin privilege actions');
ok(ControllerPairing::nonce(str_repeat('a',12))===str_repeat('a',12),'valid nonce');
ok(bad(fn()=>ControllerPairing::nonce('too-short')),'reject short nonce');
ok(bad(fn()=>ControllerPairing::nonce('a/../../x')),'reject unsafe nonce');
$future=gmdate('Y-m-d H:i:s',time()+150);$past=gmdate('Y-m-d H:i:s',time()-150);
$pending=['status'=>'pending','invite_expires_at'=>$future,'paired_expires_at'=>null];
ok(ControllerPairing::status($pending)==='pending','pending invitation remains pending');
$pending['invite_expires_at']=$past;
ok(ControllerPairing::status($pending)==='expired','pending invitation expires');
$paired=['status'=>'paired','invite_expires_at'=>$past,'paired_expires_at'=>$future];
ok(ControllerPairing::status($paired)==='paired','paired phone persists past initial QR expiry');
$paired['paired_expires_at']=$past;
ok(ControllerPairing::status($paired)==='expired','paired phone expires');
ok(ControllerPairing::status(['status'=>'revoked'])==='revoked','revoked phone remains revoked');
ok(str_contains(ControllerPairing::inviteUrl($id,$token),'controller.php?invite='),'shareable invite route');
echo "Phone pairing: $pass PASS / $fail FAIL\n";exit($fail?1:0);
