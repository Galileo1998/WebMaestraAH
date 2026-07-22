<?php
declare(strict_types=1);

define('AH_MONITOREO_V2', true);
$_SERVER['PHP_SELF'] = '/admin/monitoreo.php';

ob_start();
require __DIR__ . '/monitoreo.php';
$html = (string)ob_get_clean();
$html = str_replace('</head>', '<link rel="stylesheet" href="../assets/css/monitoreo-v2.css?v=1"></head>', $html);
$html = str_replace('</body>', '<script src="../assets/js/monitoreo-v2.js?v=1" defer></script></body>', $html);
echo $html;
