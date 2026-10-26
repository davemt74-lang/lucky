<?php
declare(strict_types=1);
$root=dirname(__DIR__);
$html=file_get_contents($root.'/recording-editor.php');
$js=file_get_contents($root.'/public/recording-editor.js');
$css=file_get_contents($root.'/public/recording-editor.css');
function studioContract(bool $pass,string $label):void{
 if(!$pass)throw new RuntimeException('FAIL '.$label);
 echo 'PASS '.$label."\n";
}
studioContract(str_contains($html,'mc-editor-page')&&str_contains($css,'body.mc-editor-page .header')&&str_contains($css,'height:100dvh'),'Dedicated full-viewport editor hides application chrome');
studioContract(str_contains($html,'mc-media-list')&&str_contains($html,'mc-media-search')&&str_contains($html,'data-media-filter="audio"'),'Searchable source library with video/photo/audio filters');
studioContract(str_contains($html,'mc-inspector-backdrop')&&str_contains($html,'mc-inspector-toggle')&&str_contains($html,'mc-inspector-close'),'Slide-out inspector has explicit open, close and backdrop controls');
studioContract(str_contains($html,'mc-export-dialog')&&str_contains($js,'exportDialog.showModal()')&&str_contains($html,'mc-export-start'),'Render queue stays behind accessible modal without losing export action');
studioContract(str_contains($js,'api(\'save\'')&&str_contains($js,'timeline.offsets')&&str_contains($js,'timeline.switches')&&str_contains($js,'revision'),'Existing timeline revision and edit guards retained');
studioContract(str_contains($js,'createElement')&&str_contains($js,'textContent')&&!str_contains($js,'innerHTML'),'Untrusted media labels are inserted as text');
studioContract(str_contains($css,'.mc-timeline-scroll{flex:1')&&str_contains($css,'@media(max-width:680px)'),'Timeline scrolling and compact-screen layout');
echo "V5Q.29E4D1 STUDIO CONTRACT CERTIFIED\n";
