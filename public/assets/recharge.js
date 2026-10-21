(() => {
  const $=id=>document.getElementById(id), mode=document.body?.dataset?.mode||'mock';
  let card=null,email='',session=null,order=null,timer=null,revision=0,tab='submit',refreshing=false,backoff=5;
  let captcha=null,captchaTimer=null;
  const busyForms=new WeakSet(),closed=new Set(['succeeded','failed']);
  const titles={pending:'订单已创建',processing:'正在处理充值',awaiting_verification:'需要完成人机验证',succeeded:'充值成功',failed:'充值未完成',unknown:'充值结果待核实'};
  function message(text){$('message').textContent=text;$('message').hidden=!text;}
  function clearCredentials(){session=null;$('session-json').value='';if(mode!=='mock')$('email').value='';}
  function clearCaptcha(){clearTimeout(captchaTimer);captcha=null;$('captcha-link').hidden=true;$('captcha-link').removeAttribute('href');$('captcha-note').hidden=true;}
  async function api(action,data){
    const response=await fetch('/api.php',{method:'POST',headers:{'Content-Type':'application/json','X-Local-Request':'1'},body:JSON.stringify({action,...data}),cache:'no-store'});
    const result=await response.json();if(!response.ok){const e=new Error(result.error||'请求暂时未完成，请稍后查询原单');const after=response.headers?.get?.('Retry-After');e.retryAfter=Math.max(5,Number(after)||5);throw e;}return result;
  }
  function selectTab(next){
    tab=next;clearTimeout(timer);if(next!=='submit'){clearCredentials();clearCaptcha();}if(next!=='help')$('tutorial-video').pause?.();
    for(const name of ['submit','query','help']){const active=name===next;$(`${name}-view`).hidden=!active;$(`tab-${name}`).setAttribute('aria-selected',String(active));$(`tab-${name}`).tabIndex=active?0:-1;}
    message('');if(tab==='submit'&&order&&(!closed.has(order.status)||order.queryError))schedule();
  }
  function step(number){for(let n=1;n<=4;n++){$(`step-${n}`).hidden=n!==number;const marker=$(`step-marker-${n}`);marker.className=n===number?'active':n<number?'complete':'';if(n===number)marker.setAttribute('aria-current','step');else marker.removeAttribute('aria-current');}}
  function reset(){clearTimeout(timer);revision++;clearCredentials();clearCaptcha();card=null;order=null;email='';$('code').value='';$('confirm-check').checked=false;step(1);selectTab('submit');$('code').focus();}
  function showCaptcha(value){
    const previous=captcha?.id;clearCaptcha();
    if(value.mock||value.status!=='awaiting_verification')return;
    const c=value.captcha;$('captcha-note').hidden=false;
    if(!c||!c.url||c.expiresAt*1000<=Date.now()){$('captcha-note').textContent='正在等待有效验证入口，请稍后查询原单。';return;}
    let url;try{url=new URL(c.url);if(url.protocol!=='https:'||url.username||url.password)throw new Error();}catch{$('captcha-note').textContent='验证入口暂不可用，请稍后查询或联系卡密销售方。';return;}
    captcha=c;$('captcha-link').href=c.url;$('captcha-link').hidden=false;
    $('captcha-note').textContent=previous&&previous!==c.id?'收到新的验证任务，请完成本轮验证。':'请在新窗口完成验证，然后回到本站继续等待充值结果。';
    captchaTimer=setTimeout(()=>{clearCaptcha();$('captcha-note').hidden=false;$('captcha-note').textContent='本轮验证已过期，请查询原单获取最新入口。';},Math.min(2147483647,Math.max(1,c.expiresAt*1000-Date.now())));
  }
  function showOrder(value){
    const mock=value.mock??mode==='mock';order=value;step(4);$('result-title').textContent=mock&&value.status==='succeeded'?'模拟充值成功':titles[value.status];
    $('result-description').textContent=value.reason||'订单已提交，请耐心等待结果。';
    if(mock&&value.status==='succeeded')$('result-description').textContent='模拟流程已完成，未产生实际充值或扣款。';
    $('result-icon').src=`/assets/${value.status==='succeeded'?'circle-check':['failed','unknown'].includes(value.status)?'circle-alert':'clock'}.svg`;$('result-icon').className=value.status==='succeeded'?'success-icon':'';
    $('result-progress').hidden=closed.has(value.status)||value.status==='unknown'||value.status==='awaiting_verification';
    $('order-id').textContent=value.id;$('order-email').textContent=value.email;$('order-plan').textContent=value.planName;
    $('verify-button').hidden=!(mock&&value.status==='awaiting_verification');
    $('renewal-row').hidden=mock||value.status!=='succeeded';$('renewal-status').textContent=value.subscriptionCancelled===true?'已取消':value.subscriptionCancelled===false?'未取消':'暂无确认结果';
    $('poll-note').textContent=value.queryError&&closed.has(value.status)?'当前展示此前已确认结果，本次查询未成功。':closed.has(value.status)?'原单结果已确认':'每次间隔至少 5 秒查询，请勿重复下单。';if(value.queryError)message(value.queryError);showCaptcha(value);schedule();
  }
  function schedule(){
    clearTimeout(timer);if(!order||(closed.has(order.status)&&!order.queryError)||tab!=='submit')return;
    if(Date.now()-order.createdAt*1000>900000){$('poll-note').textContent='自动查询已暂停，可点击“查询原单”继续。';return;}
    const version=revision,delay=Math.max(5,order.nextPollSeconds||5,backoff),remaining=900000-(Date.now()-order.createdAt*1000);if(delay*1000>remaining){timer=setTimeout(()=>{$('poll-note').textContent='自动查询已暂停，请按提示稍后查询原单。';},Math.max(1,remaining));return;}timer=setTimeout(()=>refresh(version),Math.min(2147483647,delay*1000));
  }
  async function refresh(version=revision){
    if(!order||refreshing)return;const id=order.id;refreshing=true;clearTimeout(timer);
    try{const result=await api('lookup',{reference:id});if(version!==revision||order?.id!==id)return;backoff=5;message('');showOrder(result.order);}
    catch(error){if(version===revision){clearCaptcha();message(error.message);backoff=Math.min(300,Math.max(backoff*2,error.retryAfter||5));$('poll-note').textContent='查询暂时中断，将稍后继续查询原单。';schedule();}}
    finally{refreshing=false;}
  }
  async function busy(form,fn){if(busyForms.has(form))return;busyForms.add(form);const button=form.querySelector('button[type="submit"],button:not([type])');if(button)button.disabled=true;message('');try{await fn();}catch(error){message(error.message);}finally{busyForms.delete(form);if(button)button.disabled=false;}}
  document.querySelectorAll('[data-tab]').forEach(button=>{
    button.addEventListener('click',()=>selectTab(button.dataset.tab));
    button.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const names=['submit','query','help'],index=names.indexOf(tab),next=event.key==='Home'?0:event.key==='End'?2:(index+(event.key==='ArrowRight'?1:2))%3;selectTab(names[next]);$(`tab-${names[next]}`).focus();});
  });
  document.querySelectorAll('[data-back]').forEach(button=>button.addEventListener('click',()=>{message('');if(button.dataset.back==='1')clearCredentials();step(Number(button.dataset.back));}));
  $('code-form').addEventListener('submit',event=>{event.preventDefault();busy(event.currentTarget,async()=>{
    const version=++revision;clearTimeout(timer);clearCredentials();clearCaptcha();const code=$('code').value.trim(),value=await api('inspect',{code});if(version!==revision)return;card={...value,code};
    if(value.order){showOrder(value.order);await refresh(version);return;}
    if(value.status!=='unused')throw new Error(value.status==='disabled'?'这张卡密已禁用，请联系卡密销售方。':'这张卡密已过期，请联系卡密销售方。');
    if((value.mock===true)!==(mode==='mock')&&value.mock!==undefined)throw new Error('卡密通道不匹配，请联系卡密销售方。');
    $('plan-name').textContent=value.planName;$('expires').textContent='有效期至 '+new Date(value.expiresAt*1000).toLocaleDateString('zh-CN',{timeZone:'Asia/Shanghai'});$('confirm-check').checked=false;step(2);$(mode==='mock'?'email':'session-json').focus();
  });});
  $('session-json').addEventListener('input',()=>{if(mode==='mock')return;try{const value=JSON.parse($('session-json').value);$('email').value=typeof value.user?.email==='string'?value.user.email:'';}catch{$('email').value='';}});
  $('account-form').addEventListener('submit',event=>{
    event.preventDefault();
    if(mode!=='mock'){
      try{session=JSON.parse($('session-json').value);if(!session||typeof session!=='object'||Array.isArray(session)||typeof session.user?.email!=='string'||!session.user.id||!session.account?.id||typeof session.accessToken!=='string'||!session.accessToken||!session.expires||!Number.isFinite(Date.parse(session.expires))||Date.parse(session.expires)<=Date.now())throw new Error();email=session.user.email.trim().toLowerCase();$('email').value=email;}
      catch{session=null;message('登录资料不完整或已过期，请重新登录后复制完整 Session JSON。');return;}
    }else{email=$('email').value.trim().toLowerCase();if(!/^[a-z0-9._+\-]+@(example\.(com|org|net)|[a-z0-9.-]+\.(test|invalid))$/.test(email)){message('测试模式只接受虚构邮箱，例如 demo@example.com');return;}}
    if(!card)return;message('');$('confirm-plan').textContent=card.planName;$('confirm-email').textContent=email;$('confirm-code').textContent=card.prefix+'…';$('confirm-check').checked=false;step(3);
  });
  $('confirm-form').addEventListener('submit',event=>{event.preventDefault();busy(event.currentTarget,async()=>{
    if(!$('confirm-check').checked||!card)return;if(mode!=='mock'&&!session)throw new Error('请返回上一步重新填写登录资料。');
    const version=revision,code=card.code,targetEmail=email,data={code,email:targetEmail};if(mode!=='mock'){data.session_json=session;data.authorized=true;}clearCredentials();
    try{const result=await api('redeem',data);if(version!==revision)return;showOrder(result.order);$('result-title').focus();}
    catch(error){const result=await api('lookup',{reference:code});if(result.order&&version===revision)showOrder(result.order);throw error;}
    finally{delete data.session_json;}
  });});
  $('query-form').addEventListener('submit',event=>{event.preventDefault();busy(event.currentTarget,async()=>{
    clearTimeout(timer);clearCredentials();clearCaptcha();const version=++revision,result=await api('lookup',{reference:$('reference').value.trim()});if(version!==revision)return;if(!result.order)throw new Error('此卡密尚未提交充值，请在“提交充值”中使用。');selectTab('submit');showOrder(result.order);$('result-title').focus();
  });});
  $('verify-button').addEventListener('click',async()=>{
    const id=order?.id,version=revision;if(!id||!order.mock)return;$('verify-button').disabled=true;try{await api('verify',{id});if(version===revision&&order?.id===id)await refresh(version);}catch(error){if(version===revision)message(error.message);}finally{$('verify-button').disabled=false;}
  });
  $('captcha-link').addEventListener('click',event=>{if(!captcha||captcha.expiresAt*1000<=Date.now()){event.preventDefault();clearCaptcha();message('验证入口已过期，正在查询最新任务。');refresh();}});
  $('refresh-order').addEventListener('click',async()=>{$('refresh-order').disabled=true;await refresh();$('refresh-order').disabled=false;});
  $('copy-order').addEventListener('click',async()=>{try{await navigator.clipboard.writeText(order.id);$('poll-note').textContent='订单号已复制，可用于稍后查询。';}catch{$('poll-note').textContent='请手动复制上方订单号。';}});
  $('start-over').addEventListener('click',reset);window.addEventListener('pagehide',()=>{clearTimeout(timer);clearCredentials();clearCaptcha();});
})();
