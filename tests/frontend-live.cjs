'use strict';
const assert=require('node:assert/strict'),fs=require('node:fs'),vm=require('node:vm'),path=require('node:path');
const elements=new Map(),timers=new Map(),requests=[],events={};let tid=0;
function element(id){if(!elements.has(id))elements.set(id,{id,value:'',hidden:false,checked:false,dataset:{},listeners:{},setAttribute(k,v){this[k]=v;},removeAttribute(k){delete this[k];},focus(){},querySelector(){return element(id+'-button');},addEventListener(k,v){this.listeners[k]=v;}});return elements.get(id);}
let result={id:'ORD-TEST',plan:'plus',planName:'ChatGPT Plus',status:'awaiting_verification',email:'t***@example.test',mock:false,createdAt:Math.floor(Date.now()/1000),nextPollSeconds:5,captcha:{id:'cap-A',url:'https://verify.example.com/a#access=TICKET',expiresAt:Math.floor(Date.now()/1000)+120}};
const context={document:{body:{dataset:{mode:'orbitcard'}},getElementById:element,querySelectorAll:()=>[]},window:{addEventListener(k,v){events[k]=v;}},URL,Date,console,setTimeout(fn,delay){timers.set(++tid,{fn,delay});return tid;},clearTimeout(id){timers.delete(id);},fetch:async(_url,opts)=>{const r=JSON.parse(opts.body);requests.push(r);return {ok:true,json:async()=>r.action==='inspect'?{prefix:'CDK-A',planName:'ChatGPT Plus',status:'unused',mock:false,expiresAt:1900000000}: {order:{...result}}};}};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/recharge.js'),'utf8'),context);
const submit=id=>element(id).listeners.submit({preventDefault(){},currentTarget:element(id)}),flush=()=>new Promise(setImmediate);
(async()=>{
  element('code').value='CDK-A';submit('code-form');await flush();
  element('session-json').value=JSON.stringify({user:{id:'user-test',email:'target@example.test'},account:{id:'account-test'},accessToken:'TOKEN-MARKER',expires:'2032-11-01T09:34:59Z',extra:{kept:true}});
  submit('account-form');assert.equal(element('confirm-email').textContent,'target@example.test');element('confirm-check').checked=true;submit('confirm-form');await flush();
  const sent=requests.find(r=>r.action==='redeem');assert.equal(sent.session_json.extra.kept,true);assert.equal(sent.authorized,true);assert.equal(sent.session_json.accessToken,'TOKEN-MARKER');assert.equal(element('session-json').value,'');
  assert.equal(element('captcha-link').href,'https://verify.example.com/a#access=TICKET');assert.equal(element('captcha-link').hidden,false);
  result={...result,captcha:{...result.captcha,id:'cap-B',url:'https://verify.example.com/b#access=NEW'}};await element('refresh-order').listeners.click();assert.equal(element('captcha-link').href,'https://verify.example.com/b#access=NEW');
  result={...result,captcha:undefined,status:'processing',nextPollSeconds:600};await element('refresh-order').listeners.click();assert.equal(element('captcha-link').hidden,true);assert.equal(element('captcha-link').href,undefined);assert([...timers.values()].some(v=>v.delay>=600000),'不得将服务端600秒退避截短');
  events.pagehide();assert.equal(element('session-json').value,'');assert.equal(element('captcha-link').href,undefined);console.log('PASS 真实表单保留完整Session扩展、提交后清理；验证多轮/缺失清除；遵守600秒退避');
})().catch(e=>{console.error('FAIL '+e.stack);process.exitCode=1;});
