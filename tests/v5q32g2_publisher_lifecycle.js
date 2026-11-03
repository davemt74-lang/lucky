'use strict';
// Real async node:test assertions around the production publisher. Media and
// signaling are controlled in memory; these are not hardware/network tests.
const test=require('node:test');
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {setImmediate:nextTurn}=require('node:timers/promises');
const SOURCE=fs.readFileSync(path.join(__dirname,'../public/director-publish.js'),'utf8');
const ID_A='1'.repeat(32),ID_B='2'.repeat(32),VIEWER='3'.repeat(32),KEY='c'.repeat(48);
const response=value=>({ok:true,status:200,json:async()=>value});
const published=id=>({ok:true,id,key:KEY,visibility:'private'});
function deferred(){let resolve,reject;const promise=new Promise((yes,no)=>{resolve=yes;reject=no;});return {promise,resolve,reject};}
async function until(condition,label){for(let i=0;i<40;i++){if(condition())return;await nextTurn();}assert.fail('Did not reach '+label);}
function media(video=true){
 const tracks=(video?['video','audio']:['audio']).map(kind=>({kind,readyState:'live',stops:0,
  stop(){this.stops++;this.readyState='ended';}}));
 return {tracks,getTracks:()=>tracks,getVideoTracks:()=>tracks.filter(t=>t.kind==='video'),getAudioTracks:()=>tracks.filter(t=>t.kind==='audio')};
}
function stopped(stream){return stream.tracks.every(track=>track.stops===1&&track.readyState==='ended');}
function fixture(options={}){
 class Element extends EventTarget{
  constructor(){super();this.textContent='';this.value='';this.checked=false;this.disabled=false;this.hidden=false;this.href='';this.classes=new Set();
   this.classList={toggle:(name,on)=>on?this.classes.add(name):this.classes.delete(name)};}
  setAttribute(name,value){this[name]=String(value);}
  removeAttribute(name){delete this[name];}
  select(){}
  click(){if(!this.disabled)this.dispatchEvent(new Event('click'));}
 }
 const ids=['director-publish','director-unpublish','director-broadcast-visibility','director-public-ack-label',
  'director-visibility-explanation','director-live-status','director-live-badge','director-live-link','director-publish-public-ack',
  'director-publish-consent','director-live-url','director-open-live','director-live-link-label','director-show-url',
  'director-show-copy','director-show-open','director-live-viewers','director-live-health','director-live-relay','director-live-ice','director-live-elapsed'];
 const elements=new Map(ids.map(id=>[id,new Element()]));
 const el=id=>elements.get(id);
 el('director-unpublish').textContent='■ End Live View';el('director-broadcast-visibility').value='private';
 const calls=[],streams=[],peers=[],timers=new Map(),intervals=new Map();let timerId=0,authCalls=0,acquisitions=0;
 const window=new EventTarget();
 const event=(name,detail)=>window.dispatchEvent(new CustomEvent(name,{detail}));
 const change=(id,value)=>{el(id).checked=value;el(id).dispatchEvent(new Event('change'));};
 class Peer extends EventTarget{
  constructor(config){super();if(options.peerConstructor)options.peerConstructor(config);this.config=config;this.steps=[];this.tracks=[];this.connectionState='new';this.iceConnectionState='new';
   this.iceGatheringState=options.gathering?'gathering':'complete';this.closed=0;peers.push(this);}
  addTrack(track,output){this.tracks.push({track,output});}
  async setRemoteDescription(value){this.steps.push('remote');if(options.peerStage)await options.peerStage('remote',this);this.remoteDescription=value;}
  async createAnswer(){this.steps.push('answer');if(options.peerStage)await options.peerStage('answer',this);return {type:'answer',sdp:'v=0\r\nanswer'};}
  async setLocalDescription(value){this.steps.push('local');if(options.peerStage)await options.peerStage('local',this);this.localDescription=value;}
  close(){this.closed++;this.connectionState='closed';this.onconnectionstatechange?.();this.dispatchEvent(new Event('connectionstatechange'));}
 }
 window.RTCPeerConnection=Peer;
 window.LuckyCameraDirector={programStream:()=>{
  acquisitions++;const result=options.programStream?options.programStream(acquisitions):media();
  if(result?.tracks)streams.push(result);return result;
 }};
 const Lucky={getMe:()=>{authCalls++;return options.getMe?options.getMe(authCalls):Promise.resolve({csrf:'csrf-'+authCalls});}};
 const fetch=async(url,opts={})=>{
  const parsed=new URL(url,'https://lucky.test/'),action=parsed.searchParams.get('action');
  const data=opts.body?JSON.parse(opts.body):Object.fromEntries(parsed.searchParams);
  const entry={action,data,options:opts};calls.push(entry);
  let out=options.request?await options.request(entry,calls.filter(c=>c.action===action).length):undefined;
  if(out===undefined){
   if(action==='publish')out=published(calls.filter(c=>c.action==='publish').length===1?ID_A:ID_B);
   else if(action==='host_list')out={ok:true,offers:[]};
   else if(action==='show_link')out={ok:true,showId:'e'.repeat(32)};
   else if(action==='host_status')out={ok:true,live:true,visibility:'private',viewerCount:0};
   else if(action==='stop'||action==='answer')out={ok:true};
   else throw Error('Unexpected mock API action '+action);
  }
  return typeof out.json==='function'?out:response(out);
 };
 vm.runInNewContext(SOURCE,{window,document:{baseURI:'https://lucky.test/director.php',hidden:false,getElementById:id=>elements.get(id)||null},
  Lucky,fetch,RTCPeerConnection:Peer,AbortController,URL,URLSearchParams,Date,Event,CustomEvent,navigator:{clipboard:{writeText:async()=>{}}},
  setTimeout:(fn,ms)=>{const id=++timerId;timers.set(id,{fn,ms});return id;},clearTimeout:id=>timers.delete(id),
  setInterval:(fn,ms)=>{const id=++timerId;intervals.set(id,{fn,ms});return id;},clearInterval:id=>intervals.delete(id)},
  {filename:'public/director-publish.js'});
 const publisher=window.LuckyDirectorPublisher;
 const openProof=(session='a'.repeat(32),claim='b'.repeat(32))=>event('lucky:proof-open',{session,claim});
 openProof();el('director-publish-consent').checked=true;
 return {publisher,el,event,change,openProof,calls,streams,peers,timers,intervals,
  count:action=>calls.filter(c=>c.action===action).length,
  authCalls:()=>authCalls,acquisitions:()=>acquisitions};
}

