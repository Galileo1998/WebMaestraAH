<?php
// =================================================================
// ARCHIVO: api/procesar_contacto.php
// ENDPOINT API - BLINDADO CONTRA BOTS (HONEYPOT + RATE LIMITING)
// =================================================================

header("Content-Type: application/json; charset=UTF-8");
header("Cache-Control: no-cache, must-revalidate");

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método de petición no permitido.']);
    exit;
}

// 1. DEFENSA HONEYPOT (Trampa de miel)
// Si el bot llenó este campo invisible, lo engañamos diciendo que fue un éxito y cortamos la ejecución.
$honeypot = isset($_POST['website_url']) ? $_POST['website_url'] : '';
if (!empty($honeypot)) {
    echo json_encode(['success' => '¡Tu mensaje ha sido enviado y registrado con éxito!']);
    exit;
}

// 2. Limpieza y captura de datos legítimos
$name    = isset($_POST['name']) ? trim($_POST['name']) : '';
$email   = isset($_POST['email']) ? trim($_POST['email']) : '';
$subject = isset($_POST['subject']) ? trim($_POST['subject']) : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$ip_address = $_SERVER['REMOTE_ADDR'];

// 3. Validaciones estructurales
if (empty($name) || empty($email) || empty($message)) {
    echo json_encode(['error' => 'Por favor, completa todos los campos requeridos.']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['error' => 'El correo electrónico no tiene un formato válido.']);
    exit;
}

try {
    // 4. CONEXIÓN A LA BASE DE DATOS
    require_once __DIR__ . '/../config/Database.php';
    $database = new Database();
    $db = $database->getConnection();

    // 5. DEFENSA ANTI-SPAM (Rate Limiting por IP)
    // Contamos cuántos mensajes ha enviado esta misma IP en los últimos 15 minutos
    $stmt_check = $db->prepare("SELECT COUNT(*) FROM contact_messages WHERE ip_address = :ip AND created_at > (NOW() - INTERVAL 15 MINUTE)");
    $stmt_check->execute([':ip' => $ip_address]);
    $intentos_recientes = $stmt_check->fetchColumn();

    if ($intentos_recientes >= 3) {
        echo json_encode(['error' => 'Has enviado demasiados mensajes recientemente. Por favor, intenta de nuevo en unos minutos.']);
        exit;
    }

    // 6. INSERCIÓN LEGÍTIMA
    $query = "INSERT INTO contact_messages (name, email, subject, message, ip_address) 
              VALUES (:name, :email, :subject, :message, :ip_address)";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':name'       => $name,
        ':email'      => $email,
        ':subject'    => !empty($subject) ? $subject : NULL,
        ':message'    => $message,
        ':ip_address' => $ip_address
    ]);

    // 7. ENVÍO DE NOTIFICACIÓN (Opcional, asegurado)
    $to = "contacto@accionhonduras.org"; 
    $email_subject = "Nuevo Mensaje Web: " . ($subject ? $subject : 'Contacto General');
    $email_body = "Se ha registrado un nuevo mensaje en la base de datos.\n\n".
                  "Nombre: $name\n".
                  "Correo: $email\n".
                  "IP del usuario: $ip_address\n\n".
                  "Mensaje:\n$message\n";
                  
    $headers = "From: no-reply@accionhonduras.org\r\n" .
               "Reply-To: $email\r\n" .
               "X-Mailer: PHP/" . phpversion();

    @mail($to, $email_subject, $email_body, $headers); 

    echo json_encode(['success' => '¡Tu mensaje ha sido enviado y registrado con éxito!']);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Fallo de base de datos. Por favor, intenta más tarde.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor. Por favor, intenta más tarde.']);
}
exit;