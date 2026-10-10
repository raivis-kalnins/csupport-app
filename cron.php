<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require __DIR__.'/lib/monitor.php';
try {
    cw_db();$lock=fopen(cw_config()['data_dir'].'/cron.lock','c');
    if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)){fwrite(STDERR,"Another ClientWatch cron process is running.\n");exit(0);}
    cw_setting_set('cron_at',(string)time());
    if(extension_loaded('curl'))foreach(cw_q('SELECT * FROM sites WHERE monitor=1 AND checked_at<? ORDER BY checked_at LIMIT 30',[time()-240])->fetchAll() as $site){
        try{$result=cw_check_site($site);echo $site['name'].': '.$result['status'].' ('.$result['code'].")\n";}catch(Throwable $e){fwrite(STDERR,$site['name'].": check failed. See PHP log.\n");error_log($e->getMessage());}
    }
    else fwrite(STDERR,"PHP cURL is missing. HTTP checks skipped.\n");
    cw_cleanup();cw_send_alerts();flock($lock,LOCK_UN);fclose($lock);
    echo "Cleanup and notification processing complete.\n";
} catch(Throwable $e){fwrite(STDERR,'ClientWatch cron: '.$e->getMessage()."\n");exit(1);}
