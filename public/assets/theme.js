(() => {
  const button=document.getElementById('theme-toggle');
  const apply=value=>{document.documentElement.dataset.theme=value;if(button){button.setAttribute('aria-label',value==='dark'?'切换到浅色模式':'切换到深色模式');document.getElementById('theme-icon').src=`/assets/${value==='dark'?'sun':'moon'}.svg`;}};
  let saved;try{saved=localStorage.getItem('recharge-theme');}catch{}
  apply(saved==='dark'?'dark':'light');
  button?.addEventListener('click',()=>{const value=document.documentElement.dataset.theme==='dark'?'light':'dark';apply(value);try{localStorage.setItem('recharge-theme',value);}catch{}});
})();
