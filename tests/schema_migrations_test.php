<?php
declare(strict_types=1);
/** Core certification: real bundle parsing, wiring, and fail-closed DB boundaries. */
require_once __DIR__.'/../lib/SchemaMigrations.php';

$checks=0;
function schemaCheck(bool $ok,string $message):void {
 global $checks;
 if(!$ok)throw new RuntimeException('FAIL '.$message);
 ++$checks;echo 'PASS '.$message."\n";
}
function schemaThrows(callable $run,string $message):Throwable {
 try{$run();}catch(Throwable $e){schemaCheck(true,$message);return $e;}
 throw new RuntimeException('FAIL '.$message.' (no exception)');
}
function schemaTokens(string $source):array {
 return array_values(array_filter(token_get_all($source),static fn($token):bool=>
  !is_array($token)||!in_array($token[0],[T_WHITESPACE,T_COMMENT,T_DOC_COMMENT,T_INLINE_HTML],true)));
}
function schemaToken(mixed $token,int $kind,string $text):bool {
 return is_array($token)&&$token[0]===$kind&&$token[1]===$text;
}
function schemaCalls(array $tokens,string $class,string $method):array {
 $found=[];
 foreach($tokens as $i=>$token)if(schemaToken($token,T_STRING,$class)&&
  schemaToken($tokens[$i+1]??null,T_DOUBLE_COLON,'::')&&schemaToken($tokens[$i+2]??null,T_STRING,$method)&&
  ($tokens[$i+3]??null)==='(')$found[]=$i;
 return $found;
}
function schemaRemove(string $path):void {
 if(is_link($path)||!is_dir($path)){if(file_exists($path)||is_link($path))unlink($path);return;}
 foreach(new DirectoryIterator($path) as $item)if(!$item->isDot())schemaRemove($item->getPathname());
 rmdir($path);
}
function schemaChild(string $script,string $library):array {
 $pipes=[];
 // Portable PHP wrappers may set extension_dir on their command line; PHP_BINARY
 // bypasses that wrapper. Carry the directory so the child's conditional ctype
 // load cannot turn an otherwise valid bundle into a false preflight failure.
 $process=proc_open([PHP_BINARY,'-d','extension_dir='.ini_get('extension_dir'),'-d','enable_dl=1',
  '-d','display_errors=stderr','-d','log_errors=0',$script,$library],
  [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
 if(!is_resource($process))throw new RuntimeException('Could not start bounded migration probe.');
 fclose($pipes[0]);stream_set_blocking($pipes[1],false);stream_set_blocking($pipes[2],false);
 $out='';$error='';$exit=null;$deadline=microtime(true)+5;
 try{
  do{
   $out.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
   $state=proc_get_status($process);
   if(!$state['running']){$exit=$state['exitcode'];break;}
   if(microtime(true)>$deadline)throw new RuntimeException('Migration probe exceeded its five-second timeout.');
   usleep(10000);
  }while(true);
  $out.=stream_get_contents($pipes[1]);$error.=stream_get_contents($pipes[2]);
 }finally{
  if(proc_get_status($process)['running'])proc_terminate($process,9);
  fclose($pipes[1]);fclose($pipes[2]);$closed=proc_close($process);
  if($exit===null||$exit<0)$exit=$closed;
 }
 if($exit!==0||$error!=='')throw new RuntimeException('Migration probe did not execute cleanly: '.$exit.' '.$error.' '.$out);
 $result=json_decode($out,true,32,JSON_THROW_ON_ERROR);
 if(!is_array($result))throw new RuntimeException('Invalid migration probe result.');
 return $result;
}

// Neither comments nor a quoted example can satisfy a real call-site contract.
schemaCheck(schemaCalls(schemaTokens('<?php /* SchemaMigrations::ensure($db); */ $sample="SchemaMigrations::ensure($db);";'),
 'SchemaMigrations','ensure')===[],'Wiring recognizer rejects comments and quoted examples');
schemaCheck(count(schemaCalls(schemaTokens('<?php SchemaMigrations::ensure($db);'),'SchemaMigrations','ensure'))===1,
 'Wiring recognizer accepts an executable service call');
$sources=[];
foreach(['install.php','upgrade.php','homeserver/initialize.php','homeserver/migrate.php'] as $entry){
 $source=file_get_contents(__DIR__.'/../'.$entry);
 if(!is_string($source))throw new RuntimeException('Missing entry point '.$entry);
 $tokens=schemaTokens($source);$calls=schemaCalls($tokens,'SchemaMigrations','ensure');$required=false;
 foreach($tokens as $i=>$token)if(is_array($token)&&in_array($token[0],[T_REQUIRE,T_REQUIRE_ONCE],true)){
  for($j=$i+1;isset($tokens[$j])&&$tokens[$j]!==';';$j++)if(is_array($tokens[$j])&&
   $tokens[$j][0]===T_CONSTANT_ENCAPSED_STRING&&str_ends_with(substr($tokens[$j][1],1,-1),'/lib/SchemaMigrations.php'))$required=true;
 }
 schemaCheck($required&&count($calls)===1,$entry.' imports and invokes the shared migration service exactly once');
 $call=$calls[0];
 schemaCheck((schemaToken($tokens[$call+4]??null,T_VARIABLE,'$db')||schemaToken($tokens[$call+4]??null,T_VARIABLE,'$pdo'))&&
  ($tokens[$call+5]??null)===')'&&($tokens[$call+6]??null)===';',$entry.' passes its configured PDO without a request-selected migration path');
 $code=implode('',array_map(static fn($token)=>is_array($token)?$token[1]:$token,$tokens));
 schemaCheck(!str_contains($code,'migrations/')&&!str_contains($code,'MigrationSql::statements')&&
  !preg_match('/\bALTER\s+TABLE\b|\bCREATE\s+TABLE\b/i',$code),$entry.' has no independent table list or legacy column-repair implementation');
 $sources[$entry]=$code;
}
foreach(['install.php','upgrade.php'] as $entry){
 $before=explode('SchemaMigrations::ensure(',$sources[$entry],2)[0];
 schemaCheck(str_contains($before,"'POST'")&&str_contains($before,'hash_equals(csrf(),'),$entry.' retains the POST and CSRF gate before schema execution');
}
schemaCheck(str_contains(explode('SchemaMigrations::ensure(',$sources['install.php'],2)[0],'if(installed())'),
 'Web installation retains its existing-installation lock before schema execution');
schemaCheck(str_contains(explode('SchemaMigrations::ensure(',$sources['upgrade.php'],2)[0],'if(!isAdmin())'),
 'Web upgrade retains its administrator gate before schema execution');
foreach(['homeserver/initialize.php','homeserver/migrate.php'] as $entry){
 $before=explode('SchemaMigrations::ensure(',$sources[$entry],2)[0];
 schemaCheck(str_contains($before,"PHP_SAPI!=='cli'")&&str_contains($before,"getenv('LUCKY_BOOTSTRAP')!=='homeserver'")&&
  str_contains($before,'exit(3)'),$entry.' retains its local CLI/bootstrap gate');
}
foreach(['install.php','homeserver/initialize.php'] as $entry){
 $call=strpos($sources[$entry],'SchemaMigrations::ensure(');
 $seed=strpos($sources[$entry],'INSERT');$activate=strpos($sources[$entry],'file_put_contents(');
 schemaCheck($seed!==false&&$activate!==false&&$call<$seed&&$call<$activate,$entry.' requires schema readiness before seeds or configuration activation');
}
schemaCheck(str_contains($sources['install.php'],'password_hash($password,PASSWORD_DEFAULT)')&&
 !str_contains($sources['homeserver/initialize.php'],'INSERT INTO admins')&&
 str_contains($sources['homeserver/initialize.php'],'strlen($rootPassword)<24')&&str_contains($sources['homeserver/initialize.php'],'strlen($appPassword)<24'),
 'Existing web administrator creation and strong HomeServer credential requirements remain separate from migration');

$files=SchemaMigrations::files();$actual=['schema.sql'];
foreach(glob(__DIR__.'/../lib/migrations/*.sql')?:[] as $path)$actual[]='migrations/'.basename($path);
$ordered=$files;$listed=$files;sort($actual,SORT_STRING);sort($listed,SORT_STRING);
schemaCheck($files!==[]&&$files[0]==='schema.sql'&&count($files)===count(array_unique($files)),
 'The fixed catalog begins with the base schema and has no duplicate files');
schemaCheck($listed===$actual,'Every shipped SQL migration is represented exactly once in the shared catalog');
$tables=[];$statements=0;
foreach($ordered as $relative){
 schemaCheck((bool)preg_match('~^(?:schema\.sql|migrations/[a-z0-9_]+\.sql)$~D',$relative),'Catalog path is fixed and lib-relative: '.$relative);
 $sql=file_get_contents(__DIR__.'/../lib/'.$relative);
 if(!is_string($sql))throw new RuntimeException('Cannot read bundled migration.');
 $parsed=MigrationSql::statements($sql);
 schemaCheck($parsed!==[],'Bundled SQL parses into real statements: '.$relative);
 foreach($parsed as $statement){
  if(!preg_match('/\ACREATE\s+TABLE\s+IF\s+NOT\s+EXISTS\s+([a-z][a-z0-9_]*)\s*\(/i',$statement,$match))
   throw new RuntimeException('Unexpected non-idempotent or seeding SQL in '.$relative);
  $tables[strtolower($match[1])]=true;++$statements;
 }
}
foreach(['controller_proof_state','claim_media','director_broadcasts','director_show_channels','recording_sessions','recording_phone_permissions',
 'recording_session_profiles','recording_exports','lucky_parties','lucky_themes','lucky_costumes','lucky_costume_actions'] as $table)
 schemaCheck(isset($tables[$table]),'Complete catalog includes the previously divergent feature schema '.$table);
schemaCheck(count($tables)===51&&$statements>=count($tables),'Catalog accounts for all 51 application tables');
$service=schemaTokens((string)file_get_contents(__DIR__.'/../lib/SchemaMigrations.php'));
$forbidden=['file_put_contents','fwrite','fopen','rename','unlink','password_hash','getenv'];
$sideEffects=[];$requestInputs=[];
foreach($service as $i=>$token){
 if(is_array($token)&&$token[0]===T_STRING&&in_array(strtolower($token[1]),$forbidden,true)&&($service[$i+1]??null)==='(')$sideEffects[]=$token[1];
 if(is_array($token)&&$token[0]===T_VARIABLE&&in_array($token[1],['$_POST','$_GET','$_REQUEST','$_SESSION','$_ENV','$_SERVER'],true))$requestInputs[]=$token[1];
}
schemaCheck($sideEffects===[]&&$requestInputs===[],'Shared service neither rewrites credentials/configuration nor reads request/session secrets');
$method=new ReflectionMethod(SchemaMigrations::class,'ensure');
schemaCheck($method->getNumberOfParameters()===1&&(string)$method->getParameters()[0]->getType()==='PDO'&&
 (new ReflectionMethod(SchemaMigrations::class,'files'))->getNumberOfParameters()===0,'Migration API accepts only the caller PDO, never an arbitrary SQL source');

final class SchemaBoundaryStatement extends PDOStatement {
 public function __construct(private SchemaBoundaryPDO $owner,private string $sql){}
 public function execute(?array $params=null):bool {$this->owner->executions[]=[$this->sql,$params??[]];return true;}
 public function fetchColumn(int $column=0):mixed {
  if($this->sql==='SELECT DATABASE()')return $this->owner->database;
  if(str_contains($this->sql,'GET_LOCK')){
   if($this->owner->lockFailure)throw $this->owner->lockFailure;
   return $this->owner->lockResult;
  }
  if(str_contains($this->sql,'RELEASE_LOCK'))return 1;
  throw new LogicException('Unexpected scalar query in boundary probe.');
 }
}
final class SchemaBoundaryPDO extends PDO {
 public array $prepared=[];public array $executions=[];public array $ddl=[];
 public mixed $database='schema_boundary_fixture';public mixed $lockResult=1;public ?Throwable $lockFailure=null;
 public bool $transaction=false;public bool $ddlFalse=false;
 public function __construct(){}
 public function inTransaction():bool {return $this->transaction;}
 public function prepare(string $query,array $options=[]):PDOStatement|false {$this->prepared[]=$query;return new SchemaBoundaryStatement($this,$query);}
 public function exec(string $statement):int|false {
  $this->ddl[]=$statement;
  if($this->ddlFalse)return false;
  throw new RuntimeException('controlled DDL failure');
 }
}
$db=new SchemaBoundaryPDO();$db->transaction=true;
$failure=schemaThrows(static fn()=>SchemaMigrations::ensure($db),'Active caller transaction is rejected without a database connection');
schemaCheck($db->prepared===[]&&$db->ddl===[]&&str_contains($failure->getMessage(),'transaction'),'Transaction rejection happens before SQL and explains the caller boundary');
foreach([null,''] as $database){
 $db=new SchemaBoundaryPDO();$db->database=$database;
 schemaThrows(static fn()=>SchemaMigrations::ensure($db),'Missing selected database fails before lock or DDL');
 schemaCheck($db->prepared===['SELECT DATABASE()']&&$db->ddl===[],'No schema or lock operation occurs without a selected database');
}
foreach([0,null] as $result){
 $db=new SchemaBoundaryPDO();$db->lockResult=$result;
 $failure=schemaThrows(static fn()=>SchemaMigrations::ensure($db),'Failed or unsupported advisory lock is not treated as success');
 schemaCheck($db->ddl===[]&&!in_array('SELECT RELEASE_LOCK(?)',$db->prepared,true)&&str_contains($failure->getMessage(),'schema lock'),
  'Failure to acquire a lock does not execute DDL or release an unowned lock');
}
$db=new SchemaBoundaryPDO();$cause=new PDOException('controlled denied GET_LOCK');$db->lockFailure=$cause;
$failure=schemaThrows(static fn()=>SchemaMigrations::ensure($db),'Advisory-lock transport failure propagates as an actionable migration failure');
schemaCheck($db->ddl===[]&&$failure->getPrevious()===$cause&&str_contains($failure->getMessage(),'advisory locks'),
 'Unsupported lock handling retains its cause and never continues unlocked');
foreach([false,true] as $falseReturn){
 $db=new SchemaBoundaryPDO();$db->ddlFalse=$falseReturn;
 schemaThrows(static fn()=>SchemaMigrations::ensure($db),'DDL '.($falseReturn?'false result':'exception').' propagates instead of reporting readiness');
 $expected='lucky-schema-'.substr(hash('sha256','schema_boundary_fixture'),0,48);
 schemaCheck($db->executions[1]===['SELECT GET_LOCK(?, ?)',[$expected,5]],'Database-specific advisory lock has the agreed bounded five-second wait');
 schemaCheck(count($db->ddl)===1&&end($db->executions)===['SELECT RELEASE_LOCK(?)',[$expected]],
  'An acquired advisory lock is released after failed DDL');
}

// Execute the unchanged service in a copied bundle. No original file is edited.
// The positive controls reach a sentinel PDO; corrupt bundles must fail before it.
$fixture=rtrim(sys_get_temp_dir(),DIRECTORY_SEPARATOR).'/lucky-schema-core-'.bin2hex(random_bytes(8));
try{
 if(!mkdir($fixture.'/lib/migrations',0700,true))throw new RuntimeException('Cannot create private schema fixture.');
 foreach(array_merge(['SchemaMigrations.php','MigrationSql.php'],$files) as $relative)
  if(!copy(__DIR__.'/../lib/'.$relative,$fixture.'/lib/'.$relative))throw new RuntimeException('Cannot copy schema fixture source.');
 $script=<<<'PHP'
<?php
declare(strict_types=1);
foreach(['PDO'=>'pdo','ctype'=>'ctype'] as $extension=>$module){
 if(!extension_loaded($extension)&&(!function_exists('dl')||!@dl($module.'.'.PHP_SHLIB_SUFFIX))){fwrite(STDERR,"Probe requires ".$extension.".\n");exit(90);}
}
require $argv[1].'/SchemaMigrations.php';
final class BeforeSqlProbe extends PDO {
 public array $queries=[];
 public function __construct(){}
 public function inTransaction():bool{return false;}
 public function prepare(string $query,array $options=[]):PDOStatement|false{$this->queries[]=$query;throw new LogicException('SQL_PROBE_WAS_REACHED');}
 public function exec(string $query):int|false{$this->queries[]=$query;throw new LogicException('UNEXPECTED_DDL_WAS_REACHED');}
}
$db=new BeforeSqlProbe();
try{SchemaMigrations::ensure($db);echo json_encode(['class'=>null,'message'=>'unexpected success','queries'=>$db->queries],JSON_THROW_ON_ERROR);}
catch(Throwable $e){echo json_encode(['class'=>get_class($e),'message'=>$e->getMessage(),'queries'=>$db->queries],JSON_THROW_ON_ERROR);}
PHP;
 if(file_put_contents($fixture.'/probe.php',$script)===false)throw new RuntimeException('Cannot write isolated child probe.');
 $positive=schemaChild($fixture.'/probe.php',$fixture.'/lib');
 schemaCheck($positive['class']===LogicException::class&&$positive['message']==='SQL_PROBE_WAS_REACHED'&&$positive['queries']===['SELECT DATABASE()'],
  'Intact copied bundle really passes full preflight and reaches the controlled PDO');
 $late=end($files);$path=$fixture.'/lib/'.$late;$original=file_get_contents($path);
 if(!is_string($original))throw new RuntimeException('Missing late-catalog fixture source.');
 $cases=[
  'missing last migration'=>null,
  'empty last migration'=>"  \n",
  'unterminated quoted SQL'=>"CREATE TABLE IF NOT EXISTS director_show_channels (id CHAR(32) DEFAULT 'unterminated) ENGINE=InnoDB;",
  'unexpected write statement'=>$original."\nDELETE FROM admins;\n",
  'different table catalog'=>str_replace('director_show_channels','unexpected_schema_fixture',$original),
  'oversize migration'=>str_repeat(' ',1048577),
  'NUL-containing migration'=>$original."\0",
 ];
 foreach($cases as $label=>$replacement){
  if($replacement===null)unlink($path);elseif(file_put_contents($path,$replacement)===false)throw new RuntimeException('Cannot prepare controlled corrupt bundle.');
  $result=schemaChild($fixture.'/probe.php',$fixture.'/lib');
  schemaCheck($result['class']===RuntimeException::class&&$result['queries']===[],$label.' fails before any database query or DDL');
  schemaCheck(str_contains($result['message'],'bundled schema')||str_contains($result['message'],'Bundled schema'),$label.' reports a bundle problem, not a missing PHP dependency');
  if(file_put_contents($path,$original)===false)throw new RuntimeException('Cannot restore isolated fixture.');
 }
 $positive=schemaChild($fixture.'/probe.php',$fixture.'/lib');
 schemaCheck($positive['message']==='SQL_PROBE_WAS_REACHED'&&$positive['queries']===['SELECT DATABASE()'],
  'Restoring the last migration makes the same copied bundle pass preflight again');
 schemaCheck(hash_file('sha256',__DIR__.'/../lib/'.$late)===hash('sha256',$original),'Corruption probes did not change the real repository bundle');
}finally{
 if(is_dir($fixture)&&preg_match('~/lucky-schema-core-[a-f0-9]{16}$~',$fixture))schemaRemove($fixture);
}
echo 'SHARED SCHEMA MIGRATIONS CORE: '.$checks.'/'.$checks."\n";
