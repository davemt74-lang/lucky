'use strict';
// Actual browser helper executed in a VM with a deterministic monotonic clock.
// No network, wall-clock sleeps, permissive source-string checks or test skips.
const test=require('node:test'),assert=require('node:assert/strict');
const fs=require('node:fs'),path=require('node:path'),vm=require('node:vm');
const source=fs.readFileSync(path.join(__dirname,'../public/installation-urls.js'),'utf8');
const ID='a'.repeat(32),KEY='b'.repeat(48),LAN='https://192.168.1.42:8843';
const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve();};
function fixture({base='http://localhost:8833/game.php',mode='homeserver',lan=LAN,ttl='30',respond,initialClock=0}={}){
 const metas={'lucky-deployment':mode,'lucky-lan-origin':lan,'lucky-lan-lease-seconds':ttl};
 const timers=new Map(),events=[],requests=[],listeners=new Map();let clock=initialClock,sequence=0;
 const state={respond:respond||(()=>({ok:true,deployment:'homeserver',lanOrigin:lan,leaseSeconds:30}))};
 const schedule=(callback,delay,interval=0)=>{const id=++sequence;timers.set(id,{callback,due:clock+Number(delay),interval});return id;};
 const document={baseURI:base,visibilityState:'visible',
  querySelector:selector=>{const key=/name="([^"]+)"/.exec(selector)?.[1];return key&&metas[key]!==undefined?{content:metas[key]}:null;},
  addEventListener:(name,callback)=>listeners.set(name,callback)};
 const window={dispatchEvent:event=>{events.push(event);return true;},addEventListener:(name,callback)=>listeners.set('window:'+name,callback)};
 const context=vm.createContext({document,window,URL,URLSearchParams,Promise,AbortController,
  performance:{now:()=>clock},CustomEvent:class{constructor(type,options){this.type=type;this.detail=options.detail;}},
  setTimeout:(callback,delay)=>schedule(callback,delay),clearTimeout:id=>timers.delete(id),
  setInterval:(callback,delay)=>schedule(callback,delay,Number(delay)),clearInterval:id=>timers.delete(id),
  fetch:async(url,options)=>{requests.push({url,options});const result=await state.respond(url,options);
   return {ok:result?.httpOk!==false,json:async()=>result};}});
 vm.runInContext(source,context,{timeout:1000,filename:'public/installation-urls.js'});
 return {api:window.LuckyInstallationUrls,events,requests,state,metas,document,
  jump:ms=>{clock+=ms;},visible:()=>listeners.get('visibilitychange')?.(),restored:()=>listeners.get('window:pageshow')?.({persisted:true}),
  async tick(ms){const target=clock+ms;
   for(let count=0;count<1000;count++){
    const first=[...timers.entries()].filter(([,entry])=>entry.due<=target).sort((a,b)=>a[1].due-b[1].due)[0];
    if(!first){clock=target;await flush();return;}
    const [id,entry]=first;clock=entry.due;timers.delete(id);
    if(entry.interval)timers.set(id,{...entry,due:clock+entry.interval});
    entry.callback();await flush();
   }
   throw Error('Fixture timer loop did not settle.');
  }};
}
const check=(name,fn)=>test(name,{timeout:3000},fn);

