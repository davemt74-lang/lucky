<?php
declare(strict_types=1);
function visibilityCheck(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('HF8C0: '.$label);echo "PASS $label\n";}
$root=dirname(__DIR__);
$read=static fn(string $file):string=>(string)file_get_contents($root.'/'.$file);
$proof=$read('proof.php');$publisher=$read('public/director-publish.js');$viewer=$read('public/director-view.js');
$api=$read('api/director-live.php');$service=$read('lib/DirectorBroadcast.php');
$page=$read('live-view.php');$schema=$read('lib/schema.sql');$upgrade=$read('upgrade.php');
visibilityCheck(str_contains($proof,'id="director-broadcast-visibility"')&&str_contains($proof,'value="private" selected'),'Private is the explicit default');
visibilityCheck(str_contains($proof,'director-publish-public-ack')&&str_contains($publisher,'public_ack'),'Separate affirmative public visibility consent');
visibilityCheck(str_contains($publisher,"visibility==='private'")&&str_contains($publisher,"url.searchParams.set('key',result.key)"),'Private links still include secret invitations');
visibilityCheck(str_contains($publisher,"url.searchParams.set('id',result.id)")&&str_contains($publisher,"visibility==='public'"),'Public links use broadcast ID without invitation token');
visibilityCheck(str_contains($publisher,"$('director-broadcast-visibility').disabled=busy||!!active"),'Host cannot silently change visibility on air');
visibilityCheck(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()')&&str_contains($api,'public_ack'),'Publishing is authenticated, CSRF protected and consent-gated');
visibilityCheck(str_contains($service,"if((string)\$row['visibility']!=='public')")&&str_contains($service,'hash_equals('),'Private token authorization remains enforced');
visibilityCheck(str_contains($service,"b.visibility='public'")&&str_contains($service,'p.consent_ack=1')&&str_contains($service,'p.is_open=1'),'Public directory lists only active consented public broadcasts');
visibilityCheck(str_contains($page,'director-public-directory')&&str_contains($viewer,"request('public_list'")&&str_contains($viewer,"addTransceiver('video'"),'Read-only public directory and same Program viewer');
visibilityCheck(str_contains($schema,"visibility VARCHAR(12) NOT NULL DEFAULT 'private'")&&str_contains($upgrade,"SHOW COLUMNS FROM director_broadcasts LIKE 'visibility'"),'Fresh install and guarded in-place migration preserve private default');
echo "HF8C0 VISIBILITY CONTRACT: 10/10\n";
