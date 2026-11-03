'use strict';
const assert=require('node:assert/strict');
const {test}=require('node:test');
const {spawnSync}=require('node:child_process');

function runFixture(source){
 const env={...process.env};
 // A fixture is an independent Node invocation, not a nested test-runner child.
 delete env.NODE_TEST_CONTEXT;
 const result=spawnSync(process.execPath,['-e',source],{
  encoding:'utf8',timeout:3000,maxBuffer:1024*1024,env
 });
 assert.equal(result.error,undefined,'Child must finish itself, not be killed by the process timeout');
 assert.equal(result.signal,null);
 return result;
}

test('Unresolved asynchronous test cannot exit successfully', {timeout:5000}, ()=>{
 const result=runFixture(`
  const {test}=require('node:test');
  test('unresolved fixture',{timeout:100},async()=>{
   await new Promise(()=>{});
   console.log('UNREACHABLE_COMPLETION');
  });
 `);
 assert.notEqual(result.status,0,'An unresolved registered test must fail certification');
 assert.doesNotMatch(result.stdout,/UNREACHABLE_COMPLETION/);
 assert.match(result.stdout+result.stderr,/timed out|pending|cancelled/);
});

test('Synchronous positive control completes and exits successfully', {timeout:5000}, ()=>{
 const result=runFixture(`
  const {test}=require('node:test');
  const assert=require('node:assert/strict');
  test('synchronous fixture',{timeout:1000},()=>{
   assert.equal(2+2,4);console.log('SYNC_COMPLETED');
  });
 `);
 assert.equal(result.status,0,result.stdout+result.stderr);
 assert.match(result.stdout,/SYNC_COMPLETED/);
});

test('Asynchronous positive control waits for real timer settlement', {timeout:5000}, ()=>{
 const result=runFixture(`
  const {test}=require('node:test');
  const assert=require('node:assert/strict');
  test('asynchronous fixture',{timeout:1000},async()=>{
   const result=await new Promise(resolve=>setTimeout(()=>resolve('settled'),20));
   assert.equal(result,'settled');console.log('ASYNC_COMPLETED');
  });
 `);
 assert.equal(result.status,0,result.stdout+result.stderr);
 assert.match(result.stdout,/ASYNC_COMPLETED/);
});
