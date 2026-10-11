<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Permissions-Policy: camera=(), microphone=(), geolocation=()");
header("Content-Security-Policy: default-src 'self'; script-src 'self' https://js.hcaptcha.com; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self' https://hcaptcha.com https://*.hcaptcha.com; frame-src https://*.hcaptcha.com; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
require __DIR__.'/lib/core.php';
require __DIR__.'/lib/views.php';
try {
    cw_db();
    if (empty(cw_config()['allow_http']) && !cw_https()) throw new RuntimeException('Open this dashboard over HTTPS. For a local development server only, set allow_http to true in config.php.');
    $api=cw_str($_GET['api'] ?? '',30);
    if ($api==='collect') cw_collect();
    if ($api==='report') cw_report();
    if ($api) cw_admin_api($api);
    header('Cache-Control: no-store');
    cw_session();
    $setup=cw_setting('password')===''; $error='';
    if ($_SERVER['REQUEST_METHOD']==='POST') {
        cw_csrf();
        $action=(string)($_POST['action'] ?? '');
        if ($action==='logout') {
            $_SESSION=[]; session_destroy(); header('Location: index.php'); exit;
        }
        if ($action==='setup' && $setup) {
            if (!cw_rate('setup:'.cw_ip_key(),6,600)) throw new RuntimeException('Too many installation attempts. Try again in ten minutes.');
            $key=(string)(cw_config()['setup_key'] ?? '');
            if (strlen($key)<24 || preg_match('/CHANGE_THIS|YOUR_OWN|YOUR_PRIVATE|EXAMPLE_KEY/i',$key) || !hash_equals($key,(string)($_POST['setup_key'] ?? ''))) $error='Installation key does not match config.php. Use your own private key of at least 24 characters.';
            elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,80}$/',(string)($_POST['username'] ?? ''))) $error='Use a username of 3-80 letters, numbers, dots, dashes or @.';
            elseif (strlen((string)($_POST['password'] ?? ''))<14 || strlen((string)$_POST['password'])>200) $error='Use a password between 14 and 200 characters.';
            else {
                $base=cw_url($_POST['base_url'] ?? '');
                if (!str_ends_with((string)parse_url($base,PHP_URL_PATH),'/index.php')) $error='Dashboard URL must end with /index.php.';
                elseif (!str_starts_with($base,'https://') && empty(cw_config()['allow_http'])) $error='Use HTTPS for the dashboard URL.';
                else {
                    $db=cw_db(); $db->exec('BEGIN IMMEDIATE');
                    try {
                        if (cw_setting('password')!=='') throw new RuntimeException('Installation is already complete. Sign in instead.');
                        cw_setting_set('username',$_POST['username']); cw_setting_set('password',password_hash($_POST['password'],PASSWORD_DEFAULT)); cw_setting_set('base_url',$base); cw_setting_set('retention','90');
                        $db->exec('COMMIT');
                    } catch (Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
                    session_regenerate_id(true); $_SESSION['admin']=true; $_SESSION['auth_version']=cw_setting('auth_version'); $_SESSION['seen']=$_SESSION['created']=time();
                    header('Location: index.php'); exit;
                }
            }
        } elseif ($action==='login' && !$setup) {
            if (!cw_rate('login:'.cw_ip_key(),12,900)) $error='Too many sign-in attempts. Try again in 15 minutes.';
            elseif (cw_setting('hcaptcha_enabled','0')==='1' && !cw_hcaptcha_verify((string)($_POST['h-captcha-response'] ?? ''))) $error='Please complete the hCaptcha check and try again.';
            elseif (hash_equals(cw_setting('username'),(string)($_POST['username'] ?? '')) && password_verify((string)($_POST['password'] ?? ''),cw_setting('password'))) {
                session_regenerate_id(true); $_SESSION['admin']=true; $_SESSION['auth_version']=cw_setting('auth_version'); $_SESSION['seen']=$_SESSION['created']=time();
                header('Location: index.php'); exit;
            } else $error='Username or password is incorrect.';
        }
    }
    if ($setup || !cw_logged_in()) cw_login_view($setup,$error); else cw_dashboard_view();
} catch (Throwable $e) {
    error_log('ClientWatch: '.$e->getMessage());
    if (!empty($_GET['api'])) {
        if ($e instanceof InvalidArgumentException) cw_fail($e->getMessage(),400);
        cw_fail('Server error. Check your PHP error log and ClientWatch configuration.',500);
    }
    http_response_code(503);
    cw_error_view($e instanceof RuntimeException && !$e instanceof PDOException ? $e->getMessage() : 'The application could not open its database. Check your PHP error log, pdo_sqlite extension and private directory permissions.');
}
