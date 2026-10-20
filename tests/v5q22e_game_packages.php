<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/GamePackageInstaller.php';
function ok22e(bool $condition,string $name):void {if(!$condition)throw new RuntimeException($name);}
function reject22e(callable $work,string $name):void {
 try{$work();}catch(DomainException $e){return;}
 throw new RuntimeException('Expected failure: '.$name);
}
function makeZip22e(string $file,array $assets,array $override=[],?OpenSSLAsymmetricKey $key=null):void {
 $manifest=array_replace([
  'schema'=>'lucky.game-package.v1','id'=>'table-trivia',
  'version'=>'1.0.0','title'=>'Table Trivia','publisher'=>'Test Publisher',
  'host'=>'host.html','phone'=>'phone.html',
  'files'=>array_map('hash22e', $assets)
 ],$override);
 $raw=json_encode($manifest,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
 $z=new ZipArchive();
 if($z->open($file,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('ZIP creation failed');
 $z->addFromString('manifest.json',$raw);
 if($key!==null){$signature='';openssl_sign($raw,$signature,$key,OPENSSL_ALGO_SHA256);$z->addFromString('manifest.sig',base64_encode($signature));}
 foreach($assets as $name=>$bytes)$z->addFromString($name,$bytes);
 $z->close();
}
function hash22e(string $bytes):string {return hash('sha256',$bytes);}
function cleanup22e(string $dir):void {
 if(!is_dir($dir))return;
 foreach(new FilesystemIterator($dir) as $entry){
  if($entry->isDir()&&!$entry->isLink())cleanup22e($entry->getPathname());
  else unlink($entry->getPathname());
 }
 rmdir($dir);
}
if(!class_exists(ZipArchive::class))throw new RuntimeException('Missing ZipArchive; configure PHP ZIP extension.');
$base=sys_get_temp_dir().'/lucky-package-'.bin2hex(random_bytes(8));
mkdir($base,0700,true);
try{
 $storage=$base.'/packages';$zip=$base.'/game.zip';
 $assets=['host.html'=>'<!doctype html><title>Trivia</title><h1>Quiz game</h1>',
  'phone.html'=>'<!doctype html><title>Phone trivia controller</title><button>Answer</button>',
  'assets/game.js'=>'document.body.dataset.game="trivia";'];
 makeZip22e($zip,$assets);
 $first=GamePackageInstaller::install($zip,$storage);
 ok22e($first['id']==='table-trivia'&&$first['version']==='1.0.0','Package imported');
 ok22e($first['status']==='staged'&&$first['signatureVerified']===false,'Unsigned code is staged not executed');
 ok22e($first['phone']==='phone.html','Per-game phone controller entry stored');
 ok22e(count(GamePackageInstaller::listing($storage))===1,'Version listing');
 ok22e(is_file($storage.'/table-trivia/versions/1.0.0/assets/game.js'),'Assets are stored under versioned private directory');
 $repeat=GamePackageInstaller::install($zip,$storage);
 ok22e($repeat['manifestSha256']===$first['manifestSha256'],'Identical import is idempotent');
 makeZip22e($zip,['host.html'=>'different','phone.html'=>'controller'],['version'=>'1.0.0']);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Cannot replace version with different digest');
 makeZip22e($zip,$assets,['version'=>'1.1.0','phone'=>null]);
 $second=GamePackageInstaller::install($zip,$storage);
 ok22e($second['phone']===null,'Phone controller optional');
 ok22e(count(GamePackageInstaller::listing($storage))===2,'Versions preserve prior release');
 ok22e(is_file($storage.'/table-trivia/versions/1.0.0/host.html'),'Old version survives upgrade');
 ok22e(!is_file($storage.'/table-trivia/active.json'),'Staging must not enable executable game');
 makeZip22e($zip,$assets,['id'=>'spin-wheel']);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Bundled wheel protected');
 makeZip22e($zip,$assets,['version'=>'1.2.0','files'=>[
  'host.html'=>str_repeat('f',64),'phone.html'=>hash22e($assets['phone.html']),
  'assets/game.js'=>hash22e($assets['assets/game.js'])]]);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Tampered asset digest');
 ok22e(!is_dir($storage.'/table-trivia/versions/1.2.0'),'Failed import leaves no active version');
 makeZip22e($zip,$assets,['host'=>'../../secret.html','version'=>'1.2.0']);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Traversal entry');
 makeZip22e($zip,$assets+['server.php'=>'<?php echo 1;'],['version'=>'1.2.0']);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'PHP source not installable');
 makeZip22e($zip,$assets,['version'=>'1.2.0']);
 $z=new ZipArchive();$z->open($zip);$z->addFromString('sneaky.js','alert(1)');$z->close();
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Unlisted extra archive entry');
 $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
 if(!$key)throw new RuntimeException('OpenSSL not available');
 $public=openssl_pkey_get_details($key)['key'];
 makeZip22e($zip,$assets,['version'=>'2.0.0'],$key);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage),'Signed file requires publisher trust');
 $signed=GamePackageInstaller::install($zip,$storage,$public);
 ok22e($signed['signatureVerified']===true,'Publisher signature validated');
 $other=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);
 makeZip22e($zip,$assets,['version'=>'2.1.0'],$other);
 reject22e(fn()=>GamePackageInstaller::install($zip,$storage,$public),'Incorrect publisher signature');
 ok22e(count(GamePackageInstaller::listing($storage))===3,'Only accepted releases listed');
 echo "V5Q.22E: validated ZIP staging, versioned assets, signature protection, rollback-safe failures, optional phone passed\n";
}finally{cleanup22e($base);}