test('explicit private publish uses captured scope and token; Stop closes its output', {timeout:2000}, async()=>{
 const f=fixture();await f.publisher.publish();
 assert.equal(f.publisher.state().onAir,true);assert.equal(f.publisher.state().publishing,false);
 const sent=f.calls.find(c=>c.action==='publish');
 assert.equal(sent.data.session,'a'.repeat(32));assert.equal(sent.data.claim,'b'.repeat(32));
 assert.equal(sent.data.consent,true);assert.equal(sent.data.visibility,'private');assert.equal(sent.data.public_ack,false);
 assert.equal(sent.options.headers['X-CSRF-Token'],'csrf-1');assert.equal(f.el('director-live-link').hidden,false);
 assert.match(f.el('director-live-url').value,new RegExp('id='+ID_A+'&key='+KEY));
 await f.publisher.stop();
 assert.equal(f.publisher.state().onAir,false);assert.ok(stopped(f.streams[0]));
 assert.equal(f.el('director-live-url').value,'');assert.equal(f.el('director-live-link').hidden,true);
 const revoked=f.calls.find(c=>c.action==='stop');assert.equal(revoked.data.id,ID_A);
 assert.equal(revoked.options.keepalive,true);assert.equal(revoked.options.headers['X-CSRF-Token'],'csrf-1');
});

