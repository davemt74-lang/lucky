'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const code=fs.readFileSync(path.join(__dirname,'../sdk/lucky-game.js'),'utf8');

// Native, referenced timers really execute. Only elapsed time is shortened:
// assert the SDK still requests its production 10-second timeout, then let the
// real timer reject an unanswered request instead of inventing a timer handle.
function fixture(t,timeoutMs=250){
 const listeners=new Map(),sent=[],timers=new Set(),requestedDelays=[];
 const parent={postMessage(data,target){sent.push({data,target});}};
 const scope={parent,addEventListener(type,fn){listeners.set(type,fn);},
  setTimeout(callback,delay){
   requestedDelays.push(delay);
   const timer=setTimeout(()=>{timers.delete(timer);callback();},timeoutMs);
   timers.add(timer);return timer;
  },
  clearTimeout(timer){clearTimeout(timer);timers.delete(timer);}
 };
 t.after(()=>{for(const timer of timers)clearTimeout(timer);timers.clear();});
 vm.runInNewContext(code,{window:scope});
 const message=(data,source=parent)=>listeners.get('message')({source,data});
 const state=status=>message({type:'lucky:state',state:{status,version:1}});
 const reply=(index,value,ok=true,error='Permission denied')=>message({
  type:'lucky:capability-result',requestId:sent[index].data.requestId,ok,...(ok?{value}:{error})
 });
 return {sdk:scope.LuckyGameSDK,sent,timers,requestedDelays,message,state,reply};
}

test('SDK capability families resolve matched successful replies', {timeout:3000}, async t=>{
 const f=fixture(t);f.state('active');
 assert.equal(f.sdk.version,'2.0.0');
 const cases=[
  ['storage','get',['level'],{key:'level',scope:'game'}],
  ['storage','set',['level',5,0,'game'],{key:'level',value:5,expectedRevision:0,scope:'game'}],
  ['storage','delete',['level',1],{key:'level',expectedRevision:1,scope:'game'}],
  ['players','list',[],{}],['players','add',['player1','Player One'],{id:'player1',name:'Player One'}],
  ['scores','list',[],{}],['scores','add',['player1',25],{playerId:'player1',delta:25}],
  ['rounds','get',[],{}],['rounds','next',[],{}],
  ['turns','get',[],{}],['turns','set',['player1'],{playerId:'player1'}],
  ['timers','get',['round'],{name:'round'}],['timers','start',['round',60],{name:'round',seconds:60}],
  ['timers','stop',['round'],{name:'round'}],
  ['teams','list',[],{}],['teams','add',['team1','Team One'],{id:'team1',name:'Team One'}],
  ['teams','assign',['team1','player1'],{teamId:'team1',playerId:'player1'}],
  ['achievements','list',[],{}],['achievements','unlock',['winner'],{id:'winner'}],
  ['events','list',[],{}],['events','emit',['buzz','one'],{name:'buzz',value:'one'}],
  ['session','save',[],{}]
 ];
 for(const [family,method,args,expected] of cases){
  await t.test(family+'.'+method,{timeout:500},async()=>{
   assert.equal(Object.isFrozen(f.sdk[family]),true);
   const index=f.sent.length,pending=f.sdk[family][method](...args);
   const request=f.sent[index];
   assert.equal(request.data.type,'lucky:capability');
   assert.equal(request.data.operation,family+'.'+method);
   assert.deepEqual(JSON.parse(JSON.stringify(request.data.args)),expected);
   assert.equal(request.target,'*');
   const value={operation:family+'.'+method,accepted:true};
   f.reply(index,value);assert.deepEqual(await pending,value);
   assert.equal(f.timers.size,0,'Successful requests clear their timeout');
  });
 }
 assert.equal(new Set(f.sent.map(entry=>entry.data.requestId)).size,cases.length);
 assert.equal(f.requestedDelays.length,cases.length);
 assert.ok(f.requestedDelays.every(delay=>delay===10000));
});

