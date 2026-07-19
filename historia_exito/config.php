<?php
// Puede usar variables de entorno o editar estos valores.
return [
    'db' => [
        'host' => getenv('DB_HOST') ?: 'localhost',
        'port' => getenv('DB_PORT') ?: '3306',
        'name' => getenv('DB_NAME') ?: 'accion_honduras',
        'user' => getenv('DB_USER') ?: 'root',
        'pass' => getenv('DB_PASS') ?: '',
        'charset' => 'utf8mb4',
    ],
    'upload_dir' => __DIR__ . '/uploads',
    'max_upload_bytes' => 15 * 1024 * 1024,
];
