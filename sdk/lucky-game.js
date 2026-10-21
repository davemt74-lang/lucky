/* Lucky Game Hub SDK v2 — runs only in a sandboxed game frame.
 * No network, cookies, filesystem, camera, or administrative APIs.
 */
(function (scope) {
  'use strict';
  const callbacks=new Set();
  let current=null;
  function primitive(value) {
    return value===null||typeof value==='boolean'||
      (typeof value==='string'&&value.length<=240)||
      (typeof value==='number'&&Number.isSafeInteger(value)&&Math.abs(value)<=100000000);
  }
  function sendInput(name,value=null) {
    if(typeof name!=='string'||!/^[a-z][a-z0-9_-]{0,39}$/.test(name))
      throw new TypeError('Invalid game input name');
    if(!primitive(value))throw new TypeError('Game inputs must be bounded primitive values');
    if(!current||current.status!=='active')return false;
    scope.parent.postMessage({type:'lucky:input',name,value},'*');
    return true;
  }
  function onState(callback) {
    if(typeof callback!=='function')throw new TypeError('State listener must be a function');
    callbacks.add(callback);
    if(current!==null)callback(current);
    return () => callbacks.delete(callback);
  }
  const pending=new Map();
  let nextId=0;
  function request(operation,args={}) {
    if(typeof operation!=='string'||!/^[a-z]+\\.[a-z]+$/.test(operation))
      return Promise.reject(new TypeError('Invalid SDK operation'));
    if(!current||!['active','paused'].includes(current.status))
      return Promise.reject(new Error('This game session is not running'));
    if(pending.size>=16)return Promise.reject(new Error('Too many SDK requests'));
    const requestId='sdk-'+(++nextId)+'-'+Math.random().toString(36).slice(2,14);
    return new Promise((resolve,reject)=>{
      const timeout=scope.setTimeout(()=>{
        pending.delete(requestId);reject(new Error('SDK request timed out; refresh state before retrying'));
      },10000);
      pending.set(requestId,{resolve,reject,timeout});
      scope.parent.postMessage({type:'lucky:capability',requestId,operation,args},'*');
    });
  }
  const storage=Object.freeze({
    get:(key,scopeName='game')=>request('storage.get',{key,scope:scopeName}),
    set:(key,value,expectedRevision=0,scopeName='game')=>
      request('storage.set',{key,value,expectedRevision,scope:scopeName})
  });
  const players=Object.freeze({
    list:()=>request('players.list'),
    add:(id,name)=>request('players.add',{id,name})
  });
  const scores=Object.freeze({
    list:()=>request('scores.list'),
    add:(playerId,delta)=>request('scores.add',{playerId,delta})
  });
  const rounds=Object.freeze({
    get:()=>request('rounds.get'),
    next:()=>request('rounds.next')
  });
  const turns=Object.freeze({
    get:()=>request('turns.get'),
    set:(playerId)=>request('turns.set',{playerId})
  });
  const timers=Object.freeze({
    get:(name)=>request('timers.get',{name}),
    start:(name,seconds)=>request('timers.start',{name,seconds}),
    stop:(name)=>request('timers.stop',{name})
  });
  const session=Object.freeze({save:()=>request('session.save')});
  scope.addEventListener('message',event=>{
    if(event.source!==scope.parent||!event.data)return;
    if(event.data.type==='lucky:capability-result'){
      const task=pending.get(event.data.requestId);
      if(!task)return;
      pending.delete(event.data.requestId);scope.clearTimeout(task.timeout);
      if(event.data.ok===true)task.resolve(event.data.value);
      else task.reject(new Error(typeof event.data.error==='string'?event.data.error:'SDK operation failed'));
      return;
    }
    if(event.data.type!=='lucky:state')return;
    const state=event.data.state;
    if(!state||typeof state!=='object'||Array.isArray(state))return;
    current=state;
    for(const callback of callbacks) {
      try {callback(state);}catch(e){/* isolate listeners from one another */}
    }
  });
  scope.LuckyGameSDK=Object.freeze({
    version:'2.0.0',
    onState,sendInput,request,storage,players,scores,rounds,turns,timers,session,
    getState:()=>current
  });
})(window);
