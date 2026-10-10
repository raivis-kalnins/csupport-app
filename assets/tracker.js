/* ClientWatch tracker 1.0.0. No visitor storage or network traffic before consent. */
(() => {
  'use strict';
  const script=document.currentScript;
  if(!script)return;
  const site=script.dataset.site||'',key=script.dataset.key||'',endpoint=script.dataset.endpoint||'';
  if(!/^[a-f0-9]{16}$/.test(site)||!key||!endpoint)return;
  window.__clientWatchLoaded=window.__clientWatchLoaded||{};
  if(window.__clientWatchLoaded[site])return;
  window.__clientWatchLoaded[site]=true;
  const storePrefix='cw_'+site+'_';
  const DAY=86400000,SESSION=1800000;
  const random=()=>{
    const data=new Uint8Array(16);if(window.crypto?.getRandomValues){crypto.getRandomValues(data);return Array.from(data,b=>b.toString(16).padStart(2,'0')).join('');}
    return ('cw'+Date.now().toString(36)+Math.random().toString(36).slice(2)+Math.random().toString(36).slice(2)).slice(0,40);
  };
  let active=false,visitor='',session='',tab='',timer=null,memorySession=null,lastPath='',errors=0,started=false;
  const privacyBlocked=()=>navigator.doNotTrack==='1'||window.doNotTrack==='1'||navigator.globalPrivacyControl===true;
  function read(name){try{return JSON.parse(localStorage.getItem(storePrefix+name)||'null');}catch(_){return null;}}
  function write(name,value){try{localStorage.setItem(storePrefix+name,JSON.stringify(value));}catch(_){}}
  function cleanPath(value){
    let path;try{path=new URL(value,location.href).pathname;}catch(_){path='/';}
    return path.replace(/[A-Z0-9._%+-]+(?:@|%40)[A-Z0-9.-]+\.[A-Z]{2,}/gi,'[email]').replace(/\/(?:[0-9a-f]{8}-[0-9a-f-]{27,}|[A-Za-z0-9_-]{40,}|[0-9]{6,})(?=\/|$)/gi,'/[id]').slice(0,300);
  }
  function scrub(value){return String(value||'').slice(0,1000).replace(/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/gi,'[email]').replace(/\b(password|passwd|token|api[_-]?key|secret|authorization|cookie)\s*[:=]\s*[^\s,;]+/gi,'$1=[redacted]').replace(/(https?:\/\/[^\s?#]+)[?#][^\s]*/gi,'$1');}
  function refreshSession(){
    const now=Date.now();let stored=read('session')||memorySession;
    if(!stored||!stored.id||now-Number(stored.last)>SESSION)stored={id:random(),last:now};
    stored.last=now;session=stored.id;memorySession=stored;write('session',stored);
  }
  function send(type,extra={}){
    if(!active||privacyBlocked())return;
    refreshSession();
    let mappedPath=location.href;
    try{if(typeof window.ClientWatchPath==='function')mappedPath=window.ClientWatchPath(location.pathname);}catch(_){mappedPath='/';}
    const path=cleanPath(mappedPath);
    const payload={site,key,type,visitor,session,tab,event:random(),path,visible:document.visibilityState==='visible',...extra};
    const json=JSON.stringify(payload);
    try{
      if(navigator.sendBeacon&&navigator.sendBeacon(endpoint,new Blob([json],{type:'text/plain;charset=UTF-8'})))return;
      fetch(endpoint,{method:'POST',mode:'cors',credentials:'omit',keepalive:true,headers:{'Content-Type':'text/plain;charset=UTF-8'},body:json}).catch(()=>{});
    }catch(_){}
  }
  function page(force=false){
    if(!active||document.visibilityState!=='visible')return;
    const path=cleanPath(location.href);
    if(!force&&path===lastPath)return;
    lastPath=path;
    let referrer='';try{referrer=document.referrer?new URL(document.referrer).origin:'';}catch(_){}
    const ua=navigator.userAgent||'';const device=/iPad|Tablet/i.test(ua)?'tablet':/Mobi|Android/i.test(ua)?'mobile':'desktop';
    send('pageview',{referrer,device});
  }
  function start(){
    if(active||privacyBlocked())return;
    const now=Date.now();let saved=read('visitor');
    if(!saved||!saved.id||now-Number(saved.seen)>180*DAY)saved={id:random(),seen:now};
    saved.seen=now;write('visitor',saved);visitor=saved.id;tab=random();active=true;lastPath='';
    refreshSession();page(true);
    timer=setInterval(()=>{if(active&&document.visibilityState==='visible')send('heartbeat');},30000);
    if(!started){started=true;hooks();}
  }
  function stop(){
    if(active)send('leave');active=false;clearInterval(timer);timer=null;visitor='';session='';memorySession=null;lastPath='';
    // Only remove this tracker's storage. Consent remains the consent manager's responsibility.
    try{localStorage.removeItem(storePrefix+'visitor');localStorage.removeItem(storePrefix+'session');}catch(_){}
  }
  function report(error,info={}){
    if(!active||errors>=10)return;errors++;
    let file=String(info.file||'');try{file=new URL(file,location.href).pathname.split('/').pop()||'';}catch(_){}
    const message=scrub(error instanceof Error?error.message:error);
    if(!message)return;
    send('error',{error:{message,file,line:Number(info.line)||0,path:cleanPath(location.href)}});
  }
  function hooks(){
    document.addEventListener('visibilitychange',()=>{
      if(!active)return;
      if(document.visibilityState==='hidden')send('leave');else{refreshSession();if(!lastPath)page(true);else send('heartbeat');}
    });
    window.addEventListener('pagehide',()=>{if(active)send('leave');});
    window.addEventListener('pageshow',ev=>{if(active&&ev.persisted)page(true);});
    window.addEventListener('error',ev=>{
      if(!active)return;
      if(ev instanceof ErrorEvent)report(ev.message,{file:ev.filename,line:ev.lineno});
      else if(ev.target&&ev.target!==window&&ev.target!==document){
        const target=ev.target;const source=target.src||target.href||'';
        // Ignore tracker failures: never recursively report its own transport.
        if(source&&source!==script.src)report(`Failed to load ${target.tagName?.toLowerCase()||'resource'}`,{file:source});
      }
    },true);
    window.addEventListener('unhandledrejection',ev=>{report(ev.reason instanceof Error?ev.reason.message:typeof ev.reason==='string'?ev.reason:'Unhandled promise rejection');});
    ['pushState','replaceState'].forEach(method=>{const original=history[method];history[method]=function(...args){const result=original.apply(this,args);queueMicrotask(()=>page());return result;};});
    window.addEventListener('popstate',()=>page());
    window.addEventListener('online',()=>{if(active&&document.visibilityState==='visible')send('heartbeat');});
  }
  window.ClientWatch={
    consent(granted){window.ClientWatchConsent=granted===true;if(granted===true)start();else stop();},
    pageview(){page(true);},
    error(error,info){report(error,info);},
    get active(){return active;}
  };
  window.addEventListener('clientwatch:consent',ev=>window.ClientWatch.consent(ev.detail===true||ev.detail?.granted===true));
  // Automatic startup is explicitly opt-in. Default is 'required'.
  if(window.ClientWatchConsent===true||script.dataset.consent==='granted')start();
})();