test('standalone recording publication sends only recording scope and preserves its own token through Stop', {timeout:2000}, async()=>{
 const recording='d'.repeat(32),f=fixture();
 f.event('lucky:director-studio-open',{recordingSession:recording});
 await until(()=>f.publisher.state().showUrl.includes('show='+'e'.repeat(32)),'recording show link');
 f.change('director-publish-consent',true);await f.publisher.publish();
 assert.equal(f.publisher.state().onAir,true);
 const sent=f.calls.find(c=>c.action==='publish');
 assert.equal(sent.data.recordingSession,recording);assert.equal(Object.hasOwn(sent.data,'session'),false);
 assert.equal(Object.hasOwn(sent.data,'claim'),false);assert.equal(sent.data.consent,true);
 assert.equal(sent.data.visibility,'private');assert.equal(sent.options.headers['X-CSRF-Token'],'csrf-2');
 assert.equal(f.calls.find(c=>c.action==='show_link').data.recordingSession,recording);
 await f.publisher.stop();
 assert.equal(f.publisher.state().onAir,false);assert.ok(stopped(f.streams[0]));
 const revoked=f.calls.find(c=>c.action==='stop');
 assert.equal(revoked.data.id,ID_A);assert.equal(revoked.options.headers['X-CSRF-Token'],'csrf-2');
});

test('consent and public acknowledgement remain mandatory before acquiring Program', {timeout:2000}, async()=>{
 const f=fixture();f.change('director-publish-consent',false);await f.publisher.publish();
 assert.equal(f.acquisitions(),0);assert.equal(f.count('publish'),0);
 f.change('director-publish-consent',true);f.el('director-broadcast-visibility').value='public';
 await f.publisher.publish();assert.equal(f.acquisitions(),0);assert.equal(f.count('publish'),0);
 f.change('director-publish-public-ack',true);await f.publisher.publish();
 assert.equal(f.publisher.state().visibility,'public');assert.equal(f.calls.find(c=>c.action==='publish').data.public_ack,true);
 assert.ok(!f.el('director-live-url').value.includes('key='));await f.publisher.stop();
});

test('invalid or failed Program acquisition fails closed and cleans all obtainable tracks', {timeout:4000}, async t=>{
 for(const scenario of ['throw','missing','no-track-method','no-video','ended-video','throwing-video-method']){
  await t.test(scenario,{timeout:1000},async()=>{
   const output=media(scenario!=='no-video');
   if(scenario==='no-track-method')delete output.getTracks;
   if(scenario==='ended-video')output.tracks[0].readyState='ended';
   if(scenario==='throwing-video-method')output.getVideoTracks=()=>{throw Error('bad capture');};
   const f=fixture({programStream:()=>{if(scenario==='throw')throw Error('capture failed');return scenario==='missing'?null:output;}});
   await f.publisher.publish();
   assert.equal(f.publisher.state().onAir,false);assert.equal(f.publisher.state().publishing,false);assert.equal(f.count('publish'),0);
   assert.match(f.el('director-live-status').textContent,/Unable to publish/);
   if(!['throw','missing'].includes(scenario))assert.ok(stopped(output));
   assert.equal(f.el('director-publish').disabled,false);
  });
 }
});

test('Cancel during delayed acquisition stops the late stream without authenticating or publishing', {timeout:2000}, async()=>{
 const acquisition=deferred(),output=media(),f=fixture({programStream:()=>acquisition.promise});
 const operation=f.publisher.publish();
 assert.equal(f.el('director-unpublish').disabled,false);assert.match(f.el('director-unpublish').textContent,/Cancel/);
 f.el('director-unpublish').click();assert.equal(f.publisher.state().publishing,false);
 acquisition.resolve(output);await operation;
 assert.ok(stopped(output));assert.equal(f.authCalls(),0);assert.equal(f.count('publish'),0);assert.equal(f.publisher.state().onAir,false);
});

