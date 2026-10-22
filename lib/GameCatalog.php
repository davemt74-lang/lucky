<?php
declare(strict_types=1);
require_once __DIR__.'/GameRegistry.php';

/**
 * Local, persistent catalog metadata. Content lives in the HomeServer's
 * storage junction, outside application updates or the Windows installer.
 * Catalog-only entries are not executable game modules.
 */
final class GameCatalog {
 private const IMAGE_SLOTS=['cover','hero','logo','gallery','splash'];
 public static function base(?string $root=null):string {
  return $root ?? dirname(__DIR__).'/storage/game-catalog';
 }
 public static function slug(mixed $id):string {
  if(!is_string($id)||!preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D',$id))
   throw new DomainException('Invalid game identifier.',400);
  return $id;
 }
 private static function field(array $data,string $key,int $limit,string $default=''):string {
  $value=$data[$key]??$default;
  if(!is_string($value)||strlen($value)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))
   throw new DomainException('Invalid game catalog field: '.$key,422);
  return trim($value);
 }
 public static function defaults(string $id):array {
  $modules=GameRegistry::modules();
  $registered=isset($modules[$id]);$builtIn=$id==='spin-wheel';$manifest=$registered?$modules[$id]->manifest():[];
  return ['id'=>$id,'title'=>(string)($manifest['name']??$id),'tagline'=>'','description'=>(string)($manifest['description']??''),
   'publisherName'=>'','publisherWebsite'=>'','category'=>'Games','version'=>$registered?($builtIn?'bundled':(string)($manifest['packageVersion']??'0.1.0')):'0.1.0',
   'ageRating'=>'','playersMin'=>1,'playersMax'=>8,'controllerMode'=>$registered?(GameRegistry::controller($id)===null?'none':'custom'):'custom',
   'enabled'=>$registered,'images'=>['cover'=>null,'hero'=>null,'logo'=>null,'splash'=>null,'gallery'=>[]],
   'splash'=>null,
   'status'=>$registered?'ready':'draft','source'=>$registered?($builtIn?'bundled':'package'):'catalog-only',
   'builtIn'=>$builtIn,'canDelete'=>!$builtIn];
 }
 /** Validate stored splash defaults for both draft and installed game profiles. */
 public static function splash(mixed $value):array {
  if(!is_array($value)||!isset($value['intro'])||!is_array($value['intro'])||!isset($value['theme'])||!is_array($value['theme']))
   throw new DomainException('Invalid game splash settings.',422);
  $intro=$value['intro'];$theme=$value['theme'];
  $title=self::field($intro,'title',100);
  if($title==='')throw new DomainException('Enter a splash title.',422);
  $subtitle=self::field($intro,'subtitle',200);
  $track=self::field($intro,'trackId',32);
  if($track!==''&&!preg_match('/^[a-f0-9]{32}$/D',$track))throw new DomainException('Invalid intro track.',422);
  $volume=$intro['volume']??null;
  if(!is_int($volume)||$volume<0||$volume>100)throw new DomainException('Intro volume must be 0–100.',422);
  $accent=self::field($theme,'accent',7,'#f6cb74');
  $background=self::field($theme,'background',7,'#0d1423');
  if(!preg_match('/^#[a-fA-F0-9]{6}$/D',$accent)||!preg_match('/^#[a-fA-F0-9]{6}$/D',$background))
   throw new DomainException('Invalid splash color.',422);
  return ['intro'=>['title'=>$title,'subtitle'=>$subtitle,'trackId'=>$track,'volume'=>$volume,'announcement'=>($intro['announcement']??true)===true],
   'theme'=>['accent'=>$accent,'background'=>$background]];
 }
 private static function path(string $id,?string $root=null):string {
  return self::base($root).'/'.self::slug($id);
 }
 public static function load(string $id,?string $root=null):array {
  self::slug($id);$base=self::defaults($id);
  $file=self::path($id,$root).'/metadata.json';
  if(!is_file($file))return $base;
  if(is_link($file))throw new RuntimeException('Catalog metadata cannot be a symbolic link.');
  $data=json_decode((string)file_get_contents($file),true,32,JSON_THROW_ON_ERROR);
  if(!is_array($data)||($data['id']??null)!==$id)throw new RuntimeException('Corrupt game catalog metadata.');
  $base=array_replace($base,$data);
  // Catalog metadata may not promote an unregistered game to playable.
  $modules=GameRegistry::modules();$registered=isset($modules[$id]);$builtIn=$id==='spin-wheel';
  $base['status']=$registered?'ready':'draft';
  $base['source']=$registered?($builtIn?'bundled':'package'):'catalog-only';
  $base['builtIn']=$builtIn;$base['canDelete']=!$builtIn;
  if($registered&&!$builtIn)$base['version']=(string)($modules[$id]->manifest()['packageVersion']??$base['version']);
  if($registered)$base['controllerMode']=GameRegistry::controller($id)===null?'none':'custom';
  return $base;
 }
 public static function all(?string $root=null):array {
  $ids=[];
  foreach(GameRegistry::modules() as $id=>$module)$ids[$id]=true;
  $base=self::base($root);
  if(is_dir($base))foreach(new DirectoryIterator($base) as $item){
   if($item->isDir()&&!$item->isLink()&&preg_match('/^[a-z0-9][a-z0-9-]{1,62}$/D',$item->getFilename()))
    $ids[$item->getFilename()]=true;
  }
  ksort($ids,SORT_STRING);
  return array_values(array_map(fn($id)=>self::load($id,$root),array_keys($ids)));
 }
 private static function requireDir(string $path):void {
  if(is_link($path))throw new RuntimeException('Catalog path cannot be a symbolic link.');
  if(!is_dir($path)&&!mkdir($path,0700,true)&&!is_dir($path))throw new RuntimeException('Unable to create game catalog directory.');
  if(is_link($path))throw new RuntimeException('Catalog path cannot be a symbolic link.');
 }
 private static function atomic(string $path,string $data):void {
  $tmp=$path.'.'.bin2hex(random_bytes(8)).'.tmp';
  if(file_put_contents($tmp,$data,LOCK_EX)===false)throw new RuntimeException('Could not save game catalog.');
  @chmod($tmp,0600);
  if(!rename($tmp,$path)){@unlink($tmp);throw new RuntimeException('Unable to publish game catalog changes.');}
 }
 public static function save(string $id,array $input,?string $root=null):array {
  $id=self::slug($id);$base=self::base($root);self::requireDir($base);
  $lock=fopen($base.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Catalog lock unavailable.');
  try {
   $folder=self::path($id,$root);self::requireDir($folder);
   $current=self::load($id,$root);
   $title=self::field($input,'title',120,$current['title']);
   if($title==='')throw new DomainException('Enter a game title.',422);
   $publisherWebsite=self::field($input,'publisherWebsite',2048,$current['publisherWebsite']);
   if($publisherWebsite!==''&&(!filter_var($publisherWebsite,FILTER_VALIDATE_URL)||strtolower((string)parse_url($publisherWebsite,PHP_URL_SCHEME))!=='https'))
    throw new DomainException('Publisher website must be a valid HTTPS URL.',422);
   $min=$input['playersMin']??$current['playersMin'];$max=$input['playersMax']??$current['playersMax'];
   if(!is_int($min)||!is_int($max)||$min<1||$max<$min||$max>64)throw new DomainException('Player counts must be between 1 and 64.',422);
   $controller=$input['controllerMode']??$current['controllerMode'];
   if(!in_array($controller,['none','custom'],true))throw new DomainException('Invalid controller mode.',422);
   $registered=isset(GameRegistry::modules()[$id]);
   $current=array_replace($current,[
    'title'=>$title,
    'tagline'=>self::field($input,'tagline',180,$current['tagline']),
    'description'=>self::field($input,'description',3000,$current['description']),
    'publisherName'=>self::field($input,'publisherName',160,$current['publisherName']),
    'publisherWebsite'=>$publisherWebsite,
    'category'=>self::field($input,'category',80,$current['category']),
    'version'=>self::field($input,'version',40,$current['version']),
    'ageRating'=>self::field($input,'ageRating',40,$current['ageRating']),
    'playersMin'=>$min,'playersMax'=>$max,
    'enabled'=>($input['enabled']??$current['enabled'])===true,
    'controllerMode'=>$registered?(GameRegistry::controller($id)===null?'none':'custom'):$controller,
    'splash'=>array_key_exists('splash',$input)?self::splash($input['splash']):($current['splash']??null)
   ]);
   self::atomic($folder.'/metadata.json',json_encode($current,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
   return self::load($id,$root);
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function saveImage(string $id,string $slot,string $uploaded,?string $root=null):array {
  $id=self::slug($id);
  if(!in_array($slot,self::IMAGE_SLOTS,true))throw new DomainException('Invalid image slot.',400);
  if(!is_file($uploaded)||is_link($uploaded)||filesize($uploaded)===false||filesize($uploaded)>6*1024*1024||filesize($uploaded)<1)
   throw new DomainException('Game image must be 6MB or less.',422);
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($uploaded);
  $ext=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'][$mime]??null;
  $dims=@getimagesize($uploaded);
  if(!$ext||!$dims||$dims[0]<1||$dims[1]<1||$dims[0]>8000||$dims[1]>8000)
   throw new DomainException('Upload a valid JPG, PNG, or WebP image (up to 8000px).',422);
  $base=self::base($root);self::requireDir($base);
  $lock=fopen($base.'/.lock','c');if(!$lock||!flock($lock,LOCK_EX))throw new RuntimeException('Catalog lock unavailable.');
  try{
   $folder=self::path($id,$root);self::requireDir($folder);
   $assets=$folder.'/assets';self::requireDir($assets);
   $current=self::load($id,$root);
   $images=$current['images'];if(!is_array($images))throw new RuntimeException('Catalog images are corrupt.');
   if($slot==='gallery'&&count($images['gallery']??[])>=8)throw new DomainException('Game gallery is limited to 8 images.',422);
   $name=bin2hex(random_bytes(16)).'.'.$ext;
   $target=$assets.'/'.$name;
   if(!copy($uploaded,$target))throw new RuntimeException('Failed to copy catalog artwork.');
   @chmod($target,0600);
   if($slot==='gallery')$images['gallery'][]=$name;
   else $images[$slot]=$name;
   $current['images']=$images;
   try{self::atomic($folder.'/metadata.json',json_encode($current,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));}
   catch(Throwable $e){@unlink($target);throw $e;}
   return ['image'=>'api/index.php?action=game_art&id='.$id.'&file='.$name,'game'=>self::load($id,$root)];
  }finally{flock($lock,LOCK_UN);fclose($lock);}
 }
 public static function artwork(string $id,string $file,?string $root=null):array {
  $id=self::slug($id);
  if(!preg_match('/^[a-f0-9]{32}\.(?:jpg|png|webp)$/D',$file))throw new DomainException('Invalid game artwork.',404);
  $meta=self::load($id,$root);$images=$meta['images']??[];
  $allowed=array_merge(array_values(array_filter([$images['cover']??null,$images['hero']??null,$images['logo']??null,$images['splash']??null])), $images['gallery']??[]);
  if(!in_array($file,$allowed,true))throw new DomainException('Game artwork is unavailable.',404);
  $path=self::path($id,$root).'/assets/'.$file;
  if(!is_file($path)||is_link($path))throw new DomainException('Game artwork is missing.',404);
  $ext=pathinfo($file,PATHINFO_EXTENSION);
  return ['path'=>$path,'mime'=>['jpg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp'][$ext],'bytes'=>filesize($path)];
 }
}
