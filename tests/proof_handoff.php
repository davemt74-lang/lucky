<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/SchemaGuard.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
final class HandoffStatement extends PDOStatement {
 public function __construct(private HandoffDb $owner,private string $sql){}
 public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args):array {
  return str_contains($this->sql,'information_schema')?($this->owner->tablePresent?['controller_proof_state']:[]):[];
 }
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {
  return str_contains($this->sql,'SHOW COLUMNS')&&$this->owner->consentReady?['Field'=>'consent_ack']:false;
 }
 public function execute(?array $params=null):bool {
  $this->owner->calls[]=[$this->sql,$params??[]];return true;
 }
}
final class HandoffDb extends PDO {
 public array $calls=[];
 public function __construct(public bool $tablePresent=true,public bool $consentReady=true){}
 public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {return new HandoffStatement($this,$query);}
 public function prepare(string $query,array $options=[]):PDOStatement|false{return new HandoffStatement($this,$query);}
}
function verify(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$db=new HandoffDb();$session=str_repeat('a',32);$claim=str_repeat('b',32);
ProofHandoff::activate($db,$session,$claim,7);
verify(count($db->calls)===1,'accepted prize opens paired phone proof state immediately');
verify($db->calls[0][1]===[$session,7,$claim],'proof handoff is scoped to exact game, owner and claimed prize');
verify(str_contains($db->calls[0][0],"capture_phase='opening'"),'phone enters Opening editor state before host navigates');
verify(str_contains($db->calls[0][0],'consent_ack=0'),'recording consent is reset per accepted claim');
try{ProofHandoff::activate($db,$session,'bad-claim',7);throw new RuntimeException('invalid claim allowed');}
catch(DomainException $e){verify($e->getCode()===422,'rejects invalid or cross-context claim identifiers');}
$old=new HandoffDb(true,false);
try{ProofHandoff::activate($old,$session,$claim,7);throw new RuntimeException('missing consent column allowed');}
catch(DomainException $e){verify($e->getCode()===409&&str_contains($e->getMessage(),'upgrade.php'),'old schema instructs host to upgrade');}
verify($old->calls===[],'missing migration cannot write partial handoff');
