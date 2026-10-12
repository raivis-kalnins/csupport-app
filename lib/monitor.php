<?php
declare(strict_types=1);
require_once __DIR__.'/core.php';

/** Public network targets only. Pin validated DNS answers and never follow redirects. */
function cw_public_ip(string $ip): bool {
    if (!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) return false;
    $ip=strtolower($ip);
    if (str_contains($ip,':')) {
        if (!in_array(substr($ip,0,1),['2','3'],true)) return false;
        // Reject transition/mapped addresses which could reach a private IPv4 target.
        if (str_starts_with($ip,'::') || str_starts_with($ip,'2002:') || str_starts_with($ip,'2001:0:') || str_starts_with($ip,'64:ff9b:')) return false;
    } else {
        if ((int)explode('.',$ip)[0] >= 224) return false;
        $n=ip2long($ip); $a=ip2long('100.64.0.0'); $b=ip2long('100.127.255.255');
        if ($n!==false && $n>=$a && $n<=$b) return false;
        if (str_starts_with($ip,'169.254.') || str_starts_with($ip,'0.')) return false;
    }
    return true;
}
function cw_target(string $url): array {
    $parts=parse_url($url);$host=strtolower((string)($parts['host'] ?? ''));$scheme=strtolower($parts['scheme'] ?? '');
    $port=(int)($parts['port'] ?? ($scheme==='https'?443:80));
    if (!in_array($scheme,['http','https'],true)||!in_array($port,[80,443],true)||isset($parts['user'])||isset($parts['pass'])||!$host) throw new RuntimeException('Only public HTTP/HTTPS targets on ports 80/443 can be checked.');
    $plain=trim($host,'[]');
    if ($plain==='localhost'||str_ends_with($plain,'.localhost')||str_ends_with($plain,'.local')||str_ends_with($plain,'.internal')) throw new RuntimeException('Private/local targets are blocked. Backend push reports still work.');
    if (filter_var($plain,FILTER_VALIDATE_IP)) $ips=[$plain];
    else {
        $records=@dns_get_record($plain,DNS_A|DNS_AAAA);$ips=[];
        foreach ($records ?: [] as $record) { if (!empty($record['ip'])) $ips[]=$record['ip']; if (!empty($record['ipv6'])) $ips[]=$record['ipv6']; }
        $ips=array_values(array_unique($ips));
    }
    if (!$ips) throw new RuntimeException('DNS lookup failed.');
    foreach ($ips as $ip) if (!cw_public_ip($ip)) throw new RuntimeException('Private or reserved DNS target blocked.');
    // Prefer IPv4 where available; cURL will use only this validated address.
    usort($ips,fn($a,$b)=>str_contains($a,':')<=>str_contains($b,':'));
    return [$plain,$port,$ips[0]];
}
function cw_check_site(array $site): array {
    if (!extension_loaded('curl')) throw new RuntimeException('Enable PHP cURL to run HTTP uptime checks.');
    $start=microtime(true);$code=0;$ms=0;$message='';$status='suspect';$failures=(int)$site['failures'];
    try {
        [$host,$port,$ip]=cw_target($site['url']);
        $ch=curl_init($site['url']);$received=0;
        curl_setopt_array($ch,[CURLOPT_NOBODY=>true,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'ClientWatch/1.0 (+self-hosted authorised maintenance)',CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip)]]);
        curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);
        if (in_array($code,[405,501],true)) {
            curl_setopt($ch,CURLOPT_NOBODY,false);curl_setopt($ch,CURLOPT_RANGE,'0-1023');
            curl_setopt($ch,CURLOPT_WRITEFUNCTION,static function($handle,string $chunk) use (&$received): int { $received+=strlen($chunk); return $received>65536?0:strlen($chunk); });
            curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$err=curl_error($ch);
        }
        $ms=(int)round((microtime(true)-$start)*1000);curl_close($ch);
        if ($code>=200&&$code<400) {$status=$code>=300?'redirect':'up';$failures=0;$message=$status==='redirect'?'Redirect response received; destination not followed.':'Public URL responded successfully.';}
        elseif (in_array($code,[401,403,429],true)) {$status='protected';$failures=0;$message='The site responded but access was restricted or rate-limited. Check its firewall manually.';}
        else {$failures++;$status=$failures>=2?'down':'suspect';$message=$code?'HTTP '.$code.' received.':cw_redact($err?:'Connection failed.');}
    } catch (Throwable $e) {
        $message=cw_redact($e->getMessage());$ms=(int)round((microtime(true)-$start)*1000);
        if (str_contains($message,'DNS lookup failed')) {$failures++;$status=$failures>=2?'down':'suspect';}
        else {$status='blocked';$failures=0;}
    }
    $now=time();
    cw_q('UPDATE sites SET health=?,http_code=?,response_ms=?,checked_at=?,failures=?,check_message=? WHERE id=?',[$status,$code,$ms,$now,$failures,$message,$site['id']]);
    cw_q('INSERT INTO checks(site_id,ts,status,code,ms) VALUES(?,?,?,?,?)',[$site['id'],$now,$status,$code,$ms]);
    if ($status==='down'&&$site['health']!=='down') cw_notify($site['id'],'Website failed two consecutive HTTP checks. '.$message);
    if (in_array($status,['up','redirect'],true)&&$site['health']==='down') cw_notify($site['id'],'Website is responding again. HTTP '.$code.'.');
    return ['status'=>$status,'code'=>$code,'ms'=>$ms];
}
function cw_cleanup(): void {
    $now=time();$days=(int)cw_setting('retention','90');$cutoff=$now-max(30,min(180,$days))*86400;
    cw_q('DELETE FROM pageviews WHERE ts<?',[$cutoff]);cw_q('DELETE FROM visits WHERE last_seen<?',[$cutoff]);
    cw_q('DELETE FROM visitors WHERE last_seen<?',[$now-180*86400]);
    cw_q('DELETE FROM presence WHERE seen<?',[$now-600]);cw_q('DELETE FROM errors WHERE last_seen<?',[$cutoff]);
    cw_q('DELETE FROM checks WHERE ts<?',[$now-30*86400]);cw_q('DELETE FROM nonces WHERE ts<?',[$now-600]);
    cw_q('DELETE FROM error_receipts WHERE ts<?',[$now-7*86400]);
    cw_q('DELETE FROM rate_limits WHERE expires<?',[$now]);cw_q('DELETE FROM notifications WHERE ts<?',[$now-7*86400]);
    cw_db()->exec('PRAGMA wal_checkpoint(PASSIVE)');
}
function cw_send_alerts(): void {
    $email=cw_setting('alert_email');$from=cw_setting('mail_from');
    if (!$email || !filter_var($email,FILTER_VALIDATE_EMAIL)) return;
    if (!$from || !filter_var($from,FILTER_VALIDATE_EMAIL)) {cw_setting_set('mail_status','Set a valid sending email address in Settings.');return;}
    if (!function_exists('mail')) {cw_setting_set('mail_status','PHP mail() is unavailable on this host.');return;}
    if (time()-(int)cw_setting('mail_last','0')<1800) return;
    $rows=cw_q('SELECT n.id,n.message,n.ts,s.name,s.url FROM notifications n JOIN sites s ON s.id=n.site_id WHERE n.sent=0 ORDER BY n.ts LIMIT 100')->fetchAll();
    if (!$rows)return;
    $message="ClientWatch maintenance alerts\n\n";
    foreach($rows as $row) $message.=$row['name'].' ('.$row['url'].")\n".gmdate('Y-m-d H:i:s',$row['ts'])." UTC\n".$row['message']."\n\n";
    $message.="Dashboard: ".cw_setting('base_url')."\nReview errors before making changes.\n";
    $ok=@mail($email,'ClientWatch: website maintenance alerts',$message,['From'=>$from,'Content-Type'=>'text/plain; charset=UTF-8']);
    cw_setting_set('mail_last',(string)time());
    if($ok){foreach($rows as $row)cw_q('UPDATE notifications SET sent=1 WHERE id=?',[$row['id']]);cw_setting_set('mail_status','Accepted by local mail transport at '.gmdate('Y-m-d H:i').' UTC (delivery not confirmed).');}
    else cw_setting_set('mail_status','Mail transport failed at '.gmdate('Y-m-d H:i').' UTC. Check hosting mail configuration.');
}

