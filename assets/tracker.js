/* ClientWatch tracker 1.2.2. No visitor storage or network traffic before consent. */
(() => {
  'use strict';
  const script=document.currentScript||document.querySelector('script[data-clientwatch="1"],script[data-site][data-endpoint*="api=collect"]');
  const cfg=(window.ClientWatchConfig&&typeof window.ClientWatchConfig==='object')?window.ClientWatchConfig:{};
  const site=String(cfg.site||script?.dataset?.site||''),key=String(cfg.key||script?.dataset?.key||''),endpoint=String(cfg.endpoint||script?.dataset?.endpoint||'');
  const consentMode=String(cfg.consent||script?.dataset?.consent||'required');
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
      const opts={method:'POST',mode:'cors',credentials:'omit',keepalive:true,headers:{'Content-Type':'text/plain;charset=UTF-8'},body:json};
      // Use fetch for ordinary events so failed collection is visible in browser diagnostics.
      // sendBeacon is reserved for leave/pagehide where unload reliability matters more than response visibility.
      if(type==='leave'&&navigator.sendBeacon){navigator.sendBeacon(endpoint,new Blob([json],{type:'text/plain;charset=UTF-8'}));return;}
      fetch(endpoint,opts).then(r=>{
        window.__clientWatchDiagnostic={at:Date.now(),ok:r.ok,status:r.status,type};
        if(!r.ok&&window.console?.warn)console.warn('ClientWatch collection failed',r.status);
      }).catch(err=>{
        window.__clientWatchDiagnostic={at:Date.now(),ok:false,status:0,type,error:String(err?.message||err||'network error').slice(0,160)};
        if(window.console?.warn)console.warn('ClientWatch collection failed',err);
      });
    }catch(err){window.__clientWatchDiagnostic={at:Date.now(),ok:false,status:0,type,error:String(err?.message||err||'send error').slice(0,160)};}
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
    if(!active||errors>=20)return;errors++;
    let file=String(info.file||'');try{file=new URL(file,location.href).pathname.split('/').pop()||'';}catch(_){}
    const message=scrub(error instanceof Error?error.message:error);
    if(!message)return;
    const type=scrub(info.type||(error instanceof Error?(error.name||'JavaScriptError'):'JavaScriptError')).slice(0,120);
    const stack=scrub(info.stack||(error instanceof Error?error.stack:'')).slice(0,4000);
    send('error',{error:{message,type,stack,severity:info.severity||'warning',file,line:Number(info.line)||0,path:cleanPath(location.href)}});
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
      if(ev instanceof ErrorEvent)report(ev.error||ev.message,{file:ev.filename,line:ev.lineno,type:ev.error?.name||'JavaScriptError',stack:ev.error?.stack||''});
      else if(ev.target&&ev.target!==window&&ev.target!==document){
        const target=ev.target;const source=target.src||target.href||'';
        // Ignore tracker failures: never recursively report its own transport.
        if(source&&(!script||source!==script.src))report(`Failed to load ${target.tagName?.toLowerCase()||'resource'}`,{file:source,type:'ResourceError',severity:'warning'});
      }
    },true);
    window.addEventListener('unhandledrejection',ev=>{const reason=ev.reason;report(reason instanceof Error?reason:(typeof reason==='string'?reason:'Unhandled promise rejection'),{type:reason instanceof Error?(reason.name||'UnhandledPromiseRejection'):'UnhandledPromiseRejection',stack:reason instanceof Error?(reason.stack||''):''});});
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
  if(window.ClientWatchConsent===true||consentMode==='granted')start();
})();
