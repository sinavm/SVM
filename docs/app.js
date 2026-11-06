'use strict';
(function(){
const reportUrl='https://raw.githubusercontent.com/sinavm/SVM/main/reports/test.json';
const byId=id=>document.getElementById(id);
const faNumber=new Intl.NumberFormat('fa-IR');
const setText=(id,value)=>{const el=byId(id);if(el)el.textContent=Number.isFinite(Number(value))?faNumber.format(Number(value)):'—'};
function toast(message){const el=byId('toast');if(!el)return;el.textContent=message;el.classList.add('show');window.setTimeout(()=>el.classList.remove('show'),2200)}
function safeDate(value){if(typeof value!=='string')return null;const d=new Date(value);return Number.isNaN(d.getTime())?null:d}
async function loadReport(){
const button=byId('refresh'),health=byId('health'),label=byId('health-text');
if(button){button.disabled=true;button.textContent='Loading…'}
try{
const response=await fetch(reportUrl,{cache:'no-store'});
if(!response.ok)throw new Error('HTTP '+response.status);
const data=await response.json();
setText('input',data.input);setText('kept',data.kept);setText('tcp',data.tcp_alive);setText('converted',data.converted);
const date=safeDate(data.tested_at);
byId('tested-at').textContent='آخرین تست: '+(date?new Intl.DateTimeFormat('fa-IR',{dateStyle:'medium',timeStyle:'short'}).format(date):'نامشخص');
health.dataset.state=data.infra_ok===true?'fresh':'stale';
label.textContent=data.infra_ok===true?'زیرساخت تست سالم است':'وضعیت زیرساخت نیازمند بررسی';
const entries=data.by_type_kept&&typeof data.by_type_kept==='object'?Object.entries(data.by_type_kept):[];
const types=byId('types');types.replaceChildren();
if(entries.length){entries.sort((a,b)=>Number(b[1])-Number(a[1])).forEach(([name,count])=>{const chip=document.createElement('span');chip.className='type-chip';const title=document.createElement('span');title.textContent=String(name).toUpperCase();const value=document.createElement('b');value.textContent=Number.isFinite(Number(count))?faNumber.format(Number(count)):'—';chip.append(title,value);types.append(chip)})}
else{const chip=document.createElement('span');chip.className='type-chip';chip.textContent='جزئیات پروتکل در گزارش موجود نیست';types.append(chip)}
}catch(error){
health.dataset.state='bad';label.textContent='گزارش زنده در دسترس نیست';
byId('tested-at').textContent='آخرین تست: نامشخص';
const types=byId('types');types.replaceChildren();const chip=document.createElement('span');chip.className='type-chip';chip.textContent='برای جزئیات، گزارش کامل را باز کن.';types.append(chip)
}finally{if(button){button.disabled=false;button.textContent='Refresh ↻'}}
}
document.addEventListener('click',async event=>{
const copy=event.target.closest('[data-copy]');
if(copy){const value=copy.getAttribute('data-copy');try{await navigator.clipboard.writeText(value);toast('Copied to clipboard ✓')}catch(error){toast('Clipboard is unavailable')}}
const qr=event.target.closest('[data-qr]');
if(qr){const value=qr.getAttribute('data-qr');const url='https://api.qrserver.com/v1/create-qr-code/?size=280x280&margin=12&data='+encodeURIComponent(value);window.open(url,'_blank','noopener,noreferrer')}
});
const qrData={
btc:'bc1qa6rsvv9dl24tdu4w28rptu96jak0rtwv5qnx58',
evm:'0x570f2ea8e90fcE23F1F0286492dDB189Ad9a0E85',
ton:'UQDvpPr7JRx696Aj9_E6mhA4nV_Z2nHMhmMybNV2ZgAy6ThY'
};
document.querySelectorAll('[data-wallet-qr]').forEach(img=>{const key=img.getAttribute('data-wallet-qr');const address=qrData[key];if(address)img.src='https://api.qrserver.com/v1/create-qr-code/?size=240x240&margin=8&data='+encodeURIComponent(address)});
const refresh=byId('refresh');if(refresh)refresh.addEventListener('click',loadReport);
loadReport();
window.setInterval(()=>{if(!document.hidden)loadReport()},120000);
})();
