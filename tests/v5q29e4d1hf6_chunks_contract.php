<?php
declare(strict_types=1);
require_once __DIR__.'/../lib/PhoneMediaChunks.php';
function chunkContract(bool $yes,string $label):void {
 if(!$yes)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label."\n";
}
function chunkRejected(array $form,string $pair):bool {
 try{PhoneMediaChunks::envelope($form,$pair);return false;}catch(DomainException){return true;}
}
$pair=str_repeat('c',32);
$form=['nonce'=>str_repeat('d',32),'session'=>str_repeat('a',32),'claim'=>str_repeat('b',32),
 'kind'=>'video','title'=>'Phone prize','durationMs'=>'17500','bytes'=>(string)(PhoneMediaChunks::CHUNK_BYTES*2+1),'total'=>'3'];
$ctx=PhoneMediaChunks::envelope($form,$pair);
chunkContract($ctx['total']===3 && $ctx['bytes']===1048577 && $ctx['pair']===$pair,'Three bounded chunks cover the complete video');
chunkContract(chunkRejected($form+['ignored'=>'x'],$pair)===false,'Extra untrusted fields are ignored');
chunkContract(chunkRejected(array_replace($form,['total'=>'2']),$pair),'Truncated chunk declaration denied');
chunkContract(chunkRejected(array_replace($form,['bytes'=>(string)(ClaimMedia::MAX_BYTES+1),'total'=>'161']),$pair),'Oversized recording denied');
chunkContract(chunkRejected(array_replace($form,['bytes'=>'0','total'=>'0']),$pair),'Empty recording denied');
chunkContract(chunkRejected(array_replace($form,['kind'=>'application/php']),$pair),'Unexpected media type denied');
chunkContract(chunkRejected(array_replace($form,['session'=>str_repeat('f',31)]),$pair),'Malformed session denied');
chunkContract(chunkRejected(array_replace($form,['nonce'=>'unsafe']),$pair),'Malformed upload receipt denied');
chunkContract(chunkRejected(array_replace($form,['durationMs'=>'3600001']),$pair),'Oversized duration denied');
echo "V5Q.29E4D1 HF6 CHUNK CONTRACT PASSED\n";
