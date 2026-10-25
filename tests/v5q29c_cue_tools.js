const assert=require('node:assert/strict');
const {plan}=require('../public/editor-cue-tools.js');
let pass=0;const verify=(label,test)=>{test();pass++;process.stdout.write('PASS '+label+'\n');};
const video=Object.freeze({kind:'video',durationMs:8000,trimStartMs:1000,trimEndMs:7000});
verify('Set In preserves Out and uses exact source time',()=>{
 assert.deepEqual(plan(video,2500,'in'),{trimStartMs:2500,trimEndMs:7000,split:false});
});
verify('Set Out preserves In and uses exact source time',()=>{
 assert.deepEqual(plan(video,5600,'out'),{trimStartMs:1000,trimEndMs:5600,split:false});
});
verify('Split creates two contiguous non-overlapping ranges',()=>{
 const p=plan(video,3500,'split');assert.equal(p.trimEndMs,p.secondStartMs);
 assert.equal(p.secondEndMs,7000);assert.equal(p.trimStartMs,1000);
});
verify('Audio sources support all marker actions',()=>{
 assert.equal(plan({...video,kind:'audio'},4000,'split').split,true);
});
verify('Reject still images, unknown durations, invalid markers and unsafe durations',()=>{
 for(const [clip,at,action] of [
  [{...video,kind:'photo'},3000,'in'],[{...video,durationMs:0},3000,'out'],
  [video,-1,'in'],[video,9000,'out'],[video,1000,'in'],[video,6900,'in'],
  [video,1200,'out'],[video,650,'split'],[video,6700,'split'],
  [video,NaN,'split'],[video,'2000','split'],[video,2000,'delete']
 ])assert.throws(()=>plan(clip,at,action));
});
verify('Planning does not mutate original timeline source',()=>{
 plan(video,3500,'split');assert.deepEqual(video,{kind:'video',durationMs:8000,trimStartMs:1000,trimEndMs:7000});
});
console.log(`V5Q.29C SOURCE MARKER EDITS: ${pass}/${pass} PASS`);
