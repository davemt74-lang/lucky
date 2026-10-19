/* Shared spoken intent parser. Pure logic, usable by future platform games. */
(function(root,factory){const api=factory();root.LuckyCommands=api;if(typeof module==='object'&&module.exports)module.exports=api;})(typeof window!=='undefined'?window:globalThis,function(){
  'use strict';
  function parse(input){
    const value=String(input||'').toLowerCase().replace(/[.!?,]+/g,' ').replace(/\s+/g,' ').trim()
      .replace(/^(hey|okay|ok) (lucky games|lucky spin|wheel)[, ]*/,'').replace(/^please\s+/,'').trim();
    if(!value)return {type:'unknown'};
    const simple=[
      [/^(stop listening|microphone off|turn off listening|disable listening)$/,'stop-listening'],
      [/^(select|press select|enter|okay select|press enter)$/,'select'],
      [/^(up|move up|arrow up|navigate up)$/,'up'],
      [/^(down|move down|arrow down|navigate down)$/,'down'],
      [/^(left|move left|arrow left|navigate left)$/,'left'],
      [/^(right|move right|arrow right|navigate right)$/,'right'],
      [/^(menu|open menu|game menu)$/,'menu'],
      [/^(back|go back|close menu)$/,'back'],
      [/^(pause game|pause the game)$/,'pause'],
      [/^(resume game|resume the game)$/,'resume'],
      [/^(start game|begin game|start new game|new game)$/,'start'],
      [/^(end game|stop game|finish game)$/,'end'],
      [/^(confirm|yes|confirm end game|yes end game|confirm new game)$/,'confirm'],
      [/^(cancel|no|keep playing|cancel end game|go back)$/,'cancel'],
      [/^(continue|continue game|back to tier|return to tier|back to main wheel|back to wheel)$/,'continue'],
      [/^(close prize|close popup|close|done|press done|finish prize|dismiss prize)$/,'close-prize'],
      [/^(spin|spin wheel|spin the wheel|spin again|respin|re spin|next spin|go again|spin bonus wheel)$/,'spin'],
      [/^(tier one|tier 1|tier two|tier 2|show tiers|what tiers are on the wheel|what is left|how many prizes are left)$/,'remaining'],
      [/^(music on|turn music on|enable music)$/,'music-on'],
      [/^(music off|turn music off|disable music)$/,'music-off'],
      [/^(play music|resume music|resume song|play selected)$/,'music-play'],
      [/^(pause music|pause song|stop music)$/,'music-pause'],
      [/^(next (song|track)|skip (song|track))$/,'music-next'],
      [/^(previous (song|track)|last (song|track))$/,'music-previous'],
      [/^(mute|mute all|mute audio|sound off)$/,'mute'],
      [/^(unmute|unmute all|unmute audio|sound on)$/,'unmute'],
      [/^(sound effects on|effects on|turn effects on)$/,'effects-on'],
      [/^(sound effects off|effects off|turn effects off)$/,'effects-off'],
      [/^(announcements on|voice announcements on|speak prizes|voice on)$/,'speech-on'],
      [/^(announcements off|voice announcements off|stop announcements|voice off)$/,'speech-off'],
      [/^(open game hub|game hub|go home|all games|choose game|choose a game)$/,'home'],
      [/^(open prize studio|prize studio|manage prizes|edit prizes)$/,'studio'],
      [/^(open music library|music library|manage music)$/,'library'],
      [/^(open claimed list|claimed list|show claims|claim history)$/,'claims'],
      [/^(show game show mode|game show mode|full screen game|show mode)$/,'show-on'],
      [/^(exit game show mode|exit show mode|normal view|show mode off)$/,'show-off'],
      [/^(voice help|help with voice commands|list voice commands|what can i say)$/,'help'],
      [/^(what song is playing|what is playing|current song)$/,'now-playing'],
      [/^(list songs|what songs are available|available songs)$/,'list-songs']
    ];
    for(const [pattern,type] of simple)if(pattern.test(value))return {type};
    const volume=value.match(/^(?:(music|sound effects?|effects?|voice|speech|announcements?|master|all) )?volume (up|down)$/);
    if(volume)return {type:'volume-step',channel:channelName(volume[1]),step:volume[2]==='up'?10:-10};
    const volume2=value.match(/^(?:set )?(?:(music|sound effects?|effects?|voice|speech|announcements?|master|all) )?volume (?:to )?(\d{1,3})(?: percent|%)?$/);
    if(volume2)return {type:'volume-set',channel:channelName(volume2[1]),value:Math.min(100,Math.max(0,Number(volume2[2])))};
    const volume3=value.match(/^(?:set )?(music|sound effects?|effects?|voice|speech|announcements?|master|all) (?:to )?(\d{1,3})(?: percent|%)$/);
    if(volume3)return {type:'volume-set',channel:channelName(volume3[1]),value:Math.min(100,Math.max(0,Number(volume3[2])))};
    const song=value.match(/^(?:play|select|choose) (?:song |track )?(.+)$/);
    if(song)return {type:'music-title',title:song[1].trim()};
    return {type:'unknown'};
  }
  function channelName(value){if(/effect/.test(value||''))return 'effects';if(/^(voice|speech|announcement)/.test(value||''))return 'voice';if(/^(master|all)$/.test(value||''))return 'master';return 'music';}
  return Object.freeze({parse});
});
