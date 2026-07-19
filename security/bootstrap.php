<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');

require_once dirname(__DIR__) . '/config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once __DIR__ . '/SecurityService.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$auth = new Auth($db);
$auth->requireLogin();

/**
 * El login del sitio guarda principalmente user_id, pero no siempre coloca
 * el rol dentro de $_SESSION. Por eso el permiso del antivirus se valida
 * contra users.role, que en el sistema usa los valores admin/editor.
 */
$sessionUser = is_array($_SESSION['user'] ?? null) ? $_SESSION['user'] : [];

$userId = 0;
foreach ([
    $_SESSION['user_id'] ?? null,
    $_SESSION['usuario_id'] ?? null,
    $_SESSION['id_usuario'] ?? null,
    $_SESSION['id'] ?? null,
    $sessionUser['id'] ?? null,
] as $candidate) {
    if (is_numeric($candidate) && (int)$candidate > 0) {
        $userId = (int)$candidate;
        break;
    }
}

$sessionEmail = trim((string)(
    $_SESSION['email']
    ?? $_SESSION['user_email']
    ?? $_SESSION['correo']
    ?? $sessionUser['email']
    ?? $sessionUser['correo']
    ?? ''
));

$dbUser = null;

try {
    if ($userId > 0) {
        $st = $db->prepare(
            'SELECT id, name, email, role FROM users WHERE id = ? LIMIT 1'
        );
        $st->execute([$userId]);
        $dbUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if (!$dbUser && $sessionEmail !== '') {
        $st = $db->prepare(
            'SELECT id, name, email, role FROM users WHERE email = ? LIMIT 1'
        );
        $st->execute([$sessionEmail]);
        $dbUser = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('No fue posible verificar los permisos del administrador.');
}

$sessionRole = trim((string)(
    $_SESSION['rol']
    ?? $_SESSION['role']
    ?? $sessionUser['rol']
    ?? $sessionUser['role']
    ?? ''
));

$dbRole = trim((string)($dbUser['role'] ?? ''));

$normalizeRole = static function (string $role): string {
    $role = mb_strtolower(trim($role), 'UTF-8');
    $role = str_replace(
        ['á','é','í','ó','ú','ñ','ü',' ', '-', '_'],
        ['a','e','i','o','u','n','u','','',''],
        $role
    );
    return $role;
};

$allowedRoles = [
    'admin',
    'administrador',
    'superadministrador',
    'superadmin',
    'superusuario',
];

$isAdmin = in_array($normalizeRole($dbRole), $allowedRoles, true)
    || in_array($normalizeRole($sessionRole), $allowedRoles, true);

if (!$isAdmin) {
    http_response_code(403);
    $detected = $dbRole !== '' ? $dbRole : ($sessionRole !== '' ? $sessionRole : 'sin rol');
    exit(
        'Acceso restringido únicamente a administradores. Rol detectado: ' .
        htmlspecialchars($detected, ENT_QUOTES, 'UTF-8')
    );
}

/*
 * Normaliza la sesión para que seguridad.php y el sidebar reconozcan
 * al administrador de la misma manera que los demás módulos.
 */
$_SESSION['user_id'] = (int)($dbUser['id'] ?? $userId);
$_SESSION['name'] = trim((string)($dbUser['name'] ?? ($_SESSION['name'] ?? 'Administrador')));
$_SESSION['email'] = trim((string)($dbUser['email'] ?? $sessionEmail));
$_SESSION['role'] = 'admin';
$_SESSION['rol'] = 'Administrador';

$configFile = __DIR__ . '/config.local.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Falta el archivo security/config.local.php');
}

$securityConfig = require $configFile;
$security = new SecurityService($db, $securityConfig);

$userName = trim((string)($_SESSION['name'] ?? 'Administrador'));
$userEmail = trim((string)($_SESSION['email'] ?? ''));

$securityUserLabel = $userName !== '' ? $userName : 'Administrador';
if ($userEmail !== '') {
    $securityUserLabel .= ' <' . $userEmail . '>';
}

function ahSecurityCsrfToken(): string
{
    if (empty($_SESSION['ah_security_csrf'])) {
        $_SESSION['ah_security_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['ah_security_csrf'];
}

function ahSecurityVerifyCsrf(): void
{
    $received = (string)($_POST['csrf'] ?? '');
    if ($received === '' || !hash_equals(ahSecurityCsrfToken(), $received)) {
        throw new RuntimeException('La sesión de seguridad venció. Recargue la página.');
    }
}
