<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Solo CLI'); }
require_once dirname(__DIR__) . '/config/Database.php';
require_once __DIR__ . '/SecurityService.php';
$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) { fwrite(STDERR, "Falta security/config.local.php\n"); exit(1); }
$securityConfig = require $configFile;
$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$security = new SecurityService($db, $securityConfig);
