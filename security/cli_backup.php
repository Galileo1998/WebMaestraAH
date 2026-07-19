<?php
declare(strict_types=1);
require_once __DIR__ . '/cli_bootstrap.php';
try {
    set_time_limit(0);
    echo json_encode($security->createCodeBackup('CRON/CLI','Respaldo programado'), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
