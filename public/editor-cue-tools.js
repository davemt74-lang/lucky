/* Non-destructive source-time marker edits; also used in Node certification. */
((root) => {
 'use strict';
 const integer=x=>Number.isInteger(x)&&Number.isFinite(x);
 function plan(clip,atMs,action){
  if(!clip||!['video','audio'].includes(clip.kind))throw Error('Scene markers require a video or audio clip.');
  if(!['in','out','split'].includes(action))throw Error('Choose a supported marker action.');
  const full=Number(clip.durationMs||0),start=Number(clip.trimStartMs||0);
  const end=Number(clip.trimEndMs||0)||full;
  if(!integer(full)||!integer(start)||!integer(end)||full<500||start<0||end>full||end-start<500)
   throw Error('The source clip has an invalid or unknown duration.');
  if(!integer(atMs)||atMs<0||atMs>full)throw Error('Marker is outside the source recording.');
  if(action==='in'){
   if(atMs<=start||end-atMs<500)throw Error('Set In inside the current clip, leaving at least 0.5 seconds.');
   return {trimStartMs:atMs,trimEndMs:Number(clip.trimEndMs||0),split:false};
  }
  if(action==='out'){
   if(atMs>=end||atMs-start<500)throw Error('Set Out inside the current clip, leaving at least 0.5 seconds.');
   return {trimStartMs:start,trimEndMs:atMs,split:false};
  }
  if(atMs-start<500||end-atMs<500||atMs>=end)throw Error('Split marker must leave two segments of at least 0.5 seconds.');
  return {trimStartMs:start,trimEndMs:atMs,split:true,secondStartMs:atMs,secondEndMs:end};
 }
 const api={plan};
 if(typeof module!=='undefined'&&module.exports)module.exports=api;
 else root.LuckyEditorCues=api;
})(typeof window!=='undefined'?window:{});