/** Fetch a bounded HTML document from a site's validated public IP without following redirects. */
function cw_fetch_html(string $url, int $limit=1572864): array {
    if (!extension_loaded('curl')) throw new RuntimeException('Enable PHP cURL to run SEO checks.');
    [$host,$port,$ip]=cw_target($url);
    $body='';$headers=[];$start=microtime(true);
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>false,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_MAXREDIRS=>0,
        CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>15,CURLOPT_PROTOCOLS=>CURLPROTO_HTTP|CURLPROTO_HTTPS,
        CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_USERAGENT=>'ClientWatch/1.2.4 (+self-hosted authorised SEO audit)',
        CURLOPT_HTTPHEADER=>['Accept: text/html,application/xhtml+xml;q=0.9,*/*;q=0.5'],
        CURLOPT_RESOLVE=>[$host.':'.$port.':'.(str_contains($ip,':')?'['.$ip.']':$ip)],
        CURLOPT_HEADERFUNCTION=>static function($handle,string $line) use (&$headers): int {
            $len=strlen($line);$line=trim($line);if($line===''||!str_contains($line,':'))return $len;
            [$name,$value]=array_map('trim',explode(':',$line,2));$name=strtolower($name);
            if(in_array($name,['content-security-policy','content-security-policy-report-only','location','x-frame-options','referrer-policy','server','x-powered-by'],true))$headers[$name]=$value;
            return $len;
        },
        CURLOPT_WRITEFUNCTION=>static function($handle,string $chunk) use (&$body,$limit): int {
            if (strlen($body)+strlen($chunk)>$limit) return 0;
            $body.=$chunk; return strlen($chunk);
        }
    ]);
    curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);$type=(string)curl_getinfo($ch,CURLINFO_CONTENT_TYPE);$err=curl_error($ch);$ms=(int)round((microtime(true)-$start)*1000);curl_close($ch);
    if ($code<200||$code>=400) throw new RuntimeException($code?'SEO fetch returned HTTP '.$code.'.':($err?:'SEO fetch failed.'));
    if ($body==='') throw new RuntimeException($err?:'SEO fetch returned an empty document.');
    return ['html'=>$body,'code'=>$code,'content_type'=>$type,'ms'=>$ms,'headers'=>$headers];
}
function cw_pagespeed(string $url): array {
    $key=cw_setting('pagespeed_api_key'); if ($key==='') return [];
    if (!extension_loaded('curl')) return [];
    $query=http_build_query(['url'=>$url,'strategy'=>'mobile','category'=>['performance','accessibility','best-practices','seo'],'key'=>$key], '', '&', PHP_QUERY_RFC3986);
    // http_build_query does not repeat array keys in Google's preferred syntax, so build them explicitly.
    $endpoint='https://www.googleapis.com/pagespeedonline/v5/runPagespeed?url='.rawurlencode($url).'&strategy=mobile&category=performance&category=accessibility&category=best-practices&category=seo&key='.rawurlencode($key);
    $ch=curl_init($endpoint);curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>45,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_USERAGENT=>'ClientWatch/1.2.4']);
    $raw=curl_exec($ch);$code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);curl_close($ch);
    if (!is_string($raw)||$code!==200) return [];
    $j=json_decode($raw,true); if (!is_array($j)) return [];
    $cats=$j['lighthouseResult']['categories'] ?? []; $out=['strategy'=>'mobile'];
    foreach (['performance','accessibility','best-practices','seo'] as $name) if (isset($cats[$name]['score'])) $out[str_replace('-','_',$name)]=(int)round((float)$cats[$name]['score']*100);
    $aud=$j['lighthouseResult']['audits'] ?? [];
    foreach (['first-contentful-paint'=>'fcp','largest-contentful-paint'=>'lcp','cumulative-layout-shift'=>'cls','total-blocking-time'=>'tbt','speed-index'=>'speed_index'] as $id=>$name) {
        if (isset($aud[$id]['displayValue'])) $out[$name]=cw_str($aud[$id]['displayValue'],50);
    }
    return $out;
}

