/* Owner-reviewed Claude editor proposals. No direct database writes or media uploads. */
(() => {
 'use strict';const $=id=>document.getElementById(id),L=window.Lucky;
 if(!$('editor-ai-skill')||!L)return;
 let skills=[],draft=null,configured=false,busy=false;
 const note=(message,error=false)=>{const el=$('editor-ai-feedback');el.textContent=message;el.classList.toggle('platform-error',error);};
 async function request(action,body){const opts={credentials:'same-origin',cache:'no-store'};
  if(body!==undefined){const me=await L.getMe();opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':me.csrf};opts.body=JSON.stringify(body);}
  const response=await fetch('api/video-ai.php?action='+encodeURIComponent(action),opts);
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'AI request failed.');return data;
 }
 function clear(){draft=null;$('editor-ai-review').hidden=true;$('editor-ai-operations').replaceChildren();}
 function explain(tool){return ({'timeline.reorder':'Reorder existing clips','clip.trim':'Adjust clip trim','clip.look':'Apply a visual filter',
  'clip.transition':'Change transition','clip.overlay':'Add clip text','clip.mute':'Adjust source audio',
  'timeline.title':'Add a timed title','audio.background':'Set an uploaded soundtrack'})[tool]||tool;}
 function showProposal(proposal,signature){clear();draft={proposal,signature};
  $('editor-ai-summary').textContent=proposal.summary||'Suggested timeline changes';
  for(const step of proposal.operations){const li=document.createElement('li');li.className='video-ai-operation';
   const label=document.createElement('strong');label.textContent=explain(step.tool);
   const args=document.createElement('small');args.textContent=JSON.stringify(step.args);
   li.append(label,args);$('editor-ai-operations').append(li);}
  $('editor-ai-review').hidden=false;$('editor-ai-apply').disabled=proposal.operations.length===0;
  note(`${proposal.operations.length} non-destructive suggestion(s). Review each, then apply to the unsaved timeline.`);
 }
 $('editor-ai-skill').onchange=()=>{$('editor-ai-skill-help').textContent=skills.find(s=>s.id===$('editor-ai-skill').value)?.description||'';clear();};
 $('editor-ai-instruction').oninput=clear;
 $('editor-ai-generate').onclick=async()=>{
  if(busy||!configured)return;if(!window.LuckyEditorAgent)return note('Editor is still loading.',true);
  clear();busy=true;$('editor-ai-generate').disabled=true;
  try{const snapshot=window.LuckyEditorAgent.snapshot();
   note('Requesting a metadata-only editing proposal from Claude…');
   const result=await request('plan',{session:snapshot.session,edits:snapshot.edits,skill:$('editor-ai-skill').value,instruction:$('editor-ai-instruction').value.trim()});
   showProposal(result.proposal,snapshot.signature);
  }catch(error){note(error.message,true);}finally{busy=false;$('editor-ai-generate').disabled=!configured;}
 };
 $('editor-ai-apply').onclick=()=>{if(!draft)return;
  try{const count=window.LuckyEditorAgent.apply(draft.proposal,draft.signature);clear();note(`${count} AI suggestion(s) applied. Review the timeline and click Save edit.`);}
  catch(error){clear();note(error.message,true);}
 };
 $('editor-ai-discard').onclick=()=>{clear();note('AI proposal discarded. Timeline unchanged.');};
 request('status').then(data=>{
  skills=Array.isArray(data.skills)?data.skills:[];configured=!!data.claude?.configured;
  const select=$('editor-ai-skill');select.replaceChildren();
  for(const skill of skills)select.add(new Option(skill.name,skill.id));
  $('editor-ai-generate').disabled=!configured||!skills.length;
  $('editor-ai-connection').textContent=configured?`● Claude ${data.claude.model} configured`:'Claude is not configured. Open Admin → AI Studio to add an API key.';
  $('editor-ai-connection').classList.toggle('video-ai-connected',configured);
  $('editor-ai-skill').dispatchEvent(new Event('change'));
 }).catch(error=>{configured=false;$('editor-ai-generate').disabled=true;note(error.message,true);});
})();
