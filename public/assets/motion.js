(() => {
  const body=document.body,aura=document.getElementById('cursor-aura'),panel=document.querySelector('.recharge-panel');
  if(!aura||!panel)return;
  const reduce=matchMedia('(prefers-reduced-motion: reduce)'),fine=matchMedia('(hover: hover) and (pointer: fine)');
  let frame=0,active=false,x=0,y=0,targetX=0,targetY=0;
  const enabled=()=>!reduce.matches&&fine.matches&&!document.hidden;
  function stop(){cancelAnimationFrame(frame);frame=0;active=false;body.classList.remove('has-pointer-aura');panel.classList.remove('is-illuminated');}
  function paint(){
    frame=0;if(!active||!enabled()){stop();return;}
    x+=(targetX-x)*.16;y+=(targetY-y)*.16;
    const rect=panel.getBoundingClientRect(),inside=targetX>=rect.left&&targetX<=rect.right&&targetY>=rect.top&&targetY<=rect.bottom;
    aura.style.transform=`translate3d(${x.toFixed(2)}px,${y.toFixed(2)}px,0)`;
    panel.style.setProperty('--panel-x',`${(targetX-rect.left).toFixed(2)}px`);panel.style.setProperty('--panel-y',`${(targetY-rect.top).toFixed(2)}px`);
    panel.classList.toggle('is-illuminated',inside);
    if(Math.abs(targetX-x)+Math.abs(targetY-y)>.2)frame=requestAnimationFrame(paint);
  }
  document.addEventListener('pointermove',event=>{
    if(event.pointerType!=='mouse'||!enabled())return;
    targetX=event.clientX;targetY=event.clientY;
    if(!active){x=targetX;y=targetY;active=true;body.classList.add('has-pointer-aura');}
    if(!frame)frame=requestAnimationFrame(paint);
  },{passive:true});
  document.documentElement.addEventListener('pointerleave',stop);
  document.addEventListener('visibilitychange',()=>{if(document.hidden)stop();});
  window.addEventListener('blur',stop);window.addEventListener('pagehide',stop);window.addEventListener('resize',stop);
  window.addEventListener('scroll',()=>{if(active&&!frame)frame=requestAnimationFrame(paint);},{passive:true});
  reduce.addEventListener('change',stop);fine.addEventListener('change',stop);
})();