function cw_detect_technology(string $html, array $headers=[]): array {
    $lower=strtolower($html);
    $wpSignals=[];$reactSignals=[];$phpSignals=[];
    if (str_contains($lower,'/wp-content/')) $wpSignals[]='wp-content';
    if (str_contains($lower,'/wp-includes/')) $wpSignals[]='wp-includes';
    if (preg_match('~<meta[^>]+name=["\']generator["\'][^>]+content=["\'][^"\']*wordpress~i',$html)) $wpSignals[]='WordPress generator';
    if (preg_match('~<link[^>]+https?://api\.w\.org/~i',$html)) $wpSignals[]='WordPress REST link';
    if (preg_match('~\bwp-(?:json|emoji|block-library)\b~i',$html)) $wpSignals[]='WordPress asset';

    if (str_contains($html,'__NEXT_DATA__') || str_contains($lower,'/_next/')) $reactSignals[]='Next.js/React';
    if (preg_match('~\bdata-react(?:root|id)\b~i',$html)) $reactSignals[]='React DOM marker';
    if (preg_match('~(?:react(?:-dom)?(?:\.production|\.development)?(?:\.min)?\.js|react-dom)~i',$html)) $reactSignals[]='React asset';
    if (preg_match('~<div[^>]+id=["\'](?:root|app)["\'][^>]*>~i',$html) && preg_match('~<script[^>]+type=["\']module["\'][^>]+src=["\'][^"\']*?/assets/[^"\']+\.js~i',$html)) $reactSignals[]='SPA root + module bundle';

    $powered=(string)($headers['x-powered-by'] ?? '');
    $server=(string)($headers['server'] ?? '');
    if (stripos($powered,'php')!==false) $phpSignals[]=$powered;
    // Never infer PHP just from a .php-looking URL or page content.

    $wordpress=!empty($wpSignals);$react=!empty($reactSignals);$php=!empty($phpSignals);
    $type='other';$label='Other / unknown';$confidence='low';
    if ($wordpress) {$type='wordpress';$label='WordPress';$confidence='high';}
    elseif ($php && $react) {$type='php';$label='PHP + React';$confidence='high';}
    elseif ($react) {$type='javascript';$label='React / JavaScript';$confidence='medium';}
    elseif ($php) {$type='php';$label='PHP';$confidence='high';}
    return [
        'type'=>$type,'label'=>$label,'confidence'=>$confidence,'wordpress'=>$wordpress,'react'=>$react,'php'=>$php,
        'server'=>cw_str($server,120),'powered_by'=>cw_str($powered,120),
        'signals'=>array_slice(array_values(array_unique(array_merge($wpSignals,$phpSignals,$reactSignals))),0,12),
        'checked_at'=>time()
    ];
}

