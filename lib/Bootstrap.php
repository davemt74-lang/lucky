<?php
declare(strict_types=1);
const LUCKY_ROOT=__DIR__.'/..';
require_once __DIR__.'/SpinReveal.php';require_once __DIR__.'/GameEngine.php';require_once __DIR__.'/GameRegistry.php';
if(!defined('LUCKY_TESTING')) {
  ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');
  session_name('lucky_hub_sid');
  session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off','httponly'=>true,'samesite'=>'Lax']);
  if(session_status()!==PHP_SESSION_ACTIVE)session_start();
}
function installed():bool {return is_file(LUCKY_ROOT.'/storage/config.php');}
function config():array {
  if(!installed())throw new RuntimeException('The platform is not installed.');
  $cfg=require LUCKY_ROOT.'/storage/config.php';
  if(!is_array($cfg))throw new RuntimeException('Invalid server configuration.');return $cfg;
}
function db():PDO {
  static $pdo=null;if($pdo instanceof PDO)return $pdo;
  $c=config();$dsn='mysql:host='.$c['db_host'].';port='.$c['db_port'].';dbname='.$c['db_name'].';charset=utf8mb4';
  $pdo=new PDO($dsn,$c['db_user'],$c['db_pass'],[
      PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES=>false,
      PDO::ATTR_TIMEOUT=>8]);
  // DATETIME defaults and UTC_TIMESTAMP comparisons must share one clock on hosting.
  $pdo->exec("SET time_zone = '+00:00'");
  return $pdo;
}
function headerSecurity():void {
 header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');
 header('X-Frame-Options: SAMEORIGIN');header('Cache-Control: no-store');
 header('Content-Security-Policy: default-src \'self\'; img-src \'self\' data: blob: https: http:; media-src \'self\' blob: https: http:; style-src \'self\' \'unsafe-inline\'; script-src \'self\'; connect-src \'self\'; object-src \'none\'; base-uri \'self\'; form-action \'self\'');
}
function isAdmin():bool {return isset($_SESSION['admin_id']) && is_int($_SESSION['admin_id']);}
function requireAdmin():void {if(!isAdmin())throw new DomainException('Administrator login required.',403);}
function csrf():string {if(empty($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));return $_SESSION['csrf'];}
function assertCsrf():void {$provided=$_SERVER['HTTP_X_CSRF_TOKEN']??'';if(!is_string($provided)||!hash_equals(csrf(),$provided))throw new DomainException('Invalid security token. Refresh and try again.',403);}
function jsonInput():array {
 if((int)($_SERVER['CONTENT_LENGTH']??0)>2000000)throw new DomainException('Request is too large.',413);
 $text=file_get_contents('php://input');$obj=json_decode($text?:'{}',true);
 if(!is_array($obj)||json_last_error()!==JSON_ERROR_NONE)throw new DomainException('Invalid JSON request.',400);
 return $obj;
}
function success(array $payload=[],int $status=200):never{http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['ok'=>true]+$payload,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);exit;}
function errorOut(Throwable $e):never{
 $code=$e instanceof DomainException?($e->getCode()>=400&&$e->getCode()<500?$e->getCode():422):500;
 http_response_code($code);header('Content-Type: application/json; charset=utf-8');
 echo json_encode(['ok'=>false,'error'=>$code===500?'Server error; see server logs.':$e->getMessage()],JSON_THROW_ON_ERROR);exit;
}
function token():string{return bin2hex(random_bytes(24));}
function audit(PDO $db,string $event,?string $sessionId=null,array $detail=[]):void{
 $q=$db->prepare('INSERT INTO audit_log (admin_id,event,session_id,details) VALUES (?,?,?,?)');
 $q->execute([$_SESSION['admin_id']??null,$event,$sessionId,json_encode($detail,JSON_THROW_ON_ERROR)]);
}
function sessionRow(PDO $db,string $id,bool $lock=false):array {
 if(!preg_match('/^[a-f0-9]{32}$/D',$id))throw new DomainException('Invalid session ID.',400);
 $q=$db->prepare('SELECT * FROM game_sessions WHERE id=?'.($lock?' FOR UPDATE':''));$q->execute([$id]);
 $row=$q->fetch();if(!$row)throw new DomainException('Session not found.',404);return $row;
}
function stateFromRow(array $row):array{$s=json_decode($row['state_json'],true,512,JSON_THROW_ON_ERROR);return $s;}
function saveState(PDO $db,array $s):void {
 $q=$db->prepare('UPDATE game_sessions SET state_json=?, status=?, version=?, updated_at=UTC_TIMESTAMP(),ended_at=? WHERE id=? AND version=?');
 $q->execute([json_encode($s,JSON_THROW_ON_ERROR),$s['status'],$s['version'],$s['endedAt'],$s['id'],$s['version']-1]);
 if($q->rowCount()!==1)throw new RuntimeException('Concurrent session update conflict.');
 if(($s['status']??'')==='ended'){
  try{require_once __DIR__.'/MontageAutoDraft.php';MontageAutoDraft::ensure($db,(string)$s['id']);}
  catch(Throwable $e){error_log('Lucky end-of-game montage draft: '.$e->getMessage());}
 }
}
function sql(string $path):string{return file_get_contents(LUCKY_ROOT.'/'.$path);}
