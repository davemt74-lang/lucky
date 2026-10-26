<?php
declare(strict_types=1);
require_once __DIR__.'/ClaimMediaUpload.php';
require_once __DIR__.'/PhoneCapturePolicy.php';

/**
 * Small, resumable uploads for paired-phone proof media.
 * Chunks remain private and cannot enter the editor until the live proof is
 * reauthorized inside ClaimMediaUpload's final database transaction.
 */
final class PhoneMediaChunks {
 public const CHUNK_BYTES=524288;
 private const TTL_SECONDS=1800;
 private const MAX_CHUNKS=160;

 public static function envelope(array $form,string $pairId):array {
  $nonce=(string)($form['nonce']??'');ControllerPairing::nonce($nonce);
  $session=(string)($form['session']??'');$claim=(string)($form['claim']??'');
  ControllerPairing::checkId($session);ControllerPairing::checkId($claim);
  $raw=(string)($form['bytes']??'');
  if(!preg_match('/^[1-9][0-9]{0,8}$/D',$raw))throw new DomainException('Invalid media size.',422);
  $size=(int)$raw;
  if($size>ClaimMedia::MAX_BYTES)throw new DomainException('Recording exceeds the 80 MB limit.',413);
  $total=(int)ceil($size/self::CHUNK_BYTES);
  if($total<1||$total>self::MAX_CHUNKS||(string)($form['total']??'')!==(string)$total)
   throw new DomainException('Invalid upload chunk count.',422);
  $kind=(string)($form['kind']??'');
  if(!in_array($kind,['video','photo','audio'],true))throw new DomainException('Invalid capture mode.',422);
  $title=trim((string)($form['title']??''));
  if($title===''||mbSafeLen($title)>120)throw new DomainException('Invalid recording title.',422);
  $duration=(string)($form['durationMs']??'0');
  if(!preg_match('/^[0-9]{1,7}$/D',$duration)||(int)$duration>3600000)
   throw new DomainException('Invalid recording duration.',422);
  return ['pair'=>$pairId,'nonce'=>$nonce,'session'=>$session,'claim'=>$claim,
   'bytes'=>$size,'total'=>$total,'kind'=>$kind,'title'=>$title,'durationMs'=>(int)$duration];
 }

