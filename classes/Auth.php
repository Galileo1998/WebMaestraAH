<?php
class Auth {
    private $db;
    private $table_name = "users";

    public function __construct($db_connection = null) {
        $this->db = $db_connection;
    }

    public function login($email, $password) {
        $query = "SELECT id, name, password, role FROM " . $this->table_name . " WHERE email = :email LIMIT 1";
        $stmt = $this->db->prepare($query);
        $email = htmlspecialchars(strip_tags($email));
        $stmt->bindParam(":email", $email);
        $stmt->execute();

        if($stmt->rowCount() > 0) {
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if(password_verify($password, $row['password'])) {
                session_regenerate_id(true); 
                $_SESSION['user_id'] = $row['id'];
                $_SESSION['user_name'] = $row['name'];
                $_SESSION['user_role'] = $row['role'];
                $_SESSION['logged_in'] = true;
                $_SESSION['auth_source'] = 'admin';
                $_SESSION['last_activity'] = time();
                $_SESSION['login_ip'] = $_SERVER['REMOTE_ADDR'] ?? '';
                $this->resetLoginAttempts();
                $this->recordSuccessfulLogin((int)$row['id'], (string)$row['name']);
                return true;
            } else {
                $this->recordFailedLogin($email);
            }
        } else {
            $this->recordFailedLogin($email);
        }
        return false;
    }

    // ==========================================
    // NUEVA FUNCIÓN PARA GESTIONAR PERMISOS
    // ==========================================
    public function checkAccess($current_script, $db) {
        // 1. Si estamos en login, no hacemos nada (evita bucles)
        if ($current_script == 'login.php') return true;

        $user_id = $_SESSION['user_id'] ?? 0;
        if (!$user_id) {
            header("Location: login.php");
            exit;
        }

        $stmt = $db->prepare("SELECT role, permissions FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user = $stmt->fetch();

        if (!$user) return false;

        // El Administrador tiene llave maestra a todo
        if ($user['role'] === 'admin') return true; 

        $permissions = !empty($user['permissions']) ? json_decode($user['permissions'], true) : [];
        
        if (!in_array($current_script, $permissions, true)) {
            die("<div style='text-align:center; padding:50px; font-family:sans-serif; background:#f8fafc; height:100vh;'>
                    <h1 style='color:#991b1b;'>Acceso Restringido</h1>
                    <p>Tu usuario no tiene permisos para ver este módulo.</p>
                    <a href='index.php' style='background:#34859B; color:white; padding:10px 20px; text-decoration:none; border-radius:5px;'>Volver al Inicio</a>
                 </div>");
        }
        return true;
    }

    public function requireLogin($allowedSources = ['admin'], $permissionScript = null) {
        if(!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
            header("Location: login.php");
            exit;
        }

        $now = time();
        $lastActivity = (int)($_SESSION['last_activity'] ?? $now);
        if (($now - $lastActivity) > 1800) {
            $this->logout();
        }
        $_SESSION['last_activity'] = $now;

        $source = $_SESSION['auth_source'] ?? '';
        if (!in_array($source, $allowedSources, true)) {
            $this->logout();
        }

        self::enforceSameOriginRequest();

        // Los usuarios del CMS deben conservar permiso explicito para el modulo actual.
        if ($source === 'admin' && $this->db) {
            $script = $permissionScript ?: basename($_SERVER['SCRIPT_NAME'] ?? '');
            $this->checkAccess($script, $this->db);
        }
    }

    public function logout() {
        session_unset();
        session_destroy();
        header("Location: login.php");
        exit;
    }

    public static function generateCSRF() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function checkCSRF($post_token) {
        if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $post_token)) {
            http_response_code(403);
            die("Alerta de Seguridad: Token inválido.");
        }
        return true;
    }

