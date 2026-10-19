<?php
declare(strict_types=1);
require __DIR__.'/../lib/ProductCatalog.php';
$passed=0;$failed=0;
function check(bool $yes,string $label):void{global $passed,$failed;if($yes){$passed++;echo "PASS $label\n";}else{$failed++;echo "FAIL $label\n";}}
$valid=['title'=>'Arcade Controller','summary'=>'For home gaming','category'=>'hardware','status'=>'available','priceCents'=>5999,'imageUrl'=>'https://example.org/pad.jpg','purchaseUrl'=>'https://example.org/product','sortOrder'=>1];
$r=ProductCatalog::validate($valid);check($r['priceCents']===5999,'integer cents preserved');
function rejects(array $data):bool{try{ProductCatalog::validate($data);return false;}catch(DomainException){return true;}}
check(rejects(array_replace($valid,['purchaseUrl'=>'javascript:alert(1)'])),'javascript purchase URL blocked');
check(rejects(array_replace($valid,['purchaseUrl'=>'http://example.org/pad'])),'non-HTTPS purchase blocked');
check(rejects(array_replace($valid,['purchaseUrl'=>'https://u:pw@example.org/pad'])),'credential URL blocked');
check(rejects(array_replace($valid,['purchaseUrl'=>''])),'available product requires external link');
check(rejects(array_replace($valid,['imageUrl'=>'data:image/svg+xml;base64,AAA'])),'unsafe image URL blocked');
check(rejects(array_replace($valid,['priceCents'=>59.99])),'non-integer price blocked');
check(rejects(array_replace($valid,['category'=>'shipping'])),'unsupported category blocked');
check(rejects(array_replace($valid,['status'=>'published'])),'unsupported status blocked');
check(rejects(array_replace($valid,['sortOrder'=>-1])),'negative sort order blocked');
check(rejects(array_replace($valid,['title'=>''])),'empty title blocked');
check(ProductCatalog::validate(array_replace($valid,['status'=>'draft','purchaseUrl'=>'']))['purchaseUrl']==='','draft can omit purchase link');
check(ProductCatalog::validate(array_replace($valid,['status'=>'coming_soon','purchaseUrl'=>'']))['purchaseUrl']==='','coming soon can omit purchase link');
echo "Catalog: $passed PASS / $failed FAIL\n";exit($failed?1:0);
