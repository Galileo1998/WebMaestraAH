<?php
declare(strict_types=1);
require_once __DIR__ . '/cli_bootstrap.php';
$mode = in_array('--mode=full', $argv, true) ? 'full' : 'quick';
try {
    set_time_limit(0);
    $result = $security->scan($mode, 'CRON/CLI', true);
    echo json_encode($result, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(($result['severity']['critical'] + $result['severity']['high']) > 0 ? 2 : 0);
} catch (Throwable $e) { fwrite(STDERR, $e->getMessage().PHP_EOL); exit(1); }
