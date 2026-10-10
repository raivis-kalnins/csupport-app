<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/lib/core.php';
try {
    cw_db();if(cw_setting('password')===''){fwrite(STDERR,"Complete installation in the browser first.\n");exit(1);}
    fwrite(STDOUT,"Enter a new admin password (14-200 characters). Terminal input may be visible:\n");
    $password=rtrim((string)fgets(STDIN),"\r\n");
    if(strlen($password)<14||strlen($password)>200){fwrite(STDERR,"Invalid password length.\n");exit(1);}
    cw_setting_set('password',password_hash($password,PASSWORD_DEFAULT));
    // Invalidate existing admin sessions by changing the credential fingerprint.
    cw_setting_set('auth_version',bin2hex(random_bytes(16)));
    fwrite(STDOUT,"Administrator password changed. Existing sessions will need to sign in again.\n");
} catch(Throwable $e){fwrite(STDERR,"Password reset failed: ".$e->getMessage()."\n");exit(1);}
