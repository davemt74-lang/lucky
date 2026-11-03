'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');
const {webcrypto}=require('node:crypto');

const ID='a'.repeat(32),KEY='b'.repeat(48),SDP='v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\n';
const settle=async()=>{for(let i=0;i<40;i++)await Promise.resolve();};

function harness(client){
 let now=0,sequence=0,revoked=false;
 const timers=new Map(),nodes=[],listeners=new Map(),requests=[],peers=[],viewers=new Map();
 const schedule=(callback,delay=0,interval=0)=>{
  const id=++sequence;timers.set(id,{callback,at:now+delay,interval});return id;
 };
 class Element{
  constructor(tag){
   this.tagName=tag.toUpperCase();this.listeners=new Map();this.children=[];this.dataset={};this.attributes={};
   this.textContent='';this.value='';this.muted=true;this.srcObject=null;this.hidden=false;
   const classes=new Set();
   this.classList={add:(...items)=>items.forEach(item=>classes.add(item)),remove:(...items)=>items.forEach(item=>classes.delete(item)),
    contains:item=>classes.has(item),toggle:(item,force)=>{const add=force??!classes.has(item);if(add)classes.add(item);else classes.delete(item);return add;}};
   nodes.push(this);
  }
  append(...children){this.children.push(...children);}
  replaceChildren(...children){this.children=children;}
  setAttribute(name,value){this.attributes[name]=value;}
  addEventListener(name,callback){this.listeners.set(name,callback);}
  emit(name){this.listeners.get(name)?.({preventDefault(){}});}
  play(){return Promise.resolve();}
  pause(){}
  focus(){}
  showModal(){this.open=true;}
  close(){this.open=false;}
 }
 const body=new Element('body');
 body.dataset=client==='standalone'?{broadcastId:ID,broadcastKey:KEY,showId:''}:{page:'display'};
 const document={body,baseURI:'https://lucky.test/',createElement:tag=>new Element(tag),
  getElementById:id=>nodes.find(node=>node.id===id)||null};
 if(client==='standalone')for(const name of ['video','status','sound','retry','ice']){
  const node=new Element(name==='video'?'video':'div');node.id='director-view-'+name;body.append(node);
 }
 class Peer{
  constructor(){this.iceGatheringState='complete';this.connectionState='new';this.iceConnectionState='new';this.answers=0;peers.push(this);}
  addTransceiver(){}
  createOffer(){return Promise.resolve({type:'offer',sdp:SDP});}
  setLocalDescription(value){this.localDescription=value;return Promise.resolve();}
  setRemoteDescription(value){
   this.remoteDescription=value;this.answers++;this.connectionState=this.iceConnectionState='connected';
   this.onconnectionstatechange?.();this.ontrack?.({streams:[{id:'remote-program'}]});return Promise.resolve();
  }
  close(){this.connectionState='closed';}
 }
 const fetch=async(raw,options={})=>{
  const url=new URL(raw,document.baseURI),action=url.searchParams.get('action');
  const values=options.body?JSON.parse(options.body):Object.fromEntries(url.searchParams);
  requests.push({action,values,method:options.method,url:url.href,at:now});
  let status=200,data={ok:true};
  if(revoked){status=403;data={ok:false,error:'Publication revoked'};}
  else if(action==='watch')data={ok:true,live:true,visibility:'private'};
  else if(action==='offer'){
   assert.equal(values.id,ID);assert.equal(values.key,KEY);assert.match(values.viewer,/^[a-f0-9]{32}$/);
   viewers.set(values.viewer,now);
  }else if(action==='poll'){
   assert.equal(values.id,ID);assert.equal(values.key,KEY);
   if(!viewers.has(values.viewer)||now-viewers.get(values.viewer)>=30000){status=409;data={ok:false,error:'Viewer expired'};}
   else{viewers.set(values.viewer,now);data={ok:true,answer:SDP};}
  }else throw Error('Unexpected action '+action);
  return {ok:status===200,status,json:async()=>data};
 };
 const context={document,console,URL,URLSearchParams,Uint8Array,crypto:webcrypto,RTCPeerConnection:Peer,fetch,
  setTimeout:(callback,delay)=>schedule(callback,delay),clearTimeout:id=>timers.delete(id),
  setInterval:(callback,delay)=>schedule(callback,delay,delay),clearInterval:id=>timers.delete(id),
  addEventListener:(name,callback)=>{if(!listeners.has(name))listeners.set(name,[]);listeners.get(name).push(callback);},
  navigator:{mediaDevices:{getUserMedia(){throw Error('A read-only viewer must never capture local media');}}}};
 context.window=context;
 vm.createContext(context);
 const file=client==='standalone'?'director-view.js':'display-program.js';
 vm.runInContext(fs.readFileSync(path.join(__dirname,'../public',file),'utf8'),context,{filename:file});
 return {
  requests,peers,viewers,
  get video(){return document.getElementById(client==='standalone'?'director-view-video':'lucky-program-video');},
  async start(){
   if(client==='embedded'){
    document.getElementById('lucky-program-invitation').value='https://lucky.test/live-view.php?id='+ID+'&key='+KEY;
    nodes.find(node=>node.tagName==='FORM').emit('submit');
   }
   await settle();assert.equal(peers.length,1);assert.equal(peers[0].answers,1);
  },
  async advance(amount){
   const end=now+amount;
   for(;;){
    const pending=[...timers.entries()].filter(([,timer])=>timer.at<=end).sort((a,b)=>a[1].at-b[1].at||a[0]-b[0])[0];
    if(!pending)break;
    const [id,timer]=pending;now=timer.at;timers.delete(id);
    if(timer.interval)timers.set(id,{...timer,at:now+timer.interval});
    timer.callback();await settle();
   }
   now=end;await settle();
  },
  hide(){for(const callback of listeners.get('pagehide')||[])callback();},
  revoke(){revoked=true;},
  expire(){viewers.clear();}
 };
}

