const ROOT="https://raw.githubusercontent.com/sinavm/SVM/main/";
const CDN="https://cdn.jsdelivr.net/gh/sinavm/SVM@main/";
const $=id=>document.getElementById(id);
const state={mirror:false,fallback:false,health:{},test:{},stats:{},sources:{},index:{},lite:{},nodes:[]};
const fmt=value=>Number(value||0).toLocaleString("fa-IR");
const safe=value=>String(value??"");
const base=()=>state.mirror?CDN:ROOT;
const pathUrl=path=>base()+path;\nconst refreshDataLinks=()=>document.querySelectorAll("[data-path]").forEach(link=>{link.href=pathUrl(link.dataset.path);});
const set=(id,value)=>{const el=$(id);if(el)el.textContent=value};
const toast=message=>{const el=$("toast");el.textContent=message;el.classList.add("show");clearTimeout(toast.timer);toast.timer=setTimeout(()=>el.classList.remove("show"),2300)};
const getJson=async path=>{const url=(state.mirror?CDN:ROOT)+path+"?v="+Date.now();try{const response=await fetch(url,{cache:"no-store"});if(!response.ok)throw new Error(path+" "+response.status);return response.json();}catch(error){if(state.mirror)throw error;const fallback=await fetch(CDN+path+"?v="+Date.now(),{cache:"no-store"});if(!fallback.ok)throw error;state.fallback=true;return fallback.json();}};
const linkFor=(client,tier,profile)=>{
if(client==="xray"){const lite=tier==="alive-fast"||tier==="alive-stable";return "subscriptions/xray/"+profile+"/"+(lite?"": "")+(lite?"":"")+tier;}
if(client==="singbox")return "subscriptions/singbox/mix.json";
if(client==="clash")return "subscriptions/clash/mix";
if(client==="meta")return "subscriptions/meta/mix";
return "subscriptions/surfboard/mix";
};
const updateHub=()=>{
let client=$("clientSelect").value;
let tier=$("tierSelect").value;
let profile=$("profileSelect").value;
const profileField=$("profileField");
profileField.hidden=client!=="xray";
if(client!=="xray"&&tier!=="mix"){tier="mix";$("tierSelect").value="mix";toast("برای این فرمت فقط خروجی Mix موجود است");}
let path;
if(client==="xray"){
if(tier==="alive-fast"||tier==="alive-stable")path="lite/subscriptions/xray/"+profile+"/"+tier;
else path="subscriptions/xray/"+profile+"/"+tier;
}else path=linkFor(client,tier,profile);
const url=pathUrl(path);
$("subscriptionUrl").textContent=url;
$("openSubscription").href=url;
$("copySubscription").dataset.copy=url;
$("subscriptionQr").src="https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data="+encodeURIComponent(url);
$("downloadQr").onclick=()=>window.open($("subscriptionQr").src,"_blank","noopener");
};
const renderNodes=()=>{
const query=$("nodeSearch").value.trim().toLowerCase();
const protocol=$("nodeProtocol").value;
const country=$("nodeCountry").value;
const max=Number($("nodeSpeed").value||0);
const rows=state.nodes.filter(node=>{
const text=[node.fingerprint,node.protocol,node.country_code].join(" ").toLowerCase();
return (!query||text.includes(query))&&(!protocol||String(node.protocol).toLowerCase()===protocol)&&(!country||String(node.country_code).toUpperCase()===country)&&(!max||Number(node.delay_ms)<=max);
}).sort((a,b)=>Number(a.delay_ms)-Number(b.delay_ms));
const list=$("nodeList");list.replaceChildren();
rows.slice(0,80).forEach(node=>{
const item=document.createElement("article");item.className="node-item";
const main=document.createElement("div");main.className="node-main";
const title=document.createElement("div");title.className="node-title";title.textContent=String(node.protocol||"NODE").toUpperCase()+" · "+String(node.country_code||"XX").toUpperCase();
const sub=document.createElement("div");sub.className="node-sub";sub.textContent=String(node.fingerprint||"").slice(0,15)+" · "+fmt(node.successful_rounds)+"/"+fmt(node.total_rounds)+" دور موفق"+(node.encrypted_transport?" · TLS/Reality":"");
main.append(title,sub);
const delay=document.createElement("span");delay.className="node-delay";delay.textContent=fmt(node.delay_ms)+" ms";
item.append(main,delay);list.append(item);
});
set("nodeCount",fmt(rows.length)+" نود منطبق · نمایش "+fmt(Math.min(rows.length,80)));
if(!rows.length){const empty=document.createElement("div");empty.className="empty-state";empty.textContent="نودی با این فیلتر پیدا نشد";list.append(empty);}
};
const fillSelect=(id,values,label)=>{
const select=$(id);select.replaceChildren();
const first=document.createElement("option");first.value="";first.textContent=label;select.append(first);
values.forEach(value=>{const option=document.createElement("option");option.value=value;option.textContent=value;select.append(option);});
};
const renderCountryProtocol=()=>{
const health=state.health;
const countries=health.country_nodes||{};
const protocols=health.protocols_alive||{};
const countryCodes=Object.keys(countries).sort((a,b)=>Number(countries[b])-Number(countries[a]));
const protocolNames=Object.keys(protocols).sort((a,b)=>Number(protocols[b])-Number(protocols[a]));
fillSelect("nodeCountry",countryCodes,"همهٔ کشورها");
fillSelect("nodeProtocol",protocolNames,"همهٔ پروتکل‌ها");
const countryGrid=$("countryGrid");countryGrid.replaceChildren();
countryCodes.forEach(code=>{
const a=document.createElement("a");a.className="data-chip";a.href=pathUrl("subscriptions/xray/normal/countries/"+encodeURIComponent(code));a.target="_blank";a.rel="noopener noreferrer";const label=document.createElement("span");label.textContent=code;const count=document.createElement("b");count.textContent=fmt(countries[code]);a.append(label,count);countryGrid.append(a);
});
if(!countryCodes.length)countryGrid.textContent="دادهٔ کشور موجود نیست";
const protocolGrid=$("protocolGrid");protocolGrid.replaceChildren();
protocolNames.forEach(name=>{
const a=document.createElement("a");a.className="data-chip";a.href=pathUrl("subscriptions/xray/normal/protocols/"+encodeURIComponent(name));a.target="_blank";a.rel="noopener noreferrer";const label=document.createElement("span");label.textContent=name.toUpperCase();const count=document.createElement("b");count.textContent=fmt(protocols[name]);a.append(label,count);protocolGrid.append(a);
});
if(!protocolNames.length)protocolGrid.textContent="دادهٔ پروتکل موجود نیست";
};
const renderSources=()=>{
const report=state.sources;
const total=Number(report.total_sources||0);
set("sourceTotals",fmt(report.healthy_sources)+" سالم · "+fmt(report.stale_sources)+" قدیمی · "+fmt(report.empty_sources)+" خالی · "+fmt(report.failed_sources)+" خطا · "+fmt(report.unconfigured_sources)+" تنظیم‌نشده · مجموع "+fmt(total));
const grid=$("sourceGrid");grid.replaceChildren();
const entries=Object.entries(report.sources||{}).sort((a,b)=>{
const rank={error:0,stale:1,empty:2,ok:3,unconfigured:4};
return (rank[a[1].status]??5)-(rank[b[1].status]??5)||String(a[1].name||a[0]).localeCompare(String(b[1].name||b[0]));
});
const labels={ok:"سالم",stale:"قدیمی",error:"خطا",empty:"بدون کانفیگ",unconfigured:"تنظیم‌نشده"};
entries.forEach(([key,entry])=>{
const card=document.createElement("article");card.className="source-item";
const head=document.createElement("div");head.className="source-head";
const name=document.createElement("strong");name.className="source-name";name.textContent=entry.name||key;
const badge=document.createElement("span");badge.className="source-status "+(entry.status||"");badge.textContent=labels[entry.status]||"نامشخص";
head.append(name,badge);
const meta=document.createElement("div");meta.className="source-meta";
const kind=entry.group||entry.kind||"source";
const count=entry.configs_found??entry.count??0;
const unique=entry.unique_configs===undefined?"—":fmt(entry.unique_configs);
const latency=entry.latency_ms===undefined||entry.latency_ms===null?"—":fmt(entry.latency_ms)+" ms";
meta.textContent=kind+" · کانفیگ: "+fmt(count)+" · یکتا: "+unique+" · HTTP: "+(entry.http_code||"—")+" · پاسخ: "+latency;
card.append(head,meta);grid.append(card);
});
if(!entries.length){const empty=document.createElement("div");empty.className="empty-state";empty.textContent="گزارش منبع منتشر نشده است";grid.append(empty);}
};
const setBar=(id,value,total)=>{$(id).style.width=(total>0?Math.max(0,Math.min(100,value/total*100)):0)+"%";};
const renderAll=()=>{
const h=state.health,t=state.test,s=state.stats,src=state.sources,l=state.lite;
const input=Number(t.input??h.overall?.nodes?.input??0);
const converted=Number(t.converted??0);
const tcp=Number(t.tcp_alive??0);
const alive=Number(h.overall?.nodes?.alive??t.kept??0);
set("metricInput",fmt(input));set("metricAlive",fmt(alive));
set("metricVerified",fmt(h.tiers?.verified??0));set("metricFast",fmt(h.tiers?.fast??0));
set("metricSources",fmt(src.healthy_sources??0)+"/"+fmt(src.total_sources??0));
set("metricSourceNote",fmt(src.failed_sources??0)+" خطا · "+fmt(src.empty_sources??0)+" خالی");
set("countVerified",fmt(h.tiers?.verified??0));set("countFast",fmt(h.tiers?.fast??0));set("countSecure",fmt(h.tiers?.secure??0));
set("countLiteFast",fmt(l.alive_fast??0));set("countLiteStable",fmt(l.alive_stable??0));
set("funnelInput",fmt(input));set("funnelConverted",fmt(converted));set("funnelTcp",fmt(tcp));set("funnelAlive",fmt(alive));
setBar("barInput",input,input);setBar("barConverted",converted,input);setBar("barTcp",tcp,input);setBar("barAlive",alive,input);
const stamp=t.tested_at||h.generated_at||l.updated_at;
set("testedAt","آخرین تست: "+(stamp?new Date(stamp).toLocaleString("fa-IR"):"نامشخص"));
const sourceHealth=h.overall?.source_health||{};
set("sourceSummary","منابع سالم: "+fmt(sourceHealth.healthy??src.healthy_sources??0)+" · خطا: "+fmt(sourceHealth.failed??src.failed_sources??0));
const overall=h.overall?.status||"unknown";
set("pipelineState",overall==="healthy"?"پردازش سالم":overall==="degraded"?"پردازش با هشدار":"وضعیت پردازش نامشخص");
$("statusIndicator").className="status-indicator "+(overall==="healthy"?"ok":overall==="degraded"?"":"bad");
const fresh=stamp?new Date(stamp):null;
const ageMinutes=fresh?Math.max(0,Math.floor((Date.now()-fresh.getTime())/60000)):null;
const freshness=$("freshness");
freshness.className="fresh-pill"+(ageMinutes===null?"":ageMinutes>180?" stale critical":ageMinutes>45?" stale":"");
freshness.textContent=ageMinutes===null?"زمان داده نامشخص":ageMinutes>45?"داده قدیمی · "+fmt(ageMinutes)+" دقیقه پیش":"داده تازه · "+fresh.toLocaleString("fa-IR");
if(ageMinutes!==null&&ageMinutes>45){set("pipelineState","گزارش‌ها قدیمی‌اند؛ اجرای جدید هنوز داده تازه منتشر نکرده است");$("statusIndicator").className="status-indicator "+(ageMinutes>180?"bad":"");}
renderSources();renderCountryProtocol();renderNodes();
};
const load=async()=>{
$("refreshButton").disabled=true;
set("pipelineState","در حال دریافت گزارش‌ها");
const paths=["reports/test.json","reports/health.json","reports/stats.json","reports/sources.json","reports/index.json","lite/docs/reports/status.json"];
const results=await Promise.allSettled(paths.map(getJson));
const keys=["test","health","stats","sources","index","lite"];
results.forEach((result,index)=>{if(result.status==="fulfilled")state[keys[index]]=result.value;});
state.nodes=Array.isArray(state.index.nodes)?state.index.nodes:[];
const succeeded=results.filter(result=>result.status==="fulfilled").length;
renderAll();
if(succeeded===paths.length){set("pipelineState","همهٔ گزارش‌ها با موفقیت دریافت شدند");$("statusIndicator").className="status-indicator ok";}
else if(succeeded){set("pipelineState","بخشی از گزارش‌ها در دسترس نیست");$("statusIndicator").className="status-indicator";}
else{set("pipelineState","دریافت گزارش‌ها ناموفق بود");$("statusIndicator").className="status-indicator bad";}
$("refreshButton").disabled=false;
};
const copyCurrent=async()=>{
const value=$("subscriptionUrl").textContent;
try{await navigator.clipboard.writeText(value);toast("لینک اشتراک کپی شد");}
catch(error){window.prompt("لینک اشتراک را کپی کنید",value);}
};
$("clientSelect").addEventListener("change",updateHub);
$("tierSelect").addEventListener("change",updateHub);
$("profileSelect").addEventListener("change",updateHub);
$("copySubscription").addEventListener("click",copyCurrent);
$("refreshButton").addEventListener("click",load);
$("mirrorToggle").addEventListener("change",event=>{state.mirror=event.target.checked;updateHub();refreshDataLinks();load();toast(state.mirror?"آینه CDN فعال شد":"لینک اصلی GitHub فعال شد");});
["nodeSearch","nodeProtocol","nodeCountry","nodeSpeed"].forEach(id=>$(id).addEventListener(id==="nodeSearch"?"input":"change",renderNodes));
updateHub();
load();
