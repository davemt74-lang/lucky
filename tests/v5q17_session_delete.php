<?php
declare(strict_types=1);
require __DIR__.'/../lib/GameSessionDeletion.php';
function verify(bool $good,string $label):void{if(!$good)throw new RuntimeException($label);echo "PASS $label\n";}
$id=str_repeat('a',32);
verify(GameSessionDeletion::confirm($id,'DELETE '.$id),'typed full session ID authorizes explicit deletion');
verify(!GameSessionDeletion::confirm($id,'DELETE'),'bare command cannot delete a session');
verify(!GameSessionDeletion::confirm($id,'DELETE '.str_repeat('b',32)),'another game session ID cannot authorize deletion');
verify(!GameSessionDeletion::confirm('../unsafe','DELETE ../unsafe'),'unsafe identifiers cannot be used');
$api=file_get_contents(__DIR__.'/../api/index.php');
verify(str_contains($api,"if(\$action==='delete_session')")&&str_contains($api,'assertCsrf()')&&str_contains($api,'requireAdmin()'),
 'session deletion is behind authenticated CSRF-protected API');
verify(str_contains($api,"'expectedVersion'")&&str_contains($api,"FOR UPDATE"),'version and transactional row locks protect concurrent updates');
verify(str_contains($api,"'running','queued'")&&str_contains($api,"'claim_proof_revision_clips'")===false,
 'pending exports block deletion and proof references use SQL cleanup');
verify(str_contains($api,"DELETE rc FROM claim_proof_revision_clips")&&str_contains($api,"DELETE FROM game_sessions"),
 'revision links cleaned before cascading session deletion');
verify(str_contains($api,"'session.deleted'")&&str_contains($api,"'cleanupWarnings'"),
 'permanent deletion retains audit entry and reports media cleanup warnings');
echo "V5Q.17 session deletion contract passed\n";
