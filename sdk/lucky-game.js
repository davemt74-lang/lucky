/* Lucky Game Hub SDK v1 — runs only in a sandboxed game frame.
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
  scope.addEventListener('message',event=>{
    if(event.source!==scope.parent||!event.data||event.data.type!=='lucky:state')return;
    const state=event.data.state;
    if(!state||typeof state!=='object'||Array.isArray(state))return;
    current=state;
    for(const callback of callbacks) {
      try {callback(state);}catch(e){/* isolate listeners from one another */}
    }
  });
  scope.LuckyGameSDK=Object.freeze({
    version:'1.0.0',
    onState,sendInput,
    getState:()=>current
  });
})(window);
