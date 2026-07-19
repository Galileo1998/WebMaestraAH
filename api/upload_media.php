<?php
session_start();

header('Content-Type: application/json; charset=utf-8');
require_once '../classes/Auth.php';

// Verificar sesión
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['logged_in'] !== true
) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'No autorizado.'
    ]);
    exit;
}

if (($_SESSION['auth_source'] ?? '') !== 'admin') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Origen de sesiÃ³n no autorizado.']);
    exit;
}
Auth::enforceSameOriginRequest();

// Verificar método y archivo
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['file'])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'No se recibió ningún archivo.'
    ]);
    exit;
}

$file = $_FILES['file'];

// Verificar errores de subida
if ($file['error'] !== UPLOAD_ERR_OK) {
    $upload_errors = [
        UPLOAD_ERR_INI_SIZE   => 'El archivo supera el tamaño permitido por el servidor.',
        UPLOAD_ERR_FORM_SIZE  => 'El archivo supera el tamaño permitido por el formulario.',
        UPLOAD_ERR_PARTIAL    => 'El archivo se subió parcialmente.',
        UPLOAD_ERR_NO_FILE    => 'No se seleccionó ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'No existe la carpeta temporal del servidor.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo.',
        UPLOAD_ERR_EXTENSION  => 'Una extensión de PHP detuvo la subida.'
    ];

    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $upload_errors[$file['error']] ?? 'Error desconocido al subir el archivo.'
    ]);
    exit;
}

// Limitar tamaño: 10 MB
$max_size = 10 * 1024 * 1024;

if ($file['size'] > $max_size) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'La imagen supera el tamaño máximo permitido de 10 MB.'
    ]);
    exit;
}

// Comprobar que el archivo temporal exista
if (
    empty($file['tmp_name']) ||
    !is_uploaded_file($file['tmp_name'])
) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'El archivo temporal no es válido.'
    ]);
    exit;
}

// Detectar el MIME real del archivo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime_type = $finfo->file($file['tmp_name']);

$allowed_types = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/gif'  => 'gif',
    'image/webp' => 'webp'
];

if (!isset($allowed_types[$mime_type])) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Formato no permitido. Tipo detectado: ' . $mime_type .
                   '. Solo se permiten JPG, PNG, GIF y WEBP.'
    ]);
    exit;
}

// Verificación adicional: comprobar que realmente sea una imagen
$image_info = @getimagesize($file['tmp_name']);

if ($image_info === false) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'El archivo seleccionado no es una imagen válida.'
    ]);
    exit;
}

// Ruta física de almacenamiento
$upload_dir = dirname(__DIR__) . '/uploads/images/';

// Crear carpeta si no existe
if (!is_dir($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'No se pudo crear la carpeta de imágenes.'
        ]);
        exit;
    }
}

// Verificar permisos
if (!is_writable($upload_dir)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'La carpeta uploads/images no tiene permisos de escritura.'
    ]);
    exit;
}

// Generar nombre seguro y único
$extension = $allowed_types[$mime_type];

$filename = date('Ymd_His')
    . '_'
    . bin2hex(random_bytes(8))
    . '.'
    . $extension;

$target_path = $upload_dir . $filename;

// Mover archivo
if (!move_uploaded_file($file['tmp_name'], $target_path)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'No se pudo guardar la imagen en el servidor.'
    ]);
    exit;
}

// URL pública que se guardará en la base de datos
$image_url = '/uploads/images/' . $filename;

echo json_encode([
    'success' => true,
    'url' => $image_url,
    'mime' => $mime_type,
    'filename' => $filename
]);