test('every consent, scope and exit invalidation survives a delayed authorization result', {timeout:8000}, async t=>{
 const invalidations={
  'Stop':f=>f.el('director-unpublish').click(),
  'consent revoked then restored':f=>{f.change('director-publish-consent',false);f.change('director-publish-consent',true);},
  'public acknowledgement revoked then restored':f=>{f.change('director-publish-public-ack',false);f.change('director-publish-public-ack',true);},
  'visibility changed':f=>{f.el('director-broadcast-visibility').value='private';f.el('director-broadcast-visibility').dispatchEvent(new Event('change'));},
  'proof closed':f=>f.event('lucky:proof-close'),
  'recording closed':f=>f.event('lucky:director-studio-close'),
  'proof replaced':f=>{f.openProof('f'.repeat(32),'e'.repeat(32));f.change('director-publish-consent',true);},
  'same proof reopened':f=>{f.openProof();f.change('director-publish-consent',true);},
  'Program ended':f=>f.event('lucky:director-program-ended'),
  'page exited':f=>f.event('pagehide')
 };
 for(const [name,invalidate] of Object.entries(invalidations))await t.test(name,{timeout:1500},async()=>{
  const auth=deferred(),f=fixture({getMe:()=>auth.promise});
  if(name.startsWith('public')||name==='visibility changed'){
   f.el('director-broadcast-visibility').value='public';f.change('director-publish-public-ack',true);
  }
  const operation=f.publisher.publish();await until(()=>f.authCalls()===1,'authorization');
  invalidate(f);assert.ok(stopped(f.streams[0]));
  auth.resolve({csrf:'late'});await operation;
  assert.equal(f.count('publish'),0);assert.equal(f.publisher.state().onAir,false);
  assert.equal(f.publisher.state().publishing,false);assert.equal(f.el('director-live-link').hidden,true);
 });
});

test('late authorization failure cannot clear or overwrite a newer pending attempt', {timeout:2000}, async()=>{
 const old=deferred(),newer=deferred(),f=fixture({getMe:n=>n===1?old.promise:newer.promise});
 const first=f.publisher.publish();await until(()=>f.authCalls()===1,'first authorization');await f.publisher.stop();
 const second=f.publisher.publish();await until(()=>f.authCalls()===2,'second authorization');
 old.reject(Error('old request failed'));await first;
 assert.equal(f.publisher.state().publishing,true);assert.equal(f.el('director-publish').disabled,true);
 assert.ok(!f.el('director-live-status').textContent.includes('old request failed'));
 assert.equal(f.streams[1].tracks[0].stops,0);newer.resolve({csrf:'new'});await second;
 assert.equal(f.publisher.state().onAir,true);await f.publisher.stop();
});

test('cancelled create response is revoked by its own ID, including delayed response JSON', {timeout:3000}, async t=>{
 for(const stage of ['fetch','json'])await t.test(stage,{timeout:1200},async()=>{
  const create=deferred(),f=fixture({request:entry=>entry.action==='publish'?
   (stage==='fetch'?create.promise:{ok:true,status:201,json:()=>create.promise}):undefined});
  const operation=f.publisher.publish();await until(()=>f.count('publish')===1,'server creation');
  await f.publisher.stop();assert.ok(stopped(f.streams[0]));
  create.resolve(published(ID_A));await operation;
  assert.equal(f.publisher.state().onAir,false);assert.equal(f.count('host_list'),0);
  assert.equal(f.count('stop'),1);assert.equal(f.calls.find(c=>c.action==='stop').data.id,ID_A);
  assert.equal(f.calls.find(c=>c.action==='stop').options.headers['X-CSRF-Token'],'csrf-1');
 });
});

