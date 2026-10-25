<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('CLI only');}
require __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/RecordingExportWorker.php';
if(!installed()){fwrite(STDERR,"Platform is not installed.\n");exit(1);}
RecordingExportWorker::run(db());
