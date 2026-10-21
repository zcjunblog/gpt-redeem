'use strict';
const assert=require('node:assert/strict');
const fs=require('node:fs');
const path=require('node:path');
const vm=require('node:vm');

// Run the actual controller; only browser DOM and transport are substituted.
const elements=new Map();
function element(id){
  if(!elements.has(id))elements.set(id,{
    id,value:'',hidden:false,checked:false,disabled:false,dataset:{},listeners:{},
    setAttribute(){},removeAttribute(){},focus(){},
    querySelector(){return element(`${id}-submit`);},
    addEventListener(type,fn){this.listeners[type]=fn;}
  });
  return elements.get(id);
}
const requests=[];
let resolveInspection;
const context={
  document:{getElementById:element,querySelectorAll:()=>[]},
  window:{addEventListener(){}},setTimeout,clearTimeout,Date,console,
  fetch(_url,options){
    const request=JSON.parse(options.body);requests.push(request);
    if(request.action==='inspect')return new Promise(resolve=>{
      resolveInspection=()=>resolve({ok:true,json:async()=>({
        id:'card-a',prefix:'CARD-A',plan:'plus',planName:'Plus',status:'unused',
        expiresAt:1900000000,order:null
      })});
    });
    if(request.action==='redeem')return Promise.resolve({ok:true,json:async()=>({order:{
      id:'SIM-TEST',plan:'plus',planName:'Plus',email:'d***@example.com',status:'succeeded',
      reason:'模拟充值已完成',subscriptionSucceeded:true,createdAt:1800000000,
      updatedAt:1800000000,restoredAt:null
    }})});
    throw new Error(`Unexpected action: ${request.action}`);
  }
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname,'../public/assets/recharge.js'),'utf8'),context);
const submit=id=>element(id).listeners.submit({preventDefault(){},currentTarget:element(id)});
const flush=()=>new Promise(setImmediate);

(async()=>{
  element('code').value='VALID-CARD-A';submit('code-form');
  // A user may edit the still-enabled input while validation is in flight.
  element('code').value='VALID-CARD-B';resolveInspection();await flush();
  element('email').value='demo@example.com';submit('account-form');
  assert.equal(element('confirm-plan').textContent,'Plus');
  assert.equal(element('confirm-code').textContent,'CARD-A…');
  element('confirm-check').checked=true;submit('confirm-form');await flush();
  const submitted=requests.filter(request=>request.action==='redeem');
  assert.equal(submitted.length,1,'确认后必须仅提交一次');
  assert.equal(submitted[0].code,'VALID-CARD-A','兑换卡密必须与已验证并展示确认的卡密相同');
  console.log('PASS 验证期间编辑输入不能兑换另一张未确认卡密');
})().catch(error=>{console.error(`FAIL ${error.message}`);process.exitCode=1;});
