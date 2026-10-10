/* ClientWatch 1.0.0 - dependency-free dashboard. */
(() => {
  'use strict';
  const $ = (s, r = document) => r.querySelector(s);
  const $$ = (s, r = document) => [...r.querySelectorAll(s)];
  const esc = v => String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const paths = {
    grid:'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
    globe:'<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c5 5 5 13 0 18-5-5-5-13 0-18Z"/>',
    chart:'<path d="M4 4v16h17M8 15l4-5 4 2 5-7"/>',
    alert:'<path d="m10 4-8 14a2 2 0 0 0 2 3h16a2 2 0 0 0 2-3L14 4a2.3 2.3 0 0 0-4 0Z"/><path d="M12 9v4m0 4h.01"/>',
    layers:'<path d="m12 3 10 5-10 5L2 8l10-5Zm-10 9 10 5 10-5M2 16l10 5 10-5"/>',
    settings:'<path d="m9 3-.5 3-2 1-2.7-1L2 10l2.2 2v2L2 16l1.8 4 3-1 2 1 .5 2h5.4l.5-2 2-1 3 1 1.8-4-2.2-2v-2l2.2-2-1.8-4-2.7 1-2-1-.5-3Z"/><circle cx="12" cy="12.5" r="3"/>',
    shield:'<path d="m12 3 8 3v6c0 5-8 9-8 9s-8-4-8-9V6l8-3Z"/><path d="m8 12 3 3 5-6"/>',
    logout:'<path d="M9 4H4v16h5m4-12 4 4-4 4m-5-4h13"/>',
    moon:'<path d="M21 13a9 9 0 1 1-10-10 7 7 0 0 0 10 10Z"/>',
    sun:'<circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/>',
    refresh:'<path d="M20 7v5h-5M4 17v-5h5M5 8a8 8 0 0 1 13-3l2 3M4 16l2 3a8 8 0 0 0 13-3"/>',
    plus:'<path d="M12 5v14M5 12h14"/>',
    search:'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 5 5"/>',
    info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10h.01"/>',
    edit:'<path d="m15 4 5 5M4 15 16 3a2 2 0 0 1 3 0l2 2a2 2 0 0 1 0 3L9 20l-6 1 1-6Z"/>',
    close:'<path d="m6 6 12 12M6 18 18 6"/>',
    arrow:'<path d="m9 5 7 7-7 7"/>',
    external:'<path d="M14 3h7v7m0-7L10 14m0-9H4v16h16v-6"/>',
    check:'<path d="m5 12 4 4L19 6"/>',
    copy:'<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
    code:'<path d="m7 6-6 6 6 6m10-12 6 6-6 6m-3-15-4 18"/>',
    clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'
  };
  const icon = name => `<svg class="icon" viewBox="0 0 24 24" aria-hidden="true">${paths[name] || paths.info}</svg>`;
  function icons(root = document) { $$('[data-icon]', root).forEach(el => { el.outerHTML = icon(el.dataset.icon); }); }
  try { document.documentElement.dataset.theme = localStorage.getItem('cw-theme') || 'light'; } catch (_) {}
  icons();
  if ($('#setup-url') && !$('#setup-url').value) {
    const u = new URL(location.href); u.search = ''; u.hash = ''; if (!u.pathname.endsWith('index.php')) u.pathname = u.pathname.replace(/\/?$/, '/') + 'index.php'; $('#setup-url').value = u.href;
  }
  if (!document.body.dataset.app) return;
  const csrf = document.body.dataset.csrf;
  const state = {sites:[], settings:{}, days:7, filter:'all', detail:null, tab:'overview', loading:false, timer:null};
  let toastTimer;
  function toast(msg) { const t=$('#toast'); t.textContent=msg; t.classList.add('show'); clearTimeout(toastTimer); toastTimer=setTimeout(()=>t.classList.remove('show'),4500); }
  async function api(action, body, query = {}) {
    const u = new URL('index.php',location.href); u.searchParams.set('api',action);
    Object.entries(query).forEach(([k,v])=>u.searchParams.set(k,v));
    const controller = new AbortController(); const timer=setTimeout(()=>controller.abort(),20000);
    try {
      const options = {credentials:'same-origin',cache:'no-store',signal:controller.signal};
      if (body !== undefined) Object.assign(options,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':csrf},body:JSON.stringify(body)});
      const response=await fetch(u,options);
      let json; try {json=await response.json();} catch (_) {throw new Error('Server returned an unreadable response. Check your PHP error log.');}
      if (response.status===401) { location.href='index.php'; throw new Error('Please sign in.'); }
      if (!response.ok || !json.ok) throw new Error(json.error || 'Request failed.');
      return json;
    } catch (e) { if (e.name==='AbortError') throw new Error('Request timed out. Check your connection and PHP error log.'); throw e; }
    finally {clearTimeout(timer);}
  }
  const num = v => new Intl.NumberFormat().format(Number(v)||0);
  const ago = t => {const n=Date.now()/1000-Number(t); return !Number(t)?'Never': n<60?'Just now':n<3600?`${Math.floor(n/60)}m ago`:n<86400?`${Math.floor(n/3600)}h ago`:`${Math.floor(n/86400)}d ago`;};
  const date = t => Number(t) ? new Date(Number(t)*1000).toLocaleString(undefined,{dateStyle:'medium',timeStyle:'short'}) : 'Not received';
  const host = url => {try {return new URL(url).host;}catch(_){return url;}};
  const badge = (text,kind='') => `<span class="status-badge ${kind}">${esc(text)}</span>`;
  const kindName = t => ({wordpress:'WordPress',php:'PHP',javascript:'JavaScript',other:'Other'}[t]||t);
  function health(s) {
    if (!Number(s.monitor)) return {label:'Not monitored',class:''};
    if (!Number(s.checked_at)) return {label:'Not checked',class:''};
    if (Date.now()/1000-Number(s.checked_at)>900) return {label:'Check stale',class:'warn'};
    return ({up:{label:'Online',class:'good'},redirect:{label:'Redirect',class:'good'},down:{label:'Down',class:'bad'},suspect:{label:'Recheck needed',class:'warn'},protected:{label:'Protected',class:'warn'},blocked:{label:'Not checked',class:'warn'}})[s.health]||{label:'Unknown',class:''};
  }
  function needsAttention(s) {
    return s.metrics.errors>0 || s.updates>0 || ['bad','warn'].includes(health(s).class) || (s.type==='wordpress' && Number(s.backend_at)>0 && Date.now()/1000-Number(s.backend_at)>1200);
  }
  const metricCards = m => `<div class="detail-stats">${[['Visits',m.visits],['Pageviews',m.pageviews],['New visitors',m.new],['Returning',m.returning],['Live now',m.live]].map(([t,v])=>`<div class="detail-stat"><small>${t}</small><strong>${num(v)}</strong></div>`).join('')}</div>`;
  function render() {
    const all=state.sites; const total=key=>all.reduce((sum,s)=>sum+Number(s.metrics[key]||0),0);
    const attention=all.filter(needsAttention).length;
    $('#total-sites').textContent=num(all.length);$('#total-visits').textContent=num(total('visits'));$('#total-live').textContent=num(total('live'));$('#total-attention').textContent=num(attention);$('#attention-count').textContent=num(attention);
    const online=all.filter(s=>health(s).class==='good').length;
    $('#sites-summary').innerHTML=all.length?`<em>${num(online)} responding</em> <span>· ${num(all.filter(s=>s.type==='wordpress').length)} WordPress</span>`:'Ready for your first website';
    $('#visits-summary').textContent=`${num(total('pageviews'))} pageviews · ${state.days===1?'last 24h':`last ${state.days} days`}`;
    $('#attention-summary').textContent=`${num(total('errors'))} open errors · ${num(all.reduce((a,s)=>a+s.updates,0))} available updates`;
    const search=$('#search').value.toLowerCase().trim();
    let sites=all.filter(s=>(state.filter!=='attention'||needsAttention(s))&&(state.filter!=='wordpress'||s.type==='wordpress')&&`${s.name} ${s.client} ${s.url}`.toLowerCase().includes(search));
    const sort=$('#sort').value;
    sites.sort((a,b)=>sort==='visits'?b.metrics.visits-a.metrics.visits:sort==='live'?b.metrics.live-a.metrics.live:sort==='attention'?Number(needsAttention(b))-Number(needsAttention(a))||a.name.localeCompare(b.name):a.name.localeCompare(b.name));
    $('#site-count').textContent=num(sites.length);$('#table-caption').textContent=`Showing ${num(sites.length)} of ${num(all.length)} websites`;
    $('#site-rows').innerHTML=sites.length?sites.map(s=>{
      const h=health(s); const frontend=Number(s.frontend_at)>0?`Tracker ${ago(s.frontend_at).toLowerCase()}`:'Tracker not connected';
      const updates=s.type!=='wordpress'?'<span class="num-muted">—</span>':s.updates?`<span class="metric-badge amber" title="${s.updates_known?'Available updates':'Known updates; some checks are missing'}">${num(s.updates)} pending</span>`:s.updates_known?`<span class="clear-mark" title="No updates in last received report">${icon('check')} Clear</span>`:'<span class="num-muted" title="Connect WordPress and complete update checks">Unknown</span>';
      return `<tr data-id="${esc(s.id)}" tabindex="0" aria-label="Open ${esc(s.name)}"><td><div class="site-name"><span class="site-avatar ${esc(s.type)}">${esc(s.type==='wordpress'?'W':s.type==='php'?'P':s.type==='javascript'?'JS':s.name[0]?.toUpperCase())}</span><div><strong>${esc(s.name)}</strong><small>${esc(s.client||host(s.url))}</small></div></div></td><td>${badge(h.label,h.class)}<span class="status-sub" title="${esc(frontend)}">${esc(Number(s.checked_at)?ago(s.checked_at):'Waiting for first check')}</span></td><td><span class="num-strong">${num(s.metrics.visits)}</span></td><td>${num(s.metrics.new)}</td><td>${num(s.metrics.returning)}</td><td><span class="${s.metrics.live?'live-number':'num-muted'}">${s.metrics.live?'<span class="status-dot"></span>':''}${num(s.metrics.live)}</span></td><td>${s.metrics.errors?`<span class="metric-badge">${num(s.metrics.errors)}</span>`:'<span class="num-muted">0</span>'}</td><td>${updates}</td><td><span class="row-arrow">${icon('arrow')}</span></td></tr>`;
    }).join(''):`<tr><td colspan="9"><div class="empty-state"><div class="empty-icon">${icon(all.length?'search':'globe')}</div><h3>${all.length?'No matching websites':'A clear view starts with one website.'}</h3><p>${all.length?'Try another search or switch back to all sites.':'Add a client website, copy its tracking snippet and connect WordPress to see updates and server errors.'}</p>${all.length?'':`<button class="button primary" data-add>${icon('plus')} Add your first website</button>`}</div></td></tr>`;
    $$('[data-filter]').forEach(el=>el.classList.toggle('active',el.dataset.filter===state.filter));
    $$('[data-view]').forEach(el=>el.classList.toggle('active',el.dataset.view===state.filter));
    $('#breadcrumb-title').textContent=state.filter==='attention'?'Needs attention':state.filter==='wordpress'?'WordPress':'Overview';
    $('#page-title').textContent=state.filter==='attention'?'A little attention goes a long way.':state.filter==='wordpress'?'Your WordPress maintenance desk.':'Your websites, at a glance.';
    const notice=$('#global-notice'); const c=Number(state.settings.cron_at);
    if (all.some(s=>Number(s.monitor)) && (!c||Date.now()/1000-c>900)) {
      notice.innerHTML=`${icon('clock')} Scheduled uptime checks ${c?'are stale':'are not running yet'}. Open <a href="#" data-settings>Settings</a> for the cron command. Traffic and incoming WordPress reports work separately.`;
      notice.classList.remove('hidden');
    } else notice.classList.add('hidden');
  }
  async function load(silent=false) {
    if (state.loading) return; state.loading=true;
    try { const j=await api('sites',undefined,{days:state.days}); state.sites=j.sites;state.settings=j.settings;render();$('#sync-label').textContent='Updated just now'; }
    catch(e){$('#sync-label').textContent='Connection issue'; if(!silent)toast(e.message);}
    finally{state.loading=false;}
  }
  function showForm(s=null) {
    const f=$('#site-form');f.reset();$('#form-error').classList.add('hidden');
    ['id','name','url','client','notes'].forEach(k=>f.elements[k].value=s?.[k]||'');
    f.elements.type.value=s?.type||'wordpress';f.elements.origins.value=s?.origins?.join('\n')||'';f.elements.monitor.checked=s?!!Number(s.monitor):true;
    $('#edit-title').textContent=s?'Edit website':'Add a website';$('#delete-site').classList.toggle('hidden',!s);
    $('#edit-dialog').showModal();setTimeout(()=>f.elements.name.focus(),30);
  }
  function errorList(s) {
    if (!s.errors_list.length) return `<div class="empty-state"><div class="empty-icon">${icon('shield')}</div><h3>No errors reported.</h3><p>This means no connected source has reported an error. It is not a security audit or a guarantee that the website has no issues.</p></div>`;
    return `<div class="section-title"><div><h3>Error inbox</h3><p>Latest 100 groups. Repeated messages are grouped; new occurrences reopen resolved items.</p></div>${badge(`${s.metrics.errors} open`,s.metrics.errors?'bad':'good')}</div>`+s.errors_list.map(e=>`<article class="error-item ${Number(e.resolved_at)?'resolved':''}"><div class="error-header"><div class="error-title">${badge(e.severity,e.severity==='warning'?'warn':'bad')}<span>${esc(e.source)}</span><span>× ${num(e.occurrences)}</span></div>${Number(e.resolved_at)?badge('Resolved','good'):`<button class="button small" data-resolve="${e.id}">${icon('check')} Resolve</button>`}</div><p class="error-message">${esc(e.message)}</p><div class="error-meta"><span>${esc(e.file||e.path)}${Number(e.line)?':'+e.line:''}</span><span>First: ${esc(date(e.first_seen))}</span><span>Last: ${esc(ago(e.last_seen))}</span></div></article>`).join('');
  }
  function table(headers,rows) { return rows.length?`<div class="table-scroll"><table class="data-table"><thead><tr>${headers.map(h=>`<th>${h}</th>`).join('')}</tr></thead><tbody>${rows.map(row=>`<tr>${row.map(cell=>`<td>${cell}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`:'<div class="no-data">No data for the selected period yet.</div>'; }
  function traffic(s) {
    const entries=new Map(s.chart.map(r=>[r.day,r])); const values=[];
    const start=new Date(Date.now()-state.days*86400000);start.setUTCHours(0,0,0,0); const end=new Date();end.setUTCHours(0,0,0,0);
    for(let t=start.getTime();t<=end.getTime();t+=86400000){const day=new Date(t).toISOString().slice(0,10);values.push({day,pageviews:Number(entries.get(day)?.pageviews||0)});}
    const max=Math.max(1,...values.map(r=>r.pageviews));
    return `${metricCards(s.metrics)}<div class="info-card chart-container"><div class="section-title"><div><h3>Traffic over time</h3><p>Selected rolling period, grouped by calendar day in UTC.</p></div><span class="chart-key">Pageviews</span></div><div class="chart-bars">${values.map(r=>`<div class="chart-col" title="${r.day}: ${num(r.pageviews)} pageviews"><div class="chart-bar" style="height:${Math.max(1,r.pageviews/max*100)}%"></div></div>`).join('')}</div><div class="chart-labels"><span>${values[0].day}</span><span>${values.at(-1).day}</span></div></div><div class="detail-grid"><div class="info-card"><h3>Top pages</h3>${table(['Page','Views','Visitors'],s.pages.map(p=>[esc(p.path),num(p.views),num(p.users)]))}</div><div><div class="info-card"><h3>Referral sources</h3>${table(['Source','Pageviews'],s.referrers.map(p=>[esc(p.name),num(p.views)]))}</div><div class="info-card"><h3>Devices</h3>${table(['Device','Pageviews'],s.devices.map(p=>[esc(p.device),num(p.views)]))}</div></div></div><p class="muted small-text section-spacer">No historical import or cross-device identification. New means first seen in this period; returning means first seen before it. Clearing browser storage or refusing consent changes the counts.</p>`;
  }
  function overview(s) {
    const h=health(s);const w=s.report.wordpress;
    const checks=s.checks_list.slice().reverse();
    return `${metricCards(s.metrics)}<div class="detail-grid"><div class="info-card"><div class="section-title"><h3>Connection & health</h3>${badge(h.label,h.class)}</div><dl class="kv"><dt>Platform</dt><dd>${esc(kindName(s.type))}</dd><dt>Frontend tracker</dt><dd>${Number(s.frontend_at)?esc(date(s.frontend_at)):badge('Not connected','warn')}</dd><dt>Backend report</dt><dd>${Number(s.backend_at)?esc(date(s.backend_at)):'Not connected / optional'}</dd><dt>HTTP check</dt><dd>${esc(date(s.checked_at))}${Number(s.http_code)?' · HTTP '+s.http_code:''}</dd><dt>Response time</dt><dd>${Number(s.checked_at)?num(s.response_ms)+' ms':'Not checked'}</dd><dt>Runtime</dt><dd>${esc(s.report.runtime||'Not reported')}</dd><dt>WordPress</dt><dd>${esc(w?.version||'Not reported')}</dd></dl>${s.check_message?`<p class="small-text section-spacer">${esc(s.check_message)}</p>`:''}<div class="inline-buttons"><a class="button small" href="${esc(s.url)}" target="_blank" rel="noopener noreferrer">${icon('external')} Open website</a><button class="button small" data-check>${icon('refresh')} Check HTTP now</button><button class="button small" data-tab-go="connect">${icon('code')} Connect</button></div></div><div><div class="info-card"><h3>Recent HTTP checks</h3>${checks.length?`<div class="uptime-strip">${checks.map(c=>`<span class="uptime-tick ${['up','redirect'].includes(c.status)?'good':['down','suspect'].includes(c.status)?'bad':'warn'}" title="${esc(date(c.ts))} · ${esc(c.status)} · HTTP ${c.code}"></span>`).join('')}</div><p>Last ${checks.length} checks. Redirects count as responding; their destinations are not followed.</p>`:'<p>No checks yet. Configure cron for automatic checks, or run one manually.</p>'}</div><div class="info-card"><h3>Live on your website</h3>${table(['Page','Visitors'],s.live_pages.map(p=>[esc(p.path),num(p.users)]))}<p class="small-text">Unique browser IDs with a visible-tab heartbeat in the last 90 seconds.</p></div></div></div>${s.notes?`<div class="info-card section-spacer"><h3>Maintenance notes</h3><p class="note-text">${esc(s.notes)}</p></div>`:''}${s.type==='wordpress'&&Number(s.backend_at)>0&&Date.now()/1000-Number(s.backend_at)>1200?'<div class="notice warning section-spacer">WordPress has not reported recently. Check its connector and WP-Cron. Update information may be out of date.</div>':''}`;
  }
  function wordpress(s) {
    if(s.type!=='wordpress')return '<div class="no-data">This website is not marked as WordPress. Use the Connect website tab for PHP or JavaScript integrations.</div>';
    const w=s.report.wordpress;
    if(!w)return `<div class="empty-state"><div class="empty-icon">${icon('layers')}</div><h3>Connect the WordPress backend.</h3><p>The public tracking snippet cannot read WordPress updates or PHP errors. Install the supplied ClientWatch Connector plugin and send its first report.</p><button class="button primary" data-tab-go="connect">Connection instructions</button></div>`;
    const updatesTable=kind=>table([kind==='plugins'?'Plugin':'Theme','Installed','Available','Status'],(w[kind]||[]).slice().sort((a,b)=>Number(!!b.new_version)-Number(!!a.new_version)||a.name.localeCompare(b.name)).map(p=>[esc(p.name),esc(p.version),p.new_version?`<span class="updated">${esc(p.new_version)}</span>`:w.checks[kind]?'—':'Unknown',`<span class="tag ${p.active?'active':''}">${p.active?'Active':'Inactive'}</span>`]));
    return `<div class="section-title"><div><h3>WordPress maintenance</h3><p>Received ${esc(date(s.backend_at))}. Read-only monitoring; no remote update installation.</p></div><button class="button small" data-request>${icon('refresh')} Request fresh checks</button></div>${Number(s.refresh_requested)?`<div class="notice warning">Fresh checks requested ${esc(ago(s.refresh_requested))}. Waiting for the connector's next heartbeat; nothing will be installed.</div>`:''}${!s.updates_known?'<div class="notice warning">Some update caches are missing. “Unknown” is not the same as “up to date”. Send fresh checks from the connector settings.</div>':''}<div class="detail-grid"><div class="info-card"><h3>Core & environment</h3><dl class="kv"><dt>WordPress version</dt><dd>${esc(w.version)}</dd><dt>Core update</dt><dd>${w.core_update?badge(w.core_update+' available','warn'):w.checks.core?'None reported':'Unknown'}</dd><dt>PHP version</dt><dd>${esc(w.php)}</dd><dt>WP_DEBUG</dt><dd>${w.debug?badge('Enabled','warn'):'Off'}</dd><dt>Default WP-Cron</dt><dd>${w.cron_disabled?'Disabled — verify hosting cron':'Enabled — traffic-triggered'}</dd><dt>Multisite</dt><dd>${w.multisite?'Yes':'No'}</dd></dl>${w.admin_url?`<a class="button small section-spacer" href="${esc(w.admin_url)}" target="_blank" rel="noopener noreferrer">${icon('external')} Open WordPress updates</a>`:''}</div><div class="info-card"><h3>Update check timestamps</h3><dl class="kv"><dt>Core</dt><dd>${esc(date(w.checks.core))}</dd><dt>Plugins</dt><dd>${esc(date(w.checks.plugins))}</dd><dt>Themes</dt><dd>${esc(date(w.checks.themes))}</dd></dl><p class="section-spacer">Availability comes from WordPress update data, including premium plugins only when their updater/licence supplies it. This is not a vulnerability scanner.</p></div></div><div class="info-card section-spacer"><h3>Plugins (${num(w.plugins.length)})</h3>${updatesTable('plugins')}</div><div class="info-card section-spacer"><h3>Themes (${num(w.themes.length)})</h3>${updatesTable('themes')}</div>`;
  }
  let copyIndex=0;const copies=new Map();
  function code(text) { const id='copy-'+(++copyIndex);copies.set(id,text);return `<div class="code-wrap"><button class="button small copy-button" data-copy="${id}" title="Copy code">${icon('copy')} Copy</button><pre class="has-copy"><code>${esc(text)}</code></pre></div>`; }
  async function connect(s) {
    const target=$('#detail-content');target.innerHTML='<div class="no-data">Loading private connection settings…</div>';
    try {
      const cfg=await api('install',undefined,{id:s.id});if(state.detail?.id!==s.id||state.tab!=='connect')return;
      const asset=cfg.base_url.replace(/index\.php$/,'assets/tracker.js');
      const snippet=`<script defer src="${asset}"\n  data-endpoint="${cfg.base_url}?api=collect"\n  data-site="${cfg.id}"\n  data-key="${cfg.public_key}"\n  data-consent="required"><\/script>`;
      const fields=`<dl class="kv"><dt>Dashboard URL</dt><dd>${esc(cfg.base_url)}</dd><dt>Site ID</dt><dd><code>${esc(cfg.id)}</code></dd><dt>Public tracking key</dt><dd><code>${esc(cfg.public_key)}</code></dd><dt>Private backend secret</dt><dd><input type="password" id="backend-secret" readonly aria-label="Private backend secret" value="${esc(cfg.secret)}"><div class="inline-buttons"><button class="button small" data-reveal>Show</button><button class="button small" data-copy-secret>Copy secret</button></div></dd></dl>`;
      target.innerHTML=`<div class="notice warning">Only the tracking key belongs in public JavaScript. Keep the backend secret in WordPress settings or server-side environment variables.</div><div class="connect-steps"><section class="connect-step"><h3>${s.type==='wordpress'?'Install the WordPress connector':'Add the frontend tracker'}</h3>${s.type==='wordpress'?`<p>Upload and activate <strong>ClientWatch Connector</strong>, then open <strong>Settings → ClientWatch</strong>. Fill in the four values below. Enable its frontend tracker; do not add a duplicate manual snippet.</p><div class="inline-buttons"><a class="button small" href="downloads/clientwatch-connector-1.0.0.zip" download>Download WordPress plugin</a></div>`:'<p>Add this once in your main template, before the closing head or body tag. It works with ordinary HTML, PHP templates and JavaScript-rendered pages.</p>'+code(snippet)}</section><section class="connect-step"><h3>Connection values</h3><div class="info-card">${fields}</div></section><section class="connect-step"><h3>Connect your consent manager</h3><p>By default, the tracker sends nothing and creates no visitor storage until consent is granted. Call this from your analytics-consent callback, including when consent was previously saved:</p>${code('window.ClientWatchConsent = true;\nwindow.ClientWatch?.consent(true);')}<p class="section-spacer">When analytics consent is withdrawn:</p>${code('window.ClientWatchConsent = false;\nwindow.ClientWatch?.consent(false);')}<p class="section-spacer">The tracker honours Do Not Track and Global Privacy Control. Blocking by consent, a browser extension or Content Security Policy results in missing statistics. Add your dashboard origin to script-src and connect-src where a CSP is used.</p></section><section class="connect-step"><h3>${s.type==='wordpress'?'Send a test report':'Optional backend error reporting'}</h3><p>${s.type==='wordpress'?'In WordPress Settings → ClientWatch, click Save settings and then Send report & refresh checks. Reports normally run every five minutes when WordPress cron executes. The plugin does not install any updates.':'The browser snippet cannot see PHP fatals, server exceptions or a website that fails before loading JavaScript. Use the supplied PHP or Node.js helper to send signed backend reports. Configure credentials on the server, never in frontend source.'}</p>${s.type!=='wordpress'?'<div class="inline-buttons"><a class="button small" href="downloads/clientwatch-integrations-1.0.0.zip" download>Download PHP / Node helpers</a></div>':''}</section></div>${s.type==='wordpress'?`<details><summary>Manual frontend snippet (only when plugin tracking is disabled)</summary>${code(snippet)}</details>`:''}<div class="inline-buttons"><button class="button danger-ghost small" data-rotate>Rotate private backend secret</button></div><p class="muted small-text section-spacer">Rotating invalidates the old backend secret immediately. Update the connector afterwards. It does not change the public tracking key.</p>`;
    } catch(e){target.innerHTML=`<div class="notice danger">${esc(e.message)}</div>`;}
  }
  function renderDetail() {
    const s=state.detail;if(!s)return;
    $('#site-title').textContent=s.name;$('#detail-client').textContent=s.client||kindName(s.type);$('#detail-url').textContent=s.url;$('#detail-url').href=s.url;
    $('#detail-avatar').textContent=s.type==='wordpress'?'W':s.type==='javascript'?'JS':s.name[0].toUpperCase();$('#detail-avatar').className='site-avatar '+s.type;
    $('#detail-updated').textContent=`Data refreshed now · ${state.days===1?'last 24 hours':`last ${state.days} days`}`;
    $$('.detail-tabs button').forEach(el=>{const active=el.dataset.tab===state.tab;el.classList.toggle('active',active);el.setAttribute('aria-selected',String(active));});
    if(state.tab==='connect'){connect(s);return;}
    $('#detail-content').innerHTML=state.tab==='traffic'?traffic(s):state.tab==='errors'?errorList(s):state.tab==='wordpress'?wordpress(s):overview(s);
  }
  async function detail(id,open=true) {
    if(open){state.tab='overview';$('#detail-content').innerHTML='<div class="no-data">Loading website…</div>';if(!$('#site-dialog').open)$('#site-dialog').showModal();}
    try{const j=await api('detail',undefined,{id,days:state.days});state.detail=j.site;renderDetail();}catch(e){toast(e.message);}
  }
  async function settings() {
    const s=state.settings,f=$('#settings-form');f.reset();
    ['base_url','alert_email','mail_from','retention'].forEach(k=>f.elements[k].value=s[k]||'');f.elements.email_browser.checked=s.email_browser==='1';
    $('#cron-help').innerHTML=`<h3>Run automatic checks every five minutes</h3><p>Add this command under your hosting account's Cron Jobs. Use the host's PHP 8.1+ executable if it is not <code>php</code>.</p>${code(`*/5 * * * * php '${s.cron_file}'`)}<dl class="kv"><dt>Last cron run</dt><dd>${esc(date(s.cron_at))}</dd><dt>cURL support</dt><dd>${s.curl?'Available':'Missing — enable PHP cURL for HTTP checks'}</dd><dt>Email status</dt><dd>${esc(s.mail_status)}</dd><dt>Private storage</dt><dd>${esc(s.data_dir)}</dd><dt>PHP</dt><dd>${esc(s.php)}</dd></dl><p class="section-spacer">Use a separate, reliable hosting cron for WordPress itself where timely backend reports are required. Never expose the private data directory.</p>`;
    $('#settings-error').classList.add('hidden');$('#settings-dialog').showModal();
  }
  document.addEventListener('click',async ev=>{
    const el=ev.target.closest('button,a');
    if(el?.dataset.close){$('#'+el.dataset.close).close();return;}
    if(el?.hasAttribute('data-add')){showForm();return;}
    if(el?.hasAttribute('data-settings')){ev.preventDefault();settings();return;}
    if(el?.dataset.filter||el?.dataset.view){state.filter=el.dataset.filter||el.dataset.view;render();return;}
    if(el?.dataset.tab||el?.dataset.tabGo){state.tab=el.dataset.tab||el.dataset.tabGo;renderDetail();return;}
    if(el?.dataset.copy){try{await navigator.clipboard.writeText(copies.get(el.dataset.copy)||'');toast('Copied.');}catch(_){toast('Clipboard access blocked. Select the code and copy it manually.');}return;}
    if(el?.hasAttribute('data-reveal')){const i=$('#backend-secret');i.type=i.type==='password'?'text':'password';el.textContent=i.type==='password'?'Show':'Hide';return;}
    if(el?.hasAttribute('data-copy-secret')){try{await navigator.clipboard.writeText($('#backend-secret').value);toast('Private secret copied. Keep it server-side.');}catch(_){toast('Clipboard access blocked. Reveal and copy it manually.');}return;}
    if(el?.dataset.resolve){await actionButton(el,()=>api('resolve',{id:state.detail.id,error_id:Number(el.dataset.resolve)}),'Error marked resolved.');return;}
    if(el?.hasAttribute('data-check')){await actionButton(el,()=>api('check',{id:state.detail.id}),'HTTP check completed.');return;}
    if(el?.hasAttribute('data-request')){await actionButton(el,()=>api('request_refresh',{id:state.detail.id}),'Fresh checks requested. Waiting for the next WordPress heartbeat.');return;}
    if(el?.hasAttribute('data-rotate')){if(confirm('Rotate this website’s private secret? Its backend connector will stop working until you update it.'))await actionButton(el,()=>api('rotate',{id:state.detail.id}),'Secret rotated. Update the backend connector.');return;}
    const row=ev.target.closest('tr[data-id]');if(row)detail(row.dataset.id);
  });
  async function actionButton(el,action,message){el.disabled=true;try{await action();toast(message);await load(true);if(state.detail)await detail(state.detail.id,false);}catch(e){toast(e.message);}finally{el.disabled=false;}}
  $('#site-rows').addEventListener('keydown',ev=>{if(ev.key==='Enter'||ev.key===' '){const row=ev.target.closest('tr[data-id]');if(row){ev.preventDefault();detail(row.dataset.id);}}});
  $('#add-site').onclick=()=>showForm();$('#refresh').onclick=()=>load();$('#search').oninput=render;$('#sort').onchange=render;
  $('#period').onchange=()=>{state.days=Number($('#period').value);load();};
  $('#edit-site').onclick=()=>showForm(state.detail);
  $('#detail-refresh').onclick=()=>state.detail&&detail(state.detail.id,false);
  $('#settings-nav').onclick=settings;
  const settingsTop=document.createElement('button');settingsTop.className='icon-button';settingsTop.title='Settings';settingsTop.setAttribute('aria-label','Open settings');settingsTop.innerHTML=icon('settings');settingsTop.onclick=settings;$('.topbar-actions').insertBefore(settingsTop,$('#theme-toggle'));
  $('#theme-toggle').onclick=()=>{const dark=document.documentElement.dataset.theme!=='dark';document.documentElement.dataset.theme=dark?'dark':'light';try{localStorage.setItem('cw-theme',dark?'dark':'light');}catch(_){}$('#theme-toggle').innerHTML=icon(dark?'sun':'moon');};
  $('#site-form').onsubmit=async ev=>{ev.preventDefault();const f=ev.target,submit=$('button[type=submit]',f),error=$('#form-error');submit.disabled=true;error.classList.add('hidden');const b=Object.fromEntries(new FormData(f));b.monitor=f.elements.monitor.checked;try{const j=await api('save_site',b);$('#edit-dialog').close();await load();await detail(j.id);if(!b.id){state.tab='connect';renderDetail();}toast('Website saved.');}catch(e){error.textContent=e.message;error.classList.remove('hidden');}finally{submit.disabled=false;}};
  $('#delete-site').onclick=async()=>{const f=$('#site-form'),name=f.elements.name.value,typed=prompt(`Delete this website and all its data permanently? Type its saved website name to confirm:\n${name}`);if(typed===null)return;try{await api('delete_site',{id:f.elements.id.value,confirm:typed});$('#edit-dialog').close();$('#site-dialog').close();state.detail=null;await load();toast('Website deleted.');}catch(e){toast(e.message);}};
  $('#settings-form').onsubmit=async ev=>{ev.preventDefault();const f=ev.target,submit=$('button[type=submit]',f),error=$('#settings-error');submit.disabled=true;error.classList.add('hidden');const b=Object.fromEntries(new FormData(f));b.email_browser=f.elements.email_browser.checked;try{await api('settings',b);$('#settings-dialog').close();await load();toast('Settings saved.');}catch(e){error.textContent=e.message;error.classList.remove('hidden');}finally{submit.disabled=false;}};
  $$('dialog').forEach(d=>d.addEventListener('click',ev=>{if(ev.target===d){const r=d.getBoundingClientRect();if(ev.clientX<r.left||ev.clientX>r.right||ev.clientY<r.top||ev.clientY>r.bottom)d.close();}}));
  $$('.detail-tabs [role=tab]').forEach((tab,i,arr)=>tab.addEventListener('keydown',ev=>{if(ev.key==='ArrowRight'||ev.key==='ArrowLeft'){ev.preventDefault();const next=arr[(i+(ev.key==='ArrowRight'?1:-1)+arr.length)%arr.length];next.focus();next.click();}}));
  load();
  setInterval(async()=>{if(document.visibilityState!=='visible')return;await load(true);if($('#site-dialog').open&&!$('#edit-dialog').open&&state.detail&&state.tab!=='connect')await detail(state.detail.id,false);},30000);
})();
