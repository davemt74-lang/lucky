<?php
declare(strict_types=1);
/** HF8C6 production-workspace contract: no replacement of governed broadcast APIs. */
$root=dirname(__DIR__);
$file=static fn(string $name):string=>(string)file_get_contents($root.'/'.$name);
$page=$file('director.php');$workspace=$file('public/director-workspace.js');$css=$file('public/director-workspace.css');
$studio=$file('public/director-studio.js');$pub=$file('public/director-publish.js');$proof=$file('proof.php');
$check=static function(bool $ok,string $message):void {if(!$ok)throw new RuntimeException('HF8C6: '.$message);echo "PASS $message\n";};
$check(preg_match('~director-workspace\.css\?v=hf8c[67]~',$page)===1&&preg_match('~director-workspace\.js\?v=hf8c[67]~',$page)===1,'Redesigned workspace styles and behavior are cache-busted');
$check(str_contains($workspace,"const monitors = director?.querySelector('.director-monitors')")&&str_contains($workspace,"const grid = $('camera-grid')"),'Existing live monitors and real camera grid are reparented');
$check(str_contains($workspace,'shell.replaceChildren(workspace)')&&str_contains($workspace,"target('live').append(broadcast)")&&str_contains($workspace,"target('audio').append(audio)"),'Controls retain original DOM nodes and event handlers');
$check(str_contains($workspace,'target(\'settings\').append(director)')&&str_contains($workspace,'director.hidden = true'),'Original compositor root stays connected for live animation frames');
$check(str_contains($workspace,'data-director-panel="sessions"')&&str_contains($workspace,'data-director-panel="live"')&&str_contains($workspace,'drawer.hidden = true')&&str_contains($workspace,'openPanel(name)'),'Tools default closed and open only on request');
$check(str_contains($workspace,"get('media_list')")&&str_contains($workspace,"get('detail')")&&str_contains($workspace,'start_offset_ms')&&str_contains($workspace,'duration_ms'),'Timeline renders real recording-session media and estimated offsets');
$check(str_contains($workspace,'textContent=')&&str_contains($workspace,'clip.title=')&&!str_contains($workspace,'innerHTML = item.title'),'Titles are text, not executable media markup');
$check(str_contains($css,'grid-template-columns:repeat(4,minmax(0,1fr))')&&str_contains($css,'.director-workspace-outputs .director-monitors'),'Multiview and large Preview/Program remain first-class');
$check(str_contains($studio,"audio:false")&&str_contains($studio,"lucky:director-studio-close")&&str_contains($pub,"selectedVisibility()==='public'"),'Existing permission, session teardown, and public/private controls are unchanged');
$check(str_contains($proof,'capture-phone-camera')&&str_contains($file('public/platform.css'),'.proof-editor-page #camera-director{display:none'),'Prize capture remains separate from production Camera Director');
echo "HF8C6 WORKSPACE CONTRACT PASSED\n";