test('same-session republish waits for abandoned create and cleanup before replacing server state', {timeout:3000}, async()=>{
 const create=deferred(),cleanup=deferred();let serverActive=null;
 const f=fixture({request:async(entry,n)=>{
  if(entry.action==='publish'){
   if(n===1){await create.promise;serverActive=ID_A;return published(ID_A);}
   serverActive=ID_B;return published(ID_B);
  }
  if(entry.action==='stop'&&entry.data.id===ID_A){await cleanup.promise;if(serverActive===ID_A)serverActive=null;return {ok:true};}
 }});
 const first=f.publisher.publish();await until(()=>f.count('publish')===1,'old create');await f.publisher.stop();
 const second=f.publisher.publish();await until(()=>f.authCalls()===2,'new intent authorized');
 assert.equal(f.count('publish'),1);assert.equal(f.publisher.state().publishing,true);
 create.resolve();await until(()=>f.count('stop')===1,'old creation cleanup');
 assert.equal(f.count('publish'),1);assert.equal(serverActive,ID_A);assert.equal(f.publisher.state().onAir,false);
 cleanup.resolve();await Promise.all([first,second]);
 assert.equal(serverActive,ID_B);assert.equal(f.publisher.state().onAir,true);
 assert.match(f.el('director-live-url').value,new RegExp('id='+ID_B));
 assert.ok(stopped(f.streams[0]));assert.ok(f.streams[1].tracks.every(track=>track.stops===0));
 assert.deepEqual(f.calls.filter(c=>['publish','stop'].includes(c.action)).map(c=>c.action+':'+(c.data.id||'')),
  ['publish:','stop:'+ID_A,'publish:']);await f.publisher.stop();
});

test('cancelling an attempt queued behind an old create prevents its server POST', {timeout:3000}, async()=>{
 const create=deferred(),f=fixture({request:(entry,n)=>entry.action==='publish'&&n===1?create.promise:undefined});
 const first=f.publisher.publish();await until(()=>f.count('publish')===1,'old server request');await f.publisher.stop();
 const second=f.publisher.publish();await until(()=>f.authCalls()===2,'queued attempt');await f.publisher.stop();
 const third=f.publisher.publish();await until(()=>f.authCalls()===3,'replacement intent');
 assert.equal(f.count('publish'),1);assert.ok(stopped(f.streams[1]));
 create.resolve(published(ID_A));await Promise.all([first,second,third]);
 assert.equal(f.count('publish'),2);assert.equal(f.publisher.state().onAir,true);
 assert.ok(f.streams[2].tracks.every(track=>track.stops===0));await f.publisher.stop();
});

test('a late Stop error cannot overwrite a newer successful publication', {timeout:2000}, async()=>{
 const oldStop=deferred(),f=fixture({request:entry=>entry.action==='stop'&&entry.data.id===ID_A?oldStop.promise:undefined});
 await f.publisher.publish();const stopping=f.publisher.stop();await f.publisher.publish();
 const message=f.el('director-live-status').textContent;
 oldStop.reject(Error('old stop failed'));await stopping;
 assert.equal(f.publisher.state().onAir,true);assert.equal(f.el('director-live-status').textContent,message);
 assert.match(f.el('director-live-url').value,new RegExp('id='+ID_B));await f.publisher.stop();
});

test('malformed private invitation closes acquired output and revokes only returned broadcast', {timeout:2000}, async()=>{
 const f=fixture({request:entry=>entry.action==='publish'?{ok:true,id:ID_A,key:'bad'}:undefined});
 await f.publisher.publish();assert.equal(f.publisher.state().onAir,false);assert.ok(stopped(f.streams[0]));
 assert.equal(f.count('stop'),1);assert.equal(f.calls.find(c=>c.action==='stop').data.id,ID_A);
 assert.match(f.el('director-live-status').textContent,/invalid Live View invitation/);
});

test('ended Program is rejected after authorization or creation even without a Program-ended event', {timeout:3000}, async t=>{
 for(const stage of ['authorization','creation'])await t.test(stage,{timeout:1200},async()=>{
  const held=deferred(),f=fixture({
   getMe:stage==='authorization'?()=>held.promise:undefined,
   request:entry=>stage==='creation'&&entry.action==='publish'?held.promise:undefined});
  const operation=f.publisher.publish();
  await until(()=>stage==='authorization'?f.authCalls()===1:f.count('publish')===1,'pending '+stage);
  f.streams[0].getVideoTracks()[0].readyState='ended';
  held.resolve(stage==='authorization'?{csrf:'resolved'}:published(ID_A));await operation;
  assert.equal(f.publisher.state().onAir,false);assert.equal(f.publisher.state().publishing,false);
  assert.equal(f.count('host_list'),0);assert.ok(stopped(f.streams[0]));
  assert.match(f.el('director-live-status').textContent,/Take a live source/);
  assert.equal(f.count('publish'),stage==='creation'?1:0);
  assert.equal(f.count('stop'),stage==='creation'?1:0);
  if(stage==='creation')assert.equal(f.calls.find(c=>c.action==='stop').data.id,ID_A);
 });
});

