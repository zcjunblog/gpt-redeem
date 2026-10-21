document.getElementById('download-codes')?.addEventListener('click',()=>{
  const value=document.getElementById('new-codes').value;
  const url=URL.createObjectURL(new Blob(['# 本地模拟卡密\n'+value+'\n'],{type:'text/plain;charset=utf-8'}));
  const link=document.createElement('a');link.href=url;link.download='demo-codes.txt';link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);
});
