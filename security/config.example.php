<?php
declare(strict_types=1);

return [
    'root_path' => realpath(dirname(__DIR__)) ?: dirname(__DIR__),
    'storage_path' => dirname(dirname(__DIR__)) . '/accion_security_storage',
    'cron_token' => 'CAMBIE_ESTE_TOKEN',
    'scan' => [
        'max_content_bytes' => 8388608,
        'web_file_limit' => 12000,
        'clamav_enabled' => true,
        'clamav_binary' => 'clamscan',
        'exclude_paths' => ['.git','node_modules','vendor','uploads/formularios','cache','tmp','logs'],
        'upload_like_paths' => ['uploads','images','img','media','archivos','documentos'],
    ],
    'backup' => [
        'extensions' => ['php','phtml','inc','js','css','html','json','xml','sql','md','txt','yml','yaml','ini','conf'],
        'special_files' => ['.htaccess','.gitignore','composer.json','composer.lock'],
        'exclude_paths' => ['.git','node_modules','vendor','uploads','cache','tmp','logs'],
        'keep_last' => 12,
    ],
    'git' => [
        'enabled' => true,
        'binary' => 'git',
        'remote' => 'origin',
        'block_push_on_high_findings' => true,
        'user_name' => 'Acción Honduras Security',
        'user_email' => 'sistemas@accionhonduras.org',
    ],
    'notifications' => ['emails' => [], 'from' => 'seguridad@accionhonduras.org'],
];
