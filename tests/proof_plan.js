'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const {build,run}=require('../public/proof-sequence.js');
 const pass=(label,fn)=>test('Proof plan: '+label,{timeout:1000},fn);
 const cams=[{id:'usb-A',label:'USB Front'},{id:'usb-B',label:'USB Side'}];
 const configured=[{label:'Prize hero angle',seconds:3},{label:'Winner reaction',seconds:9},{label:'Extra camera',seconds:4}];
 const p=build(cams,configured);
 pass('active camera count determines proof angles',()=>assert.equal(p.length,2));
 pass('prize names map by view index',()=>assert.deepEqual(p.map(x=>x.angleLabel),['Prize hero angle','Winner reaction']));
 pass('camera names remain separate',()=>assert.deepEqual(p.map(x=>x.cameraName),['USB Front','USB Side']));
 pass('time per view honors prize settings',()=>assert.deepEqual(p.map(x=>x.seconds),[3,9]));
 pass('default new camera is named automatically',()=>assert.equal(build(cams,[])[1].angleLabel,'USB Side'));
 pass('invalid timing falls back to 8 seconds',()=>assert.equal(build(cams,[{label:'ok',seconds:900}])[0].seconds,8));
 pass('no cameras cannot start',()=>assert.throws(()=>build([],[])));
 pass('more than 4 cameras rejected',()=>assert.throws(()=>build([...cams,...cams,...cams],[])));
test('Proof sequence records in order and announces each countdown', {timeout:1000}, async () => {
 const events=[],recorded=[];
 await run(p,{announce:e=>events.push(e),record:async a=>recorded.push(a),sleep:async()=>{}});
 assert.deepEqual(events.filter(e=>e.phase==='countdown').map(e=>e.remaining),[3,2,1,3,2,1]);
 assert.deepEqual(recorded.map(e=>e.id),['usb-A','usb-B']);
 assert.equal(events.find(e=>e.phase==='countdown').next.cameraName,'USB Side');
 assert.equal(events.filter(e=>e.phase==='recording')[1].next,null);
});
test('Proof sequence cancellation prevents recording after stop', {timeout:1000}, async () => {
 const controller=new AbortController();let aborted=[];
 await assert.rejects(run(p,{signal:controller.signal,announce:e=>{aborted.push(e);if(e.phase==='countdown'&&e.remaining===2)controller.abort();},record:async()=>{throw Error('Should not record');},sleep:async(ms,signal)=>{if(signal?.aborted)throw Error('Capture stopped.');}}),/Capture stopped/);
 assert.equal(aborted.at(-1).remaining,2);
});
