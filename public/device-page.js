(() => {
'use strict';const area=document.getElementById('controller-list'),status=document.getElementById('controller-status');
function refresh(){let pads=[];try{pads=Array.from(navigator.getGamepads?.()||[]).filter(Boolean);}catch(_){status.textContent='Gamepad access is not available in this browser.';return;}
area.replaceChildren();if(!pads.length){status.textContent='No controller detected. Connect one and press a button.';return;}
status.textContent=`${pads.length} controller${pads.length===1?'':'s'} available.`;
for(const pad of pads){const line=document.createElement('div');line.className='platform-claim';const name=document.createElement('strong');name.textContent=pad.id||'Game controller';const state=document.createElement('span');state.textContent=`${pad.buttons.length} buttons · ${pad.axes.length} axes · ${pad.connected?'Connected':'Disconnected'}`;line.append(name,state);area.append(line);}}
document.getElementById('controller-refresh').onclick=refresh;window.addEventListener('gamepadconnected',refresh);window.addEventListener('gamepaddisconnected',refresh);refresh();
})();
