<?php
// ========================================================================
// 1. BLINDAJE GLOBAL DE SESIONES (Seguridad contra secuestro y XSS)
// ========================================================================

// Solo intentamos modificar la configuración si la sesión NO ha sido iniciada aún.
if (session_status() === PHP_SESSION_NONE) {
    // Evita que JavaScript malicioso pueda leer la cookie de sesión (Bloquea ataques XSS)
    ini_set('session.cookie_httponly', 1);

    // Obliga a PHP a usar solo cookies para el ID de sesión (Evita fijación de sesión por URL)
    ini_set('session.use_only_cookies', 1);
    ini_set('session.use_strict_mode', 1);

    // Previene que el navegador envíe la cookie en peticiones cruzadas (Mitiga ataques CSRF)
    ini_set('session.cookie_samesite', 'Lax');
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
    ini_set('session.cookie_secure', $isHttps ? '1' : '0');
}

// ========================================================================
// 1.5 CABECERAS DE SEGURIDAD (Prevención XSS y Clickjacking)
// ========================================================================

if (session_status() === PHP_SESSION_ACTIVE || session_status() === PHP_SESSION_NONE) {
    // Evita que tu sitio sea incrustado en un <iframe> (Ataques de Clickjacking)
    header("X-Frame-Options: SAMEORIGIN");
    
    // Evita que el navegador intente adivinar el tipo de archivo (MIME Sniffing)
    header("X-Content-Type-Options: nosniff");
    
    // Filtro contra Cross-Site Scripting (XSS) en navegadores antiguos
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()");
    header("Cross-Origin-Opener-Policy: same-origin");
    
    // Content Security Policy (CSP): La regla suprema. 
    // Le dice al navegador que solo cargue recursos de tu dominio, de Google Fonts, FontAwesome y Unsplash.
    // Bloquea cualquier script de un servidor hacker externo.
// Content Security Policy (CSP) ACTUALIZADA: 
    // Ahora incluye permisos para los mapas de Leaflet (unpkg.com) y sus recursos visuales (cartocdn, githubusercontent).
// Content Security Policy (CSP) FINAL:
// Content Security Policy (CSP) FINAL:
    header("Content-Security-Policy: default-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'; object-src 'none'; connect-src 'self' https://raw.githubusercontent.com wss://*.kaspersky-labs.com; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://unpkg.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data: https://images.unsplash.com https://*.unsplash.com https://*.cartocdn.com https://raw.githubusercontent.com https://cdnjs.cloudflare.com https://unpkg.com;");
}
// ========================================================================
// 2. CLASE DE BASE DE DATOS PROTEGIDA
// ========================================================================
class Database {
    private $host;
    private $db_name;
    private $username;
    private $password;
    public $conn;

    public function __construct() {
        $secrets = [];
        $secretsFile = dirname(__DIR__, 2) . '/.accionhonduras_secrets.php';
        $localFile = __DIR__ . '/Database.local.php';

        foreach ([$secretsFile, $localFile] as $file) {
            if (is_file($file)) {
                $loaded = require $file;
                if (is_array($loaded)) {
                    $secrets = array_merge($secrets, $loaded);
                }
            }
        }

        $this->host = (string)(getenv('AH_DB_HOST') ?: ($secrets['AH_DB_HOST'] ?? 'localhost'));
        $this->db_name = (string)(getenv('AH_DB_NAME') ?: ($secrets['AH_DB_NAME'] ?? ''));
        $this->username = (string)(getenv('AH_DB_USER') ?: ($secrets['AH_DB_USER'] ?? ''));
        $this->password = (string)(getenv('AH_DB_PASS') ?: ($secrets['AH_DB_PASS'] ?? ''));

        if ($this->db_name === '' || $this->username === '' || $this->password === '') {
            throw new RuntimeException('La configuraciÃ³n privada de base de datos no estÃ¡ disponible.');
        }
    }

    public function getConnection() {
        $this->conn = null;
        try {
            $this->conn = new PDO("mysql:host=" . $this->host . ";dbname=" . $this->db_name . ";charset=utf8mb4", $this->username, $this->password, [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ]);
            
            // Modo de errores estricto para manejo interno
            $this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            
            // DEFENSA SQL AVANZADA: Desactiva la emulación de consultas.
            $this->conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); 
            
            $this->conn->exec("set names utf8mb4");
            
        } catch(PDOException $exception) {
            error_log("Error crítico de BD: " . $exception->getMessage());
            die("Error de conexión al sistema. Por favor, intente más tarde.");
        }
        return $this->conn;
    }
}
?>
