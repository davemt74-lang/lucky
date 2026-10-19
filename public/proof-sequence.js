/* Testable 5-second countdown + timed, sequential multi-camera proof plan. */
(function(root,factory){
 const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.LuckyProofSequence=api;
})(typeof window==='undefined'?null:window,function(){
 'use strict';
 const MAX_CAMERAS=4,COUNTDOWN=5;
 function build(cameras,configured=[]){
  if(!Array.isArray(cameras)||!cameras.length||cameras.length>MAX_CAMERAS)throw Error('Choose 1–4 active cameras.');
  return cameras.map((camera,i)=>{
   const p=Array.isArray(configured)?configured[i]:null;
   const alias=String(camera.label||`Camera ${i+1}`).slice(0,120);
   const angle=String(p?.label||alias).trim().slice(0,80)||`Angle ${i+1}`;
   const seconds=Number(p?.seconds??8);
   return {id:camera.id,cameraName:alias,angleLabel:angle,index:i+1,seconds:Number.isInteger(seconds)&&seconds>=3&&seconds<=30?seconds:8};
  });
 }
 function wait(ms,signal){return new Promise((resolve,reject)=>{
  if(signal?.aborted)return reject(Error('Capture stopped.'));
  let timer;const cancel=()=>{clearTimeout(timer);reject(Error('Capture stopped.'));};
  timer=setTimeout(()=>{signal?.removeEventListener?.('abort',cancel);resolve();},ms);
  signal?.addEventListener?.('abort',cancel,{once:true});
 });}
 async function run(plan,{announce,record,signal,sleep=wait,countdown=COUNTDOWN}={}){
  if(!Array.isArray(plan)||!plan.length||!record||!announce)throw Error('No proof capture plan available.');
  let completed=0;
  for(let i=0;i<plan.length;i++){
   const now=plan[i],next=plan[i+1]||null;
   if(signal?.aborted)throw Error('Capture stopped.');
   for(let t=countdown;t>0;t--){announce({phase:'countdown',now,next,remaining:t,completed,total:plan.length});await sleep(1000,signal);}
   if(signal?.aborted)throw Error('Capture stopped.');
   announce({phase:'recording',now,next,remaining:now.seconds,completed,total:plan.length});
   await record(now,{signal,tick:s=>announce({phase:'recording',now,next,remaining:s,completed,total:plan.length})});
   completed++;
   announce({phase:'saved',now,next,remaining:0,completed,total:plan.length});
  }
  return completed;
 }
 return {MAX_CAMERAS,COUNTDOWN,build,run,wait};
});