 private static function directory():string {
  $path=LUCKY_ROOT.'/storage/phone-media-pending';
  if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))
   throw new RuntimeException('Private phone upload storage is unavailable.');
  return $path;
 }
 private static function paths(array $ctx):array {
  $key=hash('sha256','lucky-phone-media-chunks-v1:'.$ctx['pair'].':'.$ctx['nonce']);
  $base=self::directory().'/'.$key;
  return ['part'=>$base.'.part','meta'=>$base.'.meta','lock'=>$base.'.lock'];
 }
 private static function permitted(PDO $db,array $pair,array $ctx):void {
  $q=$db->prepare('SELECT * FROM controller_proof_state WHERE session_id=?');
  $q->execute([$ctx['session']]);$proof=$q->fetch(PDO::FETCH_ASSOC);
  if(!$proof)throw new DomainException('The desktop prize proof is not open.',409);
  PhoneCapturePolicy::verify($pair,$proof,$ctx['session'],$ctx['claim'],time());
 }
 private static function withLock(array $paths,callable $fn):mixed {
  $handle=@fopen($paths['lock'],'c+b');
  if(!$handle||!flock($handle,LOCK_EX))throw new RuntimeException('Could not lock phone upload.');
  try{return $fn();}
  finally{flock($handle,LOCK_UN);fclose($handle);}
 }
 private static function manifest(array $paths):?array {
  if(!is_file($paths['meta']))return null;
  $raw=@file_get_contents($paths['meta']);
  $data=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($data)||!isset($data['next'],$data['context'])||!is_array($data['context']))
   throw new DomainException('Interrupted upload metadata is invalid. Start a new capture.',409);
  return $data;
 }
 private static function match(array $expected,array $actual):void {
  if($expected!==$actual)throw new DomainException('Upload identifier is already tied to another file.',409);
 }
 public static function append(PDO $db,array $pair,array $form,?array $chunk,?callable $receivedFileValidator=null):array {
  $ctx=self::envelope($form,(string)$pair['id']);
  $indexRaw=(string)($form['index']??'');
  if(!preg_match('/^(0|[1-9][0-9]{0,2})$/D',$indexRaw))throw new DomainException('Invalid upload index.',422);
  $index=(int)$indexRaw;
  if($index>=$ctx['total'])throw new DomainException('Upload index is outside this recording.',422);
  $want=min(self::CHUNK_BYTES,$ctx['bytes']-$index*self::CHUNK_BYTES);
  $receivedFileValidator??=static fn(string $path):bool=>is_uploaded_file($path);
  if(!$chunk||($chunk['error']??-1)!==UPLOAD_ERR_OK||
    !$receivedFileValidator((string)($chunk['tmp_name']??''))||
    (int)($chunk['size']??0)!==$want)
   throw new DomainException('Video chunk was rejected. Check the PHP upload limit (512 KB chunks).',413);
  self::permitted($db,$pair,$ctx);
  $paths=self::paths($ctx);
  return self::withLock($paths,static function()use($ctx,$index,$chunk,$want,$paths):array{
   $current=self::manifest($paths);
   if($current)self::match($ctx,$current['context']);
   elseif($index!==0)throw new DomainException('Upload must begin with the first chunk.',409);
   // A server interruption can occur after a successful move but before the
   // progress receipt is cleared. Reconstruct from the first piece on retry.
   if($current&&!is_file($paths['part'])){
    if($index!==0)throw new DomainException('Upload staging was interrupted. Send the recording again.',409);
    $current=null;@unlink($paths['meta']);
   }
   $next=$current?(int)$current['next']:0;
   $part=@fopen($paths['part'],'c+b');
   if(!$part)throw new RuntimeException('Could not open private upload file.');
   try{
    $offset=$index*self::CHUNK_BYTES;
    if($index>$next)throw new DomainException('A previous upload chunk is missing.',409);
    if($index<$next){
     if(filesize($paths['part'])<$offset+$want)throw new DomainException('A previous chunk is missing. Retry the capture.',409);
     fseek($part,$offset);$existing=fread($part,$want);
     $incoming=file_get_contents((string)$chunk['tmp_name']);
     if(!is_string($incoming)||!hash_equals(hash('sha256',$existing?:''),hash('sha256',$incoming)))
      throw new DomainException('Upload retry differs from its original chunk.',409);
    }else{
     $expectedOffset=$next*self::CHUNK_BYTES;
     if(!ftruncate($part,$expectedOffset)||fseek($part,$expectedOffset)!==0)
      throw new RuntimeException('Could not resume private upload.');
     $incoming=@file_get_contents((string)$chunk['tmp_name']);
     if(!is_string($incoming)||strlen($incoming)!==$want)throw new DomainException('Upload chunk is incomplete.',422);
     $written=0;while($written<$want){
      $count=fwrite($part,substr($incoming,$written));
      if($count===false||$count===0)throw new RuntimeException('Insufficient storage for phone recording.');
      $written+=$count;
     }
     fflush($part);$next++;
     if(@file_put_contents($paths['meta'],json_encode(['context'=>$ctx,'next'=>$next,'updated'=>time()],JSON_THROW_ON_ERROR),LOCK_EX)===false)
      throw new RuntimeException('Could not save upload progress.');
     @chmod($paths['part'],0600);@chmod($paths['meta'],0600);
    }
   }finally{fclose($part);}
   return ['next'=>$next,'total'=>$ctx['total'],'receivedBytes'=>min($ctx['bytes'],$next*self::CHUNK_BYTES)];
  });
 }

 public static function finish(PDO $db,array $pair,array $form,callable $authorize,string $receipt):array {
  $ctx=self::envelope($form,(string)$pair['id']);
  self::permitted($db,$pair,$ctx);
  $paths=self::paths($ctx);
  return self::withLock($paths,static function()use($db,$pair,$ctx,$paths,$authorize,$receipt):array{
   $current=self::manifest($paths);
   if(!$current)throw new DomainException('Upload has not started. Send the recording again.',409);
   self::match($ctx,$current['context']);
   if((int)$current['next']===$ctx['total']&&!is_file($paths['part'])){
    $q=$db->prepare('SELECT * FROM claim_media WHERE id=?');
    $q->execute([$receipt]);$saved=$q->fetch(PDO::FETCH_ASSOC);
    if($saved&&$saved['session_id']===$ctx['session']&&$saved['claim_id']===$ctx['claim']&&
       $saved['kind']===$ctx['kind']&&(int)$saved['bytes']===$ctx['bytes']){
     @unlink($paths['meta']);return ClaimMedia::asPublic($saved);
    }
   }
   if((int)$current['next']!==$ctx['total']||!is_file($paths['part'])||filesize($paths['part'])!==$ctx['bytes'])
    throw new DomainException('Upload is incomplete. Retry sending the recording.',409);
   $form=['session'=>$ctx['session'],'claim'=>$ctx['claim'],'kind'=>$ctx['kind'],'title'=>$ctx['title'],
    'durationMs'=>(string)$ctx['durationMs'],'sourceLabel'=>'Paired phone · '.substr((string)$pair['device_name'],0,46),
    'angleLabel'=>substr('Phone · '.preg_replace('/[\x00-\x1f\x7f]/','',(string)$pair['device_name']),0,80),
    'angleIndex'=>'0','replaceClipId'=>''];
   $file=['tmp_name'=>$paths['part'],'size'=>$ctx['bytes'],'error'=>UPLOAD_ERR_OK];
   $clip=ClaimMediaUpload::store($db,$form,$file,$authorize,$receipt,$paths['part']);
   @unlink($paths['meta']);@unlink($paths['part']);
   return $clip;
  });
 }

 public static function prune():void {
  $dir=self::directory();$count=0;$now=time();
  foreach(new DirectoryIterator($dir) as $file){
   if($count>=32)break;
   if(!$file->isFile()||!preg_match('/^[a-f0-9]{64}\.meta$/D',$file->getFilename()))continue;
   if($file->getMTime()>$now-self::TTL_SECONDS)continue;
   $base=substr($file->getFilename(),0,64);
   foreach(['.part','.meta','.lock'] as $suffix)@unlink($dir.'/'.$base.$suffix);
   $count++;
  }
 }
}

