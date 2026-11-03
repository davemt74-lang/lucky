<?php
declare(strict_types=1);
require __DIR__.'/../lib/Bootstrap.php';
headerSecurity();
try{
 if(($_SERVER['REQUEST_METHOD']??'')!=='GET'){
  header('Allow: GET');throw new DomainException('GET required.',405);
 }
 if(!installed())throw new DomainException('Complete platform installation first.',409);
 // The same nonsecret metadata already accompanies public application pages.
 // No account, capability, credential, database connection or CORS grant is returned.
 $metadata=installationUrls();
 releaseSessionLock();
 success($metadata);
}catch(Throwable $error){errorOut($error);}
