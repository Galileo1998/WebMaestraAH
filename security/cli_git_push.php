<?php
declare(strict_types=1);
require_once __DIR__ . '/cli_bootstrap.php';
$message = '';
foreach ($argv as $arg) if (str_starts_with($arg,'--message=')) $message = substr($arg,10);
try {
    set_time_limit(0);
    echo json_encode($security->backupCommitPush('CRON/CLI',$message), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
