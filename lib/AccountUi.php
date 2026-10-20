<?php
declare(strict_types=1);
function accountAvatarPath(int $id):string{return LUCKY_ROOT.'/storage/avatars/'.$id.'.image';}
function accountIdentity():array {
 $id=(int)($_SESSION['admin_id']??0);$name='Host';
 if($id>0){try{$q=db()->prepare('SELECT username FROM admins WHERE id=?');$q->execute([$id]);$name=(string)($q->fetchColumn()?:'Host');}catch(Throwable $e){error_log('Lucky account header: '.$e->getMessage());}}
 return ['id'=>$id,'username'=>$name,'hasAvatar'=>$id>0&&is_file(accountAvatarPath($id))];
}
function accountMenu():void {
 if(!isAdmin())return;
 $account=accountIdentity();
 $image=$account['hasAvatar']?'<img src="account.php?action=avatar&amp;v='.h((string)filemtime(accountAvatarPath($account['id']))).'" alt="">':'<span class="profile-avatar-fallback" aria-hidden="true">★</span>';
 echo '<details class="account-menu"><summary aria-label="Account menu for '.h($account['username']).'"><span class="profile-avatar">'.$image.'</span><span class="account-name">'.h($account['username']).'</span><span aria-hidden="true">⌄</span></summary><div class="account-dropdown"><div class="account-dropdown-name">Signed in as <strong>'.h($account['username']).'</strong></div><a href="admin.php">⚙ Admin Studio</a><a href="account.php">◉ Account settings</a><form action="account.php" method="post"><input type="hidden" name="csrf" value="'.h(csrf()).'"><input type="hidden" name="action" value="logout"><button type="submit">↪ Sign out</button></form></div></details>';
}
