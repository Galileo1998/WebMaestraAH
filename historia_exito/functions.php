<?php
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('La sesión del formulario venció. Regrese y vuelva a intentarlo.');
    }
}

function normalize_date(?string $date): ?string
{
    if (!$date) return null;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date ? $date : null;
}

function upload_media(string $field, ?string $existingPath = null): ?array
{
    $config = require __DIR__ . '/config.php';
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        return $existingPath ? ['path' => $existingPath, 'name' => null] : null;
    }

    $file = $_FILES[$field];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('No fue posible cargar uno de los archivos.');
    }
    if ($file['size'] > $config['max_upload_bytes']) {
        throw new RuntimeException('Cada archivo debe pesar como máximo 15 MB.');
    }

    $allowed = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'video/mp4' => 'mp4', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file($file['tmp_name']);
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Tipo de archivo no permitido. Use imagen, MP4, audio, PDF, DOCX o XLSX.');
    }

    if (!is_dir($config['upload_dir']) && !mkdir($config['upload_dir'], 0755, true)) {
        throw new RuntimeException('No se pudo crear la carpeta de archivos.');
    }

    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $allowed[$mime];
    $destination = $config['upload_dir'] . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('No se pudo guardar el archivo cargado.');
    }

    if ($existingPath && str_starts_with($existingPath, 'uploads/')) {
        $old = __DIR__ . '/' . $existingPath;
        if (is_file($old)) @unlink($old);
    }

    return ['path' => 'uploads/' . $filename, 'name' => basename($file['name'])];
}

function is_image_path(?string $path): bool
{
    return (bool)preg_match('/\.(jpe?g|png|webp)$/i', (string)$path);
}

function record_or_404(PDO $pdo, int $id): array
{
    $stmt = $pdo->prepare('SELECT * FROM magic_moments WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        http_response_code(404);
        exit('Registro no encontrado.');
    }
    return $row;
}
