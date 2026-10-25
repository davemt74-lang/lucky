<?php
declare(strict_types=1);
if(getenv('CI')!=='true')throw new RuntimeException('CI MariaDB only.');
define('LUCKY_TESTING',true);
require_once __DIR__.'/../lib/Bootstrap.php';
require_once __DIR__.'/../lib/MigrationSql.php';
require_once __DIR__.'/../lib/PhoneCapturePolicy.php';
require_once __DIR__.'/../lib/ProofHandoff.php';
function verifyPhoneDb(bool $ok,string $label):void {if(!$ok)throw new RuntimeException('FAIL '.$label);echo 'PASS '.$label."\n";}
$db=new PDO('mysql:host=127.0.0.1;port=3306;dbname=lucky_test;charset=utf8mb4','root','luckytestpassword',[
 PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
foreach(MigrationSql::statements((string)file_get_contents(__DIR__.'/../lib/schema.sql')) as $sql)$db->exec($sql);
$columns=$db->query("SHOW COLUMNS FROM controller_proof_state LIKE 'phone_camera_enabled'")->fetch();
verifyPhoneDb($columns!==false,'Installed schema has independent phone permission');
$host=0;$session=bin2hex(random_bytes(16));$claim=bin2hex(random_bytes(16));
try{
 $db->prepare('INSERT INTO admins(username,password_hash) VALUES (?,?)')->execute(['capture-'.bin2hex(random_bytes(6)),password_hash('test',PASSWORD_DEFAULT)]);
 $host=(int)$db->lastInsertId();
 $db->prepare('INSERT INTO game_sessions(id,game_id,status,version,state_json,viewer_token) VALUES (?,\'spin-wheel\',\'active\',1,\'{}\',?)')->execute([$session,bin2hex(random_bytes(24))]);
 ProofHandoff::activate($db,$session,$claim,$host);
 $q=$db->prepare('SELECT * FROM controller_proof_state WHERE session_id=?');$q->execute([$session]);$proof=$q->fetch();
 verifyPhoneDb((int)$proof['phone_camera_enabled']===0&&(int)$proof['consent_ack']===0,'New prize proof defaults phone capture and consent off');
 $db->prepare('UPDATE controller_proof_state SET phone_camera_enabled=1,consent_ack=1,capture_phase=\'live\' WHERE session_id=?')->execute([$session]);
 $q->execute([$session]);$proof=$q->fetch();
 $pair=['status'=>'paired','host_admin_id'=>$host,'paired_expires_at'=>gmdate('Y-m-d H:i:s',time()+120)];
 PhoneCapturePolicy::verify($pair,$proof,$session,$claim,time());verifyPhoneDb(true,'Host enable plus consent accepts approved phone');
 ProofHandoff::activate($db,$session,bin2hex(random_bytes(16)),$host);
 $q->execute([$session]);$proof=$q->fetch();
 verifyPhoneDb((int)$proof['phone_camera_enabled']===0&&(int)$proof['consent_ack']===0,'New prize handoff revokes old camera permission and consent');
 echo "V5Q.28B MARIA DB POLICY INTEGRATION PASSED\n";
}finally{
 $db->prepare('DELETE FROM controller_proof_state WHERE session_id=?')->execute([$session]);
 $db->prepare('DELETE FROM game_sessions WHERE id=?')->execute([$session]);
 if($host)$db->prepare('DELETE FROM admins WHERE id=?')->execute([$host]);
}
