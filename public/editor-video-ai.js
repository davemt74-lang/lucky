/* Owner-reviewed Claude editor proposals. No direct database writes or media uploads. */
(() => {
 'use strict';const $=id=>document.getElementById(id),L=window.Lucky;
 if(!$('editor-ai-skill')||!L)return;
 let skills=[],draft=null,configured=false,busy=false,analyzing=false,analyzedSession='';
 const note=(message,error=false)=>{const el=$('editor-ai-feedback');el.textContent=message;el.classList.toggle('platform-error',error);};
 const analysisNote=(message,error=false)=>{const el=$('editor-ai-analysis-state');el.textContent=message;el.classList.toggle('platform-error',error);};
 async function request(action,body){const opts={credentials:'same-origin',cache:'no-store'};
  if(body!==undefined){const me=await L.getMe();opts.method='POST';opts.headers={'Content-Type':'application/json','X-CSRF-Token':me.csrf};opts.body=JSON.stringify(body);}
  const response=await fetch('api/video-ai.php?action='+encodeURIComponent(action),opts);
  const data=await response.json();if(!response.ok||!data.ok)throw Error(data.error||'AI request failed.');return data;
 }
 function clear(){draft=null;$('editor-ai-review').hidden=true;$('editor-ai-operations').replaceChildren();}
 function explain(tool){return ({'timeline.reorder':'Reorder existing clips','clip.trim':'Adjust clip trim','clip.look':'Apply a visual filter',
  'clip.transition':'Change transition','clip.overlay':'Add clip text','clip.mute':'Adjust source audio',
  'timeline.title':'Add a timed title','audio.background':'Set an uploaded soundtrack'})[tool]||tool;}
 function markersFor(row,source,signals){
  if(!source.instanceId||!window.LuckyEditorAgent?.applyCue)return;
  const markerList=document.createElement('div');markerList.className='video-ai-marker-list';
  const make=(at,label,actions)=>{
   if(!Number.isInteger(at)||at<0)return;
   const line=document.createElement('div');line.className='video-ai-marker';
   const caption=document.createElement('span');caption.textContent=label+' · '+(at/1000).toFixed(1)+'s';line.append(caption);
   for(const [action,title] of actions){
    const button=document.createElement('button');button.type='button';button.className='btn btn-outline';button.textContent=title;
    button.setAttribute('aria-label',`${title} at ${(at/1000).toFixed(1)} seconds of ${source.title}`);
    button.onclick=()=>{try{window.LuckyEditorAgent.applyCue(source.instanceId,at,action);clear();note(`${title} applied to the unsaved timeline. Undo or Save edit as needed.`);}
     catch(error){note(error.message,true);}};
    line.append(button);
   }
   markerList.append(line);
  };
  for(const at of (signals.sceneCutsMs||[]).slice(0,5))make(at,'Scene cut',[['in','Set In'],['out','Set Out'],['split','Split']]);
  for(const range of (signals.silenceRanges||[]).slice(0,4)){
   if(!range||!Number.isInteger(range.startMs)||!Number.isInteger(range.endMs))continue;
   make(range.startMs,'Quiet starts',[['out','Set Out']]);
   make(range.endMs,'Quiet ends',[['in','Set In']]);
  }
  if(markerList.childElementCount){const title=document.createElement('small');title.className='video-ai-marker-target';title.textContent='Timeline clip: '+source.title;row.append(title,markerList);}
 }
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
 $('editor-ai-share-cues').onchange=clear;
 $('editor-ai-analyze').onclick=async()=>{
  if(analyzing||busy)return;if(!window.LuckyEditorAgent)return analysisNote('Editor is still loading.',true);
  let snapshot;try{snapshot=window.LuckyEditorAgent.snapshot();}catch(error){return analysisNote(error.message,true);}
  const unique=new Map();for(const source of snapshot.analyzable||[]){if(source.id&&!unique.has(source.id))unique.set(source.id,source);}
  const sources=[...unique.values()].slice(0,12);
  if(!sources.length)return analysisNote('No video or audio in the selected timeline. Still photos do not have timing cues.');
  if(analyzedSession!==snapshot.session){$('editor-ai-analysis-results').replaceChildren();analyzedSession=snapshot.session;}
  analyzing=true;$('editor-ai-analyze').disabled=true;clear();
  let done=0;
  try{
   for(const source of sources){
    analysisNote(`Analyzing ${done+1} of ${sources.length} recordings locally…`);
    const result=await request('analyze',{session:snapshot.session,id:source.id});
    // The response contains only source timings; never display or upload source bytes.
    const cues=result.signals||{};
    const cuts=Array.isArray(cues.sceneCutsMs)?cues.sceneCutsMs:[];
    const quiet=Array.isArray(cues.silenceRanges)?cues.silenceRanges:[];
    const label=(source.title||source.kind||'Recording').slice(0,70);
    const row=document.createElement('li');
    row.textContent=`${label}: ${cuts.length} scene changes, ${quiet.length} quiet sections`;
    for(const target of (snapshot.analyzable||[]).filter(x=>x.id===source.id))markersFor(row,target,cues);
    row.dataset.sourceId=source.id;
    const existing=[...$('editor-ai-analysis-results').children].find(x=>x.dataset.sourceId===source.id);
    if(existing)existing.replaceWith(row);else $('editor-ai-analysis-results').append(row);
    done++;
   }
   analysisNote(`Local analysis ready for ${done} recording(s). Tick the timing-cues box if you want Claude to use these markers.`);
  }catch(error){analysisNote(`Analyzed ${done} recording(s). ${error.message}`,true);}
  finally{analyzing=false;$('editor-ai-analyze').disabled=false;}
 };
 $('editor-ai-generate').onclick=async()=>{
  if(busy||analyzing||!configured)return;if(!window.LuckyEditorAgent)return note('Editor is still loading.',true);
  clear();busy=true;$('editor-ai-generate').disabled=true;
  try{const snapshot=window.LuckyEditorAgent.snapshot();
   const includeTimingCues=$('editor-ai-share-cues').checked===true;
   note(includeTimingCues?'Requesting an editing proposal with approved numerical timing cues…':'Requesting a metadata-only editing proposal from Claude…');
   const result=await request('plan',{session:snapshot.session,edits:snapshot.edits,skill:$('editor-ai-skill').value,instruction:$('editor-ai-instruction').value.trim(),includeTimingCues});
   showProposal(result.proposal,snapshot.signature);
   if(includeTimingCues)analysisNote(`${Number(result.timingCuesShared||0)} recording(s) with cached timing cues included in this Claude proposal.`);
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
