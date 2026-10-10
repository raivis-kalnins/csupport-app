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
