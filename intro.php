<?php
declare(strict_types=1);
require __DIR__.'/lib/Bootstrap.php';require_once __DIR__.'/lib/GameCatalog.php';
if(!installed()){header('Location: install.php');exit;}headerSecurity();
$game=strval($_GET['game']??'spin-wheel');
if(!preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D',$game)){http_response_code(404);exit('Game unavailable.');}
$module=GameRegistry::modules()[$game]??null;
if(!$module){http_response_code(404);exit('Game unavailable.');}
$path=strval($module->manifest()['path']??'');
if(!preg_match('~^(?:game|package-game)\.php(?:\?[a-z0-9_=&%-]+)?$~iD',$path)){http_response_code(404);exit('Game unavailable.');}
$background=GameCatalog::load($game)['images']['splash']??null;
$bgUrl=$background?'api/index.php?'.http_build_query(['action'=>'game_art','id'=>$game,'file'=>$background]):null;

?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Game Intro — Lucky Games</title><link rel="stylesheet" href="public/style.css"><link rel="stylesheet" href="public/platform.css"></head><body data-page="intro" data-game="<?=htmlspecialchars($game,ENT_QUOTES,'UTF-8')?>"><main class="intro-stage"><?php if($bgUrl):?><img id="intro-background" class="intro-background" src="<?=htmlspecialchars($bgUrl,ENT_QUOTES,'UTF-8')?>" alt="" aria-hidden="true"><?php endif;?><div class="intro-ambient intro-ambient-a"></div><div class="intro-ambient intro-ambient-b"></div><div class="intro-top"><a class="intro-back" href="index.php">← All games</a><span class="mini-label">LUCKY GAMES · GAME INTRO</span></div><div class="intro-center"><span class="intro-icon">✦</span><span class="mini-label">WELCOME TO THE GAME</span><h1 id="intro-title">Spin the Wheel</h1><p id="intro-subtitle">Get ready to play</p><div id="intro-status" class="intro-message" role="status">Choose your intro music or enter the game.</div><div class="intro-actions"><button class="btn btn-outline" type="button" id="intro-play">♫ Play intro</button><button class="btn btn-outline" type="button" id="intro-stop">Ⅱ Stop intro</button><a class="btn btn-primary" href="<?=htmlspecialchars($path,ENT_QUOTES,'UTF-8')?>" id="intro-enter">▶ Enter Game</a></div></div><div class="intro-bottom"><span>INTRO MUSIC OPTIONAL</span><a href="index.php">Game Hub ↗</a></div></main><script src="public/client.js" defer></script><script src="public/intro.js?v=5q26c-splash" defer></script></body></html>