function cw_tracker_probe(array $site, string $html, array $headers=[]): array {
    $public=(string)($site['public_key'] ?? '');
    $base=cw_setting('base_url');
    $trackerDetected=stripos($html,'ClientWatchConfig')!==false || stripos($html,'clientwatch-tracker')!==false || stripos($html,'tracker.js')!==false;
    $siteDetected=$site['id']!=='' && stripos($html,(string)$site['id'])!==false;
    $keyDetected=$public!=='' && stripos($html,$public)!==false;
    $endpointHost=$base!==''?(string)(parse_url($base,PHP_URL_HOST) ?: ''):'';
    $endpointDetected=$endpointHost!=='' && stripos($html,$endpointHost)!==false;
    $mode='';
    if (preg_match('/["\']consent["\']\s*:\s*["\'](granted|required)["\']/i',$html,$m)) $mode=strtolower($m[1]);
    if ($mode==='' && preg_match('/data-consent=["\'](granted|required)["\']/i',$html,$m)) $mode=strtolower($m[1]);
    $report=json_decode((string)($site['report'] ?? '{}'),true) ?: [];
    $analytics=$report['analytics'] ?? [];
    if ($mode==='' && is_array($analytics)) {
        $reported=(string)($analytics['mode'] ?? '');
        if ($reported==='immediate') $mode='granted'; elseif ($reported==='required') $mode='required'; elseif ($reported==='off') $mode='off';
    }
    $scriptSrc='';
    if (preg_match('~<script[^>]+src=["\']([^"\']*(?:clientwatch|tracker\.js)[^"\']*)["\'][^>]*>~i',$html,$m)) $scriptSrc=cw_str(html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5),500);
    $csp=(string)($headers['content-security-policy'] ?? '');
    if ($csp==='' && preg_match('~<meta[^>]+http-equiv=["\']Content-Security-Policy["\'][^>]+content=["\']([^"\']+)["\']~i',$html,$m)) $csp=html_entity_decode($m[1],ENT_QUOTES|ENT_HTML5);
    $cspConnect='';$cspScript='';$cspMayBlockConnect=false;$cspMayBlockScript=false;
    if ($csp!=='') {
        foreach (explode(';',$csp) as $directive) {
            $directive=trim($directive);if($directive==='')continue;
            [$name,$value]=array_pad(preg_split('/\s+/', $directive, 2),2,'');$name=strtolower($name);
            if($name==='connect-src')$cspConnect=$value;
            if($name==='script-src')$cspScript=$value;
        }
        $connect=$cspConnect!==''?$cspConnect:(preg_match('/(?:^|;)\s*default-src\s+([^;]+)/i',$csp,$m)?trim($m[1]):'');
        if($connect!=='' && $endpointHost!=='' && !str_contains($connect,'*') && !str_contains($connect,$endpointHost) && !str_contains($connect,'https:')) $cspMayBlockConnect=true;
        if($cspScript!=='' && $scriptSrc!=='' && !str_contains($cspScript,'*') && !str_contains($cspScript,"'self'") && !str_contains($cspScript,'https:')) $cspMayBlockScript=true;
    }
    $status='unknown';$message='Tracker could not be confirmed in the initial HTML response.';$recommendation='Run a real browser visit and check the collector request.';
    if (is_array($analytics) && array_key_exists('enabled',$analytics) && empty($analytics['enabled'])) {
        $status='disabled';$message='The WordPress connector reports frontend analytics as disabled.';$recommendation='Enable frontend analytics in the existing WordPress connector settings.';
    } elseif ($trackerDetected && $siteDetected && $keyDetected) {
        $status='detected';$message='ClientWatch tracker, site ID and public tracking key were detected in the homepage HTML.';
        if($mode==='required')$recommendation='Tracking is consent-gated. Confirm the consent manager calls ClientWatch.consent(true) after analytics consent.';
        elseif($cspMayBlockConnect)$recommendation='The site Content Security Policy may block requests to the ClientWatch dashboard. Allow the dashboard origin in connect-src.';
        else $recommendation='The public tracker configuration looks correct. If no event arrives, check browser privacy controls, CSP and script-delay/optimisation settings.';
    } elseif ($trackerDetected) {
        $status='partial';$message='A ClientWatch tracker reference was found, but the expected site ID/public key was not confirmed in the homepage HTML.';$recommendation='Clear site/cache/CDN output and verify the current public Site ID and tracking key are rendered.';
    }
    return [
        'status'=>$status,'message'=>$message,'recommendation'=>$recommendation,'tracker_detected'=>$trackerDetected,'site_id_detected'=>$siteDetected,
        'public_key_detected'=>$keyDetected,'endpoint_detected'=>$endpointDetected,'consent'=>$mode,'script_src'=>$scriptSrc,
        'csp_present'=>$csp!=='','csp_connect_src'=>cw_str($cspConnect,300),'csp_script_src'=>cw_str($cspScript,300),
        'csp_may_block_connect'=>$cspMayBlockConnect,'csp_may_block_script'=>$cspMayBlockScript,'checked_at'=>time()
    ];
}
function cw_tracker_check_site(array $site): array {
    $f=cw_fetch_html($site['url']);
    $tracker=cw_tracker_probe($site,$f['html'],$f['headers'] ?? []);
    $current=json_decode((string)($site['seo_report'] ?? '{}'),true) ?: [];
    $current['tracker']=$tracker;
    $current['technology']=cw_detect_technology($f['html'],$f['headers'] ?? []);
    cw_q('UPDATE sites SET seo_report=? WHERE id=?',[json_encode($current,JSON_INVALID_UTF8_SUBSTITUTE),$site['id']]);
    return $tracker;
}

