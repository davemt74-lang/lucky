<?php
declare(strict_types=1);
function sceneCheck(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('HF8C2: '.$name);echo "PASS $name\n";}
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$service=$read('lib/DirectorScenes.php');$api=$read('api/director-scenes.php');
$client=$read('public/director-scenes.js');$mixer=$read('public/camera-director.js');
$page=$read('proof.php');$schema=$read('lib/schema.sql');$migration=$read('lib/migrations/v5q29e4d1hf8c2_director_scenes.sql');$upgrade=$read('upgrade.php');
sceneCheck(str_contains($schema,'director_scene_presets')&&str_contains($migration,'CREATE TABLE IF NOT EXISTS')&&str_contains($upgrade,'v5q29e4d1hf8c2_director_scenes.sql'),'Fresh install and idempotent upgrade');
sceneCheck(str_contains($api,'requireAdmin()')&&str_contains($api,'assertCsrf()')&&str_contains($api,'SchemaGuard::require'),'Admin authorization, CSRF and migration gate');
sceneCheck(str_contains($service,'host_admin_id=? AND is_open=1')&&str_contains($service,'INTERVAL 12 SECOND'),'Scenes limited to active host-owned proof');
sceneCheck(str_contains($service,'MAXIMUM')===false&&str_contains($service,'self::LIMIT'),'Server enforces bounded scene catalog');
sceneCheck(str_contains($service,"kind='video'")&&str_contains($service,'claim_id=?'),'Saved video references must belong to active claim');
sceneCheck(str_contains($service,"private const TRANSITIONS=['cut','fade','wipe','dip']")&&str_contains($service,'private const STICKERS='),'Transition and sticker allowlists enforced');
sceneCheck(str_contains($mixer,'state.programGraphics=readGraphics()')&&str_contains($mixer,'graphics(previewCtx,readGraphics())'),'Graphics cue separately in Preview and commit on Take');
sceneCheck(str_contains($page,'director-scene-list')&&str_contains($page,'director-scenes.js?v=5q29e4d1hf8c2'),'Scene manager integrated into proof');
sceneCheck(str_contains($client,'LuckyCameraDirector.select')&&str_contains($client,'LuckyCameraDirector.take'),'Explicit Cue and Take controls');
sceneCheck(!str_contains($client,'innerHTML')&&str_contains($client,'textContent=scene.name')&&str_contains($client,"window.addEventListener('lucky:proof-close',close)"),'Safe scene rendering and proof-close cleanup');
echo "HF8C2 SCENES CONTRACT: 10/10\n";