check('cloud/no metadata keeps subdomain, HTTPS, exact installation prefix and capability query',async()=>{
 for(const mode of ['', 'cloud']){
  const f=fixture({mode,base:'https://education.example.test/~class/lucky/game.php'});
  const input=`live-view.php?id=${ID}&key=${KEY}&label=A%2BB`;
  assert.equal(f.api.share(input).href,'https://education.example.test/~class/lucky/'+input);
  assert.equal(f.api.localShare(input).href,'https://education.example.test/~class/lucky/'+input);
  await flush();await f.tick(60000);assert.equal(f.requests.length,0,'hosted pages do not poll the HomeServer API');
 }
});
check('HomeServer maps only the sharing origin and preserves private capability scope',()=>{
 const f=fixture();
 for(const route of ['controller.php','display.php','package-controller.php','live-view.php']){
  const raw=`${route}?id=${ID}&key=${KEY}`;
  assert.equal(f.api.share(raw).href,`${LAN}/${raw}`);
  assert.equal(f.api.share(`http://localhost:8833/${raw}`).href,`${LAN}/${raw}`);
  assert.equal(f.api.share(`${LAN}/${raw}`).href,`${LAN}/${raw}`);
  assert.equal(f.api.localShare(`${LAN}/${raw}`).href,`http://localhost:8833/${raw}`);
 }
});
check('LAN sharing accepts canonical RFC1918 ranges only',()=>{
 for(const ip of ['10.0.0.1','10.255.255.254','172.16.0.1','172.31.255.254','192.168.0.1','192.168.255.254']){
  const lan=`https://${ip}:8843`,f=fixture({lan});assert.equal(f.api.share('controller.php').origin,lan);
 }
});
check('missing, malformed, expired, and public LAN metadata fail closed',()=>{
 for(const lan of ['', 'https://127.0.0.1:8843','https://localhost:8843','https://8.8.8.8:8843',
  'https://172.15.255.1:8843','https://172.32.0.1:8843','https://169.254.1.2:8843','https://100.64.1.1:8843',
  'http://192.168.1.42:8843','https://192.168.1.42:8833','https://192.168.1.42:8843/',
  'https://192.168.1.42:8843/path','https://192.168.1.42:8843?x=1','https://192.168.1.42:8843#x',
  'https://user@192.168.1.42:8843','https://192.168.1.42.evil.test:8843','https://192.168.1.999:8843',
  'https://0300.0250.1.42:8843','https://0xc0a8012a:8843','https://3232235818:8843',`${LAN}\n`]){
  const f=fixture({lan});assert.throws(()=>f.api.share('controller.php'),/sharing is unavailable/,lan);
 }
 for(const ttl of ['', '0','-1','31','Infinity','1.5','030'])assert.throws(()=>fixture({ttl}).api.share('display.php'),/sharing is unavailable/);
 assert.throws(()=>fixture({mode:'unknown'}).api.share('display.php'),/configuration is invalid/);
});
check('capability links cannot leave this installation or become credentials/fragment URLs',()=>{
 const f=fixture({base:'http://localhost:8833/class/lucky/game.php'});
 const hostile=[`https://evil.test/class/lucky/live-view.php?id=${ID}&key=${KEY}`,
  `https://192.168.1.43:8843/class/lucky/live-view.php?key=${KEY}`,
  'https://192.168.1.42:8843/other/live-view.php','/live-view.php','../live-view.php',
  'api/index.php','director.php','live-view.php/extra','live-view.php#secret',
  'https://user:password@192.168.1.42:8843/class/lucky/live-view.php','javascript:alert(1)',
  'data:text/html,test','\\\\evil.test/live-view.php',' live-view.php','live-view.php\n',
  'https://evil.test/class/lucky/live-view.php?redirect=http://localhost:8833'];
 for(const raw of hostile){assert.throws(()=>f.api.share(raw),undefined,raw);assert.throws(()=>f.api.localShare(raw),undefined,raw);}
 assert.equal(f.api.share('live-view.php').pathname,'/class/lucky/live-view.php');
});
check('cloud never accepts a different installation origin even if it is private or loopback',()=>{
 const f=fixture({mode:'cloud',base:'https://lucky.example.test/school/game.php'});
 for(const raw of ['http://localhost:8833/school/controller.php',LAN+'/school/controller.php','https://other.example.test/school/controller.php'])
  assert.throws(()=>f.api.share(raw));
});
check('HomeServer stores portable paths while hosted nested/encoded directories preserve absolute URLs',()=>{
 for(const base of ['http://localhost:8833/admin.php','https://club.test/school/lucky/admin.php','https://club.test/~account/lucky/admin.php',
  'https://club.test/.class/lucky/admin.php','https://club.test/summer%20school/lucky/admin.php','https://club.test/\u00e9ducation/lucky/admin.php']){
  const f=fixture({base,mode:base.includes('club.test')?'cloud':'homeserver'}),directory=new URL('.',base).pathname;
  for(const action of ['prize_image','prize_video','track']){
   const raw=`api/index.php?action=${action}&id=${ID}`;
   const expected=base.includes('club.test')?new URL(raw,base).href:directory+raw;
   assert.equal(f.api.storeMedia(raw),expected);
   assert.equal(f.api.storeMedia(new URL(raw,base)),expected);
   assert.equal(f.api.storeMedia(`api/index.php?id=${ID}&action=${action}`),expected,'stored query order agrees with server validation');
   assert.equal(f.api.media(directory+raw),new URL(raw,base).href);
  }
 }
});
check('known legacy local uploads repair to the viewer origin without moving host API/authentication',()=>{
 const f=fixture({base:LAN+'/controller.php'});
 for(const action of ['prize_image','prize_video','track'])for(const host of ['localhost','127.0.0.1']){
  const raw=`http://${host}:8833/api/index.php?action=${action}&id=${ID}`;
  assert.equal(f.api.media(raw),`${LAN}/api/index.php?action=${action}&id=${ID}`);
 }
 const local=fixture();assert.equal(local.api.media(`${LAN}/api/index.php?action=prize_image&id=${ID}`),
  `http://localhost:8833/api/index.php?action=prize_image&id=${ID}`);
 assert.equal(local.api.localShare(`${LAN}/live-view.php?id=${ID}&key=${KEY}`).origin,'http://localhost:8833');
});
check('unrelated media are never relabeled local by pathname, hostname, or query similarity',()=>{
 const f=fixture({base:LAN+'/controller.php'});
 const paths=[`http://localhost:80/api/index.php?action=prize_image&id=${ID}`,
  `http://localhost:9999/api/index.php?action=prize_image&id=${ID}`,
  `https://localhost:8833/api/index.php?action=prize_image&id=${ID}`,
  `https://external.test/api/index.php?action=prize_image&id=${ID}`,
  `https://192.168.1.99:8843/api/index.php?action=prize_image&id=${ID}`,
  `http://localhost:8833/other/api/index.php?action=prize_image&id=${ID}`,
  `http://localhost:8833/api/index.php?action=secret&id=${ID}`,
  `http://localhost:8833/api/index.php?action=prize_image&id=${ID}&extra=1`,
  `http://localhost:8833/api/index.php?action=prize_image&action=prize_video&id=${ID}`,
  `http://localhost:8833/api/index.php?action=prize_image&id=${ID}&id=${ID}`,
  `http://localhost:8833/api/index.php?action=prize_image&id=${ID}#fragment`,
  `http://user:pass@localhost:8833/api/index.php?action=prize_image&id=${ID}`,
  'http://localhost:8833/api/index.php?action=prize_image&id=not-an-id',
  'https://cdn.example.test/prize.jpg?version=2','https://youtube.test/watch?v=test'];
 for(const raw of paths){assert.equal(f.api.media(raw),raw,raw);assert.throws(()=>f.api.storeMedia(raw),undefined,raw);}
 assert.equal(f.api.media(''),'');assert.equal(f.api.media(null),null);
});
check('hosted installations preserve legitimate external HTTP(S) media byte for byte',()=>{
 const f=fixture({mode:'cloud',base:'https://club.test/lucky/admin.php'});
 for(const raw of [`http://localhost:8833/lucky/api/index.php?action=prize_video&id=${ID}`,
  `https://cdn.test/lucky/api/index.php?action=prize_image&id=${ID}`,'http://cdn.test/photo.jpg'])assert.equal(f.api.media(raw),raw);
});
check('same-origin media and host navigation remain usable when the sharing lease is missing',()=>{
 const f=fixture({lan:'',ttl:'0'}),raw=`api/index.php?action=prize_image&id=${ID}`;
 assert.throws(()=>f.api.share('controller.php'));
 assert.equal(f.api.media(raw),'http://localhost:8833/'+raw);
 assert.equal(f.api.storeMedia(raw),'/'+raw);
 assert.equal(f.api.localShare('live-view.php?id='+ID).origin,'http://localhost:8833');
});
check('lease refresh uses only the current installation endpoint and forbids redirects',async()=>{
 const f=fixture({base:'http://localhost:8833/school/game.php'});await flush();
 assert.equal(f.requests.length,1);const request=f.requests[0];
 assert.equal(request.url,'http://localhost:8833/school/api/installation-urls.php');
 assert.equal(request.options.credentials,'same-origin');assert.equal(request.options.cache,'no-store');
 assert.equal(request.options.redirect,'error');assert.ok(request.options.signal instanceof AbortSignal);
 await f.tick(10000);assert.equal(f.requests.length,2);
});
check('failed refresh invalidates copied/QR addresses immediately and broadcasts unavailability',async()=>{
 const f=fixture();await flush();f.state.respond=()=>({httpOk:false});
 assert.equal(await f.api.refresh(),false);assert.throws(()=>f.api.share('display.php'),/sharing is unavailable/);
 assert.equal(f.events.at(-1).type,'lucky:installation-urls');assert.equal(f.events.at(-1).detail.available,false);
});
check('hanging lease refresh is bounded, aborts the request, and cannot preserve an old lease',async()=>{
 const f=fixture();await flush();let finish;f.state.respond=()=>new Promise(resolve=>{finish=resolve;});
 const pending=f.api.refresh();await flush();const request=f.requests.at(-1);
 await f.tick(3999);assert.equal(request.options.signal.aborted,false);
 await f.tick(1);assert.equal(await pending,false);assert.equal(request.options.signal.aborted,true);
 assert.throws(()=>f.api.share('controller.php'));
 finish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:30});await flush();
 assert.throws(()=>f.api.share('controller.php'),'late response must not resurrect a timed-out lease');
});
check('expiry is checked synchronously even if background timers/events have not executed',async()=>{
 const f=fixture();await flush();f.jump(30001);
 assert.throws(()=>f.api.share('controller.php'),/sharing is unavailable/);
 assert.throws(()=>f.api.localShare(`${LAN}/controller.php`));
 assert.equal(f.events.at(-1).detail.available,false);
});
check('lease timer itself clears old addresses while a renewal is pending',async()=>{
 const f=fixture({ttl:'1',respond:()=>new Promise(()=>{})});
 assert.equal(f.api.share('controller.php').origin,LAN);await f.tick(1000);
 assert.equal(f.events.at(-1).detail.available,false);assert.throws(()=>f.api.share('display.php'));
});
check('renewal deducts request latency so a delayed reply cannot extend the server lease',async()=>{
 let finish;const f=fixture({ttl:'1',respond:()=>new Promise(resolve=>{finish=resolve;})});await flush();
 await f.tick(3000);finish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:4});await flush();
 assert.equal(f.api.share('controller.php').origin,LAN);await f.tick(1000);
 assert.throws(()=>f.api.share('controller.php'));
});
check('a fresh server address rebuilds relative invitations but rejects an old absolute LAN origin',async()=>{
 const f=fixture();await flush();const next='https://10.2.3.4:8843';
 f.state.respond=()=>({ok:true,deployment:'homeserver',lanOrigin:next,leaseSeconds:30});
 assert.equal(await f.api.refresh(),true);
 assert.equal(f.api.share(`live-view.php?id=${ID}&key=${KEY}`).href,`${next}/live-view.php?id=${ID}&key=${KEY}`);
 assert.throws(()=>f.api.share(`${LAN}/live-view.php?id=${ID}`));
 assert.equal(f.events.at(-1).detail.lanOrigin,next);
 const upload=`/api/index.php?action=prize_image&id=${ID}`;
 assert.equal(f.api.storeMedia(upload),upload,'stored assets are not coupled to a LAN IP');
});
check('bad refresh payloads cannot extend or change the trusted sharing origin',async()=>{
 for(const answer of [{ok:false},{ok:true,deployment:'cloud',lanOrigin:LAN,leaseSeconds:30},
  {ok:true,deployment:'homeserver',lanOrigin:'https://evil.test:8843',leaseSeconds:30},
  {ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:31},
  {ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:'30'},
  {ok:true,deployment:'homeserver',lanOrigin:[LAN],leaseSeconds:30},
  {ok:true,deployment:'homeserver',lanOrigin:{origin:LAN},leaseSeconds:30},
  {ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:0}]){
  const f=fixture();await flush();f.state.respond=()=>answer;
  assert.equal(await f.api.refresh(),false);assert.throws(()=>f.api.share('controller.php'));
 }
});
check('concurrent refresh calls coalesce, but foregrounding starts a genuinely new request',async()=>{
 const f=fixture();await flush();let finish;f.state.respond=()=>new Promise(resolve=>{finish=resolve;});
 const p=f.api.refresh(),q=f.api.refresh();assert.equal(p,q);assert.equal(f.requests.length,2);
 const oldFinish=finish;f.visible();assert.equal(f.requests.length,3);assert.equal(f.requests[1].options.signal.aborted,true);
 assert.throws(()=>f.api.share('display.php'),'foregrounding invalidates the cached hint until current-origin refresh');
 oldFinish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:30});assert.equal(await p,false);
 assert.throws(()=>f.api.share('display.php'),'old in-flight response cannot renew a foreground lease');
 finish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:30});await flush();
 assert.equal(f.api.share('display.php').origin,LAN);
});
check('slow HTML/deferred helper cannot revive a lease older than the navigation',async()=>{
 const f=fixture({initialClock:31000,respond:()=>new Promise(()=>{})});
 assert.throws(()=>f.api.share('controller.php'));assert.equal(f.events.at(-1).detail.available,false);
 await f.tick(4000);assert.throws(()=>f.api.share('controller.php'));
});
check('BFCache restoration fails closed until a fresh server lease, even if the monotonic clock paused',async()=>{
 const f=fixture();await flush();let finish;
 f.state.respond=()=>new Promise(resolve=>{finish=resolve;});f.restored();
 assert.throws(()=>f.api.share('controller.php'));assert.equal(f.requests.length,2);
 finish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:30});await flush();
 assert.equal(f.api.share('controller.php').origin,LAN);
});
check('a late pre-BFCache request cannot overwrite a newer valid address or clear its pending request',async()=>{
 const f=fixture();await flush();let finish;f.state.respond=()=>new Promise(resolve=>{finish=resolve;});
 const old=f.api.refresh(),oldFinish=finish;f.restored();const fresh=f.api.refresh(),newFinish=finish;
 oldFinish({ok:true,deployment:'homeserver',lanOrigin:LAN,leaseSeconds:30});assert.equal(await old,false);
 assert.equal(f.api.refresh(),fresh,'stale finally must not clear the current pending generation');
 newFinish({ok:true,deployment:'homeserver',lanOrigin:'https://10.9.8.7:8843',leaseSeconds:30});assert.equal(await fresh,true);
 assert.equal(f.api.share('controller.php').origin,'https://10.9.8.7:8843');
});