test('a peer constructor failure is contained and the next viewer can still connect', {timeout:2000}, async()=>{
 let constructions=0;
 const f=fixture({peerConstructor:()=>{if(++constructions===1)throw Error('invalid RTC configuration');},
  request:entry=>entry.action==='host_list'?{ok:true,offers:[
   {id:VIEWER,offer:'v=0\r\nfirst'}, {id:'4'.repeat(32),offer:'v=0\r\nsecond'}]}:undefined});
 await f.publisher.publish();await until(()=>f.count('answer')===1,'remaining viewer answer');
 assert.equal(constructions,2);assert.equal(f.peers.length,1);assert.equal(f.publisher.state().onAir,true);
 assert.equal(f.calls.find(c=>c.action==='answer').data.viewer,'4'.repeat(32));
 assert.ok(f.streams[0].tracks.every(track=>track.stops===0));
 await f.publisher.stop();assert.equal(f.peers[0].closed,1);assert.ok(stopped(f.streams[0]));
});

test('Stop guards every awaited peer negotiation stage and cancels the ICE wait', {timeout:6000}, async t=>{
 for(const stage of ['remote','answer','local','gathering'])await t.test(stage,{timeout:1500},async()=>{
  const held=deferred(),f=fixture({gathering:stage==='gathering',
   peerStage:name=>name===stage?held.promise:undefined,
   request:entry=>entry.action==='host_list'?{ok:true,offers:[{id:VIEWER,offer:'v=0\r\noffer'}]}:undefined});
  await f.publisher.publish();
  await until(()=>stage==='gathering'?[...f.timers.values()].some(t=>t.ms===7000):f.peers[0]?.steps.includes(stage),'peer '+stage);
  await f.publisher.stop();assert.equal(f.publisher.state().viewerConnections,0);assert.equal(f.peers[0].closed,1);
  assert.ok(stopped(f.streams[0]));held.resolve();await nextTurn();await nextTurn();
  assert.equal(f.count('answer'),0);assert.ok(![...f.timers.values()].some(t=>t.ms===7000));
  assert.deepEqual(f.peers[0].steps,stage==='remote'?['remote']:stage==='answer'?['remote','answer']:['remote','answer','local']);
 });
});

test('a stale host poll failure cannot shut down a newer live publisher', {timeout:2000}, async()=>{
 const oldPoll=deferred(),f=fixture({request:entry=>entry.action==='host_list'&&entry.data.id===ID_A?oldPoll.promise:undefined});
 await f.publisher.publish();await until(()=>f.count('host_list')===1,'old host poll');
 await f.publisher.stop();await f.publisher.publish();oldPoll.reject(Error('late network failure'));await nextTurn();await nextTurn();
 assert.equal(f.publisher.state().onAir,true);assert.equal(f.count('stop'),1);
 assert.ok(f.streams[1].tracks.every(track=>track.stops===0));await f.publisher.stop();
});

test('late same-recording show-link response cannot overwrite a reopened scope', {timeout:2000}, async()=>{
 const oldLink=deferred(),f=fixture({request:(entry,n)=>entry.action==='show_link'?
  (n===1?oldLink.promise:{ok:true,showId:'f'.repeat(32)}):undefined});
 f.event('lucky:director-studio-open',{recordingSession:'a'.repeat(32)});
 await until(()=>f.count('show_link')===1,'old show link');
 f.event('lucky:director-studio-open',{recordingSession:'a'.repeat(32)});
 await until(()=>f.publisher.state().showUrl.includes('f'.repeat(32)),'new show link');
 oldLink.resolve({ok:true,showId:'e'.repeat(32)});await nextTurn();await nextTurn();
 assert.match(f.publisher.state().showUrl,/show=f{32}$/);assert.equal(f.publisher.state().onAir,false);
});
