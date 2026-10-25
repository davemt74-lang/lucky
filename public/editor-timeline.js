/* Shared, non-destructive timeline edit utilities. */
(() => {
 'use strict';
 const copy=value=>JSON.parse(JSON.stringify(value));
 function history(limit=60){
  let entries=[],position=-1;
  const fingerprint=value=>JSON.stringify(value);
  return {
   reset(value){entries=[copy(value)];position=0;},
   record(value){
    const next=copy(value);
    if(position>=0&&fingerprint(entries[position])===fingerprint(next))return false;
    entries=entries.slice(0,position+1);
    entries.push(next);
    if(entries.length>limit)entries.shift();
    position=entries.length-1;
    return true;
   },
   undo(){if(position<=0)return null;return copy(entries[--position]);},
   redo(){if(position<0||position>=entries.length-1)return null;return copy(entries[++position]);},
   get canUndo(){return position>0;},
   get canRedo(){return position>=0&&position<entries.length-1;},
   get length(){return entries.length;}
  };
 }
 function snapTime(value,points=[],enabled=true,threshold=140,grid=500){
  if(!enabled||!Number.isFinite(value))return value;
  const candidates=[Math.round(value/grid)*grid,...points.filter(Number.isFinite)];
  let nearest=value,distance=threshold+1;
  for(const candidate of candidates){const d=Math.abs(candidate-value);if(d<distance){distance=d;nearest=candidate;}}
  return distance<=threshold?nearest:value;
 }
 function peakSamples(audioBuffer,count=96){
  const channels=Math.max(0,Number(audioBuffer?.numberOfChannels||0));
  const frames=Number(audioBuffer?.length||0);
  if(!channels||!frames||count<1)return [];
  const samples=Array.from({length:channels},(_,i)=>audioBuffer.getChannelData(i));
  const result=[];
  for(let i=0;i<count;i++){
   const start=Math.floor(i*frames/count),end=Math.max(start+1,Math.floor((i+1)*frames/count));
   let peak=0;
   for(let frame=start;frame<Math.min(frames,end);frame+=Math.max(1,Math.floor((end-start)/96))){
    for(const channel of samples)peak=Math.max(peak,Math.abs(channel[frame]||0));
   }
   result.push(Math.max(0,Math.min(1,peak)));
  }
  return result;
 }
 window.LuckyTimeline={history,snapTime,peakSamples};
})();