function cw_seo_check_site(array $site, bool $includePageSpeed=false): array {
    $now=time();$issues=[];$score=100;$title='';$description='';$canonical='';$robots='';$h1=0;$h1Raw=0;$h1Texts=[];$h1ByLang=[];$images=0;$missingAlt=0;$viewport=false;$tracker=[];
    try {
        $f=cw_fetch_html($site['url']);$html=$f['html'];$tracker=cw_tracker_probe($site,$html,$f['headers'] ?? []);
        if (class_exists('DOMDocument')) {
            $old=libxml_use_internal_errors(true);$dom=new DOMDocument();@$dom->loadHTML($html,LIBXML_NOWARNING|LIBXML_NOERROR);libxml_clear_errors();libxml_use_internal_errors($old);
            $titles=$dom->getElementsByTagName('title'); if ($titles->length) $title=trim((string)$titles->item(0)?->textContent);
            foreach ($dom->getElementsByTagName('meta') as $m) {
                $name=strtolower((string)$m->getAttribute('name'));
                if ($name==='description') $description=trim((string)$m->getAttribute('content'));
                if ($name==='robots') $robots=trim((string)$m->getAttribute('content'));
                if ($name==='viewport') $viewport=true;
            }
            foreach ($dom->getElementsByTagName('link') as $l) if (strtolower((string)$l->getAttribute('rel'))==='canonical') {$canonical=trim((string)$l->getAttribute('href'));break;}
            $htmlEl=$dom->getElementsByTagName('html')->item(0);$docLang=$htmlEl?strtolower(trim((string)$htmlEl->getAttribute('lang'))):'';$docLang=$docLang!==''?explode('-',str_replace('_','-',$docLang))[0]:'';
            $h1Nodes=$dom->getElementsByTagName('h1');$h1Raw=$h1Nodes->length;
            foreach($h1Nodes as $node){
                $insideTemplate=false;
                for($n=$node;$n instanceof DOMElement;$n=$n->parentNode instanceof DOMElement?$n->parentNode:null){
                    if(strtolower($n->tagName)==='template'){$insideTemplate=true;break;}
                }
                if($insideTemplate)continue;
                $text=preg_replace('/\s+/u',' ',trim((string)$node->textContent))??'';if($text==='')continue;
                $key=function_exists('mb_strtolower')?mb_strtolower($text):strtolower($text);$h1Texts[$key]=$text;
                $lang='';
                for($n=$node;$n instanceof DOMElement;$n=$n->parentNode instanceof DOMElement?$n->parentNode:null){$v=strtolower(trim((string)$n->getAttribute('lang')));if($v!==''){$lang=explode('-',str_replace('_','-',$v))[0];break;}}
                if($lang==='')$lang=$docLang!==''?$docLang:'default';
                $h1ByLang[$lang][$key]=$text;
            }
            if($h1ByLang){$h1=max(array_map('count',$h1ByLang));}else{$h1=count($h1Texts);}
            foreach ($dom->getElementsByTagName('img') as $img) {$images++;if(trim((string)$img->getAttribute('alt'))==='')$missingAlt++;}
        } else {
            if (preg_match('~<title[^>]*>(.*?)</title>~is',$html,$m))$title=trim(strip_tags($m[1]));
            if (preg_match('~<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']*)~i',$html,$m))$description=trim($m[1]);
            $h1Raw=preg_match_all('~<h1\b~i',$html);preg_match_all('~<h1\b[^>]*>(.*?)</h1>~is',$html,$h1Matches);foreach($h1Matches[1]??[] as $raw){$text=preg_replace('/\s+/u',' ',trim(strip_tags($raw)))??'';if($text==='')continue;$key=function_exists('mb_strtolower')?mb_strtolower($text):strtolower($text);$h1Texts[$key]=$text;}$h1=count($h1Texts);$images=preg_match_all('~<img\b~i',$html);$missingAlt=preg_match_all('~<img\b(?![^>]*\balt\s*=)[^>]*>~i',$html);$viewport=(bool)preg_match('~<meta[^>]+name=["\']viewport["\']~i',$html);
        }
        $penalty=function(int $points,string $severity,string $message) use (&$score,&$issues): void {$score=max(0,$score-$points);$issues[]=['severity'=>$severity,'message'=>$message];};
        if ($title==='') $penalty(18,'critical','Missing HTML title.'); elseif ((function_exists('mb_strlen')?mb_strlen($title):strlen($title))<20||(function_exists('mb_strlen')?mb_strlen($title):strlen($title))>65) $penalty(6,'warning','Title length is outside the usual 20–65 character range.');
        if ($description==='') $penalty(14,'critical','Missing meta description.'); elseif ((function_exists('mb_strlen')?mb_strlen($description):strlen($description))<70||(function_exists('mb_strlen')?mb_strlen($description):strlen($description))>165) $penalty(5,'warning','Meta description length is outside the usual 70–165 character range.');
        if ($h1===0 && $h1Raw>0) $penalty(4,'warning','An H1 exists in the source, but the lightweight HTML audit could not confirm visible/indexable text. JavaScript-rendered page builders can cause this.'); elseif ($h1===0) $penalty(12,'critical','No H1 heading with text was found in the homepage HTML.'); elseif ($h1>1) $penalty(4,'warning','More than one distinct H1 heading was found for the same page language.');
        if (!$viewport) $penalty(8,'critical','Viewport meta tag is missing.');
        if ($canonical==='') $penalty(5,'warning','Canonical link is missing.');
        if (!str_starts_with($site['url'],'https://')) $penalty(15,'critical','Website URL is not HTTPS.');
        if ($images>0&&$missingAlt>0) $penalty(min(12,$missingAlt*2),'warning',$missingAlt.' of '.$images.' images are missing alt text.');
        if ($f['ms']>2500) $penalty(8,'warning','Homepage server response was slow ('.$f['ms'].' ms).'); elseif ($f['ms']>1200) $penalty(4,'warning','Homepage server response could be faster ('.$f['ms'].' ms).');
        if (stripos($robots,'noindex')!==false) $penalty(20,'critical','Homepage robots meta contains noindex.');
        if (!$issues) $issues[]=['severity'=>'good','message'=>'No major homepage SEO issues found by the lightweight audit.'];
        $langs=[];foreach($h1ByLang as $lang=>$items)$langs[$lang]=count($items);
        $report=['score'=>$score,'checked_at'=>$now,'http_code'=>$f['code'],'response_ms'=>$f['ms'],'title'=>cw_str($title,180),'description'=>cw_str($description,240),'canonical'=>cw_str($canonical,500),'robots'=>cw_str($robots,120),'h1'=>$h1,'h1_raw'=>$h1Raw,'h1_texts'=>array_values(array_slice($h1Texts,0,5,true)),'h1_languages'=>$langs,'images'=>$images,'missing_alt'=>$missingAlt,'viewport'=>$viewport,'issues'=>array_slice($issues,0,30),'tracker'=>$tracker,'technology'=>cw_detect_technology($html,$f['headers'] ?? [])];
        if ($includePageSpeed||cw_setting('pagespeed_api_key')!=='') $report['pagespeed']=cw_pagespeed($site['url']);
    } catch (Throwable $e) {
        $report=['score'=>0,'checked_at'=>$now,'issues'=>[['severity'=>'critical','message'=>'SEO audit failed: '.cw_redact($e->getMessage(),240)]],'pagespeed'=>[],'tracker'=>$tracker];
    }
    cw_q('UPDATE sites SET seo_report=?,seo_checked_at=? WHERE id=?',[json_encode($report,JSON_INVALID_UTF8_SUBSTITUTE),$now,$site['id']]);
    return $report;
}
