'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs'),vm=require('node:vm');
const code=fs.readFileSync('sdk/lucky-game.js','utf8');
const listeners=new Map(),sent=[];
let timer=0;
const parent={postMessage:function(data,target){sent.push({data,target})}};
const scope={parent,addEventListener(type,fn){listeners.set(type,fn)},
 setTimeout(){return ++timer},clearTimeout(){}};
vm.runInNewContext(code,{window:scope});
const sdk=scope.LuckyGameSDK;
assert.equal(sdk.version,'2.0.0');
function parentMessage(data){listeners.get('message')({source:parent,data})}
function receive(index,value,ok=true){
 parentMessage({type:'lucky:capability-result',requestId:sent[index].data.requestId,ok,...(ok?{value}:{error:'Permission denied'})});
}
(async()=>{
 assert.equal(Object.isFrozen(sdk.storage),true);
 parentMessage({type:'lucky:state',state:{status:'active',version:1}});
 let pending=sdk.players.add('player1','Player One');
 assert.equal(sent.at(-1).data.operation,'players.add');
 assert.deepEqual(JSON.parse(JSON.stringify(sent.at(-1).data.args)),{id:'player1',name:'Player One'});
 receive(0,{id:'player1',name:'Player One'});
 assert.equal((await pending).name,'Player One');
 pending=sdk.scores.add('player1',25);
 assert.equal(sent[1].data.operation,'scores.add');
 receive(1,{score:25});
 assert.equal((await pending).score,25);
 pending=sdk.storage.set('level',5,0,'game');
 assert.equal(sent[2].data.operation,'storage.set');
 receive(2,{revision:1});assert.equal((await pending).revision,1);
 pending=sdk.timers.start('round',60);
 assert.equal(sent[3].data.operation,'timers.start');
 receive(3,{remainingSeconds:60});assert.equal((await pending).remainingSeconds,60);
 pending=sdk.session.save();assert.equal(sent[4].data.operation,'session.save');
 receive(4,{status:'saved'});await pending;
 pending=sdk.players.list();receive(5,null,false);
 await assert.rejects(pending,/Permission denied/);
 assert.equal(sent.length,6);
 parentMessage({type:'lucky:capability-result',requestId:'sdk-not-requested',ok:true,value:'ignored'});
 listeners.get('message')({source:{},data:{type:'lucky:state',state:{status:'ended'}}});
 assert.equal(sdk.getState().status,'active');
 parentMessage({type:'lucky:state',state:{status:'paused'}});
 await assert.rejects(sdk.rounds.next(),/not running/);
 console.log('V5Q.24 SDK: asynchronous RPC, all capability families, error isolation, paused denial PASSED');
})().catch(e=>{console.error(e);process.exit(1)});