for(const client of ['standalone','embedded']){
 test(client+' keeps the same admitted viewer alive beyond 30 seconds',{timeout:5000},async()=>{
  const app=harness(client);await app.start();await app.advance(45500);
  const offers=app.requests.filter(request=>request.action==='offer');
  const polls=app.requests.filter(request=>request.action==='poll');
  assert.equal(offers.length,1,'A healthy session never reconnects to hide lost heartbeats');
  assert.equal(app.requests.filter(request=>request.action==='watch').length,1,'Admission/status is not a viewer heartbeat');
  assert.equal(polls.length,14,'Initial SDP poll plus thirteen 3.5-second heartbeats');
  assert.ok(polls.every(request=>request.values.viewer===offers[0].values.viewer),'Heartbeat remains scoped to the admitted viewer');
  assert.equal(app.viewers.get(offers[0].values.viewer),45500);
  assert.equal(app.peers[0].answers,1,'Repeated heartbeat answers do not renegotiate WebRTC');
  assert.equal(app.peers[0].connectionState,'connected');assert.ok(app.video.srcObject);
  if(client==='embedded')assert.ok(app.requests.every(request=>request.method==='POST'&&!request.url.includes(KEY)),
   'Embedded private bearer stays in request bodies');
  app.hide();
 });

 test(client+' stops polling and clears media on pagehide',{timeout:5000},async()=>{
  const app=harness(client);await app.start();await app.advance(7000);
  app.hide();const count=app.requests.length;await app.advance(45000);
  assert.equal(app.requests.length,count);assert.equal(app.peers[0].connectionState,'closed');assert.equal(app.video.srcObject,null);
 });

 test(client+' heartbeat revocation blanks video and does not keep retrying a private bearer',{timeout:5000},async()=>{
  const app=harness(client);await app.start();app.revoke();await app.advance(3500);
  assert.equal(app.requests.at(-1).action,'poll');assert.equal(app.peers[0].connectionState,'closed');assert.equal(app.video.srcObject,null);
  const count=app.requests.length;await app.advance(45000);assert.equal(app.requests.length,count);
  app.hide();
 });

 test(client+' detects expired viewer admission instead of a watch-only false healthy state',{timeout:5000},async()=>{
  const app=harness(client);await app.start();const first=app.requests.find(request=>request.action==='offer').values.viewer;
  app.expire();await app.advance(3500);
  assert.equal(app.peers[0].connectionState,'closed');assert.equal(app.video.srcObject,null);
  if(client==='standalone'){
   await app.advance(1500);
   const offers=app.requests.filter(request=>request.action==='offer');
   assert.equal(offers.length,2);assert.notEqual(offers[1].values.viewer,first,'Recovery needs a fresh admission, not expired-row revival');
  }else{
   const count=app.requests.length;await app.advance(15000);assert.equal(app.requests.length,count);
  }
  app.hide();
 });
}
