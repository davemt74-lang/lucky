'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const sandbox={window:{}};
vm.runInNewContext(fs.readFileSync(path.resolve(__dirname,'../public/editor-timeline.js'),'utf8'),sandbox);
const t=sandbox.window.LuckyTimeline;
let checks=0;
function check(name,run){run();checks++;console.log('PASS '+name);}
check('history begins with no undo or redo',()=>{const h=t.history();h.reset({clips:[1],layers:{}});assert.equal(h.canUndo,false);assert.equal(h.canRedo,false);});
check('undo, redo and branching do not leak shared state',()=>{
 const h=t.history(10);const current={clips:[{title:'Original'}],layers:{titles:[]}};
 h.reset(current);current.clips[0].title='Changed';
 assert.equal(h.undo(),null);h.record(current);const old=h.undo();assert.equal(old.clips[0].title,'Original');old.clips[0].title='Tampered';
 assert.equal(h.redo().clips[0].title,'Changed');h.undo();h.record({clips:[{title:'Replacement'}],layers:{titles:[]}});
 assert.equal(h.canRedo,false);assert.equal(h.undo().clips[0].title,'Original');
});
check('history ignores identical snapshots and respects memory cap',()=>{
 const h=t.history(4);h.reset({n:0});h.record({n:0});assert.equal(h.length,1);
 for(let n=1;n<=10;n++)h.record({n});assert.equal(h.length,4);
 assert.equal(h.undo().n,9);assert.equal(h.undo().n,8);assert.equal(h.undo().n,7);assert.equal(h.canUndo,false);
});
check('snapping chooses nearby grid and edit boundaries',()=>{
 assert.equal(t.snapTime(987,[],true),1000);assert.equal(t.snapTime(1250,[1300],true),1300);
 assert.equal(t.snapTime(1450,[1300],true),1500);assert.equal(t.snapTime(1200,[],true),1200);
 assert.equal(t.snapTime(987,[1000],false),987);
});
check('peak extraction uses actual samples from both audio channels',()=>{
 const audio={numberOfChannels:2,length:8,getChannelData(i){return i?Float32Array.from([0,0,.8,0,0,0,0,0]):Float32Array.from([0,.4,0,0,0,0,.25,0]);}};
 const peaks=Array.from(t.peakSamples(audio,4));assert.equal(peaks.length,4);assert.ok(peaks[0]>=.39&&peaks[1]>=.79&&peaks[3]>=.24);
 assert.equal(t.peakSamples({numberOfChannels:0,length:0},4).length,0);
});
console.log('V5Q.29A timeline utility assertions:',checks,'/',checks);
