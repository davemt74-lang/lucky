<?php
declare(strict_types=1);
function audioCheck(bool $ok,string $name):void {if(!$ok)throw new RuntimeException('HF8C3: '.$name);echo "PASS $name\n";}
$root=dirname(__DIR__);
$read=static fn(string $path):string=>(string)file_get_contents($root.'/'.$path);
$mixer=$read('public/camera-director.js');$publisher=$read('public/director-publish.js');
$viewer=$read('public/director-view.js');$page=$read('proof.php');$live=$read('live-view.php');$css=$read('public/platform.css');$layout=$read('lib/Layout.php');
audioCheck(str_contains($page,'director-audio-volume')&&str_contains($page,'director-audio-mute')&&str_contains($page,'director-host-mic'),'Host volume, mute, and opt-in microphone controls');
audioCheck(str_contains($mixer,'createMediaStreamDestination')&&str_contains($mixer,'createMediaStreamSource'),'Web Audio mixes incoming phone and microphone tracks');
audioCheck(str_contains($mixer,'createMediaElementSource')&&str_contains($mixer,'clipVideo.muted=false'),'Saved clip sound routes into mixer rather than speakers');
audioCheck(str_contains($mixer,'if(isDesktop)')&&str_contains($mixer,"key:'host-mic'")&&str_contains($mixer,"id.startsWith('phone:')"),'Program audio follows the selected live source');
audioCheck(str_contains($mixer,'routeAudio(kind===')&&str_contains($mixer,'linearRampToValueAtTime'),'Audio transitions alongside camera Take');
audioCheck(str_contains($mixer,'state.audioMuted')&&str_contains($mixer,'state.audioVolume'),'Output gain has mute and volume controls');
audioCheck(str_contains($mixer,'getUserMedia({video:false,audio:')&&str_contains($mixer,'toggleHostMicrophone'),'Desktop mic never starts without host action');
audioCheck(str_contains($mixer,'hostMicrophone?.getTracks().forEach(t=>t.stop())')&&str_contains($mixer,'resetAudio()'),'Mic and graph released on proof closure');
audioCheck(str_contains($mixer,'audioOutput?.stream.getAudioTracks()[0]')&&str_contains($mixer,'result.addTrack(track.clone())'),'Stable isolated audio track in Program stream');
audioCheck(str_contains($mixer,'exportStream=programStream(false)')&&str_contains($mixer,'codecs=vp8,opus')&&str_contains($mixer,'codecs=vp8'),'Export records audio when available and remains video-only when silent');
audioCheck(str_contains($publisher,'for(const track of stream.getTracks())peer.addTrack(track,stream)'),'Live View publishes Program audio with video');
audioCheck(str_contains($viewer,"peer.addTransceiver('audio'")&&str_contains($live,'director-view-sound')&&str_contains($viewer,'view.muted=!view.muted'),'Viewer receives audio and explicitly unmutes');
audioCheck(str_contains($page,'camera-director.js?v=5q29e4d1hf8c3')&&str_contains($live,'director-view.js?v=5q29e4d1hf8c3')&&str_contains($layout,'platform.css?v=5q29e4d1hf8c3')&&str_contains($css,'#director-view-sound'),'Release assets cache-busted together');
echo "HF8C3 PROGRAM AUDIO CONTRACT: 13/13\n";
