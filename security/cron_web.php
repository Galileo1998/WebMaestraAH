<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/config/Database.php';
require_once __DIR__ . '/SecurityService.php';
$config = require __DIR__ . '/config.local.php';
$received = (string)($_SERVER['HTTP_X_SECURITY_TOKEN'] ?? $_GET['token'] ?? '');
if ($received === '' || !hash_equals((string)$config['cron_token'], $received)) { http_response_code(403); exit('Forbidden'); }
$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$security = new SecurityService($db,$config);
header('Content-Type: application/json; charset=utf-8');
try {
    set_time_limit(0);
    $task = (string)($_GET['task'] ?? 'quick');
    $result = $task === 'backup'
        ? $security->createCodeBackup('CRON WEB','Respaldo programado')
        : $security->scan($task === 'full' ? 'full' : 'quick','CRON WEB',true);
    echo json_encode(['status'=>'ok','result'=>$result],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','msg'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);
}
