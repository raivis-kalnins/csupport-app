<?php
declare(strict_types=1);

const CW_VERSION = '1.1.0';
const CW_SESSION_IDLE = 1800;
const CW_LIVE_WINDOW = 90;

function cw_config(): array {
    static $config;
    if ($config !== null) return $config;
    $file = dirname(__DIR__) . '/config.php';
    if (!is_file($file)) throw new RuntimeException('Copy config.example.php to config.php and set your private data directory and installation key.');
    $config = require $file;
    if (!is_array($config)) throw new RuntimeException('config.php must return an array.');
    $dir = rtrim((string)($config['data_dir'] ?? ''), '/\\');
    if (!$dir || str_contains($dir, 'YOUR_CPANEL_USER') || !str_starts_with($dir, '/')) throw new RuntimeException('Set data_dir to an absolute writable path outside public_html, for example /home/youraccount/clientwatch-private.');
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Cannot create the private data directory. Create it in your hosting File Manager and grant your PHP user access.');
    $real = realpath($dir);
    $roots = [realpath(dirname(__DIR__))];
    if (!empty($_SERVER['DOCUMENT_ROOT'])) $roots[] = realpath($_SERVER['DOCUMENT_ROOT']);
    foreach ($roots as $root) {
        if ($root && ($real === $root || str_starts_with($real . '/', rtrim($root, '/') . '/'))) throw new RuntimeException('The data directory is inside the public website. Move it outside the document root and update config.php.');
    }
    if (!$real || !is_writable($real)) throw new RuntimeException('The private data directory is not writable by PHP.');
    $config['data_dir'] = $real;
    return $config;
}
function cw_db(): PDO {
    static $db;
    if ($db) return $db;
    $config = cw_config();
    if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) throw new RuntimeException('Enable the PHP pdo_sqlite extension in your hosting PHP settings. No database account is required.');
    umask(0077);
    $db = new PDO('sqlite:' . $config['data_dir'] . '/clientwatch.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA synchronous=NORMAL;');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS sites (
 id TEXT PRIMARY KEY, name TEXT NOT NULL, url TEXT NOT NULL, type TEXT NOT NULL,
 client TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '', origins TEXT NOT NULL,
 public_key TEXT NOT NULL, secret TEXT NOT NULL, created_at INTEGER NOT NULL,
 monitor INTEGER NOT NULL DEFAULT 1, health TEXT NOT NULL DEFAULT 'unknown', http_code INTEGER NOT NULL DEFAULT 0,
 response_ms INTEGER NOT NULL DEFAULT 0, checked_at INTEGER NOT NULL DEFAULT 0, failures INTEGER NOT NULL DEFAULT 0,
 check_message TEXT NOT NULL DEFAULT '', backend_at INTEGER NOT NULL DEFAULT 0, frontend_at INTEGER NOT NULL DEFAULT 0,
 report TEXT NOT NULL DEFAULT '{}', refresh_requested INTEGER NOT NULL DEFAULT 0
);
CREATE TABLE IF NOT EXISTS visitors (site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, visitor TEXT NOT NULL, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL, PRIMARY KEY(site_id,visitor));
CREATE TABLE IF NOT EXISTS visits (site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, session TEXT NOT NULL, visitor TEXT NOT NULL, started INTEGER NOT NULL, last_seen INTEGER NOT NULL, PRIMARY KEY(site_id,session));
CREATE TABLE IF NOT EXISTS pageviews (id INTEGER PRIMARY KEY, site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, event_id TEXT NOT NULL, session TEXT NOT NULL, visitor TEXT NOT NULL, path TEXT NOT NULL, referrer TEXT NOT NULL DEFAULT '', device TEXT NOT NULL DEFAULT 'desktop', ts INTEGER NOT NULL, UNIQUE(site_id,event_id));
CREATE INDEX IF NOT EXISTS pv_site_ts ON pageviews(site_id,ts);
CREATE INDEX IF NOT EXISTS pv_ts ON pageviews(ts);
CREATE TABLE IF NOT EXISTS presence (site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, tab TEXT NOT NULL, visitor TEXT NOT NULL, path TEXT NOT NULL, seen INTEGER NOT NULL, PRIMARY KEY(site_id,tab));
CREATE TABLE IF NOT EXISTS errors (id INTEGER PRIMARY KEY, site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, fingerprint TEXT NOT NULL, source TEXT NOT NULL, severity TEXT NOT NULL, message TEXT NOT NULL, file TEXT NOT NULL DEFAULT '', line INTEGER NOT NULL DEFAULT 0, path TEXT NOT NULL DEFAULT '', occurrences INTEGER NOT NULL DEFAULT 1, first_seen INTEGER NOT NULL, last_seen INTEGER NOT NULL, resolved_at INTEGER NOT NULL DEFAULT 0, UNIQUE(site_id,fingerprint));
CREATE INDEX IF NOT EXISTS errors_open ON errors(site_id,resolved_at,last_seen);
CREATE TABLE IF NOT EXISTS checks (id INTEGER PRIMARY KEY, site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, ts INTEGER NOT NULL, status TEXT NOT NULL, code INTEGER NOT NULL, ms INTEGER NOT NULL);
CREATE INDEX IF NOT EXISTS checks_site_ts ON checks(site_id,ts);
CREATE TABLE IF NOT EXISTS nonces (site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, nonce TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY(site_id,nonce));
CREATE TABLE IF NOT EXISTS error_receipts (site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, event_id TEXT NOT NULL, ts INTEGER NOT NULL, PRIMARY KEY(site_id,event_id));
CREATE TABLE IF NOT EXISTS rate_limits (key TEXT PRIMARY KEY, count INTEGER NOT NULL, expires INTEGER NOT NULL);
CREATE TABLE IF NOT EXISTS notifications (id INTEGER PRIMARY KEY, site_id TEXT NOT NULL REFERENCES sites(id) ON DELETE CASCADE, message TEXT NOT NULL, ts INTEGER NOT NULL, sent INTEGER NOT NULL DEFAULT 0);
SQL);
    if (cw_setting('app_secret') === '') cw_setting_set('app_secret', bin2hex(random_bytes(32)));
    return $db;
}
function cw_q(string $sql, array $params = []): PDOStatement {
    $st = cw_db()->prepare($sql);
    foreach ($params as $i => $value) $st->bindValue(is_int($i) ? $i + 1 : $i, $value, is_int($value) ? PDO::PARAM_INT : ($value === null ? PDO::PARAM_NULL : PDO::PARAM_STR));
    $st->execute();
    return $st;
}
function cw_setting(string $key, string $default = ''): string {
    $v = cw_q('SELECT value FROM settings WHERE key=?', [$key])->fetchColumn();
    return $v === false ? $default : (string)$v;
}
function cw_setting_set(string $key, string $value): void { cw_q('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value', [$key,$value]); }
function cw_json(mixed $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function cw_fail(string $message, int $status = 400): never { cw_json(['ok'=>false,'error'=>$message], $status); }
function cw_body(int $max = 262144): array {
    $raw = file_get_contents('php://input', false, null, 0, $max + 1);
    if ($raw === false || strlen($raw) > $max) cw_fail('Request body too large.',413);
    $GLOBALS['cw_raw_body'] = $raw;
    try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
    catch (JsonException) { cw_fail('Invalid JSON.'); }
    if (!is_array($data)) cw_fail('Expected a JSON object.');
    return $data;
}
function cw_str(mixed $value, int $max = 240): string {
    return substr(trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', '', is_scalar($value) ? (string)$value : '') ?? ''),0,$max);
}
function cw_redact(mixed $value, int $max = 1000): string {
    $text = cw_str($value, $max);
    $text = preg_replace('/\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b/i', '[email]', $text) ?? '';
    $text = preg_replace('/\b(password|passwd|token|api[_-]?key|secret|authorization|cookie)\s*[:=]\s*[^\s,;]+/i', '$1=[redacted]', $text) ?? '';
    $text = preg_replace('~(https?://[^\s?#]+)[?#][^\s]*~i', '$1', $text) ?? '';
    $text = preg_replace('/\b[a-f0-9]{32,}\b/i', '[identifier]', $text) ?? '';
    return $text;
}
function cw_path(mixed $value): string {
    $v = cw_str($value, 600);
    $path = parse_url($v, PHP_URL_PATH);
    if (!is_string($path) || !str_starts_with($path,'/')) $path = '/';
    $path = cw_redact($path, 300);
    $path = preg_replace('~/(?:[0-9a-f]{8}-[0-9a-f-]{27,}|[A-Za-z0-9_-]{40,}|[0-9]{6,})(?=/|$)~i', '/[id]', $path) ?? '/';
    return $path;
}
function cw_origin(string $url): string {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['https','http'],true) || isset($p['user']) || isset($p['pass'])) return '';
    $scheme = strtolower($p['scheme']); $port = (int)($p['port'] ?? ($scheme === 'https' ? 443 : 80));
    return $scheme.'://'.strtolower($p['host']).(($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80) ? '' : ':'.$port);
}
function cw_url(mixed $value): string {
    $v = rtrim(cw_str($value, 1000), '/');
    if (!filter_var($v,FILTER_VALIDATE_URL) || !cw_origin($v)) throw new InvalidArgumentException('Enter a complete http(s) website URL.');
    if (parse_url($v,PHP_URL_QUERY) || parse_url($v,PHP_URL_FRAGMENT)) throw new InvalidArgumentException('Use a URL without a query string or fragment.');
    return $v;
}
function cw_site(string $id): array {
    $site = cw_q('SELECT * FROM sites WHERE id=?',[$id])->fetch();
    if (!$site) cw_fail('Website not found.',404);
    return $site;
}
function cw_rate(string $key, int $limit, int $seconds): bool {
    $bucket = intdiv(time(), $seconds);
    $key = hash('sha256',$key.':'.$bucket);
    cw_q('INSERT INTO rate_limits(key,count,expires) VALUES(?,1,?) ON CONFLICT(key) DO UPDATE SET count=count+1',[$key,time()+$seconds]);
    $row = cw_q('SELECT count FROM rate_limits WHERE key=?',[$key])->fetchColumn();
    return (int)$row <= $limit;
}
function cw_ip_key(): string { return hash_hmac('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? 'cli'),cw_setting('app_secret')); }
function cw_https(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }
function cw_hcaptcha_verify(string $token): bool {
    if (cw_setting('hcaptcha_enabled','0') !== '1') return true;
    $secret = cw_setting('hcaptcha_secret');
    $sitekey = cw_setting('hcaptcha_sitekey');
    if ($secret === '' || $sitekey === '' || $token === '') return false;
    if (!function_exists('curl_init')) throw new RuntimeException('hCaptcha is enabled but PHP cURL is unavailable. Enable cURL or disable hCaptcha in ClientWatch settings.');
    $post = http_build_query(['secret'=>$secret,'response'=>$token,'remoteip'=>(string)($_SERVER['REMOTE_ADDR'] ?? ''),'sitekey'=>$sitekey]);
    $ch = curl_init('https://api.hcaptcha.com/siteverify');
    curl_setopt_array($ch,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$post,CURLOPT_RETURNTRANSFER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_CONNECTTIMEOUT=>4,CURLOPT_TIMEOUT=>8,CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,CURLOPT_HTTPHEADER=>['Content-Type: application/x-www-form-urlencoded']]);
    $raw = curl_exec($ch); $code = (int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    if ($code !== 200 || !is_string($raw)) return false;
    $json = json_decode($raw,true);
    if (!is_array($json) || empty($json['success'])) return false;
    $hostname = strtolower((string)($json['hostname'] ?? ''));
    $expected = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $expected = preg_replace('/:\d+$/','',$expected) ?? $expected;
    return $hostname === '' || $hostname === $expected;
}

function cw_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name('clientwatch_session');
    ini_set('session.use_strict_mode','1'); ini_set('session.use_only_cookies','1');
    $path = str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
    session_set_cookie_params(['lifetime'=>0,'path'=>rtrim($path,'/').'/','secure'=>!empty(cw_config()['allow_http']) ? cw_https() : true,'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}
function cw_logged_in(): bool {
    cw_session();
    if (empty($_SESSION['admin']) || ($_SESSION['auth_version'] ?? '') !== cw_setting('auth_version') || time() - (int)($_SESSION['seen'] ?? 0)>14400 || time() - (int)($_SESSION['created'] ?? 0)>43200) { unset($_SESSION['admin']); return false; }
    $_SESSION['seen'] = time(); return true;
}
function cw_auth(): void { if (!cw_logged_in()) cw_fail('Please sign in again.',401); }
function cw_csrf(): void {
    cw_session();
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cw_fail('POST required.',405);
    if (!hash_equals($_SESSION['csrf'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? ''))) cw_fail('Security token expired. Reload the page.',403);
}
function cw_error(array $site, array $e, string $source): void {
    $message = cw_redact($e['message'] ?? '',1000);
    if ($message === '') return;
    $eventId = cw_str($e['id'] ?? '',80);
    if (preg_match('/^[a-zA-Z0-9_-]{12,80}$/',$eventId)) {
        if (cw_q('INSERT OR IGNORE INTO error_receipts(site_id,event_id,ts) VALUES(?,?,?)',[$site['id'],$eventId,time()])->rowCount()===0) return;
    }
    $file = basename(str_replace('\\','/',cw_str($e['file'] ?? '',500)));
    $file = explode('?', $file)[0];
    $line = max(0,min(10000000,(int)($e['line'] ?? 0)));
    $path = cw_path($e['path'] ?? '/');
    $severity = $source === 'browser' ? 'warning' : (in_array($e['severity'] ?? '', ['critical','error','warning'],true) ? $e['severity'] : 'error');
    $fp = hash('sha256',implode('|',[$source,$message,$file,(string)$line,$path]));
    $old = cw_q('SELECT id,resolved_at FROM errors WHERE site_id=? AND fingerprint=?',[$site['id'],$fp])->fetch();
    if (!$old && (int)cw_q('SELECT COUNT(*) FROM errors WHERE site_id=?',[$site['id']])->fetchColumn() >= 3000) return;
    cw_q('INSERT INTO errors(site_id,fingerprint,source,severity,message,file,line,path,first_seen,last_seen) VALUES(?,?,?,?,?,?,?,?,?,?) ON CONFLICT(site_id,fingerprint) DO UPDATE SET occurrences=occurrences+1,last_seen=excluded.last_seen,resolved_at=0',[$site['id'],$fp,$source,$severity,$message,$file,$line,$path,time(),time()]);
    if ((!$old || (int)$old['resolved_at']>0) && ($source !== 'browser' || cw_setting('email_browser','0') === '1')) cw_notify($site['id'],ucfirst($source).' '.$severity.': '.$message);
}
function cw_notify(string $id, string $message): void {
    if (cw_setting('alert_email') !== '') cw_q('INSERT INTO notifications(site_id,message,ts) VALUES(?,?,?)',[$id,cw_redact($message,1000),time()]);
}
function cw_collect(): never {
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        $origin = cw_origin($_SERVER['HTTP_ORIGIN'] ?? '');
        foreach (cw_q('SELECT origins FROM sites')->fetchAll() as $s) {
            if ($origin && in_array($origin,json_decode($s['origins'],true) ?: [],true)) {
                header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin');
                header('Access-Control-Allow-Methods: POST, OPTIONS'); header('Access-Control-Allow-Headers: Content-Type'); header('Access-Control-Max-Age: 600');
                http_response_code(204); exit;
            }
        }
        cw_fail('Origin not allowed.',403);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cw_fail('POST required.',405);
    $b = cw_body(12288); $site = cw_site(cw_str($b['site'] ?? '',32));
    $origin = cw_origin($_SERVER['HTTP_ORIGIN'] ?? '');
    if (!$origin || !in_array($origin,json_decode($site['origins'],true) ?: [],true)) cw_fail('Origin not allowed.',403);
    header('Access-Control-Allow-Origin: '.$origin); header('Vary: Origin');
    if (!hash_equals($site['public_key'],cw_str($b['key'] ?? '',100))) cw_fail('Invalid tracking key.',403);
    $type = $b['type'] ?? '';
    if (!in_array($type,['pageview','heartbeat','leave','error'],true)) cw_fail('Unknown event.');
    foreach (['visitor','session','tab','event'] as $field) {
        if (!preg_match('/^[a-zA-Z0-9_-]{12,80}$/',(string)($b[$field] ?? ''))) cw_fail('Invalid event identifiers.');
    }
    if (!cw_rate('collect-site:'.$site['id'],6000,60) || !cw_rate('collect-ip:'.cw_ip_key().':'.$site['id'],600,60)) cw_fail('Collection rate limit reached.',429);
    $hash = fn(string $s): string => hash_hmac('sha256',$s,cw_setting('app_secret').$site['id']);
    $v = $hash($b['visitor']); $sid = $hash($b['session']); $tab = $hash($b['tab']); $now=time();
    if (!cw_rate('visitor:'.$site['id'].':'.$v,100,60)) cw_fail('Visitor rate limit reached.',429);
    $path = cw_path($b['path'] ?? '/');
    if ($type === 'leave') { cw_q('DELETE FROM presence WHERE site_id=? AND tab=?',[$site['id'],$tab]); cw_json(['ok'=>true]); }
    $db = cw_db(); $db->beginTransaction();
    try {
        cw_q('INSERT INTO visitors(site_id,visitor,first_seen,last_seen) VALUES(?,?,?,?) ON CONFLICT(site_id,visitor) DO UPDATE SET last_seen=excluded.last_seen',[$site['id'],$v,$now,$now]);
        cw_q('INSERT INTO visits(site_id,session,visitor,started,last_seen) VALUES(?,?,?,?,?) ON CONFLICT(site_id,session) DO UPDATE SET last_seen=excluded.last_seen',[$site['id'],$sid,$v,$now,$now]);
        if (!empty($b['visible'])) cw_q('INSERT INTO presence(site_id,tab,visitor,path,seen) VALUES(?,?,?,?,?) ON CONFLICT(site_id,tab) DO UPDATE SET visitor=excluded.visitor,path=excluded.path,seen=excluded.seen',[$site['id'],$tab,$v,$path,$now]);
        if ($type==='pageview') {
            $ref = strtolower((string)(parse_url(cw_str($b['referrer'] ?? '',400),PHP_URL_HOST) ?: ''));
            $device = in_array($b['device'] ?? '',['desktop','mobile','tablet'],true) ? $b['device'] : 'desktop';
            cw_q('INSERT OR IGNORE INTO pageviews(site_id,event_id,session,visitor,path,referrer,device,ts) VALUES(?,?,?,?,?,?,?,?)',[$site['id'],$b['event'],$sid,$v,$path,$ref,$device,$now]);
        } elseif ($type==='error' && is_array($b['error'] ?? null)) { cw_error($site,array_merge($b['error'],['id'=>$b['event']]),'browser'); }
        cw_q('UPDATE sites SET frontend_at=? WHERE id=?',[$now,$site['id']]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
    cw_json(['ok'=>true]);
}
function cw_report(): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') cw_fail('POST required.',405);
    $b = cw_body(); $site = cw_site(cw_str($_SERVER['HTTP_X_CW_SITE'] ?? '',32));
    $timestamp = (string)($_SERVER['HTTP_X_CW_TIMESTAMP'] ?? '');
    $nonce = (string)($_SERVER['HTTP_X_CW_NONCE'] ?? '');
    if (!ctype_digit($timestamp) || abs(time()-(int)$timestamp)>300 || !preg_match('/^[a-f0-9]{32,64}$/',$nonce)) cw_fail('Invalid or expired signature metadata.',403);
    $expected = hash_hmac('sha256',$timestamp."\n".$nonce."\n".$GLOBALS['cw_raw_body'],$site['secret']);
    if (!hash_equals($expected,(string)($_SERVER['HTTP_X_CW_SIGNATURE'] ?? ''))) cw_fail('Invalid signature.',403);
    if (!cw_rate('report:'.$site['id'],90,60)) cw_fail('Report rate limit reached.',429);
    try { cw_q('INSERT INTO nonces(site_id,nonce,ts) VALUES(?,?,?)',[$site['id'],$nonce,time()]); }
    catch (PDOException $e) { if (str_contains($e->getMessage(),'UNIQUE')) cw_fail('Duplicate report.',409); throw $e; }
    if (isset($b['report']) && is_array($b['report'])) {
        $r = $b['report'];
        $clean = ['kind'=>cw_str($r['kind'] ?? $site['type'],20), 'runtime'=>cw_str($r['runtime'] ?? '',100), 'connector'=>cw_str($r['connector'] ?? '',30), 'received_at'=>time()];
        if (isset($r['environment']) && is_array($r['environment'])) {
            $e=$r['environment']; $clean['environment']=[];
            foreach (['platform','architecture','sapi','server','memory_limit','upload_max','post_max','max_execution_time','database','active_theme'] as $key) {
                if (isset($e[$key])) $clean['environment'][$key]=cw_str($e[$key],120);
            }
            foreach (['disk_free_mb','disk_total_mb','process_memory_mb','uptime_seconds'] as $key) if (isset($e[$key])) $clean['environment'][$key]=max(0,(int)$e[$key]);
            if (isset($e['extensions']) && is_array($e['extensions'])) {
                $clean['environment']['extensions']=[];
                foreach (array_slice($e['extensions'],0,40,true) as $key=>$value) $clean['environment']['extensions'][cw_str($key,40)]=!empty($value);
            }
        }
        if ($site['type']==='wordpress' && isset($r['wordpress']) && is_array($r['wordpress'])) {
            $w=$r['wordpress'];
            $clean['wordpress']=['version'=>cw_str($w['version'] ?? '',30),'php'=>cw_str($w['php'] ?? '',30),'debug'=>!empty($w['debug']),'cron_disabled'=>!empty($w['cron_disabled']),'multisite'=>!empty($w['multisite']),'object_cache'=>!empty($w['object_cache']),'memory_limit'=>cw_str($w['memory_limit'] ?? '',30),'max_memory_limit'=>cw_str($w['max_memory_limit'] ?? '',30),'database'=>cw_str($w['database'] ?? '',80),'active_theme'=>cw_str($w['active_theme'] ?? '',120),'admin_url'=>'','checks'=>[],'plugins'=>[],'themes'=>[],'core_update'=>cw_str($w['core_update'] ?? '',30)];
            $admin = cw_str($w['admin_url'] ?? '',1000);
            if (cw_origin($admin) === cw_origin($site['url'])) $clean['wordpress']['admin_url']=$admin;
            foreach (['core','plugins','themes'] as $kind) $clean['wordpress']['checks'][$kind]=max(0,min(time(),(int)($w['checks'][$kind] ?? 0)));
            foreach (['plugins','themes'] as $kind) foreach (array_slice(is_array($w[$kind] ?? null)?$w[$kind]:[],0,500) as $item) {
                if (!is_array($item)) continue;
                $clean['wordpress'][$kind][]=['name'=>cw_str($item['name'] ?? '',180),'version'=>cw_str($item['version'] ?? '',40),'new_version'=>cw_str($item['new_version'] ?? '',40),'active'=>!empty($item['active'])];
            }
        }
        cw_q('UPDATE sites SET report=?,backend_at=?,refresh_requested=CASE WHEN ?=1 THEN 0 ELSE refresh_requested END WHERE id=?',[json_encode($clean,JSON_INVALID_UTF8_SUBSTITUTE),time(),!empty($b['fresh'])?1:0,$site['id']]);
    }
    foreach (array_slice(is_array($b['errors'] ?? null)?$b['errors']:[],0,30) as $error) if (is_array($error)) cw_error($site,$error,'server');
    cw_q('UPDATE sites SET backend_at=? WHERE id=?',[time(),$site['id']]);
    $requested=(int)cw_q('SELECT refresh_requested FROM sites WHERE id=?',[$site['id']])->fetchColumn();
    cw_json(['ok'=>true,'refresh_requested'=>$requested>0,'server_time'=>time()]);
}
function cw_since(int $days): int { return time()-max(1,min(30,$days))*86400; }
function cw_metrics(string $id, int $since): array {
    $pv = cw_q('SELECT COUNT(*) AS pageviews,COUNT(DISTINCT session) AS visits,COUNT(DISTINCT visitor) AS users FROM pageviews WHERE site_id=? AND ts>=?',[$id,$since])->fetch();
    $new = (int)cw_q('SELECT COUNT(DISTINCT p.visitor) FROM pageviews p JOIN visitors v ON v.site_id=p.site_id AND v.visitor=p.visitor WHERE p.site_id=? AND p.ts>=? AND v.first_seen>=?',[$id,$since,$since])->fetchColumn();
    return ['visits'=>(int)$pv['visits'],'pageviews'=>(int)$pv['pageviews'],'users'=>(int)$pv['users'],'new'=>$new,'returning'=>max(0,(int)$pv['users']-$new),'live'=>(int)cw_q('SELECT COUNT(DISTINCT visitor) FROM presence WHERE site_id=? AND seen>=?',[$id,time()-CW_LIVE_WINDOW])->fetchColumn(),'errors'=>(int)cw_q('SELECT COUNT(*) FROM errors WHERE site_id=? AND resolved_at=0',[$id])->fetchColumn()];
}
function cw_site_public(array $s, int $since): array {
    unset($s['secret'],$s['public_key']);
    $s['report']=json_decode($s['report'],true) ?: [];
    $s['origins']=json_decode($s['origins'],true) ?: [];
    $s['metrics']=cw_metrics($s['id'],$since);
    $w=$s['report']['wordpress'] ?? null; $count=0; $known=false;
    if (is_array($w)) {
        $known = !empty($w['checks']['core']) && !empty($w['checks']['plugins']) && !empty($w['checks']['themes']);
        $count = !empty($w['core_update']) ? 1 : 0;
        foreach (['plugins','themes'] as $type) foreach ($w[$type] ?? [] as $p) if (!empty($p['new_version'])) $count++;
    }
    $s['updates']=$count; $s['updates_known']=$known;
    return $s;
}
function cw_detail(string $id,int $days): array {
    $s = cw_site_public(cw_site($id),cw_since($days)); $since=cw_since($days);
    $s['chart']=cw_q("SELECT strftime('%Y-%m-%d',ts,'unixepoch') AS day, COUNT(*) AS pageviews, COUNT(DISTINCT session) AS visits FROM pageviews WHERE site_id=? AND ts>=? GROUP BY day ORDER BY day",[$id,$since])->fetchAll();
    $s['pages']=cw_q('SELECT path,COUNT(*) AS views,COUNT(DISTINCT visitor) AS users FROM pageviews WHERE site_id=? AND ts>=? GROUP BY path ORDER BY views DESC LIMIT 20',[$id,$since])->fetchAll();
    $s['referrers']=cw_q("SELECT CASE WHEN referrer='' THEN 'Direct / unknown' ELSE referrer END AS name,COUNT(*) AS views FROM pageviews WHERE site_id=? AND ts>=? GROUP BY name ORDER BY views DESC LIMIT 12",[$id,$since])->fetchAll();
    $s['devices']=cw_q('SELECT device,COUNT(*) AS views FROM pageviews WHERE site_id=? AND ts>=? GROUP BY device ORDER BY views DESC',[$id,$since])->fetchAll();
    $s['errors_list']=cw_q('SELECT * FROM errors WHERE site_id=? ORDER BY (resolved_at=0) DESC,last_seen DESC LIMIT 100',[$id])->fetchAll();
    $s['live_pages']=cw_q('SELECT path,COUNT(DISTINCT visitor) AS users FROM presence WHERE site_id=? AND seen>=? GROUP BY path ORDER BY users DESC',[$id,time()-CW_LIVE_WINDOW])->fetchAll();
    $s['checks_list']=cw_q('SELECT ts,status,code,ms FROM checks WHERE site_id=? ORDER BY ts DESC LIMIT 60',[$id])->fetchAll();
    return $s;
}
function cw_save_site(array $b): string {
    $name=cw_str($b['name'] ?? '',150); if ($name==='') throw new InvalidArgumentException('Website name is required.');
    $url=cw_url($b['url'] ?? ''); $type=$b['type'] ?? 'other';
    if (!in_array($type,['wordpress','php','javascript','other'],true)) $type='other';
    $origins=[cw_origin($url)];
    $extra=$b['origins'] ?? ''; if (is_array($extra)) $extra=implode("\n",$extra);
    foreach (preg_split('/[\s,]+/',cw_str($extra,2000)) ?: [] as $origin) {
        if ($origin==='') continue; $o=cw_origin($origin);
        if (!$o) throw new InvalidArgumentException('Each additional origin must be a full http(s) URL. Wildcards are not allowed.');
        $origins[]=$o;
    }
    $origins=json_encode(array_values(array_unique($origins)));
    $id=cw_str($b['id'] ?? '',32); $client=cw_str($b['client'] ?? '',150); $notes=cw_str($b['notes'] ?? '',4000); $monitor=!empty($b['monitor'])?1:0;
    if ($id) {
        $old=cw_site($id);
        cw_q('UPDATE sites SET name=?,url=?,type=?,client=?,notes=?,origins=?,monitor=? WHERE id=?',[$name,$url,$type,$client,$notes,$origins,$monitor,$id]);
        if ($url!==$old['url']) cw_q("UPDATE sites SET health='unknown',checked_at=0,failures=0 WHERE id=?",[$id]);
    } else {
        if ((int)cw_q('SELECT COUNT(*) FROM sites')->fetchColumn()>=200) throw new InvalidArgumentException('This basic edition is limited to 200 websites.');
        $id=bin2hex(random_bytes(8));
        cw_q('INSERT INTO sites(id,name,url,type,client,notes,origins,public_key,secret,created_at,monitor) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$id,$name,$url,$type,$client,$notes,$origins,bin2hex(random_bytes(16)),bin2hex(random_bytes(32)),time(),$monitor]);
    }
    return $id;
}
function cw_settings_public(): array {
    return ['base_url'=>cw_setting('base_url'),'alert_email'=>cw_setting('alert_email'),'mail_from'=>cw_setting('mail_from'),'email_browser'=>cw_setting('email_browser','0'),'retention'=>cw_setting('retention','90'),'hcaptcha_enabled'=>cw_setting('hcaptcha_enabled','0'),'hcaptcha_sitekey'=>cw_setting('hcaptcha_sitekey'),'hcaptcha_secret_saved'=>cw_setting('hcaptcha_secret')!==''?'1':'0','cron_at'=>cw_setting('cron_at','0'),'mail_status'=>cw_setting('mail_status','Not configured'),'curl'=>extension_loaded('curl'),'version'=>CW_VERSION,'data_dir'=>cw_config()['data_dir'],'cron_file'=>dirname(__DIR__).'/cron.php','php'=>PHP_VERSION];
}
function cw_admin_api(string $action): never {
    cw_auth();
    $days=in_array((int)($_GET['days'] ?? 7),[1,7,30],true)?(int)($_GET['days'] ?? 7):7;
    if ($action==='sites' && $_SERVER['REQUEST_METHOD']==='GET') {
        $rows=cw_q('SELECT * FROM sites ORDER BY name COLLATE NOCASE')->fetchAll();
        cw_json(['ok'=>true,'sites'=>array_map(fn($s)=>cw_site_public($s,cw_since($days)),$rows),'settings'=>cw_settings_public(),'now'=>time()]);
    }
    if ($action==='detail' && $_SERVER['REQUEST_METHOD']==='GET') cw_json(['ok'=>true,'site'=>cw_detail(cw_str($_GET['id'] ?? '',32),$days)]);
    if ($action==='install' && $_SERVER['REQUEST_METHOD']==='GET') {
        $s=cw_site(cw_str($_GET['id'] ?? '',32));
        cw_json(['ok'=>true,'id'=>$s['id'],'public_key'=>$s['public_key'],'secret'=>$s['secret'],'base_url'=>cw_setting('base_url')]);
    }
    cw_csrf(); $b=cw_body();
    if ($action==='save_site') { $id=cw_save_site($b); cw_json(['ok'=>true,'id'=>$id]); }
    if ($action==='delete_site') {
        $s=cw_site(cw_str($b['id'] ?? '',32));
        if (($b['confirm'] ?? '')!==$s['name']) cw_fail('Type the exact website name to confirm deletion.');
        cw_q('DELETE FROM sites WHERE id=?',[$s['id']]); cw_json(['ok'=>true]);
    }
    if ($action==='rotate') {
        $s=cw_site(cw_str($b['id'] ?? '',32));
        cw_q('UPDATE sites SET secret=? WHERE id=?',[bin2hex(random_bytes(32)),$s['id']]); cw_json(['ok'=>true]);
    }
    if ($action==='resolve') {
        cw_q('UPDATE errors SET resolved_at=? WHERE id=? AND site_id=?',[time(),(int)($b['error_id'] ?? 0),cw_str($b['id'] ?? '',32)]); cw_json(['ok'=>true]);
    }
    if ($action==='request_refresh') {
        $s=cw_site(cw_str($b['id'] ?? '',32)); if ($s['type']!=='wordpress') cw_fail('This action is for WordPress websites.');
        cw_q('UPDATE sites SET refresh_requested=? WHERE id=?',[time(),$s['id']]); cw_json(['ok'=>true]);
    }
    if ($action==='check') {
        $s=cw_site(cw_str($b['id'] ?? '',32));
        if (!cw_rate('manual-check:'.$s['id'],2,60)) cw_fail('Please wait a minute before another check.',429);
        session_write_close(); require_once __DIR__.'/monitor.php';
        cw_check_site($s); cw_json(['ok'=>true]);
    }
    if ($action==='settings') {
        $base=cw_url($b['base_url'] ?? '');
        if (!str_ends_with((string)parse_url($base,PHP_URL_PATH),'/index.php')) cw_fail('Dashboard URL must end with /index.php.');
        if (!str_starts_with($base,'https://') && empty(cw_config()['allow_http'])) cw_fail('Use HTTPS for the dashboard URL.');
        foreach (['alert_email','mail_from'] as $field) { $v=cw_str($b[$field] ?? '',200); if ($v && !filter_var($v,FILTER_VALIDATE_EMAIL)) cw_fail('Enter valid email addresses.'); }
        $retention=(int)($b['retention'] ?? 90); if (!in_array($retention,[30,60,90,180],true)) $retention=90;
        $hcaptchaEnabled=!empty($b['hcaptcha_enabled']);
        $hcaptchaSitekey=cw_str($b['hcaptcha_sitekey'] ?? '',120);
        $hcaptchaSecret=cw_str($b['hcaptcha_secret'] ?? '',240);
        if ($hcaptchaEnabled && ($hcaptchaSitekey==='' || ($hcaptchaSecret==='' && cw_setting('hcaptcha_secret')===''))) cw_fail('Enter both hCaptcha site key and secret before enabling login protection.');
        if (!empty($b['password'])) {
            if (!password_verify((string)($b['current_password'] ?? ''),cw_setting('password'))) cw_fail('Current password is incorrect.',403);
            if (strlen((string)$b['password'])<14 || strlen((string)$b['password'])>200) cw_fail('Use a password between 14 and 200 characters.');
        }
        cw_setting_set('base_url',$base); cw_setting_set('alert_email',cw_str($b['alert_email'] ?? '',200)); cw_setting_set('mail_from',cw_str($b['mail_from'] ?? '',200)); cw_setting_set('email_browser',!empty($b['email_browser'])?'1':'0'); cw_setting_set('retention',(string)$retention); cw_setting_set('hcaptcha_enabled',$hcaptchaEnabled?'1':'0'); cw_setting_set('hcaptcha_sitekey',$hcaptchaSitekey); if ($hcaptchaSecret!=='') cw_setting_set('hcaptcha_secret',$hcaptchaSecret);
        if (!empty($b['password'])) {
            cw_setting_set('password',password_hash($b['password'],PASSWORD_DEFAULT)); cw_setting_set('auth_version',bin2hex(random_bytes(16))); $_SESSION['auth_version']=cw_setting('auth_version'); session_regenerate_id(true);
        }
        cw_json(['ok'=>true]);
    }
    cw_fail('Unknown action.',404);
}