test('SDK ignores foreign/unknown replies and propagates a parent rejection', {timeout:1000}, async t=>{
 const f=fixture(t);f.state('active');
 const pending=f.sdk.players.list();
 let settled=false;pending.then(()=>{settled=true;},()=>{settled=true;});
 f.message({type:'lucky:capability-result',requestId:'sdk-unknown',ok:true,value:'ignored'});
 f.message({type:'lucky:capability-result',requestId:f.sent[0].data.requestId,ok:true,value:'forged'},{});
 f.message({type:'lucky:state',state:{status:'ended'}},{});
 await Promise.resolve();
 assert.equal(settled,false);
 assert.equal(f.sdk.getState().status,'active');
 const rejected=assert.rejects(pending,/Permission denied/);
 f.reply(0,null,false);await rejected;
 assert.equal(f.timers.size,0);
});

test('SDK unanswered requests really time out and allow later recovery', {timeout:1000}, async t=>{
 const f=fixture(t,20);f.state('active');
 await assert.rejects(f.sdk.players.list(),/SDK request timed out/);
 assert.deepEqual(f.requestedDelays,[10000]);
 assert.equal(f.timers.size,0);
 f.reply(0,'late result');
 const next=f.sdk.players.list();f.reply(1,[{id:'player1'}]);
 assert.deepEqual(await next,[{id:'player1'}]);
 assert.equal(f.timers.size,0);
});

test('SDK forwards paused reads for parent/server authorization', {timeout:1000}, async t=>{
 const f=fixture(t);f.state('paused');
 const reads=[
  ['storage','get',['level']],['players','list',[]],['scores','list',[]],
  ['rounds','get',[]],['turns','get',[]],['timers','get',['round']],
  ['teams','list',[]],['achievements','list',[]],['events','list',[]]
 ];
 for(const [family,method,args] of reads){
  const index=f.sent.length,pending=f.sdk[family][method](...args);
  assert.equal(f.sent[index].data.operation,family+'.'+method);
  f.reply(index,{read:true});assert.deepEqual(await pending,{read:true});
 }
 assert.equal(f.sdk.sendInput('buzz','one'),false,'Gameplay input stays blocked while paused');
 assert.equal(f.sent.length,reads.length);
 assert.equal(f.timers.size,0);
});

test('SDK rejects paused mutations when the authoritative parent/server denies them', {timeout:1000}, async t=>{
 const f=fixture(t);f.state('paused');
 // The SDK forwards capabilities in a paused session; it must await the
 // authoritative response, not falsely expect a local "not running" error.
 for(const invoke of [()=>f.sdk.rounds.next(),()=>f.sdk.scores.add('player1',5),()=>f.sdk.storage.set('level',6)]){
  const index=f.sent.length,pending=invoke();
  assert.equal(f.sent.length,index+1);
  const rejected=assert.rejects(pending,/SDK changes require an active game/);
  f.reply(index,null,false,'SDK changes require an active game.');await rejected;
 }
 assert.equal(f.sdk.getState().status,'paused');
 assert.equal(f.timers.size,0);
});

test('SDK rejects requests before initialization, after game end and for invalid operations', {timeout:1000}, async t=>{
 const f=fixture(t);
 await assert.rejects(f.sdk.rounds.get(),/not running/);
 f.state('ended');await assert.rejects(f.sdk.players.list(),/not running/);
 f.state('active');await assert.rejects(f.sdk.request('../../admin'),/Invalid SDK operation/);
 assert.equal(f.sent.length,0);
 assert.equal(f.timers.size,0);
});

test('SDK pending-request limit rejects excess work and frees capacity after replies', {timeout:1000}, async t=>{
 const f=fixture(t);f.state('active');
 const pending=Array.from({length:16},()=>f.sdk.players.list());
 await assert.rejects(f.sdk.players.list(),/Too many SDK requests/);
 assert.equal(f.sent.length,16);
 pending.forEach((_,index)=>f.reply(index,{index}));
 assert.deepEqual(await Promise.all(pending),pending.map((_,index)=>({index})));
 const next=f.sdk.players.list();f.reply(16,[]);assert.deepEqual(await next,[]);
 assert.equal(f.timers.size,0);
});
