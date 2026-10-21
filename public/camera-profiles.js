/* Shared camera preferences for this HomeServer browser/device.
 * Camera identifiers and getUserMedia permissions are origin/browser bound.
 * No camera devices, streams or private identifiers are transmitted to the server.
 * Built-in and packaged-game host views may use the same trusted helper. */
(function(root,factory){
 const api=factory();
 if(typeof module==='object'&&module.exports)module.exports=api;
 if(root)root.LuckyCameraProfiles=api;
})(typeof window==='undefined'?null:window,function(){
 'use strict';
 const GLOBAL='lucky-shared-camera-profiles-v1',OVERRIDE='lucky-game-camera-selections-v1',LEGACY='lucky-selected-camera-ids',ALIASES='lucky-camera-aliases',LIMIT=4;
 const store=()=>typeof localStorage==='undefined'?null:localStorage;
 const read=key=>{try{return JSON.parse(store()?.getItem(key)||'null');}catch(_){return null;}};
 const write=(key,value)=>{try{store()?.setItem(key,JSON.stringify(value));return true;}catch(_){return false;}};
 const id=s=>typeof s==='string'&&s.length>0&&s.length<=512&&!/[\x00-\x1f\x7f]/.test(s);
 const clean=ids=>[...new Set((Array.isArray(ids)?ids:[]).filter(id))];
 const validGame=game=>typeof game==='string'&&/^[a-z0-9][a-z0-9-]{1,62}$/.test(game)?game:'spin-wheel';
 const saved=()=>read(GLOBAL);
 function shared(){const p=saved();if(p&&Array.isArray(p.ids))return clean(p.ids).slice(0,LIMIT);return clean(read(LEGACY)).slice(0,LIMIT);}
 function overrides(){const v=read(OVERRIDE);return v&&typeof v==='object'&&!Array.isArray(v)?v:{};}
 function gameOverrides(game){const r=overrides()[validGame(game)];return {add:clean(r?.add),remove:clean(r?.remove)};}
 function selected(game){const global=shared(),override=gameOverrides(game),exclude=new Set(override.remove);
  return [...new Set([...global.filter(x=>!exclude.has(x)),...override.add])].slice(0,LIMIT);
 }
 function saveShared(ids){const cleanIds=clean(ids);if(cleanIds.length>LIMIT)throw Error(`Choose no more than ${LIMIT} shared cameras.`);
  if(!write(GLOBAL,{ids:cleanIds,version:1}))throw Error('Browser storage is unavailable. Camera preferences were not saved.');return cleanIds;
 }
 function setGameCamera(game,camera,checked){if(!id(camera))throw Error('Camera identity is unavailable. Detect the camera again.');
  const name=validGame(game),data=overrides(),globals=shared(),settings=gameOverrides(name);
  let add=new Set(settings.add),remove=new Set(settings.remove);
  if(globals.includes(camera)){if(checked)remove.delete(camera);else remove.add(camera);add.delete(camera);}
  else{if(checked)add.add(camera);else add.delete(camera);remove.delete(camera);}
  const result=[...new Set([...globals.filter(x=>!remove.has(x)),...add])];
  if(result.length>LIMIT)throw Error(`Recording supports up to ${LIMIT} cameras. Deselect one camera before adding another.`);
  data[name]={add:[...add],remove:[...remove]};
  if(!write(OVERRIDE,data))throw Error('Browser storage is unavailable. Game selection was not saved.');
  return result;
 }
 function getAliases(){const a=read(ALIASES);return a&&typeof a==='object'&&!Array.isArray(a)?a:{};}
 function alias(camera,label){if(!id(camera))throw Error('Camera identity is unavailable.');const text=String(label||'').trim().replace(/[\x00-\x1f\x7f]/g,'').slice(0,120);
  if(!text)throw Error('Enter a camera name.');const values=getAliases();values[camera]=text;
  if(!write(ALIASES,values))throw Error('Browser storage is unavailable. Camera name was not saved.');return text;
 }
 return Object.freeze({LIMIT,shared,selected,saveShared,setGameCamera,getAliases,alias,gameOverrides,validGame});
});
