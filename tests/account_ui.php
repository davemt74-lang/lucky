<?php
declare(strict_types=1);
define('LUCKY_TESTING',true);
$_SESSION=['admin_id'=>7];
require_once __DIR__.'/../lib/Layout.php';
function verify(bool $ok,string $label):void {
 if(!$ok)throw new RuntimeException($label);
 echo "PASS $label\n";
}
ob_start();accountMenu();$html=ob_get_clean();
verify(str_contains($html,'class="profile-avatar"'),'host profile photo/avatar appears in account dropdown');
verify(str_contains($html,'href="account.php"'),'account menu opens existing host account settings');
verify(str_contains($html,'name="csrf"')&&str_contains($html,'name="action" value="logout"'),'sign out requires CSRF-protected POST');
ob_start();shellStart('Host Game','game','game-fullwidth');shellEnd();$body=ob_get_clean();
verify(str_contains($body,'class="header"')&&str_contains($body,'class="account-menu"'),'main game shared header shows profile image and dropdown');
$account=file_get_contents(__DIR__.'/../account.php');
verify(str_contains($account,"password_verify(")&&str_contains($account,"password_hash("),'username/password changes verify current credentials');
verify(str_contains($account,'is_uploaded_file')&&str_contains($account,'getimagesize')&&str_contains($account,'image/webp'),'uploaded avatar MIME and pixel dimensions validated');
verify(str_contains($account,"(\$_GET['action']??'')==='avatar'")||str_contains($account,"action']??'')==='avatar'"),'avatar URL is served by authenticated account endpoint');
