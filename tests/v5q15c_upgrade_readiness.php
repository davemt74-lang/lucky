<?php
declare(strict_types=1);
require __DIR__.'/../lib/PrizeDecision.php';
require __DIR__.'/../lib/ControllerGame.php';
final class FakePrizeTableStatement extends PDOStatement {
 public function __construct(private bool $isPresent) {}
 public function fetchColumn(int $column=0):mixed {return $this->isPresent?1:false;}
 public function fetch(int $mode=PDO::FETCH_DEFAULT,int $cursorOrientation=PDO::FETCH_ORI_NEXT,int $cursorOffset=0):mixed {return false;}
}
final class FakePrizeTableDb extends PDO {
 public function __construct(private bool $isPresent) {}
 public function query(string $query, ?int $fetchMode=null,mixed ...$fetchModeArgs):PDOStatement|false {
  return new FakePrizeTableStatement($this->isPresent && str_contains($query,'information_schema.TABLES'));
 }
}
function check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);echo "PASS $label\n";}
$missing=new FakePrizeTableDb(false);
check(!PrizeDecision::available($missing),'missing prize migration detected');
try {PrizeDecision::requireReady($missing);throw new RuntimeException('Missing table was allowed');}
catch(DomainException $e) {
 check($e->getCode()===409&&str_contains($e->getMessage(),'upgrade.php'),'missing migration gives actionable HTTP 409');
}
$state=ControllerGame::snapshot($missing);
check($state['upgradeRequired']===true&&!$state['canStart']&&!$state['canSpin'],'phone stays readable and blocks unsafe actions until upgraded');
$ready=new FakePrizeTableDb(true);
check(PrizeDecision::available($ready),'migration readiness detected');
$state=ControllerGame::snapshot($ready);
check($state['upgradeRequired']===false&&$state['canStart']===true,'phone can recover without re-pairing after upgrade');