    public static function enforceSameOriginRequest() {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        $expectedHost = strtolower(preg_replace('/:\\d+$/', '', $_SERVER['HTTP_HOST'] ?? ''));
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        $fetchSite = strtolower($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');

        if ($fetchSite === 'cross-site') {
            http_response_code(403);
            die('Solicitud de origen no autorizado.');
        }

        $sourceUrl = $origin !== '' ? $origin : $referer;
        if ($sourceUrl !== '') {
            $sourceHost = strtolower(parse_url($sourceUrl, PHP_URL_HOST) ?? '');
            if ($expectedHost === '' || !hash_equals($expectedHost, $sourceHost)) {
                http_response_code(403);
                die('Solicitud de origen no autorizado.');
            }
            return true;
        }

        // Clientes sin Origin/Referer deben presentar el token tradicional.
        self::checkCSRF($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
        return true;
    }

    public static function secureImageUpload($file, $uploadDir, $maxBytes = 5242880) {
        $result = ['success' => false, 'filename' => null, 'error' => 'No se pudo procesar la imagen.'];
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $result['error'] = 'La carga de la imagen no se completo correctamente.';
            return $result;
        }

        $size = (int)($file['size'] ?? 0);
        if ($size < 1 || $size > $maxBytes) {
            $result['error'] = 'La imagen debe pesar como maximo 5 MB.';
            return $result;
        }

        $tmp = $file['tmp_name'] ?? '';
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            $result['error'] = 'El archivo recibido no es una carga valida.';
            return $result;
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp);
        $allowed = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        if (!isset($allowed[$mime]) || @getimagesize($tmp) === false) {
            $result['error'] = 'El archivo no es una imagen valida o permitida.';
            return $result;
        }

        $uploadsRoot = realpath(dirname(__DIR__) . '/uploads');
        if ($uploadsRoot === false) {
            $result['error'] = 'El directorio de cargas no esta disponible.';
            return $result;
        }
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true)) {
            $result['error'] = 'No se pudo preparar el directorio de imagenes.';
            return $result;
        }
        $targetDir = realpath($uploadDir);
        $rootPrefix = rtrim($uploadsRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($targetDir === false || strpos(rtrim($targetDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR, $rootPrefix) !== 0) {
            $result['error'] = 'El destino de la carga no esta autorizado.';
            return $result;
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $destination = $targetDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $destination)) {
            $result['error'] = 'No se pudo guardar la imagen en el servidor.';
            return $result;
        }
        @chmod($destination, 0644);
        return ['success' => true, 'filename' => $filename, 'error' => null];
    }

    public function isIpBlocked() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $query = "SELECT attempt_count, last_attempt FROM login_attempts WHERE ip_address = :ip LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute(['ip' => $ip]);
        
        if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fallos = (int)$row['attempt_count'];
            $ultimo_intento = strtotime($row['last_attempt']);
            $tiempo_actual = time();
            
            if ($fallos >= 5) {
                if (($tiempo_actual - $ultimo_intento) < 900) return true; 
                else $this->resetLoginAttempts();
            }
        }
        return false;
    }

    public function recordFailedLogin($email) {
        $ip = $_SERVER['REMOTE_ADDR'];
        $fecha_actual = date('Y-m-d H:i:s');
        
        $query = "INSERT INTO login_attempts (ip_address, email_attempt, last_attempt, attempt_count) 
                  VALUES (:ip, :email, :fecha, 1) 
                  ON DUPLICATE KEY UPDATE 
                  email_attempt = :email_upd, 
                  last_attempt = :fecha_upd, 
                  attempt_count = attempt_count + 1";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindValue(':ip', $ip);
        $stmt->bindValue(':email', $email);
        $stmt->bindValue(':fecha', $fecha_actual);
        $stmt->bindValue(':email_upd', $email);
        $stmt->bindValue(':fecha_upd', $fecha_actual);
        $stmt->execute();
    }

    public function resetLoginAttempts() {
        $ip = $_SERVER['REMOTE_ADDR'];
        $stmt = $this->db->prepare("DELETE FROM login_attempts WHERE ip_address = :ip");
        $stmt->execute(['ip' => $ip]);
    }

    private function recordSuccessfulLogin(int $userId, string $name): void {
        $ip = (string)($_SERVER['REMOTE_ADDR'] ?? 'desconocida');
        $agent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        try {
            $this->db->exec("CREATE TABLE IF NOT EXISTS ah_security_login_events (
                id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                ip_address VARCHAR(45) NOT NULL,
                user_agent VARCHAR(500) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_login_user_date (user_id, created_at),
                INDEX idx_login_ip_date (ip_address, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            $stmt = $this->db->prepare('INSERT INTO ah_security_login_events (user_id, ip_address, user_agent) VALUES (?, ?, ?)');
            $stmt->execute([$userId, $ip, $agent]);
        } catch (Throwable $e) {
            error_log('No fue posible registrar el acceso administrativo: ' . $e->getMessage());
        }

        $alertEmail = trim((string)getenv('AH_SECURITY_ALERT_EMAIL'));
        $secretsFile = dirname(__DIR__, 2) . '/.accionhonduras_secrets.php';
        if ($alertEmail === '' && is_file($secretsFile)) {
            $secrets = require $secretsFile;
            if (is_array($secrets)) {
                $alertEmail = trim((string)($secrets['AH_SECURITY_ALERT_EMAIL'] ?? ''));
            }
        }
        if ($alertEmail !== '' && filter_var($alertEmail, FILTER_VALIDATE_EMAIL)) {
            $subject = 'Acceso administrativo - Accion Honduras';
            $message = "Usuario: {$name}\nIP: {$ip}\nFecha: " . date('c') . "\nNavegador: {$agent}";
            @mail($alertEmail, $subject, $message, 'Content-Type: text/plain; charset=UTF-8');
        }
    }
}
?>
