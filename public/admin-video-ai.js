/* Claude settings are write-only: neither saved secrets nor ciphertext reach the browser. */
(() => {
 'use strict';const $=id=>document.getElementById(id),L=window.Lucky;
 if(!$('video-ai-settings-form')||!L)return;
 let configured=false,busy=false;
 const note=(text,error=false)=>{const el=$('video-ai-feedback');el.textContent=text;el.classList.toggle('platform-error',error);};
 const request=async(action,body)=>{
  const opts={credentials:'same-origin',cache:'no-store'};
  if(body!==undefined){const me=await L.getMe();opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':me.csrf};opts.body=JSON.stringify(body);}
  const response=await fetch('api/video-ai.php?action='+encodeURIComponent(action),opts);
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'Claude request failed.');return data;
 };
 function update(state){configured=!!state.configured;
  $('video-ai-state').textContent=configured?'● Claude configured':'Not configured';
  $('video-ai-state').classList.toggle('video-ai-connected',configured);
  $('video-ai-test').disabled=!configured;$('video-ai-disconnect').disabled=!configured;
  if(state.model&&[...$('video-ai-model').options].some(o=>o.value===state.model))$('video-ai-model').value=state.model;
 }
 async function run(task){if(busy)return;busy=true;
  for(const el of $('video-ai-settings-form').querySelectorAll('button'))el.disabled=true;
  try{await task();}catch(error){note(error.message,true);}
  finally{busy=false;update({configured,model:$('video-ai-model').value});$('video-ai-settings-form').querySelector('button[type=submit]').disabled=false;}
 }
 $('video-ai-settings-form').onsubmit=e=>{e.preventDefault();void run(async()=>{
  const key=$('video-ai-key').value.trim();if(!key&&!configured)throw Error('Enter an API key to configure Claude.');
  const data=await request('settings_save',{apiKey:key,model:$('video-ai-model').value});
  $('video-ai-key').value='';update(data.claude);note('Claude key encrypted and saved on this server. You can now test the connection.');
 });};
 $('video-ai-test').onclick=()=>run(async()=>{const data=await request('test',{});note(data.connected?'Claude connection verified. Video Studio skills are ready.':'Claude did not confirm the connection.',!data.connected);});
 $('video-ai-disconnect').onclick=()=>{if(!confirm('Remove the Claude API key from this game installation?'))return;
  void run(async()=>{const data=await request('settings_remove',{confirm:true});update(data.claude);$('video-ai-key').value='';note('Claude disconnected. The video editor continues to work locally.');});
 };
 request('status').then(data=>{update(data.claude);const root=$('video-ai-admin-skills');
  for(const skill of data.skills||[]){const card=document.createElement('article');card.className='video-ai-skill-card';
   const heading=document.createElement('strong');heading.textContent=skill.name;
   const detail=document.createElement('p');detail.textContent=skill.description;
   const tools=document.createElement('small');tools.textContent='Tools: '+skill.tools.join(', ');
   card.append(heading,detail,tools);root.append(card);}
  note(configured?'Claude is configured. Use Test connection when you are ready.':'Claude is off. Paste a key to enable optional video-editing proposals.');
 }).catch(e=>note(e.message,true));
})();
