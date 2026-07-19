<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$projectRootCandidates = [
    dirname(__DIR__, 3),
    dirname(__DIR__, 2),
    dirname(__DIR__),
];

$databaseFile = null;
foreach ($projectRootCandidates as $candidate) {
    $path = $candidate . '/config/Database.php';
    if (is_file($path)) {
        $databaseFile = $path;
        break;
    }
}
if (!$databaseFile) {
    throw new RuntimeException('No se encontró config/Database.php. Ajuste la ruta en includes/bootstrap.php.');
}
require_once $databaseFile;

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

function form_json_decode(?string $value, array $default = []): array {
    if ($value === null || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function form_json_encode($value): string {
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('No se pudo convertir la información a JSON.');
    return $json;
}

function form_csrf_token(): string {
    if (empty($_SESSION['form_csrf'])) {
        $_SESSION['form_csrf'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['form_csrf'];
}

function form_validate_csrf(?string $token): void {
    if (!$token || !hash_equals(form_csrf_token(), $token)) {
        throw new RuntimeException('La sesión del formulario expiró. Recargue la página.');
    }
}

function form_current_user_id(): ?int {
    foreach (['user_id','usuario_id','id_usuario','id'] as $key) {
        if (isset($_SESSION[$key]) && is_numeric($_SESSION[$key])) return (int)$_SESSION[$key];
    }
    if (isset($_SESSION['user']) && is_array($_SESSION['user']) && isset($_SESSION['user']['id']) && is_numeric($_SESSION['user']['id'])) {
        return (int)$_SESSION['user']['id'];
    }
    return null;
}

function form_slugify(string $text): string {
    $text = trim(mb_strtolower($text, 'UTF-8'));
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = preg_replace('/[^a-z0-9]+/', '-', $text) ?: '';
    $text = trim($text, '-');
    return $text !== '' ? $text : 'formulario-' . bin2hex(random_bytes(3));
}

function form_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function form_require_admin_auth(PDO $db): void {
    $authFile = dirname($GLOBALS['databaseFile'] ?? '') . '/../classes/Auth.php';
    if (is_file($authFile)) {
        require_once $authFile;
        $auth = new Auth($db);
        $auth->requireLogin();
    } elseif (!form_current_user_id()) {
        http_response_code(401);
        exit('Debe iniciar sesión.');
    }
}

function form_table_exists(PDO $db, string $table): bool {
    try {
        $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        $stmt->execute([$table]);
        return (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function form_assert_installed(PDO $db): void {
    foreach (['ah_forms','ah_form_sections','ah_form_questions','ah_form_responses','ah_form_answers'] as $table) {
        if (!form_table_exists($db, $table)) {
            throw new RuntimeException('El sistema de formularios no está instalado. Importe sql/instalar_formularios.sql desde phpMyAdmin.');
        }
    }
}
