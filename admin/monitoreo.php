<?php
// BUILD: MONITOREO_OPERATIVO_V3_FULL_CATALOG_SYNC
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth = new Auth($db);
$auth->requireLogin();
$auth->checkAccess(basename($_SERVER['PHP_SELF']), $db);

if (empty($_SESSION['monitoreo_csrf'])) {
    $_SESSION['monitoreo_csrf'] = bin2hex(random_bytes(32));
}
$monitoreoCsrf = (string)$_SESSION['monitoreo_csrf'];

$msg = '';
$tabla_poa = 'ah_poa';
$col_id = 'id';

$CATALOG_PROGRAMS = [
    'GENERAL' => 'GENERAL / TODOS',
    'CRECER' => 'CRECER',
    'REDES' => 'REDES',
    'TEJIENDO_MI_FUTURO' => 'TEJIENDO MI FUTURO',
    'ML_MONITOREO' => 'ML_MONITOREO',
    'X_PATROCINIO' => 'X_PATROCINIO',
    'Z_ADMINISTRACION' => 'Z_ADMINISTRACION',
];

$CATALOG_STAGES = [
    'TODAS' => 'TODAS LAS ETAPAS',
    'E-1' => 'E-1 · Diseñar actividad',
    'E-2' => 'E-2 · Organizar y socializar',
    'E-3' => 'E-3 · Desarrollar y reportar',
    'E-4' => 'E-4 · Asistencia y monitoreo',
];

function addColIfNotExists(PDO $db, string $table, string $col, string $def): void {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE " . $db->quote($col));
        if ($stmt && $stmt->rowCount() == 0) {
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        }
    } catch (Throwable $e) {}
}

function safe_json_decode($value, $default = []) {
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function poa_codigo_corto($valor): string {
    $valor = trim((string)$valor);
    if ($valor === '') return '';
    $partes = preg_split('/\s+/', $valor);
    return $partes[0] ?? $valor;
}

function metasEditUnlocked(): bool {
    return isset($_SESSION['metas_edit_unlocked_until']) && (int)$_SESSION['metas_edit_unlocked_until'] >= time();
}

function getCurrentUserCredential(PDO $db): ?array {
    $idCandidates = [$_SESSION['user_id'] ?? null, $_SESSION['usuario_id'] ?? null, $_SESSION['id_usuario'] ?? null, $_SESSION['id'] ?? null, is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? null) : null];
    foreach ($idCandidates as $id) {
        if ($id !== null && $id !== '' && is_numeric($id)) {
            $st = $db->prepare("SELECT id, email, password FROM users WHERE id = ? LIMIT 1");
            $st->execute([(int)$id]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
        }
    }
    $emailCandidates = [$_SESSION['email'] ?? null, $_SESSION['user_email'] ?? null, $_SESSION['correo'] ?? null, is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? null) : null];
    foreach ($emailCandidates as $email) {
        $email = trim((string)$email);
        if ($email !== '') {
            $st = $db->prepare("SELECT id, email, password FROM users WHERE email = ? LIMIT 1");
            $st->execute([$email]);
            if ($row = $st->fetch(PDO::FETCH_ASSOC)) return $row;
        }
    }
    return null;
}

function monitoreoCurrentUserLabel(): string {
    $candidates = [
        $_SESSION['email'] ?? null,
        $_SESSION['user_email'] ?? null,
        $_SESSION['correo'] ?? null,
        $_SESSION['name'] ?? null,
        $_SESSION['nombre'] ?? null,
        is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['email'] ?? ($_SESSION['user']['nombre'] ?? null)) : null
    ];
    foreach ($candidates as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return 'Usuario autenticado';
}

function captureActivitySnapshot(PDO $db, int $idPoa): array {
    $st = $db->prepare("SELECT * FROM ah_poa WHERE id = ? LIMIT 1");
    $st->execute([$idPoa]);
    $poa = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $st = $db->prepare("SELECT * FROM ah_poa_asignaciones WHERE id_poa = ? ORDER BY id ASC");
    $st->execute([$idPoa]);
    $asignaciones = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? ORDER BY orden ASC, id ASC");
    $st->execute([$idPoa]);
    $etapas = $st->fetchAll(PDO::FETCH_ASSOC);

    return [
        'poa' => $poa,
        'asignaciones' => $asignaciones,
        'etapas' => $etapas
    ];
}

function saveActivitySnapshot(PDO $db, int $idPoa, string $evento): void {
    if ($idPoa <= 0) return;
    try {
        $estado = captureActivitySnapshot($db, $idPoa);
        $json = json_encode($estado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) return;

        $hash = hash('sha256', $json);
        $usuario = monitoreoCurrentUserLabel();
        $periodo = date('Y-m');

        $last = $db->prepare("SELECT id, estado_hash, evento, created_at FROM ah_monitoreo_historial WHERE id_poa = ? ORDER BY id DESC LIMIT 1");
        $last->execute([$idPoa]);
        $row = $last->fetch(PDO::FETCH_ASSOC);

        if ($row && hash_equals((string)$row['estado_hash'], $hash)) return;

        $isRecentSameEvent = false;
        if ($row && (string)$row['evento'] === $evento && !empty($row['created_at'])) {
            $isRecentSameEvent = strtotime((string)$row['created_at']) >= (time() - 120);
        }

        if ($row && $isRecentSameEvent) {
            $upd = $db->prepare("UPDATE ah_monitoreo_historial SET periodo = ?, estado_json = ?, estado_hash = ?, usuario = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$periodo, $json, $hash, $usuario, (int)$row['id']]);
        } else {
            $ins = $db->prepare("INSERT INTO ah_monitoreo_historial (id_poa, periodo, evento, estado_json, estado_hash, usuario) VALUES (?, ?, ?, ?, ?, ?)");
            $ins->execute([$idPoa, $periodo, $evento, $json, $hash, $usuario]);
        }
    } catch (Throwable $e) {}
}

$monitoreoSchemaVersion = 0;
try {
    $monitoreoSchemaVersion = (int)$db->query('SELECT version FROM ah_monitoreo_schema WHERE id=1')->fetchColumn();
} catch (Throwable $missingSchemaVersion) {}

if ($monitoreoSchemaVersion < 1) {
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_monitoreo_historial (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        id_poa INT NOT NULL,
        periodo VARCHAR(7) NOT NULL,
        evento VARCHAR(80) NOT NULL,
        estado_json LONGTEXT NOT NULL,
        estado_hash CHAR(64) NOT NULL,
        usuario VARCHAR(190) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_hist_poa_fecha (id_poa, created_at),
        INDEX idx_hist_poa_periodo (id_poa, periodo)
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ah_tecnicos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre VARCHAR(150),
        identidad VARCHAR(50) UNIQUE,
        activo TINYINT(1) DEFAULT 1
    )");

    $db->exec("CREATE TABLE IF NOT EXISTS ah_cat_responsables (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL UNIQUE)");
    $db->exec("CREATE TABLE IF NOT EXISTS ah_cat_unidades (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL UNIQUE)");
    $db->exec("CREATE TABLE IF NOT EXISTS ah_cat_verificaciones (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL UNIQUE)");
    $db->exec("CREATE TABLE IF NOT EXISTS ah_cat_lugares (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(150) NOT NULL UNIQUE)");

    $default_resps = ['Lider-CRECER', 'Lider-REDES', 'Lider-Tejiendo-Futuro', 'Monitoreo', 'Coordinador Programas', 'Jardineras', 'Docentes', 'Líderes', 'Profesional de la Salud', 'Otros'];
    $default_unis = ['Caja de Herramientas', 'Población', 'Visitas', 'Centros Básica/Media', 'Centros Preescolares', 'Docentes', 'Jardineras', 'Líderes', 'NNAJ'];
    $default_ver = ['Guión Metodológico', 'Producto Multimedia', 'Listado de Participación', 'Fotografías', 'Historia de Éxito', 'Reporte Mensual Docente', 'Asistencia Técnica', 'Ficha de supervisión'];
    $default_lug = ['Oficina', 'Trabajo de campo', 'Centro Educativo', 'Centro Preescolar', 'Centro ADN', 'UAPS/CIS'];
    foreach ([['ah_cat_responsables', $default_resps], ['ah_cat_unidades', $default_unis], ['ah_cat_verificaciones', $default_ver], ['ah_cat_lugares', $default_lug]] as $pair) {
        [$table, $items] = $pair;
        $count = (int)$db->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
        if ($count === 0) {
            $st = $db->prepare("INSERT IGNORE INTO `$table` (nombre) VALUES (?)");
            foreach ($items as $item) $st->execute([$item]);
        }
    }

    $db->exec("CREATE TABLE IF NOT EXISTS ah_poa_asignaciones (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_poa INT NOT NULL,
        tecnico VARCHAR(150) NOT NULL,
        base_asignada VARCHAR(150) NULL,
        meses_asignados VARCHAR(255) NULL,
        meta_asignada DECIMAL(10,2) DEFAULT 0,
        logro_asignado DECIMAL(10,2) DEFAULT 0,
        lugares_json LONGTEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (id_poa),
        INDEX (tecnico),
        INDEX (base_asignada)
    )");

    try { $db->exec("ALTER TABLE ah_poa_asignaciones ADD COLUMN base_asignada VARCHAR(150) NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE ah_poa_asignaciones ADD COLUMN logro_asignado DECIMAL(10,2) DEFAULT 0"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE ah_poa_asignaciones ADD COLUMN lugares_json LONGTEXT NULL"); } catch (Throwable $e) {}
    try { $db->exec("ALTER TABLE ah_poa ADD COLUMN operativo_oculto TINYINT(1) DEFAULT 0"); } catch (Throwable $e) {}

    $db->exec("CREATE TABLE IF NOT EXISTS ah_poa_etapas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_poa INT NOT NULL,
        codigo_etapa VARCHAR(50) NULL,
        nombre_etapa VARCHAR(150) NULL,
        descripcion_etapa TEXT NULL,
        unidad_medida TEXT NULL,
        responsable TEXT NULL,
        involucrados_json LONGTEXT NULL,
        fecha_recepcion DATE NULL,
        orden INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX (id_poa)
    )");
    addColIfNotExists($db, 'ah_poa_etapas', 'nombre_etapa', 'VARCHAR(150) NULL');
    addColIfNotExists($db, 'ah_poa_etapas', 'unidad_medida', 'TEXT NULL');
    addColIfNotExists($db, 'ah_poa_etapas', 'responsable', 'TEXT NULL');
    addColIfNotExists($db, 'ah_poa_etapas', 'involucrados_json', 'LONGTEXT NULL');
    addColIfNotExists($db, 'ah_poa_etapas', 'fecha_recepcion', 'DATE NULL');
    addColIfNotExists($db, 'ah_poa_etapas', 'updated_at', 'TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');

    foreach (['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $m) {
        addColIfNotExists($db, $tabla_poa, 'op_act_'.$m, 'DECIMAL(10,2) DEFAULT 0');
        addColIfNotExists($db, $tabla_poa, 'op_part_'.$m, 'DECIMAL(10,2) DEFAULT 0');
        addColIfNotExists($db, $tabla_poa, 'op_editado_'.$m, 'TINYINT(1) DEFAULT 0');
        addColIfNotExists($db, 'ah_poa_asignaciones', 'meta_'.$m, 'DECIMAL(10,2) DEFAULT 0');
        addColIfNotExists($db, 'ah_poa_asignaciones', 'logro_'.$m, 'DECIMAL(10,2) DEFAULT 0');
    }
    addColIfNotExists($db, $tabla_poa, 'operativo_info_adicional', 'LONGTEXT NULL');
    addColIfNotExists($db, $tabla_poa, 'descripcion_actividad', 'TEXT NULL');
    addColIfNotExists($db, $tabla_poa, 'equipo_lugares_json', 'LONGTEXT NULL');
    $db->exec("CREATE TABLE IF NOT EXISTS ah_monitoreo_schema (
        id TINYINT PRIMARY KEY,
        version INT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $db->exec('INSERT INTO ah_monitoreo_schema(id,version) VALUES(1,1) ON DUPLICATE KEY UPDATE version=VALUES(version)');
} catch (Throwable $e) {
    $msg = "<div class='alert error'>Error de migración: " . htmlspecialchars($e->getMessage()) . "</div>";
}
}

$estados = ['Pendiente', 'En Proceso', 'Completado', 'Reprogramado', 'Cancelado'];
$etapas_default = [
    ['codigo'=>'E-1', 'nombre'=>'Diseñar Actividad', 'descripcion'=>'Diseñar, facilitar y explicar a ET e implementadores lineamientos, recursos, protocolos y guías.', 'dia'=>3],
    ['codigo'=>'E-2', 'nombre'=>'Organizar y socializar Actividad', 'descripcion'=>'Organizar y facilitar la logística antes, durante y después de la actividad. Listados, actas, alimentación, transporte, etc.', 'dia'=>6],
    ['codigo'=>'E-3', 'nombre'=>'Desarrollar y reportar Actividad', 'descripcion'=>'Desarrollar actividad o evento en base a criterios de calidad y objetivos establecidos.', 'dia'=>20],
    ['codigo'=>'E-4', 'nombre'=>'Asistencia y Monitoreo de Actividad', 'descripcion'=>'Acompañar la actividad aplicando herramientas evaluativas a la calidad del proceso y el nivel de satisfacción de participantes.', 'dia'=>'last']
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_ocultar') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $csrf = (string)($_POST['csrf'] ?? '');
        if ($csrf === '' || !hash_equals($monitoreoCsrf, $csrf)) {
            throw new RuntimeException('La sesión de seguridad venció. Recargue la página.');
        }
        $id = (int)$_POST['id_poa'];
        $oculto = (int)$_POST['oculto'];
        $db->prepare("UPDATE ah_poa SET operativo_oculto = ? WHERE id = ?")->execute([$oculto, $id]);
        echo json_encode(['status'=>'ok']);
    } catch(Throwable $e) { echo json_encode(['status'=>'error','msg'=>$e->getMessage()]); }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_metas_password') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $password = (string)($_POST['password'] ?? '');
        if ($password === '') throw new Exception('Ingrese su contraseña.');
        $user = getCurrentUserCredential($db);
        if (!$user) throw new Exception('No fue posible identificar al usuario autenticado.');
        $hash = (string)($user['password'] ?? '');
        if (!(password_verify($password, $hash) || hash_equals($hash, $password))) throw new Exception('Contraseña incorrecta.');
        $_SESSION['metas_edit_unlocked_until'] = time() + 900;
        echo json_encode(['status'=>'ok','expires_in'=>900]);
    } catch (Throwable $e) {
        http_response_code(403);
        echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_to_catalog') {
    header('Content-Type: application/json; charset=utf-8');
    $type = $_POST['catalog_type'] ?? '';
    $value = trim($_POST['catalog_value'] ?? '');
    $prog = trim($_POST['programa'] ?? 'GENERAL');
    $etapa = trim($_POST['etapa'] ?? 'TODAS');

    if ($value === '') { echo json_encode(['status'=>'error', 'msg'=>'Valor vacío']); exit; }
    $tableMap = ['responsable'=>'ah_cat_responsables', 'unidad'=>'ah_cat_unidades', 'verificacion'=>'ah_cat_verificaciones', 'lugar'=>'ah_cat_lugares'];
    $table = $tableMap[$type] ?? 'ah_cat_lugares';

    try {
        if ($type === 'unidad' || $type === 'verificacion') {
            $st = $db->prepare("INSERT INTO `$table` (programa, etapa, nombre, activo) VALUES (?, ?, ?, 1) ON DUPLICATE KEY UPDATE activo=1");
            $st->execute([$prog, $etapa, $value]);
        } else {
            $db->prepare("INSERT IGNORE INTO `$table` (nombre) VALUES (?)")->execute([$value]);
        }
        echo json_encode(['status'=>'ok', 'value'=>$value]);
    } catch (Throwable $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
    exit;
}

// GUARDADO MASIVO (BULK SAVE) DE ASIGNACIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autosave_team_assignment_bulk') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $id_poa = (int)($_POST['id_poa'] ?? 0);
        if ($id_poa <= 0) throw new Exception('Actividad inválida.');

        $lugaresRaw = $_POST['lugares'] ?? [];
        $lugares = [];
        foreach ((array)$lugaresRaw as $lugar) {
            $lugar = trim((string)$lugar);
            if ($lugar !== '' && !in_array($lugar, $lugares, true)) $lugares[] = $lugar;
        }
        $lugaresJson = json_encode($lugares, JSON_UNESCAPED_UNICODE);

        $rows = json_decode($_POST['rows'] ?? '[]', true);
        if (!is_array($rows)) $rows = [];

        $db->beginTransaction();

        try {
            $db->prepare("UPDATE {$tabla_poa} SET equipo_lugares_json = ? WHERE {$col_id} = ?")->execute([$lugaresJson, $id_poa]);
        } catch (Throwable $e) {}

        $db->prepare("DELETE FROM ah_poa_asignaciones WHERE id_poa = ?")->execute([$id_poa]);

        $sql = "INSERT INTO ah_poa_asignaciones (id_poa, tecnico, base_asignada, meses_asignados, meta_asignada, logro_asignado, lugares_json, meta_jul, meta_aug, meta_sep, meta_oct, meta_nov, meta_dec, meta_jan, meta_feb, meta_mar, meta_apr, meta_may, meta_jun, logro_jul, logro_aug, logro_sep, logro_oct, logro_nov, logro_dec, logro_jan, logro_feb, logro_mar, logro_apr, logro_may, logro_jun) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $db->prepare($sql);

        foreach ($rows as $row) {
            $tecnico = trim($row['tecnico'] ?? '');
            $base_asignada = trim($row['base_asignada'] ?? '');
            if ($base_asignada === 'General') $base_asignada = '';
            $selected = (int)($row['selected'] ?? 0) === 1;

            $metas = $row['metas'] ?? [];
            $logros = $row['logros'] ?? [];
            $meta_total = 0; $logro_total = 0; $meses = [];

            foreach (['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $m) {
                $valM = (float)($metas[$m] ?? 0);
                $valL = (float)($logros[$m] ?? 0);
                $meta_total += $valM;
                $logro_total += $valL;
                if ($valM > 0 || $valL > 0) $meses[] = $m;
            }

            if ($selected || $meta_total > 0 || $logro_total > 0) {
                $stmt->execute([
                    $id_poa, $tecnico, $base_asignada, implode(', ', $meses), $meta_total, $logro_total, $lugaresJson,
                    (float)($metas['jul']??0),(float)($metas['aug']??0),(float)($metas['sep']??0),(float)($metas['oct']??0),(float)($metas['nov']??0),(float)($metas['dec']??0),
                    (float)($metas['jan']??0),(float)($metas['feb']??0),(float)($metas['mar']??0),(float)($metas['apr']??0),(float)($metas['may']??0),(float)($metas['jun']??0),
                    (float)($logros['jul']??0),(float)($logros['aug']??0),(float)($logros['sep']??0),(float)($logros['oct']??0),(float)($logros['nov']??0),(float)($logros['dec']??0),
                    (float)($logros['jan']??0),(float)($logros['feb']??0),(float)($logros['mar']??0),(float)($logros['apr']??0),(float)($logros['may']??0),(float)($logros['jun']??0)
                ]);
            }
        }

        $db->commit();
        saveActivitySnapshot($db, $id_poa, 'asignacion_equipo_masiva');
        echo json_encode(['status'=>'ok','lugares'=>$lugares]); exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        echo json_encode(['status'=>'error','msg'=>$e->getMessage()]); exit;
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'autosave_centros_etapa3') {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $idPoa = (int)($_POST['id_poa'] ?? 0);
        $rowKey = trim((string)($_POST['row_key'] ?? ''));
        $rowJson = (string)($_POST['row_json'] ?? '');
        $rowData = json_decode($rowJson, true);

        if ($idPoa <= 0) throw new Exception('Actividad inválida.');
        if ($rowKey === '') throw new Exception('No se recibió la clave del responsable.');
        if (!is_array($rowData)) throw new Exception('El detalle de centros no tiene un formato válido.');

        $rowData['persona'] = trim((string)($rowData['persona'] ?? ''));
        $rowData['base'] = trim((string)($rowData['base'] ?? ''));
        $rowData['unidad'] = trim((string)($rowData['unidad'] ?? ''));
        $rowData['mes'] = trim((string)($rowData['mes'] ?? $mes_actual ?? ''));
        $rowData['a_lograr'] = (float)($rowData['a_lograr'] ?? 0);
        $rowData['cumplido'] = (float)($rowData['cumplido'] ?? 0);
        $rowQualityVersion = (int)($rowData['quality_version'] ?? 0);
        $rowAtRaw = array_key_exists('a_tiempo', $rowData) ? (float)$rowData['a_tiempo'] : null;
        $rowEfRaw = array_key_exists('en_forma', $rowData) ? (float)$rowData['en_forma'] : null;

        if ($rowQualityVersion < 2 && (($rowAtRaw === null && $rowEfRaw === null) || ((float)$rowAtRaw === 0.0 && $rowEfRaw === 0.0))) {
            $rowData['a_tiempo'] = 100;
            $rowData['en_forma'] = 100;
        } else {
            $rowData['a_tiempo'] = max(0, min(100, (float)($rowAtRaw ?? 100)));
            $rowData['en_forma'] = max(0, min(100, (float)($rowEfRaw ?? 100)));
        }
        $rowData['quality_initialized'] = true;
        $rowData['quality_version'] = 2;
        $rowData['deleted'] = !empty($rowData['deleted']);
        $rowData['verifics'] = isset($rowData['verifics']) && is_array($rowData['verifics']) ? array_values($rowData['verifics']) : [];
        $rowData['lugar'] = isset($rowData['lugar']) && is_array($rowData['lugar']) ? array_values($rowData['lugar']) : [];
        $rowData['centros'] = isset($rowData['centros']) && is_array($rowData['centros']) ? $rowData['centros'] : [];

        foreach ($rowData['centros'] as $idCentro => &$centro) {
            if (!is_array($centro)) $centro = [];
            $centro['id'] = (string)($centro['id'] ?? $idCentro);
            $centro['a_lograr'] = (float)($centro['a_lograr'] ?? 0);
            $centro['cumplido'] = (float)($centro['cumplido'] ?? 0);
            $centerQualityVersion = (int)($centro['quality_version'] ?? 0);
            $centerAtRaw = array_key_exists('a_tiempo', $centro) ? (float)$centro['a_tiempo'] : null;
            $centerEfRaw = array_key_exists('en_forma', $centro) ? (float)$centro['en_forma'] : null;
            if ($centerQualityVersion < 2 && (($centerAtRaw === null && $centerEfRaw === null) || ((float)$centerAtRaw === 0.0 && $centerEfRaw === 0.0))) {
                $centro['a_tiempo'] = 100;
                $centro['en_forma'] = 100;
            } else {
                $centro['a_tiempo'] = max(0, min(100, (float)($centerAtRaw ?? 100)));
                $centro['en_forma'] = max(0, min(100, (float)($centerEfRaw ?? 100)));
            }
            $centro['quality_initialized'] = true;
            $centro['quality_version'] = 2;
            $centro['pob_0_5'] = (float)($centro['pob_0_5'] ?? 0);
            $centro['pob_6_17'] = (float)($centro['pob_6_17'] ?? 0);
            $centro['pob_18_24'] = (float)($centro['pob_18_24'] ?? 0);
        }
        unset($centro);

        $db->beginTransaction();
        $find = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? AND orden = 3 LIMIT 1");
        $find->execute([$idPoa]);
        $etapa = $find->fetch(PDO::FETCH_ASSOC);

        if (!$etapa) {
            $find = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? AND codigo_etapa = 'E-3' LIMIT 1");
            $find->execute([$idPoa]);
            $etapa = $find->fetch(PDO::FETCH_ASSOC);
        }

        if ($etapa) {
            $involucrados = safe_json_decode($etapa['involucrados_json'] ?? '{}', []);
            $existente = isset($involucrados[$rowKey]) && is_array($involucrados[$rowKey]) ? $involucrados[$rowKey] : [];
            $centrosExistentes = isset($existente['centros']) && is_array($existente['centros']) ? $existente['centros'] : [];
            $centrosNuevos = isset($rowData['centros']) && is_array($rowData['centros']) ? $rowData['centros'] : [];
            $rowData['centros'] = array_replace($centrosExistentes, $centrosNuevos);
            $involucrados[$rowKey] = array_merge($existente, $rowData);

            $json = json_encode($involucrados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $upd = $db->prepare("UPDATE ah_poa_etapas SET involucrados_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $upd->execute([$json, (int)$etapa['id']]);
        } else {
            $involucrados = [$rowKey => $rowData];
            $json = json_encode($involucrados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $fechaMaxima = date('Y-m-20');
            $insEtapa = $db->prepare("INSERT INTO ah_poa_etapas (id_poa, codigo_etapa, nombre_etapa, descripcion_etapa, unidad_medida, responsable, involucrados_json, fecha_recepcion, orden) VALUES (?, 'E-3', ?, ?, '[]', '[]', ?, ?, 3)");
            $insEtapa->execute([$idPoa, $etapas_default[2]['nombre'] ?? 'Desarrollar y reportar Actividad', $etapas_default[2]['descripcion'] ?? '', $json, $fechaMaxima]);
        }

        $db->commit();
        saveActivitySnapshot($db, $idPoa, 'detalle_centros_etapa3');

        echo json_encode(['status' => 'ok', 'msg' => 'Detalle de centros guardado.', 'row' => $rowData], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(500);
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_task') {
    try {
        $db->beginTransaction();
        $taskId = (int)($_POST['task_id'] ?? 0);
        $db->prepare("UPDATE {$tabla_poa} SET operativo_estado=?, operativo_info_adicional=? WHERE {$col_id}=?")
           ->execute([$_POST['estado'] ?? '0%', $_POST['info_adicional'] ?? '', $taskId]);

        if (metasEditUnlocked() && (($_POST['metas_authorized'] ?? '0') === '1')) {
            $db->prepare("UPDATE {$tabla_poa} SET meta_actividades=?, operativo_meta_obj=?, meta_actividades_alc=?, operativo_meta_alc=? WHERE {$col_id}=?")
               ->execute([(float)($_POST['meta_act_obj'] ?? 0), (float)($_POST['meta_part_obj'] ?? 0), (float)($_POST['meta_act_alc'] ?? 0), (float)($_POST['meta_part_alc'] ?? 0), $taskId]);
            foreach (['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $m) {
                $db->prepare("UPDATE {$tabla_poa} SET op_act_{$m}=?, op_part_{$m}=?, op_editado_{$m}=1 WHERE {$col_id}=?")
                   ->execute([(float)($_POST['op_act'][$m] ?? 0), (float)($_POST['op_part'][$m] ?? 0), $taskId]);
            }
        }

        if (($_POST['etapas_loaded'] ?? '1') === '1') {
        $db->prepare("DELETE FROM ah_poa_etapas WHERE id_poa=?")->execute([(int)$_POST['task_id']]);
        if (isset($_POST['etapa_codigo']) && is_array($_POST['etapa_codigo'])) {
            $ins = $db->prepare("INSERT INTO ah_poa_etapas (id_poa, codigo_etapa, nombre_etapa, descripcion_etapa, unidad_medida, responsable, involucrados_json, fecha_recepcion, orden) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($_POST['etapa_codigo'] as $i => $codigo) {
                $unidades = isset($_POST['etapa_unidades'][$i]) ? json_encode($_POST['etapa_unidades'][$i], JSON_UNESCAPED_UNICODE) : '[]';
                $resps = isset($_POST['etapa_resps'][$i]) ? json_encode($_POST['etapa_resps'][$i], JSON_UNESCAPED_UNICODE) : '[]';
                $inv = [];
                if (!empty($_POST['etapa_involucrados_json'][$i])) {
                    $invPrev = json_decode((string)$_POST['etapa_involucrados_json'][$i], true);
                    if (is_array($invPrev)) $inv = $invPrev;
                }
                if (isset($_POST['inv_alograr'][$i])) {
                    foreach ($_POST['inv_alograr'][$i] as $key => $aLograr) {
                        $centrosPrev = [];
                        if (!empty($_POST['inv_centros_json'][$i][$key])) {
                            $tmpCentros = json_decode($_POST['inv_centros_json'][$i][$key], true);
                            if (is_array($tmpCentros)) { $centrosPrev = $tmpCentros; }
                        }
                        if (isset($_POST['inv_centros'][$i][$key]) && is_array($_POST['inv_centros'][$i][$key])) {
                            foreach ($_POST['inv_centros'][$i][$key] as $idCentro => $centroData) {
                                $centrosPrev[$idCentro] = [
                                    'id' => $idCentro,
                                    'nombre' => $centroData['nombre'] ?? '',
                                    'tipo' => $centroData['tipo'] ?? '',
                                    'comunidad_base' => $centroData['comunidad_base'] ?? '',
                                    'caserio' => $centroData['caserio'] ?? '',
                                    'a_lograr' => (float)($centroData['a_lograr'] ?? 0),
                                    'cumplido' => (float)($centroData['cumplido'] ?? 0),
                                    'a_tiempo' => max(0, min(100, (float)($centroData['a_tiempo'] ?? 100))),
                                    'en_forma' => max(0, min(100, (float)($centroData['en_forma'] ?? 100))),
                                    'quality_initialized' => true,
                                    'quality_version' => 2,
                                    'pob_0_5' => (float)($centroData['pob_0_5'] ?? 0),
                                    'pob_6_17' => (float)($centroData['pob_6_17'] ?? 0),
                                    'pob_18_24' => (float)($centroData['pob_18_24'] ?? 0)
                                ];
                            }
                        }
                        $inv[$key] = [
                            'persona' => $_POST['inv_persona'][$i][$key] ?? '',
                            'base' => $_POST['inv_base'][$i][$key] ?? '',
                            'unidad' => $_POST['inv_unidad'][$i][$key] ?? '',
                            'mes' => $_POST['inv_mes'][$i][$key] ?? '',
                            'deleted' => (($_POST['inv_deleted'][$i][$key] ?? '0') === '1'),
                            'a_lograr' => (float)$aLograr,
                            'cumplido' => (float)($_POST['inv_cumplido'][$i][$key] ?? 0),
                            'a_tiempo' => max(0, min(100, (float)($_POST['inv_a_tiempo'][$i][$key] ?? 100))),
                            'en_forma' => max(0, min(100, (float)($_POST['inv_en_forma'][$i][$key] ?? 100))),
                            'quality_initialized' => true,
                            'quality_version' => 2,
                            'verifics' => $_POST['inv_verifics'][$i][$key] ?? [],
                            'lugar' => $_POST['inv_lugar'][$i][$key] ?? [],
                            'centros' => $centrosPrev
                        ];
                    }
                }
                $fecha = trim($_POST['etapa_fecha_recepcion'][$i] ?? '');
                $ins->execute([(int)$_POST['task_id'], trim($codigo), trim($_POST['etapa_nombre'][$i] ?? ''), trim($_POST['etapa_descripcion'][$i] ?? ''), $unidades, $resps, json_encode($inv, JSON_UNESCAPED_UNICODE), $fecha !== '' ? $fecha : null, $i + 1]);
            }
        }
        }
        $db->commit();
        saveActivitySnapshot($db, $taskId, 'configuracion_actividad');
        if (isset($_POST['autosave_full']) && $_POST['autosave_full'] == '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'ok','msg'=>'Autoguardado correcto']);
            exit;
        }
        $msg = "<div class='alert success'><i class='fa-solid fa-check'></i> Configuración guardada correctamente.</div>";
    } catch (Throwable $e) {
        $db->rollBack();
        if (isset($_POST['autosave_full']) && $_POST['autosave_full'] == '1') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()]);
            exit;
        }
        $msg = "<div class='alert error'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$meses_keys = ['jul'=>'Jul','aug'=>'Ago','sep'=>'Sep','oct'=>'Oct','nov'=>'Nov','dec'=>'Dic','jan'=>'Ene','feb'=>'Feb','mar'=>'Mar','apr'=>'Abr','may'=>'May','jun'=>'Jun'];
try { $tareas = $db->query("SELECT * FROM {$tabla_poa} WHERE operativo_oculto = 0 ORDER BY id ASC LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $tareas = []; }
try { $tareas_ocultas = $db->query("SELECT id, descripcion_actividad, marco_logico, codigo_maestro FROM {$tabla_poa} WHERE operativo_oculto = 1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $tareas_ocultas = []; }
try { $asignaciones_raw = $db->query("SELECT a.* FROM ah_poa_asignaciones a INNER JOIN ah_poa p ON p.id=a.id_poa WHERE p.is_active=1")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $asignaciones_raw = []; }
$asignaciones_map = [];
foreach ($asignaciones_raw as $a) $asignaciones_map[$a['id_poa']][] = $a;
try { $etapas_raw = $db->query("SELECT e.* FROM ah_poa_etapas e INNER JOIN ah_poa p ON p.id=e.id_poa WHERE p.is_active=1 ORDER BY e.id_poa ASC, e.orden ASC, e.id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $etapas_raw = []; }
$etapas_map = [];
foreach ($etapas_raw as $e) $etapas_map[$e['id_poa']][] = $e;
try { $tecnicos_list = $db->query("SELECT nombre FROM ah_tecnicos WHERE activo=1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $tecnicos_list = []; }

// Técnico multibase robusto: genera una fila por cada relación técnico-base, sin perder técnicos sin base.
$tecnicos_bases = [];
$tb_seen = [];
try {
    $sql_tb = "SELECT DISTINCT t.nombre, COALESCE(b.nombre_base, '') AS nombre_base
               FROM ah_tecnicos t
               LEFT JOIN ah_bases_geograficas b ON t.identidad = b.identidad_tecnico
               WHERE t.activo = 1
               ORDER BY t.nombre ASC, b.nombre_base ASC";
    $rows_tb = $db->query($sql_tb)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows_tb as $tb) {
        $nombre = trim($tb['nombre'] ?? '');
        $base = trim($tb['nombre_base'] ?? '');
        if ($nombre === '') continue;
        $key = mb_strtolower($nombre . '|' . $base, 'UTF-8');
        if (!isset($tb_seen[$key])) {
            $tb_seen[$key] = true;
            $tecnicos_bases[] = ['nombre' => $nombre, 'nombre_base' => $base];
        }
    }
} catch (Throwable $e) { }

// También respeta bases que ya fueron guardadas manualmente en POA.
try {
    $rows_asig_bases = $db->query("SELECT DISTINCT tecnico AS nombre, COALESCE(base_asignada,'') AS nombre_base FROM ah_poa_asignaciones ORDER BY tecnico ASC, base_asignada ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows_asig_bases as $tb) {
        $nombre = trim($tb['nombre'] ?? '');
        $base = trim($tb['nombre_base'] ?? '');
        if ($nombre === '') continue;
        $key = mb_strtolower($nombre . '|' . $base, 'UTF-8');
        if (!isset($tb_seen[$key])) {
            $tb_seen[$key] = true;
            $tecnicos_bases[] = ['nombre' => $nombre, 'nombre_base' => $base];
        }
    }
} catch (Throwable $e) { }

foreach ($tecnicos_list as $tn) {
    $tieneFila = false;
    foreach ($tecnicos_bases as $tb) {
        if (($tb['nombre'] ?? '') === $tn) { $tieneFila = true; break; }
    }
    if (!$tieneFila) { $tecnicos_bases[] = ['nombre' => $tn, 'nombre_base' => '']; }
}

// -----------------------------------------------------------------------------
// CATÁLOGOS DINÁMICOS OBTENIDOS DESDE BD
// -----------------------------------------------------------------------------
try { $cat_responsables = $db->query("SELECT nombre FROM ah_cat_responsables ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $cat_responsables = []; }
try { $cat_lugares = $db->query("SELECT nombre FROM ah_cat_lugares ORDER BY id ASC")->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $e) { $cat_lugares = []; }

// CORRECCIÓN: Traemos TODA LA TABLA ah_centros para evitar perder las columnas pob_total, lideres_f, lideres_m
try { $centros_catalogo = $db->query("SELECT * FROM ah_centros ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $e) { $centros_catalogo = []; }

$cat_unidades_raw = [];
try {
    $cat_unidades_raw = $db->query("SELECT nombre, programa, etapa FROM ah_cat_unidades WHERE activo=1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $nombres = $db->query("SELECT nombre FROM ah_cat_unidades ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach($nombres as $n) $cat_unidades_raw[] = ['nombre'=>$n, 'programa'=>'GENERAL', 'etapa'=>'TODAS'];
    } catch (Throwable $e2) {}
}

$cat_verificaciones_raw = [];
try {
    $cat_verificaciones_raw = $db->query("SELECT nombre, programa, etapa FROM ah_cat_verificaciones WHERE activo=1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    try {
        $nombres = $db->query("SELECT nombre FROM ah_cat_verificaciones ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
        foreach($nombres as $n) $cat_verificaciones_raw[] = ['nombre'=>$n, 'programa'=>'GENERAL', 'etapa'=>'TODAS'];
    } catch (Throwable $e2) {}
}

$lista_total_responsables = array_values(array_unique(array_merge($cat_responsables, $tecnicos_list)));
$mes_actual_php = strtolower(date('M'));
$map_month_php = ['jan'=>'jan','feb'=>'feb','mar'=>'mar','apr'=>'apr','may'=>'may','jun'=>'jun','jul'=>'jul','aug'=>'aug','sep'=>'sep','oct'=>'oct','nov'=>'nov','dec'=>'dec'];
$mes_actual = $map_month_php[$mes_actual_php] ?? 'jul';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<title>Monitoreo Operativo General | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/jquery@3.7.0/dist/jquery.min.js"></script>
<script src="https://unpkg.com/tinymce@6/tinymce.min.js"></script>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
:root{--ah-primary:#34859B;--bg-canvas:#f8fafc;--border:#e2e8f0;--text-main:#1e293b;--text-muted:#64748b;--blue-soft:#e0f2fe;--blue-text:#075985;--green:#16a34a;--red:#dc2626;--yellow:#f59e0b;}
body{font-family:'Inter',sans-serif;display:flex;min-height:100vh;background:var(--bg-canvas);margin:0;color:var(--text-main)}
.main-wrapper{flex-grow:1;padding:30px 50px;overflow-y:auto;width:100%;box-sizing:border-box}.alert{padding:15px;border-radius:8px;margin-bottom:20px;font-weight:600}.success{background:#dcfce7;color:#166534;border:1px solid #bbf7d0}.error{background:#fee2e2;color:#991b1b;border:1px solid #fecaca}
.metrics-dashboard{display:grid;grid-template-columns:repeat(4,1fr);gap:20px;margin-bottom:25px}.metric-card{background:white;padding:20px;border-radius:16px;border:1px solid var(--border);box-shadow:0 2px 10px rgba(0,0,0,.02);display:flex;align-items:center;gap:15px}.metric-icon{width:55px;height:55px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.6rem}.metric-info h4{margin:0;color:var(--text-muted);font-size:.85rem;text-transform:uppercase}.metric-info p{margin:5px 0 0;font-size:1.8rem;font-weight:800;color:var(--text-main)}
.filter-panel{background:white;padding:20px;border-radius:16px;border:1px solid var(--border);margin-bottom:25px}.filter-row{display:flex;gap:15px;flex-wrap:wrap;margin-bottom:15px;align-items:center}.filter-row:last-child{margin-bottom:0;border-top:1px solid #f1f5f9;padding-top:15px}.form-control{padding:10px 14px;border:1px solid var(--border);border-radius:8px;font-family:inherit;font-size:.9rem;outline:none;width:100%;box-sizing:border-box}.form-control:focus{border-color:var(--ah-primary);box-shadow:0 0 0 3px rgba(52,133,155,.1)}.toggle-label{display:flex;align-items:center;gap:8px;background:#f8fafc;padding:8px 15px;border-radius:30px;font-size:.85rem;cursor:pointer;font-weight:700;color:#475569;border:1px solid #e2e8f0;user-select:none}.toggle-label.active-toggle{background:#e0f2fe;border-color:#bae6fd;color:#0284c7}.month-pill{cursor:pointer;padding:6px 12px;font-size:.8rem;font-weight:700;border-radius:20px;background:white;border:1px solid #cbd5e1;color:#64748b;user-select:none}.month-pill input{display:none}.month-pill.active{background:var(--ah-primary);color:white;border-color:var(--ah-primary)}
.btn-primary{background:var(--ah-primary);color:white;border:0;padding:10px 20px;font-weight:800;border-radius:8px;cursor:pointer;display:inline-flex;gap:8px;align-items:center}.btn-action{background:white;color:var(--ah-primary);border:1px solid var(--border);padding:10px 16px;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;cursor:pointer;font-weight:800;gap:8px}.btn-action:hover{background:#f0f9ff;border-color:#bae6fd}.btn-mini{padding:7px 11px;border-radius:999px;font-size:.78rem}.btn-eye{background:#eff6ff;border-color:#bfdbfe;color:#075985}.btn-eye.active{background:#075985;color:#fff;border-color:#075985}
.task-list{display:grid;grid-template-columns:1fr;gap:15px}.task-card{background:#fff;border:1px solid var(--border);border-radius:12px;padding:22px;display:grid;grid-template-columns:3fr 2.5fr 2.5fr auto;gap:20px;align-items:center;transition:.2s}.task-card:hover{border-color:#cbd5e1;box-shadow:0 8px 20px rgba(0,0,0,.06);transform:translateY(-2px)}.task-card.completed{border-left:6px solid #22c55e}.task-card.pending{border-left:6px solid #cbd5e1}.task-main h3{margin:0 0 8px;font-size:1.1rem;line-height:1.4}.task-meta{font-size:.9rem;display:flex;flex-direction:column;gap:8px}.task-meta i{color:var(--ah-primary);width:18px;text-align:center}.prog-badge,.month-mini-badge,.code-pill{display:inline-flex;align-items:center;gap:6px;border-radius:8px;font-size:.75rem;font-weight:800;padding:4px 8px;border:1px solid #e2e8f0}.month-mini-badge{background:#fffbeb;color:#b45309;border-color:#fde68a;margin-right:4px}.prog-badge{background:#f1f5f9;color:#334155;margin-top:5px}.code-corner{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}.code-pill{border-radius:999px;padding:6px 10px}.code-pill.ml{background:#e0f2fe;color:#075985;border-color:#bae6fd}.code-pill.ext{background:#fef3c7;color:#92400e;border-color:#fde68a}.badge{padding:6px 14px;border-radius:20px;font-size:.8rem;font-weight:800;text-align:center;display:inline-block}.badge.pendiente{background:#f1f5f9;color:#475569}.badge.proceso{background:#fef3c7;color:#b45309}.badge.completado{background:#dcfce7;color:#166534}
.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.7);z-index:1000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:20px}.modal-content{background:white;width:96%;max-width:1660px;border-radius:16px;display:flex;flex-direction:column;height:95vh;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);overflow:hidden}.modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 26px 0;flex-shrink:0}.modal-tabs{display:flex;gap:8px;padding:12px 26px 0;border-bottom:1px solid var(--border);background:white;flex-shrink:0}.modal-tab-btn{background:none;border:0;padding:10px 16px;font-size:.93rem;font-weight:800;color:var(--text-muted);cursor:pointer;border-bottom:3px solid transparent}.modal-tab-btn.active{color:var(--ah-primary);border-bottom-color:var(--ah-primary)}.modal-body{padding:0;overflow-y:auto;flex-grow:1;background:#f8fafc}.modal-footer{padding:14px 26px;border-top:1px solid var(--border);background:white;text-align:right;flex-shrink:0}.modal-tab-content{display:none;padding:22px 26px}.modal-tab-content.active{display:block}
.agenda-sticky{position:sticky;top:0;z-index:40;background:rgba(248,250,252,.98);backdrop-filter:blur(6px);border-bottom:1px solid var(--border);padding:12px 26px 10px;box-shadow:0 8px 18px rgba(15,23,42,.06)}.agenda-sticky-inner{display:grid;grid-template-columns:1fr 240px 150px;gap:12px;align-items:center}.agenda-title{font-size:1rem;font-weight:800;margin:0;line-height:1.28}.agenda-status label{font-size:.68rem;text-transform:uppercase;font-weight:900;color:#0284c7;display:block;margin-bottom:3px}.agenda-status select{height:38px;font-weight:800;color:#075985}.agenda-meta{background:white;border:1px solid #bae6fd;border-left:4px solid var(--ah-primary);border-radius:10px;padding:8px 12px;text-align:center}.agenda-meta span{display:block;font-size:.68rem;color:#0284c7;font-weight:900;text-transform:uppercase}.agenda-meta strong{font-size:1.15rem;color:#0f172a}.agenda-meta.month-meta{border-left-color:#16a34a}.agenda-meta.month-meta span{color:#166534}
.styled-table{width:100%;border-collapse:collapse;background:white;border-radius:8px;border:1px solid var(--border);overflow:hidden}.styled-table th,.styled-table td{padding:11px 12px;text-align:left;border-bottom:1px solid #f1f5f9;vertical-align:middle}.styled-table th{background:#f8fafc;color:#475569;font-weight:800;font-size:.78rem;text-transform:uppercase;letter-spacing:.4px}.table-input{width:100%;padding:8px 10px;border:1px solid var(--border);background:white;border-radius:6px;font-size:.85rem;font-family:inherit;box-sizing:border-box}.table-input:focus{border-color:var(--ah-primary);outline:none;box-shadow:0 0 0 3px rgba(52,133,155,.1)}.stage-scroll{overflow-x:auto;border:1px solid var(--border);border-radius:12px;background:white;min-height:320px}.stage-main-row td{background:#fff}.stage-info{display:flex;gap:14px;align-items:flex-start}.stage-code{font-weight:900;color:#0f172a;min-width:42px}.stage-name{font-weight:900;color:#075985}.stage-desc{color:#475569;font-size:.88rem;line-height:1.35}.global-date-pill{display:inline-flex;align-items:center;gap:8px;background:#fffbeb;color:#92400e;border:1px solid #fde68a;border-radius:999px;padding:8px 12px;font-weight:900;font-size:.82rem}.date-input-compact{width:145px!important;padding:6px 8px!important;border-radius:999px!important}
.custom-multiselect{position:relative;width:100%;min-width:180px}.multiselect-select-box{background:white;border:1px solid #cbd5e1;border-radius:6px;padding:6px 10px;font-size:.85rem;cursor:pointer;display:flex;justify-content:space-between;align-items:center;min-height:36px;box-sizing:border-box;flex-wrap:wrap;gap:4px}.multiselect-select-box::after{content:'\f107';font-family:'Font Awesome 6 Free';font-weight:900;color:#64748b;margin-left:auto}.multiselect-select-box > span { pointer-events: none; }.multi-tag{display:inline-block;background:#e0f2fe;color:#0369a1;padding:2px 6px;border-radius:4px;font-size:.72rem;font-weight:900;border:1px solid #bae6fd}.multiselect-dropdown-panel{display:none;position:fixed;background:white;border:1px solid #cbd5e1;border-radius:6px;box-shadow:0 10px 25px -5px rgba(0,0,0,.2);max-height:250px;overflow-y:auto;z-index:999999;padding:6px;box-sizing:border-box}.multiselect-option{display:flex;align-items:center;gap:8px;padding:6px 8px;font-size:.85rem;border-radius:4px;cursor:pointer;color:#334155;user-select:none}.multiselect-option:hover{background:#f1f5f9}.multiselect-add-new-btn{display:block;text-align:center;padding:8px;border-top:1px solid #e2e8f0;color:var(--ah-primary);font-weight:800;font-size:.8rem;text-decoration:none;margin-top:4px}
.subgrid-wrapper{background:#f8fafc;border-top:2px dashed #cbd5e1;padding:12px 16px 18px}.subgrid-card{background:white;border:1px solid #dbeafe;border-radius:12px;box-shadow:0 4px 16px rgba(15,23,42,.04);overflow:hidden}.subgrid-header{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#f0f9ff;border-bottom:1px solid #dbeafe}.subgrid-header h5{margin:0;font-size:.86rem;color:#075985;text-transform:uppercase;letter-spacing:.3px}.subgrid-table{width:100%;border-collapse:collapse}.subgrid-table th{background:#f8fafc;color:#334155;font-size:.76rem;font-weight:900;padding:9px 10px;text-transform:uppercase}.subgrid-table td{padding:9px 10px;border-top:1px solid #f1f5f9;font-size:.84rem}.inv-row-toggle{background:#eff6ff!important}.detail-centros-row td{padding:0!important;background:#f8fafc!important}.centros-detail-panel{width:100%;box-sizing:border-box;border-top:1px solid #bfdbfe;background:#fff}.centros-detail-toolbar{display:flex;justify-content:space-between;align-items:center;padding:12px 14px;background:#f0f9ff;border-bottom:1px solid #dbeafe}.centros-detail-toolbar strong{color:#075985}.centros-detail-body{padding:14px;max-height:420px;overflow:auto;position:relative}.centros-table{width:100%;border-collapse:collapse;min-width:980px;table-layout:fixed}.centros-table th{background:#e0f2fe;color:#075985;font-size:.76rem;font-weight:900;padding:10px;text-align:left;position:static;top:auto;z-index:auto}.centros-table td{padding:9px 10px;border-bottom:1px solid #e2e8f0}.center-name{font-weight:900;color:#0f172a}.center-meta{font-size:.76rem;color:#64748b}.pct-badge{display:inline-block;padding:5px 10px;border-radius:999px;font-size:.76rem;font-weight:900;min-width:52px;text-align:center}.pct-red{background:#fee2e2;color:#991b1b}.pct-yellow{background:#fef3c7;color:#92400e}.pct-softgreen{background:#dcfce7;color:#166534}.pct-darkgreen{background:#14532d;color:#fff}.pct-gray{background:#f1f5f9;color:#64748b}.score-input{max-width:80px;text-align:center;font-weight:900}.a-lograr-input{max-width:95px;font-weight:900}.d-none{display:none!important}.autosave-indicator{display:none!important}
.saved-flash{border-color:#16a34a!important;box-shadow:0 0 0 3px rgba(22,163,74,.16)!important;background:#f0fdf4!important;transition:all .25s ease}.saving-flash{border-color:#0284c7!important;box-shadow:0 0 0 3px rgba(2,132,199,.12)!important}.error-flash{border-color:#dc2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.16)!important;background:#fef2f2!important}.catalog-mini-modal{position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:10500;display:none;align-items:center;justify-content:center}.catalog-mini-box{background:white;border-radius:12px;width:90%;max-width:420px;padding:25px;box-shadow:0 20px 25px -5px rgba(0,0,0,.15)}
.team-month-col{text-align:center;border-left:1px solid #e2e8f0}.team-month-input{text-align:center;font-weight:900;border-radius:4px;margin:0 auto}.team-month-prog,.team-month-logro{width:55px}.team-total-row td{background:#f8fafc;font-weight:900;border-top:2px solid #94a3b8}.hidden-team-month{display:none!important}.avatar{width:30px;height:30px;border-radius:50%;background:#e0f2fe;color:#0284c7;display:flex;align-items:center;justify-content:center;font-weight:900;font-size:.75rem;flex-shrink:0}.base-badge{background:#fffbeb;color:#b45309;border:1px solid #fde68a;padding:4px 10px;border-radius:20px;font-size:.75rem;font-weight:800}
@media(max-width:1100px){.task-card{grid-template-columns:1fr}.agenda-sticky-inner{grid-template-columns:1fr}.metrics-dashboard{grid-template-columns:1fr 1fr}.modal-tabs{overflow-x:auto}.modal-tab-btn{white-space:nowrap}}

.fill-drag-handle{display:inline-flex;align-items:center;justify-content:center;width:22px;height:22px;border-radius:8px;background:#f1f5f9;color:#64748b;margin-left:4px;cursor:grab;border:1px solid #e2e8f0;flex-shrink:0}.fill-drag-handle:active{cursor:grabbing}.multiselect-select-box.drag-over{outline:3px solid rgba(52,133,155,.25);border-color:var(--ah-primary);background:#f0f9ff}.autosave-warning{background:#92400e!important}.subgrid-toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}.btn-mini{padding:7px 10px;font-size:.78rem}.team-base-count{font-size:.72rem;color:#64748b;font-weight:800;margin-left:6px}.centros-detail-body{max-height:420px}.centros-table{min-width:1120px;table-layout:fixed}.team-table{font-variant-numeric:tabular-nums}.team-total-meta,.team-total-logro,.team-total-dif{min-width:86px;white-space:nowrap}.team-month-col{min-width:86px}.base-labels-wrap{display:flex;gap:6px;flex-wrap:wrap;margin-top:5px}.detail-base-section{border:1px solid #dbeafe;border-radius:12px;margin-bottom:14px;overflow:hidden;background:#fff}.detail-base-title{padding:10px 12px;background:#eff6ff;color:#075985;font-weight:900;border-bottom:1px solid #dbeafe}.detail-base-title .count{color:#64748b;font-weight:800;font-size:.82rem}.drag-fill-source{outline:2px solid rgba(52,133,155,.35)}.sticky-mini-note{font-size:.76rem;color:#075985;font-weight:800}


/* FIX UX detalle centros: sin traslape, tabla estable y mes actual */
.current-month-strip{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:8px 0 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;padding:10px 14px;color:#075985;font-weight:900}.current-month-strip .month-chip{background:white;border:1px solid #7dd3fc;border-radius:999px;padding:6px 12px;color:#075985}.centros-detail-row>td{padding:0!important;background:#fff!important}.centros-detail-panel{width:100%;max-width:100%;box-sizing:border-box;border:1px solid #bfdbfe;border-radius:14px;background:#fff;overflow:hidden;margin:10px 0 14px;box-shadow:0 8px 22px rgba(15,23,42,.06)}.centros-detail-toolbar{position:sticky;top:0;z-index:20;display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 16px;background:#e0f2fe;border-bottom:1px solid #bae6fd}.centros-detail-toolbar strong{color:#075985}.centros-detail-body{padding:0;max-height:430px;overflow:auto;background:#fff}.centros-table{width:100%;min-width:1040px;border-collapse:separate;border-spacing:0}.centros-table th{position:sticky;top:0;z-index:12;background:#f0f9ff;color:#075985;font-size:.76rem;font-weight:900;padding:10px;text-align:left;border-bottom:1px solid #bae6fd}.centros-table td{background:#fff;padding:9px 10px;border-bottom:1px solid #e2e8f0;vertical-align:middle}.centros-table tbody tr:nth-child(even) td{background:#f8fafc}.centros-table input.table-input{height:36px}.centros-table .center-name{font-weight:900;color:#0f172a}.center-meta{font-size:.74rem;color:#64748b}.btn-eye.active{background:#0f766e!important;color:#fff!important;border-color:#0f766e!important}.stage-sticky-mini{position:sticky;top:0;z-index:25;background:#f8fafc;border-bottom:1px solid #e2e8f0;padding-bottom:8px;margin-bottom:10px}

/* ========================================================
   FIX VISUAL FINAL: columnas amplias, 6 dígitos y detalle limpio
   ======================================================== */
.team-table{
    min-width: 2700px !important;
    table-layout: fixed !important;
    font-variant-numeric: tabular-nums !important;
}
.team-table th,
.team-table td{
    box-sizing: border-box !important;
    vertical-align: middle !important;
}
.team-table th:nth-child(1),
.team-table td:nth-child(1){width:44px !important; min-width:44px !important; max-width:44px !important; text-align:center !important;}
.team-table th:nth-child(2),
.team-table td:nth-child(2){width:310px !important; min-width:310px !important; max-width:310px !important;}
.team-table th:nth-child(3),
.team-table td:nth-child(3){width:260px !important; min-width:260px !important; max-width:260px !important;}
.team-month-col{
    min-width: 124px !important;
    width: 124px !important;
    max-width: 124px !important;
    text-align: center !important;
}
.team-month-input,
.team-month-prog,
.team-month-logro{
    width: 106px !important;
    min-width: 106px !important;
    max-width: 106px !important;
    height: 42px !important;
    padding: 8px 10px !important;
    text-align: center !important;
    font-size: .95rem !important;
    font-weight: 900 !important;
    font-variant-numeric: tabular-nums !important;
}
.team-dif,
.team-pct,
.team-total-meta,
.team-total-logro,
.team-total-dif,
.team-total-pct{
    width: 124px !important;
    min-width: 124px !important;
    max-width: 124px !important;
    text-align: center !important;
    white-space: nowrap !important;
    font-variant-numeric: tabular-nums !important;
}
#team-assign-table input[type="number"]::-webkit-outer-spin-button,
#team-assign-table input[type="number"]::-webkit-inner-spin-button,
.centros-table input[type="number"]::-webkit-outer-spin-button,
.centros-table input[type="number"]::-webkit-inner-spin-button{
    margin: 0;
}

/* Detalle de centros: tabla más legible y sin nombres comprimidos */
.centros-detail-panel{
    width: 100% !important;
    max-width: 100% !important;
    overflow: hidden !important;
}
.centros-detail-toolbar{
    min-height: 54px !important;
    flex-wrap: wrap !important;
}
.centros-detail-body{
    max-height: 520px !important;
    overflow: auto !important;
    width: 100% !important;
}
.centros-table{
    min-width: 1580px !important;
    width: 1580px !important;
    table-layout: fixed !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}
.centros-table th,
.centros-table td{
    box-sizing: border-box !important;
    vertical-align: middle !important;
    line-height: 1.25 !important;
}
.centros-table th:nth-child(1), .centros-table td:nth-child(1){width:130px !important; min-width:130px !important; max-width:130px !important;}
.centros-table th:nth-child(2), .centros-table td:nth-child(2){width:300px !important; min-width:300px !important; max-width:300px !important;}
.centros-table th:nth-child(3), .centros-table td:nth-child(3){width:180px !important; min-width:180px !important; max-width:180px !important;}
.centros-table th:nth-child(4), .centros-table td:nth-child(4){width:220px !important; min-width:220px !important; max-width:220px !important;}
.centros-table th:nth-child(5), .centros-table td:nth-child(5){width:170px !important; min-width:170px !important; max-width:170px !important; text-align:center !important;}
.centros-table th:nth-child(6), .centros-table td:nth-child(6),
.centros-table th:nth-child(7), .centros-table td:nth-child(7),
.centros-table th:nth-child(8), .centros-table td:nth-child(8){width:86px !important; min-width:86px !important; max-width:86px !important; text-align:center !important;}
.centros-table th:nth-child(9), .centros-table td:nth-child(9),
.centros-table th:nth-child(10), .centros-table td:nth-child(10){width:145px !important; min-width:145px !important; max-width:145px !important; text-align:center !important;}
.centros-table th:nth-child(11), .centros-table td:nth-child(11){width:100px !important; min-width:100px !important; max-width:100px !important; text-align:center !important;}
.centros-table .center-name{
    display:block !important;
    white-space: normal !important;
    overflow-wrap: anywhere !important;
    word-break: normal !important;
    font-size: .93rem !important;
    line-height: 1.22 !important;
    margin-bottom: 2px !important;
}
.centros-table .center-meta{
    display:block !important;
    line-height: 1.15 !important;
}
.centros-table .a-lograr-input,
.centros-table .score-input{
    width: 118px !important;
    min-width: 118px !important;
    max-width: 118px !important;
    height: 40px !important;
    text-align: center !important;
    font-size: .95rem !important;
    font-weight: 900 !important;
    font-variant-numeric: tabular-nums !important;
}
.centros-table .a-lograr-input{width:130px !important; min-width:130px !important; max-width:130px !important;}
.detail-base-title{
    position: sticky !important;
    top: 0 !important;
    z-index: 22 !important;
}
.detail-base-section{
    overflow: visible !important;
}

/* Evita que el área inferior del modal tape columnas y mejora scroll */
.stage-scroll,
#tab-equipo > div[style*="overflow:auto"]{
    scrollbar-gutter: stable both-edges;
}
.modal-body{
    padding-bottom: 90px !important;
}


/* UX FINAL: detalle de centros en panel lateral, sin scroll horizontal del modal */
.modal-content{position:relative;overflow:hidden;}
.modal-content:has(.centros-drawer.open) .modal-header,
.modal-content:has(.centros-drawer.open) .modal-tabs,
.modal-content:has(.centros-drawer.open) .modal-body,
.modal-content:has(.centros-drawer.open) .modal-footer{visibility:hidden;}
.centros-drawer{
    position:absolute!important;
    inset:0!important;
    width:100%!important;
    max-width:none!important;
    height:100%!important;
    background:#ffffff!important;
    border-left:none!important;
    box-shadow:none!important;
    z-index:999!important;
    display:none;
    flex-direction:column;
    overflow:hidden;
}
.centros-drawer.open{display:flex!important;}
.centros-drawer-header{
    flex:0 0 auto;
    position:sticky!important;
    top:0!important;
    z-index:20!important;
    background:linear-gradient(180deg,#f0f9ff,#e0f2fe)!important;
    border-bottom:1px solid #bae6fd!important;
    padding:14px 18px!important;
    display:flex!important;
    align-items:center!important;
    justify-content:space-between!important;
    gap:16px!important;
}
.centros-drawer-title{
    margin:0!important;
    color:#075985!important;
    font-size:1.12rem!important;
    line-height:1.25!important;
    font-weight:900!important;
}
.centros-drawer-sub{
    margin-top:4px!important;
    color:#334155!important;
    font-size:.9rem!important;
    font-weight:700!important;
}
.centros-drawer-close{
    background:#ffffff!important;
    border:1px solid #bae6fd!important;
    color:#075985!important;
    border-radius:999px!important;
    padding:10px 16px!important;
    font-weight:900!important;
    cursor:pointer!important;
    display:inline-flex!important;
    align-items:center!important;
    gap:8px!important;
    box-shadow:0 4px 14px rgba(15,23,42,.08)!important;
    white-space:nowrap!important;
}
.centros-drawer-close:hover{background:#075985!important;color:#fff!important;border-color:#075985!important;}
.centros-drawer-body{
    flex:1 1 auto!important;
    min-height:0!important;
    overflow:auto!important;
    background:#f8fafc!important;
    padding:16px 18px 20px!important;
}
.centros-drawer .detail-base-section{
    border:1px solid #dbeafe!important;
    border-radius:14px!important;
    margin-bottom:16px!important;
    overflow:hidden!important;
    background:#fff!important;
    box-shadow:0 8px 24px rgba(15,23,42,.05)!important;
}
.centros-drawer .detail-base-title{
    position:sticky!important;
    top:0!important;
    z-index:12!important;
    display:flex!important;
    justify-content:space-between!important;
    align-items:center!important;
    gap:12px!important;
    padding:12px 14px!important;
    background:#eff6ff!important;
    color:#075985!important;
    font-weight:900!important;
    border-bottom:1px solid #dbeafe!important;
}
.centros-drawer .detail-base-title .count{font-size:.88rem!important;color:#64748b!important;font-weight:900!important;}
.centros-drawer .centros-detail-body{
    padding:0!important;
    max-height:calc(95vh - 180px)!important;
    overflow:auto!important;
    background:#ffffff!important;
}
.centros-drawer .centros-table{
    width:100%!important;
    min-width:0!important;
    max-width:100%!important;
    table-layout:fixed!important;
    border-collapse:separate!important;
    border-spacing:0!important;
}
.centros-drawer .centros-table th,
.centros-drawer .centros-table td{
    box-sizing:border-box!important;
    white-space:normal!important;
    overflow-wrap:anywhere!important;
    vertical-align:middle!important;
}
.centros-drawer .centros-table th{
    position:sticky!important;
    top:0!important;
    z-index:10!important;
    background:#e0f2fe!important;
    color:#075985!important;
    font-size:.78rem!important;
    font-weight:900!important;
    padding:10px 9px!important;
    text-align:left!important;
    border-bottom:1px solid #bae6fd!important;
}
.centros-drawer .centros-table td{
    background:#fff!important;
    padding:10px 9px!important;
    border-bottom:1px solid #e2e8f0!important;
}
.centros-drawer .centros-table tbody tr:nth-child(even) td{background:#f8fafc!important;}
.centros-drawer .centros-table th:nth-child(1),.centros-drawer .centros-table td:nth-child(1){width:8%!important;min-width:0!important;max-width:none!important;}
.centros-drawer .centros-table th:nth-child(2),.centros-drawer .centros-table td:nth-child(2){width:22%!important;min-width:0!important;max-width:none!important;}
.centros-drawer .centros-table th:nth-child(3),.centros-drawer .centros-table td:nth-child(3){width:12%!important;min-width:0!important;max-width:none!important;}
.centros-drawer .centros-table th:nth-child(4),.centros-drawer .centros-table td:nth-child(4){width:14%!important;min-width:0!important;max-width:none!important;}
.centros-drawer .centros-table th:nth-child(5),.centros-drawer .centros-table td:nth-child(5){width:11%!important;min-width:0!important;max-width:none!important;text-align:center!important;}
.centros-drawer .centros-table th:nth-child(6),.centros-drawer .centros-table td:nth-child(6),
.centros-drawer .centros-table th:nth-child(7),.centros-drawer .centros-table td:nth-child(7),
.centros-drawer .centros-table th:nth-child(8),.centros-drawer .centros-table td:nth-child(8){width:5%!important;min-width:0!important;max-width:none!important;text-align:center!important;}
.centros-drawer .centros-table th:nth-child(9),.centros-drawer .centros-table td:nth-child(9),
.centros-drawer .centros-table th:nth-child(10),.centros-drawer .centros-table td:nth-child(10){width:8%!important;min-width:0!important;max-width:none!important;text-align:center!important;}
.centros-drawer .centros-table th:nth-child(11),.centros-drawer .centros-table td:nth-child(11){width:7%!important;min-width:0!important;max-width:none!important;text-align:center!important;}
.centros-drawer .center-name{font-weight:900!important;color:#0f172a!important;line-height:1.25!important;overflow-wrap:anywhere!important;}
.centros-drawer .center-meta{font-size:.75rem!important;color:#64748b!important;margin-top:3px!important;}
.centros-drawer .prog-badge{white-space:normal!important;text-align:center!important;font-size:.76rem!important;}
.centros-drawer .a-lograr-input,.centros-drawer .score-input{
    width:100%!important;
    min-width:0!important;
    max-width:100%!important;
    height:38px!important;
    text-align:center!important;
    font-weight:900!important;
    padding:6px!important;
    font-size:.95rem!important;
}
.centros-drawer-empty{padding:18px;border-radius:12px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-weight:800;}
@media (max-width:1050px){
    .centros-drawer .centros-table{min-width:980px!important;}
    .centros-drawer .centros-detail-body{overflow:auto!important;}
}


/* Últimos ajustes: mes actual, estado porcentual, programado/cumplido y columnas */
.code-pill.month{background:#ecfdf5;color:#166534;border-color:#bbf7d0}.agenda-sticky-inner{grid-template-columns:1fr 170px 150px 150px!important}.activity-pct-badge{display:inline-flex;align-items:center;justify-content:center;min-height:38px;min-width:110px;border-radius:999px;font-weight:900;font-size:1rem;border:1px solid transparent}.activity-red{background:#fee2e2;color:#991b1b;border-color:#fecaca}.activity-orange{background:#ffedd5;color:#9a3412;border-color:#fed7aa}.activity-softgreen{background:#dcfce7;color:#166534;border-color:#bbf7d0}.activity-green{background:#14532d;color:#fff;border-color:#14532d}.subgrid-table{min-width:1500px;table-layout:fixed}.subgrid-card{overflow:auto}.subgrid-table th,.subgrid-table td{vertical-align:middle}.a-lograr-input,.cumplido-input,.score-input{max-width:118px!important;min-width:96px!important;text-align:center;font-weight:900}.btn-delete-row{color:#dc2626;border-color:#fecaca;background:#fff5f5}.btn-delete-row:hover{background:#fee2e2}.centros-table{min-width:1240px!important}.centros-table th:nth-child(2),.centros-table td:nth-child(2){min-width:280px}.centros-table th:nth-child(5),.centros-table td:nth-child(5),.centros-table th:nth-child(6),.centros-table td:nth-child(6){width:130px}.pct-red{background:#fee2e2;color:#991b1b}.pct-yellow{background:#ffedd5;color:#9a3412}.pct-softgreen{background:#dcfce7;color:#166534}.pct-darkgreen{background:#14532d;color:#fff}

/* ========================================================
   AJUSTE VISUAL SUBGRID: Responsable + columnas compactas
   ======================================================== */
.subgrid-card{
    overflow-x: auto !important;
    overflow-y: visible !important;
}
.subgrid-table{
    width: 100% !important;
    min-width: 1240px !important;
    table-layout: fixed !important;
    border-collapse: separate !important;
    border-spacing: 0 !important;
}
.subgrid-table th,
.subgrid-table td{
    box-sizing: border-box !important;
    overflow: visible !important;
    vertical-align: middle !important;
}
.subgrid-table th:nth-child(1),
.subgrid-table td:nth-child(1){width: 20% !important; min-width: 220px !important;}
.subgrid-table th:nth-child(2),
.subgrid-table td:nth-child(2){width: 7.5% !important; min-width: 92px !important;}
.subgrid-table th:nth-child(3),
.subgrid-table td:nth-child(3),
.subgrid-table th:nth-child(4),
.subgrid-table td:nth-child(4),
.subgrid-table th:nth-child(5),
.subgrid-table td:nth-child(5),
.subgrid-table th:nth-child(6),
.subgrid-table td:nth-child(6){width: 8.2% !important; min-width: 98px !important;}
.subgrid-table th:nth-child(7),
.subgrid-table td:nth-child(7){width: 6.5% !important; min-width: 76px !important; text-align:center !important;}
.subgrid-table th:nth-child(8),
.subgrid-table td:nth-child(8){width: 14.5% !important; min-width: 170px !important;}
.subgrid-table th:nth-child(9),
.subgrid-table td:nth-child(9){width: 14.5% !important; min-width: 170px !important;}
.subgrid-table th:nth-child(10),
.subgrid-table td:nth-child(10){width: 6.2% !important; min-width: 72px !important; text-align:center !important;}
.subgrid-table th:nth-child(11),
.subgrid-table td:nth-child(11){width: 6.4% !important; min-width: 78px !important; text-align:center !important;}
.resp-cell{
    display:flex !important;
    align-items:flex-start !important;
    gap:7px !important;
    min-width:0 !important;
}
.resp-icon{
    color:var(--ah-primary) !important;
    width:18px !important;
    flex:0 0 18px !important;
    margin-top:2px !important;
}
.resp-name{
    display:flex !important;
    flex-direction:column !important;
    min-width:0 !important;
    line-height:1.12 !important;
}
.resp-first,
.resp-last{
    display:block !important;
    font-weight:900 !important;
    color:#0f172a !important;
    white-space:normal !important;
    overflow-wrap:anywhere !important;
}
.resp-last{font-size:.86rem !important; color:#334155 !important; margin-top:1px !important;}
.base-labels-wrap{margin-left:25px !important; gap:5px !important;}
.base-badge{font-size:.68rem !important; padding:3px 8px !important; line-height:1.15 !important;}
.subgrid-table .code-pill.ext{
    max-width:100% !important;
    white-space:normal !important;
    text-align:center !important;
    justify-content:center !important;
    font-size:.72rem !important;
    line-height:1.12 !important;
    padding:6px 8px !important;
}
.subgrid-table .a-lograr-input,
.subgrid-table .cumplido-input,
.subgrid-table .score-input{
    width:100% !important;
    max-width:104px !important;
    min-width:88px !important;
    height:42px !important;
    padding:8px 8px !important;
    text-align:center !important;
    font-size:.95rem !important;
    font-weight:900 !important;
    font-variant-numeric:tabular-nums !important;
}
.subgrid-table .pct-badge{min-width:58px !important; padding:6px 8px !important;}
.subgrid-table .custom-multiselect{min-width:0 !important; width:100% !important;}
.subgrid-table .multiselect-select-box{
    min-height:42px !important;
    padding:6px 9px !important;
    gap:4px !important;
}
.subgrid-table .multi-tag{font-size:.68rem !important; max-width:130px !important; overflow:hidden !important; text-overflow:ellipsis !important; white-space:nowrap !important;}
.subgrid-table .fill-drag-handle{width:20px !important; height:20px !important; margin-left:2px !important;}
.subgrid-table .btn-delete-row,
.subgrid-table .btn-eye{
    width:42px !important;
    height:38px !important;
    padding:0 !important;
    justify-content:center !important;
    border-radius:10px !important;
    overflow:hidden !important;
}
.subgrid-table .btn-eye{width:58px !important; font-size:.74rem !important;}
.subgrid-table .btn-delete-row i{margin:0 !important;}

.metas-lock-toolbar{display:flex;align-items:center;gap:14px;justify-content:space-between;background:#fffbeb;border:1px solid #fde68a;border-radius:12px;padding:14px 16px;margin-bottom:16px;color:#92400e}.metas-lock-help{font-size:.82rem;color:#64748b;margin-top:4px}.metas-lock-toolbar code{background:#fff7ed;border:1px solid #fed7aa;border-radius:5px;padding:1px 5px}.metas-unlocked-badge{display:inline-flex;align-items:center;gap:7px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:999px;padding:8px 12px;font-weight:900;font-size:.82rem}#metas-fieldset{border:0;padding:0;margin:0;min-width:0}#metas-fieldset:disabled{opacity:.82}#metas-fieldset:disabled input{background:#f1f5f9;color:#475569;cursor:not-allowed}

/* Detalle de centros: columnas dinámicas según el tipo de lugar */
.centros-drawer .centros-table.without-ages th.col-type,
.centros-drawer .centros-table.without-ages td.col-type{width:8%!important;}
.centros-drawer .centros-table.without-ages th.col-center,
.centros-drawer .centros-table.without-ages td.col-center{width:25%!important;}
.centros-drawer .centros-table.without-ages th.col-community,
.centros-drawer .centros-table.without-ages td.col-community{width:13%!important;}
.centros-drawer .centros-table.without-ages th.col-caserio,
.centros-drawer .centros-table.without-ages td.col-caserio{width:15%!important;}
.centros-drawer .centros-table.without-ages th.col-prog,
.centros-drawer .centros-table.without-ages td.col-prog{width:12%!important;text-align:center!important;}
.centros-drawer .centros-table.without-ages th.col-cumpl,
.centros-drawer .centros-table.without-ages td.col-cumpl{width:10%!important;text-align:center!important;}
.centros-drawer .centros-table.without-ages th.col-score,
.centros-drawer .centros-table.without-ages td.col-score{width:8%!important;text-align:center!important;}
.centros-drawer .centros-table.without-ages th.col-pct,
.centros-drawer .centros-table.without-ages td.col-pct{width:6%!important;text-align:center!important;}

.centros-drawer .centros-table.with-ages th.col-type,
.centros-drawer .centros-table.with-ages td.col-type{width:7%!important;}
.centros-drawer .centros-table.with-ages th.col-center,
.centros-drawer .centros-table.with-ages td.col-center{width:20%!important;}
.centros-drawer .centros-table.with-ages th.col-community,
.centros-drawer .centros-table.with-ages td.col-community{width:10%!important;}
.centros-drawer .centros-table.with-ages th.col-caserio,
.centros-drawer .centros-table.with-ages td.col-caserio{width:12%!important;}
.centros-drawer .centros-table.with-ages th.col-prog,
.centros-drawer .centros-table.with-ages td.col-prog{width:10%!important;text-align:center!important;}
.centros-drawer .centros-table.with-ages th.col-cumpl,
.centros-drawer .centros-table.with-ages td.col-cumpl{width:8%!important;text-align:center!important;}
.centros-drawer .centros-table.with-ages th.col-age,
.centros-drawer .centros-table.with-ages td.col-age{width:5%!important;text-align:center!important;}
.centros-drawer .centros-table.with-ages th.col-score,
.centros-drawer .centros-table.with-ages td.col-score{width:7%!important;text-align:center!important;}
.centros-drawer .centros-table.with-ages th.col-pct,
.centros-drawer .centros-table.with-ages td.col-pct{width:6%!important;text-align:center!important;}

.centros-drawer .centros-table th,
.centros-drawer .centros-table td{
    min-width:0!important;
    max-width:none!important;
}

/* Autoguardado integral y lugar global en Asignar Equipo */
.modal-footer{display:flex!important;align-items:center!important;justify-content:space-between!important;gap:12px!important;}
.team-global-toolbar{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px;padding:12px 14px;background:#f0f9ff;border:1px solid #bae6fd;border-radius:12px;}
.team-global-place-group{display:flex;align-items:center;gap:12px;flex-wrap:wrap;min-width:420px;}
.team-global-place-label{font-weight:900;color:#075985;white-space:nowrap;}
.team-global-location-control{width:min(560px,55vw)!important;min-width:300px!important;}
.team-global-location-control .multiselect-select-box{min-height:42px!important;background:#fff!important;}
.team-global-help{font-size:.78rem;color:#475569;font-weight:700;line-height:1.3;}
#team-assign-table.team-table{width:max-content!important;min-width:100%!important;table-layout:auto!important;}
#team-assign-table th:nth-child(1),#team-assign-table td:nth-child(1){width:44px!important;min-width:44px!important;max-width:44px!important;text-align:center!important;}
#team-assign-table th:nth-child(2),#team-assign-table td:nth-child(2){width:320px!important;min-width:320px!important;max-width:320px!important;}
#team-assign-table th:nth-child(3),#team-assign-table td:nth-child(3){width:230px!important;min-width:230px!important;max-width:230px!important;}
#team-assign-table .team-month-col{width:118px!important;min-width:118px!important;max-width:118px!important;}
#team-assign-table .team-month-input{width:96px!important;min-width:96px!important;max-width:96px!important;}
#team-assign-table .team-total-meta,#team-assign-table .team-total-logro,#team-assign-table .team-total-dif,#team-assign-table .team-total-pct{width:118px!important;min-width:118px!important;max-width:118px!important;}
.center-memory-note{font-size:.75rem;color:#64748b;font-weight:700;}

/* Ajustes compactos, histórico y exportación XLSX */
.table-toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0 0 10px}
.table-toolbar h4,.table-toolbar h5{margin:0}
.btn-xlsx{background:#ecfdf5!important;color:#166534!important;border-color:#bbf7d0!important}
.btn-xlsx:hover{background:#166534!important;color:#fff!important;border-color:#166534!important}
.history-link{background:#fff7ed!important;color:#9a3412!important;border-color:#fed7aa!important;text-decoration:none!important}
.history-link:hover{background:#9a3412!important;color:#fff!important;border-color:#9a3412!important}
#team-assign-table.team-table{width:100%!important;min-width:1120px!important;table-layout:fixed!important}
#team-assign-table.team-table.show-all-months{width:max-content!important;min-width:6100px!important;table-layout:fixed!important}
#team-assign-table th:nth-child(1),#team-assign-table td:nth-child(1){width:44px!important;min-width:44px!important;max-width:44px!important}
#team-assign-table th:nth-child(2),#team-assign-table td:nth-child(2){width:280px!important;min-width:280px!important;max-width:280px!important}
#team-assign-table th:nth-child(3),#team-assign-table td:nth-child(3){width:170px!important;min-width:170px!important;max-width:170px!important}
#team-assign-table .team-month-col{width:96px!important;min-width:96px!important;max-width:96px!important;padding:8px 6px!important}
#team-assign-table .team-month-input{width:78px!important;min-width:78px!important;max-width:78px!important;height:38px!important;padding:6px!important}
#team-assign-table .team-total-meta,#team-assign-table .team-total-logro,#team-assign-table .team-total-dif,#team-assign-table .team-total-pct{width:92px!important;min-width:92px!important;max-width:92px!important;padding:8px 6px!important}
.team-global-toolbar{padding:10px 12px!important}
.agenda-heading-actions{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.export-clean-copy{position:absolute!important;left:-99999px!important;top:-99999px!important}
@media(max-width:1200px){
  #team-assign-table.team-table{min-width:1040px!important}
  #team-assign-table th:nth-child(2),#team-assign-table td:nth-child(2){width:245px!important;min-width:245px!important;max-width:245px!important}
  #team-assign-table th:nth-child(3),#team-assign-table td:nth-child(3){width:145px!important;min-width:145px!important;max-width:145px!important}
}

/* ========================================================
   AJUSTE VISUAL: ASIGNAR EQUIPO SIN COLUMNAS TRASLAPADAS
   ======================================================== */
#team-assign-table{
    border-collapse:separate!important;
    border-spacing:0!important;
    font-variant-numeric:tabular-nums!important;
}
#team-assign-table thead tr:first-child th.team-month-col{
    width:384px!important;
    min-width:384px!important;
    max-width:384px!important;
    text-align:center!important;
}
#team-assign-table thead tr:first-child th:last-child{
    width:368px!important;
    min-width:368px!important;
    max-width:368px!important;
    text-align:center!important;
}
#team-assign-table thead tr:nth-child(2) th.team-month-col,
#team-assign-table tbody td.team-month-col{
    width:96px!important;
    min-width:96px!important;
    max-width:96px!important;
    padding:8px 7px!important;
    text-align:center!important;
}
#team-assign-table thead tr:nth-child(2) th:not(.team-month-col):nth-last-child(-n+4),
#team-assign-table tbody td.team-total-meta,
#team-assign-table tbody td.team-total-logro,
#team-assign-table tbody td.team-total-dif,
#team-assign-table tbody td.team-total-pct{
    width:92px!important;
    min-width:92px!important;
    max-width:92px!important;
    padding:8px 7px!important;
    text-align:center!important;
}
#team-assign-table .team-month-input{
    display:block!important;
    width:80px!important;
    min-width:80px!important;
    max-width:80px!important;
    height:40px!important;
    margin:0 auto!important;
    padding:6px 8px!important;
    box-sizing:border-box!important;
}
#team-assign-table .pct-badge{
    min-width:58px!important;
    box-sizing:border-box!important;
}
#team-assign-table th{
    white-space:nowrap!important;
}
#team-assign-table td:nth-child(2),
#team-assign-table td:nth-child(3){
    overflow:hidden!important;
}
#team-assign-table.team-table:not(.show-all-months){
    width:max-content!important;
    min-width:1248px!important;
    table-layout:fixed!important;
}
#team-assign-table.team-table.show-all-months{
    width:max-content!important;
    min-width:5840px!important;
    table-layout:fixed!important;
}
@media(max-width:1350px){
    #team-assign-table.team-table:not(.show-all-months){
        min-width:1190px!important;
    }
    #team-assign-table th:nth-child(2),
    #team-assign-table td:nth-child(2){
        width:245px!important;
        min-width:245px!important;
        max-width:245px!important;
    }
    #team-assign-table th:nth-child(3),
    #team-assign-table td:nth-child(3){
        width:145px!important;
        min-width:145px!important;
        max-width:145px!important;
    }
}

#btn-force-save:hover { background: #f0fdf4 !important; }
.btn-archive-toggle { background:#fff1f2; color:#991b1b; border-color:#fecaca; }
.btn-archive-toggle:hover { background:#fee2e2; border-color:#fca5a5; }

/* Evita calcular el diseño de subtablas que aún están fuera de pantalla. */
.subgrid-wrapper{content-visibility:auto;contain-intrinsic-size:400px}
.data-card.filtered-out,.data-card.paged-out{display:none!important}
.monitor-pagination{display:flex;align-items:center;justify-content:center;gap:10px;margin:22px 0;flex-wrap:wrap}.monitor-pagination button{border:1px solid var(--border);background:#fff;color:#334155;border-radius:8px;padding:8px 13px;font-weight:800;cursor:pointer}.monitor-pagination button:disabled{opacity:.4;cursor:not-allowed}.monitor-pagination button.active{background:var(--ah-primary);color:#fff;border-color:var(--ah-primary)}.monitor-pagination-info{font-size:.84rem;color:#64748b;font-weight:700}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main-wrapper">
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 25px;">
        <h1 style="margin:0;color:var(--text-main);font-size:2rem;font-weight:900"><i class="fa-solid fa-compass" style="color:var(--ah-primary)"></i> Monitoreo Operativo General</h1>
        <button class="btn-action" onclick="$('#archiveModal').css('display','flex')"><i class="fa-solid fa-box-archive"></i> Ver Actividades Ocultas (<?php echo count($tareas_ocultas); ?>)</button>
    </div>

    <?php echo $msg; ?>

    <div class="metrics-dashboard">
        <div class="metric-card"><div class="metric-icon" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-layer-group"></i></div><div class="metric-info"><h4>Líneas Visibles</h4><p id="count-total">0</p></div></div>
        <div class="metric-card"><div class="metric-icon" style="background:#dcfce7;color:#16a34a"><i class="fa-solid fa-check-double"></i></div><div class="metric-info"><h4>Completadas</h4><p id="count-comp">0</p></div></div>
        <div class="metric-card"><div class="metric-icon" style="background:#fef3c7;color:#d97706"><i class="fa-solid fa-person-digging"></i></div><div class="metric-info"><h4>En Proceso</h4><p id="count-proc">0</p></div></div>
        <div class="metric-card" style="border-left:5px solid var(--ah-primary)"><div class="metric-icon" style="background:#f1f5f9;color:var(--ah-primary)"><i class="fa-solid fa-chart-line"></i></div><div class="metric-info"><h4>Alcance Promedio</h4><p id="count-rend">0%</p></div></div>
    </div>

    <div class="filter-panel">
        <div class="filter-row">
            <div style="flex-grow:1;min-width:250px"><input type="search" id="filter-search" name="monitor_search_q_ignore" readonly class="form-control" placeholder="Buscar por actividad o palabra clave..." style="background:#f8fafc" autocomplete="off" autocapitalize="off" spellcheck="false" value=""></div>
            <div style="min-width:230px"><select id="filter-prog" class="form-control"><option value="">Todos los programas y sectores</option><optgroup label="Programas"><option value="programa:crecer" selected>CRECER</option><option value="programa:redes">REDES</option><option value="programa:tejiendo">TEJIENDO MI FUTURO</option></optgroup><optgroup label="Sectores sin programa"><option value="sector:z_administracion">Z_Administración</option><option value="sector:x_patrocinio">X_Patrocinio</option><option value="sector:ml_monitoreo">ML_Monitoreo</option></optgroup></select></div>
            <div style="min-width:250px"><select id="filter-tec" class="form-control"><option value="">Todos los Líderes</option><option value="Trabajo en Equipo">Trabajo en Equipo</option><?php foreach($tecnicos_list as $t): ?><option value="<?php echo htmlspecialchars($t); ?>"><?php echo htmlspecialchars($t); ?></option><?php endforeach; ?></select></div>
            <div style="min-width:150px"><select id="filter-est" class="form-control"><option value="">Todos los Estados</option><?php foreach($estados as $e): ?><option value="<?php echo htmlspecialchars($e); ?>"><?php echo htmlspecialchars($e); ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="filter-row smart-toggles">
            <label class="toggle-label active-toggle"><input type="checkbox" id="toggle-admin" checked><span>Ocultar Administrativos</span></label>
            <label class="toggle-label active-toggle"><input type="checkbox" id="toggle-zero" checked><span>Ocultar Gestión sin Meta</span></label>
            <label class="toggle-label active-toggle"><input type="checkbox" id="toggle-main-progs" checked><span>Solo Programas Prioritarios</span></label>
            <button type="button" id="btn-show-all" class="btn-action" style="margin-left:auto;border-radius:30px"><i class="fa-solid fa-expand"></i> Quitar Filtros</button>
        </div>
        <div class="filter-row month-filters"><span><i class="fa-solid fa-filter"></i> Mostrar mes:</span><?php foreach($meses_keys as $k=>$nom): ?><label class="month-pill"><input type="checkbox" class="toggle-month" value="<?php echo $k; ?>"> <?php echo $nom; ?></label><?php endforeach; ?></div>
    </div>

    <div class="task-list" id="task-container">
    <?php if(count($tareas)>0): foreach($tareas as $t):
        $estado_actual = $t['operativo_estado'] ?? 'Pendiente';
        $card_class = ($estado_actual === 'Completado') ? 'task-card completed' : 'task-card pending';
        $badge_class = ($estado_actual === 'En Proceso') ? 'proceso' : (($estado_actual === 'Completado') ? 'completado' : 'pendiente');
        $active_months=[];$html_months='';
        foreach($meses_keys as $k=>$nom){ if((float)($t['op_act_'.$k]??0)>0 || (float)($t['op_part_'.$k]??0)>0){ $active_months[]=$k; $html_months.="<span class='month-mini-badge'>$nom</span>"; } }
        $descripcion_principal = trim($t['descripcion_actividad'] ?? '') ?: trim($t['marco_logico'] ?? 'Actividad');
        $codigo_visible = trim($t['codigo_maestro'] ?? '') ?: poa_codigo_corto($t['marco_logico'] ?? '');
        $task_json = htmlspecialchars(json_encode([
            'id'=>$t[$col_id], 'actividad'=>$descripcion_principal, 'codigo'=>$codigo_visible, 'extension'=>trim($t['ext'] ?? ''), 'marco_logico'=>$t['marco_logico'] ?? '',
            'programa'=>$t['programa'] ?? '', 'sector'=>$t['sector'] ?? '', 'tecnico'=>$t['operativo_tecnico'] ?? 'Trabajo en Equipo', 'comunidad'=>$t['operativo_comunidad'] ?? '', 'periodo'=>$t['operativo_periodo'] ?? '', 'estado'=>$estado_actual, 't_part'=>$t['tipo_participante'] ?? '',
            'm_act_obj'=>(float)($t['meta_actividades'] ?? 0), 'm_act_alc'=>(float)($t['meta_actividades_alc'] ?? 0), 'm_part_obj'=>(float)($t['operativo_meta_obj'] ?? 0), 'm_part_alc'=>(float)($t['operativo_meta_alc'] ?? 0),
            'info_adicional'=>$t['operativo_info_adicional'] ?? '', 'team_lugares'=>$t['equipo_lugares_json'] ?? '[]', 'etapas'=>$etapas_map[$t[$col_id]] ?? [],
            'op_act'=>array_combine(array_keys($meses_keys), array_map(fn($m)=>$t['op_act_'.$m]??0, array_keys($meses_keys))),
            'op_part'=>array_combine(array_keys($meses_keys), array_map(fn($m)=>$t['op_part_'.$m]??0, array_keys($meses_keys))),
            'asignaciones'=>$asignaciones_map[$t[$col_id]] ?? []
        ], JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
    ?>
        <div class="<?php echo $card_class; ?> data-card" data-prog="<?php echo htmlspecialchars($t['programa'] ?? ''); ?>" data-sec="<?php echo strtolower(htmlspecialchars($t['sector'] ?? '')); ?>" data-tec="<?php echo htmlspecialchars($t['operativo_tecnico'] ?? ''); ?>" data-est="<?php echo htmlspecialchars($estado_actual); ?>" data-metap="<?php echo (float)($t['operativo_meta_obj'] ?? 0); ?>" data-alcp="<?php echo (float)($t['operativo_meta_alc'] ?? 0); ?>" data-meses="<?php echo implode(',', $active_months); ?>">
            <div class="task-main"><div class="code-corner"><?php if($codigo_visible): ?><span class="code-pill ml"><i class="fa-solid fa-hashtag"></i> <?php echo htmlspecialchars($codigo_visible); ?></span><?php endif; ?><?php if(trim($t['ext'] ?? '') !== ''): ?><span class="code-pill ext"><i class="fa-solid fa-code-branch"></i> EXT <?php echo htmlspecialchars(trim($t['ext'])); ?></span><?php endif; ?></div><h3 class="searchable-text"><?php echo htmlspecialchars($descripcion_principal); ?></h3><div><?php echo $html_months; ?></div><span class="prog-badge"><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($t['programa'] ?? ''); ?></span><span class="prog-badge" style="background:#e0f2fe;color:#0284c7"><i class="fa-solid fa-layer-group"></i> <?php echo htmlspecialchars($t['sector'] ?? ''); ?></span></div>
            <div class="task-meta searchable-text"><div><i class="fa-solid fa-users"></i> Líder: <strong><?php echo htmlspecialchars($t['operativo_tecnico'] ?? 'Trabajo en Equipo'); ?></strong></div><div><i class="fa-solid fa-map-pin"></i> Base: <strong><?php echo htmlspecialchars($t['operativo_comunidad'] ?? 'General'); ?></strong></div><div><i class="fa-solid fa-calendar"></i> Periodo: <strong><?php echo htmlspecialchars($t['operativo_periodo'] ?? '-'); ?></strong></div></div>
            <div class="task-meta"><div style="color:var(--ah-primary)"><i class="fa-solid fa-person"></i> Público: <strong><?php echo htmlspecialchars($t['tipo_participante'] ?? ''); ?></strong></div><div><i class="fa-solid fa-clipboard-check"></i> Actividades: <strong><?php echo (float)($t['meta_actividades_alc'] ?? 0); ?> / <?php echo (float)($t['meta_actividades'] ?? 0); ?></strong></div><div><i class="fa-solid fa-user-check"></i> Alcanzados: <strong><?php echo (float)($t['operativo_meta_alc'] ?? 0); ?> / <?php echo (float)($t['operativo_meta_obj'] ?? 0); ?></strong></div></div>
            <div style="display:flex;flex-direction:column;gap:10px;align-items:center">
                <span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($estado_actual); ?></span>
                <button class="btn-action" data-task="<?php echo $task_json; ?>" onclick="openUpdateModal(this)"><i class="fa-solid fa-pen-to-square"></i> Detallar</button>
                <button class="btn-action btn-mini btn-archive-toggle" onclick="toggleOcultar(<?php echo $t['id']; ?>, 1)"><i class="fa-solid fa-eye-slash"></i> Ocultar</button>
            </div>
        </div>
    <?php endforeach; endif; ?>
    </div>
    <div id="monitor-pagination" class="monitor-pagination" aria-label="Paginación de actividades"></div>
</main>

<div id="updateModal" class="modal-overlay"><div class="modal-content">
    <div class="modal-header"><h2 style="margin:0;font-size:1.35rem"><i class="fa-solid fa-sliders"></i> Panel de Ejecución Programática</h2><button type="button" onclick="closeModal('updateModal')" style="background:none;border:0;font-size:1.45rem;cursor:pointer;color:#64748b"><i class="fa-solid fa-xmark"></i></button></div>
    <div class="modal-tabs"><button type="button" class="modal-tab-btn active" onclick="switchModalTab('tab-equipo', this)"><i class="fa-solid fa-map-location-dot"></i> Asignar Equipo</button><button type="button" class="modal-tab-btn" onclick="switchModalTab('tab-metas', this)"><i class="fa-solid fa-bullseye"></i> Metas y Meses</button><button type="button" class="modal-tab-btn" onclick="switchModalTab('tab-etapas', this)"><i class="fa-solid fa-diagram-project"></i> Agenda Técnico (Etapas)</button><button type="button" class="modal-tab-btn" onclick="switchModalTab('tab-notas', this)"><i class="fa-solid fa-file-word"></i> Notas y Materiales</button></div>
    <form method="POST" id="formUpdate" style="display:flex;flex-direction:column;overflow:hidden;flex-grow:1">
        <input type="hidden" name="action" value="update_task"><input type="hidden" name="task_id" id="upd_task_id"><input type="hidden" name="metas_authorized" id="metas_authorized" value="0"><input type="hidden" name="etapas_loaded" id="etapas_loaded" value="0">
        <div class="modal-body">
            <div class="agenda-sticky" id="agendaSticky">
                <div class="agenda-sticky-inner">
                    <div>
                        <div class="code-corner" style="margin-bottom:6px">
                            <span class="code-pill ml" id="lbl_codigo_modal" style="display:none"><i class="fa-solid fa-hashtag"></i> <span></span></span>
                            <span class="code-pill ext" id="lbl_ext_modal" style="display:none"><i class="fa-solid fa-code-branch"></i> EXT <span></span></span>
                            <span class="code-pill month"><i class="fa-solid fa-calendar-days"></i> Mes en registro: <span id="lbl_mes_actual_modal">-</span></span>
                            <a id="btn-historial-actividad" class="btn-action btn-mini history-link" href="#" target="_blank" rel="noopener"><i class="fa-solid fa-clock-rotate-left"></i> Ver histórico</a>
                            <!-- NUEVO BOTÓN: Autollenar con mes anterior -->
                            <button type="button" class="btn-action btn-mini" style="background:#fffbeb;color:#92400e;border-color:#fde68a" onclick="copiarMesAnterior()"><i class="fa-solid fa-clone"></i> Autollenar (mes ant.)</button>
                        </div>
                        <p class="agenda-title" id="lbl_actividad"></p>
                    </div>
                    <div class="agenda-status"><label>Estado de actividad</label><input type="hidden" name="estado" id="upd_estado" value="0%"><div id="lbl_estado_porcentaje" class="activity-pct-badge activity-red">0%</div></div>
                    <div class="agenda-meta month-meta"><span>Meta del mes</span><strong id="lbl_meta_mes_actual">0</strong></div>
                    <div class="agenda-meta"><span>Meta Global</span><strong id="lbl_meta_global">0</strong></div>
                </div>
            </div>
            <div id="tab-equipo" class="modal-tab-content active"><div class="team-global-toolbar"><div class="team-global-place-group"><span class="team-global-place-label"><i class="fa-solid fa-location-dot"></i> Lugar(es) para todo el equipo</span><div id="team-global-location-host"></div><span class="team-global-help">Al seleccionar tipos de centro, el PROG. del mes actual se calcula automáticamente para cada técnico según su base y la matrícula registrada en Gestión de Centros.</span></div><div style="display:flex;gap:10px;flex-wrap:wrap"><button type="button" class="btn-action btn-xlsx" onclick="exportTableXlsx('#team-assign-table','asignacion_equipo')"><i class="fa-solid fa-file-excel"></i> XLSX</button><button type="button" class="btn-action" onclick="toggleTeamMonths()"><i class="fa-solid fa-calendar-days"></i> Ver todos los meses</button><button type="button" class="btn-action" onclick="toggleNoBaseTechs()"><i class="fa-solid fa-users-slash"></i> Mostrar/Ocultar técnicos sin base</button></div></div><div style="max-height:52vh;overflow:auto;border:1px solid var(--border);border-radius:12px;background:white"><table id="team-assign-table" class="styled-table team-table" style="margin:0;border:none"><thead><tr><th rowspan="2">✓</th><th rowspan="2">Técnico</th><th rowspan="2">Base</th><?php foreach($meses_keys as $k=>$n): ?><th colspan="4" class="team-month-col team-month-<?php echo $k; ?>"><?php echo $n; ?></th><?php endforeach; ?><th colspan="4">Total Anual</th></tr><tr><?php foreach($meses_keys as $k=>$n): ?><th class="team-month-col team-month-<?php echo $k; ?>">Prog.</th><th class="team-month-col team-month-<?php echo $k; ?>">Logr.</th><th class="team-month-col team-month-<?php echo $k; ?>">Dif.</th><th class="team-month-col team-month-<?php echo $k; ?>">%</th><?php endforeach; ?><th>Prog.</th><th>Logr.</th><th>Dif.</th><th>%</th></tr></thead><tbody id="tabla_tecnicos_body"></tbody></table></div></div>
            <div id="tab-metas" class="modal-tab-content">
<div class="metas-lock-toolbar"><div><strong><i class="fa-solid fa-shield-halved"></i> Metas provenientes del POA</strong><div class="metas-lock-help">La programación mensual se carga directamente de <code>ah_poa</code>. Para modificarla debe validar la contraseña de su sesión.</div></div><button type="button" id="btn-unlock-metas" class="btn-action" onclick="openMetasPasswordModal()"><i class="fa-solid fa-lock"></i> Habilitar modificación</button><span id="metas-unlocked-badge" class="metas-unlocked-badge" style="display:none"><i class="fa-solid fa-lock-open"></i> Edición habilitada</span></div>
<fieldset id="metas-fieldset" disabled>
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;background:white;border:1px solid var(--border);border-radius:12px;padding:18px;margin-bottom:18px"><div><label>Total actividades</label><input type="number" step="1" name="meta_act_obj" id="upd_m_act_obj" class="form-control"></div><div><label>Actividades completadas</label><input type="number" step="1" name="meta_act_alc" id="upd_m_act_alc" class="form-control"></div><div><label>Total personas</label><input type="number" step="1" name="meta_part_obj" id="upd_m_part_obj" class="form-control"></div><div><label>Personas alcanzadas</label><input type="number" step="1" name="meta_part_alc" id="upd_m_part_alc" class="form-control"></div></div>
<div class="table-toolbar"><h4>Distribución de actividades</h4><button type="button" class="btn-action btn-mini btn-xlsx" onclick="exportTableXlsx('#tabla-metas-actividades','metas_actividades')"><i class="fa-solid fa-file-excel"></i> XLSX</button></div><table id="tabla-metas-actividades" class="styled-table"><thead><tr><th>Jul</th><th>Ago</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dic</th></tr></thead><tbody id="tabla_act_body_1"></tbody><thead><tr><th>Ene</th><th>Feb</th><th>Mar</th><th>Abr</th><th>May</th><th>Jun</th></tr></thead><tbody id="tabla_act_body_2"></tbody></table>
<div class="table-toolbar" style="margin-top:16px"><h4>Distribución de participantes</h4><button type="button" class="btn-action btn-mini btn-xlsx" onclick="exportTableXlsx('#tabla-metas-participantes','metas_participantes')"><i class="fa-solid fa-file-excel"></i> XLSX</button></div><table id="tabla-metas-participantes" class="styled-table"><thead><tr><th>Jul</th><th>Ago</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dic</th></tr></thead><tbody id="tabla_part_body_1"></tbody><thead><tr><th>Ene</th><th>Feb</th><th>Mar</th><th>Abr</th><th>May</th><th>Jun</th></tr></thead><tbody id="tabla_part_body_2"></tbody></table>
</fieldset></div>
<div id="tab-etapas" class="modal-tab-content"><div class="table-toolbar"><h4><i class="fa-solid fa-diagram-project"></i> Agenda técnico por etapas</h4><button type="button" class="btn-action btn-mini btn-xlsx" onclick="exportTableXlsx('#etapas-main-table','agenda_etapas')"><i class="fa-solid fa-file-excel"></i> XLSX</button></div><div class="stage-scroll"><table id="etapas-main-table" class="styled-table" style="min-width:1180px;margin:0;border:none"><thead style="position:sticky;top:0;z-index:20"><tr><th style="width:55%">Etapa</th><th style="width:18%">Unidades a evaluar</th><th style="width:18%">Responsable</th><th style="width:170px">Fecha máxima global</th></tr></thead><tbody id="tabla_etapas_body"></tbody></table></div></div>
            <div id="tab-notas" class="modal-tab-content"><textarea name="info_adicional" id="upd_info_adicional" rows="18" placeholder="Escriba guiones, tablas, enlaces, acuerdos o evidencia..."></textarea></div>
        </div>

            <div id="centrosDrawer" class="centros-drawer" aria-hidden="true" data-index="" data-key="">
                <div class="centros-drawer-header">
                    <div>
                        <h3 class="centros-drawer-title" id="centrosDrawerTitle"><i class="fa-solid fa-building-columns"></i> Centros</h3>
                        <div class="centros-drawer-sub" id="centrosDrawerSub"></div>
                    </div>
                    <button type="button" class="centros-drawer-close" onclick="closeCentrosDrawer()"><i class="fa-solid fa-arrow-left"></i> Volver a Agenda</button>
                </div>
                <div class="centros-drawer-body" id="centrosDrawerBody"></div>
            </div>
        <div class="modal-footer">
            <button type="button" id="btn-force-save" onclick="autosaveFullForm(true)" style="margin-right:auto;background:transparent;border:none;color:#166534;font-weight:800;font-size:1rem;cursor:pointer;display:flex;align-items:center;gap:8px;padding:8px 12px;border-radius:8px;transition:0.2s;">
                <i class="fa-solid fa-cloud-arrow-up" id="save-icon"></i> <span id="save-text">Guardado automático activo</span>
            </button>
            <button type="button" class="btn-primary" style="background:white;color:var(--text-main);border:1px solid var(--border)" onclick="closeModal('updateModal')">Cerrar</button>
        </div>
    </form>
</div></div>

<!-- MODAL ACTIVIDADES ARCHIVADAS -->
<div id="archiveModal" class="modal-overlay" onclick="if(event.target===this) $('#archiveModal').hide()">
    <div class="modal-content" style="max-width:800px; height:auto; max-height:85vh">
        <div class="modal-header">
            <h2 style="margin:0;font-size:1.35rem"><i class="fa-solid fa-box-archive"></i> Actividades Ocultas</h2>
            <button type="button" onclick="$('#archiveModal').hide()" style="background:none;border:0;font-size:1.45rem;cursor:pointer;color:#64748b"><i class="fa-solid fa-xmark"></i></button>
        </div>
        <div class="modal-body" style="padding:20px">
            <table class="styled-table">
                <thead><tr><th>Código</th><th>Actividad</th><th style="width:120px;text-align:center">Acción</th></tr></thead>
                <tbody>
                    <?php if(empty($tareas_ocultas)): ?>
                        <tr><td colspan="3" style="text-align:center;padding:30px;color:var(--text-muted)">No hay actividades ocultas.</td></tr>
                    <?php else: foreach($tareas_ocultas as $to): ?>
                        <tr>
                            <td><span class="code-pill ml"><i class="fa-solid fa-hashtag"></i> <?php echo htmlspecialchars($to['codigo_maestro'] ?: poa_codigo_corto($to['marco_logico'])); ?></span></td>
                            <td style="font-weight:700"><?php echo htmlspecialchars($to['descripcion_actividad'] ?: $to['marco_logico']); ?></td>
                            <td style="text-align:center"><button class="btn-action btn-mini" onclick="toggleOcultar(<?php echo $to['id']; ?>, 0)"><i class="fa-solid fa-eye"></i> Restaurar</button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div id="metasPasswordModal" class="catalog-mini-modal" onclick="if(event.target===this) closeMetasPasswordModal()"><div class="catalog-mini-box"><h3 style="margin-top:0"><i class="fa-solid fa-key" style="color:var(--ah-primary)"></i> Autorizar modificación de metas</h3><p style="color:#64748b;line-height:1.45">Ingrese la contraseña del usuario con el que inició sesión. La autorización permanecerá activa durante 15 minutos.</p>
    <form onsubmit="event.preventDefault(); verifyMetasPassword();">
        <label style="font-weight:800">Contraseña</label>
        <input type="password" id="metas-password-input" class="form-control" autocomplete="current-password" placeholder="Contraseña">
        <div id="metas-password-error" style="display:none;color:#991b1b;background:#fee2e2;border:1px solid #fecaca;padding:9px 11px;border-radius:8px;margin-top:10px;font-weight:700"></div>
        <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:18px">
            <button type="button" class="btn-action" onclick="closeMetasPasswordModal()">Cancelar</button>
            <button type="submit" class="btn-primary"><i class="fa-solid fa-unlock-keyhole"></i> Validar</button>
        </div>
    </form>
</div></div>

<!-- NUEVO MODAL DE CREACIÓN DE CATÁLOGOS CON PROGRAMA Y ETAPA -->
<div id="catalogMiniModal" class="catalog-mini-modal" onclick="if(event.target===this) $('#catalogMiniModal').hide()">
    <div class="catalog-mini-box">
        <h3 id="mini-modal-title" style="margin-top:0"><i class="fa-solid fa-folder-plus" style="color:var(--ah-primary)"></i> <span></span></h3>
        <input type="hidden" id="mini-modal-type">
        <input type="hidden" id="mini-modal-target-index">
        <input type="hidden" id="mini-modal-target-key">

        <div id="mini-modal-prog-etapa" style="display:none;">
            <div style="margin-bottom:12px;">
                <label style="font-size:0.8rem;font-weight:800;color:#475569;display:block;margin-bottom:4px;">Programa / Sector</label>
                <select id="mini-modal-prog" class="form-control">
                    <?php foreach($CATALOG_PROGRAMS as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div style="margin-bottom:12px;">
                <label style="font-size:0.8rem;font-weight:800;color:#475569;display:block;margin-bottom:4px;">Etapa</label>
                <select id="mini-modal-stg" class="form-control">
                    <?php foreach($CATALOG_STAGES as $k=>$v): ?><option value="<?= htmlspecialchars($k) ?>"><?= htmlspecialchars($v) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>

        <div style="margin-bottom:12px;">
            <label style="font-size:0.8rem;font-weight:800;color:#475569;display:block;margin-bottom:4px;">Nombre o descripción</label>
            <input type="text" id="mini-modal-input" class="form-control" placeholder="Escriba el nuevo valor...">
        </div>

        <div style="text-align:right;margin-top:18px">
            <button type="button" class="btn-action" onclick="$('#catalogMiniModal').hide()">Cancelar</button>
            <button type="button" class="btn-primary" onclick="submitNewCatalogItem()">Añadir</button>
        </div>
    </div>
</div>
<div id="autosave-indicator" class="autosave-indicator"></div>
<script>
const _normCache = {};
function normalizarTxt(v){
    if(!v) return '';
    const str=String(v).trim();
    if(Object.prototype.hasOwnProperty.call(_normCache,str)) return _normCache[str];
    return _normCache[str]=str.toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
}
window.savedInvData = {};
let showAllTeamMonths = false;
let hideNoBaseRowState = true;
let currentTaskData = null;
let currentTaskButton = null;
let modalEtapasBuilt = false;
let autosaveQueue = Promise.resolve();
function enqueueAutosave(job){
    autosaveQueue=autosaveQueue.catch(()=>{}).then(job);
    return autosaveQueue;
}
const masterResponsables = <?php echo json_encode($lista_total_responsables, JSON_UNESCAPED_UNICODE); ?>;
const masterUnidadesRaw = <?php echo json_encode($cat_unidades_raw, JSON_UNESCAPED_UNICODE); ?>;
const masterVerificacionesRaw = <?php echo json_encode($cat_verificaciones_raw, JSON_UNESCAPED_UNICODE); ?>;
const masterLugares = <?php echo json_encode($cat_lugares, JSON_UNESCAPED_UNICODE); ?>;
const etapasDefault = <?php echo json_encode($etapas_default, JSON_UNESCAPED_UNICODE); ?>;
const tecnicosBases = <?php echo json_encode($tecnicos_bases, JSON_UNESCAPED_UNICODE); ?>;
const centrosCatalogo = <?php echo json_encode($centros_catalogo, JSON_UNESCAPED_UNICODE); ?>;
const mesesEquipo = [{k:'jul',n:'Jul'},{k:'aug',n:'Ago'},{k:'sep',n:'Sep'},{k:'oct',n:'Oct'},{k:'nov',n:'Nov'},{k:'dec',n:'Dic'},{k:'jan',n:'Ene'},{k:'feb',n:'Feb'},{k:'mar',n:'Mar'},{k:'apr',n:'Abr'},{k:'may',n:'May'},{k:'jun',n:'Jun'}];
const currentTeamMonth = '<?php echo $mes_actual; ?>';
let visibleTeamMonths = new Set([currentTeamMonth]);

function normalizeProg(value) {
    let v = String(value || '').toUpperCase().normalize("NFD").replace(/[\u0300-\u036f]/g, "");
    if (v.includes('CRECER')) return 'CRECER';
    if (v.includes('REDES')) return 'REDES';
    if (v.includes('TEJIENDO')) return 'TEJIENDO_MI_FUTURO';
    if (v.includes('MONITOREO') || v.includes('MEAL')) return 'ML_MONITOREO';
    if (v.includes('PATROCINIO')) return 'X_PATROCINIO';
    if (v.includes('ADMINISTRACION') || v.includes('ADMIN')) return 'Z_ADMINISTRACION';
    return 'GENERAL';
}

function normalizeStg(value) {
    let v = String(value || '').toUpperCase();
    if (v.includes('1')) return 'E-1';
    if (v.includes('2')) return 'E-2';
    if (v.includes('3')) return 'E-3';
    if (v.includes('4')) return 'E-4';
    return 'TODAS';
}

function getFilteredCatalog(catalogRaw, prog, stg) {
    if (!catalogRaw) return [];
    const p = normalizeProg(prog);
    const s = normalizeStg(stg);
    const filtered = catalogRaw.filter(item => {
        const itemP = normalizeProg(item.programa);
        const itemS = normalizeStg(item.etapa);
        const matchP = (itemP === 'GENERAL' || itemP === p);
        const matchS = (itemS === 'TODAS' || itemS === s);
        return matchP && matchS;
    });
    return [...new Set(filtered.map(x => x.nombre))];
}

function escHtml(str){if(str===null||str===undefined)return'';return String(str).replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));}
function formatNum(n){return (parseFloat(n)||0).toLocaleString('es-HN',{maximumFractionDigits:1});}
function pctClass(p){p=parseFloat(p)||0;if(p<=0)return'pct-gray';if(p<50)return'pct-red';if(p<85)return'pct-yellow';if(p<100)return'pct-softgreen';return'pct-darkgreen';}
function calcPct(aTiempo,enForma){return Math.max(0,Math.min(100,((parseFloat(aTiempo)||0)+(parseFloat(enForma)||0))/2));}

function toggleOcultar(id, oculto) {
    if(!confirm(oculto ? '¿Ocultar esta actividad del panel principal?' : '¿Restaurar esta actividad al panel principal?')) return;
    $.post(window.location.pathname, {action:'toggle_ocultar', id_poa:id, oculto:oculto, csrf:<?php echo json_encode($monitoreoCsrf); ?>}, function(res){
        if(res.status==='ok') location.reload();
        else alert('Error: ' + res.msg);
    });
}

function copiarMesAnterior() {
    if (!confirm('¿Autollenar la programación con los datos del mes anterior?\n\nSe copiará lo programado en la pestaña "Asignar Equipo" y en "Metas y Meses" hacia el mes de ' + (mesesEquipo.find(x => x.k === currentTeamMonth)||{}).n + '.')) return;

    let idx = mesesEquipo.findIndex(x => x.k === currentTeamMonth);
    if (idx <= 0) { alert('No hay un mes anterior definido en el ciclo.'); return; }
    let prevMonth = mesesEquipo[idx - 1].k;

    // Metas
    let m_act_prev = $(`input[name="op_act[${prevMonth}]"]`).val();
    let m_part_prev = $(`input[name="op_part[${prevMonth}]"]`).val();
    if(m_act_prev) $(`input[name="op_act[${currentTeamMonth}]"]`).val(m_act_prev).trigger('change');
    if(m_part_prev) $(`input[name="op_part[${currentTeamMonth}]"]`).val(m_part_prev).trigger('change');

    // Equipo
    $('#tabla_tecnicos_body .team-row').each(function(){
        let row = $(this);
        let prevProg = parseFloat(row.find(`.team-month-prog[data-mes="${prevMonth}"]`).val()) || 0;
        if (prevProg > 0) {
            row.find(`.team-month-prog[data-mes="${currentTeamMonth}"]`).val(prevProg).trigger('change');
            row.find('.team-selected').prop('checked', true);
        }
    });

    recalcTeamRows();
    autosaveFullForm(true);
    showToast('Lógica del mes anterior aplicada.');
}

function qualityDataVersion(data){
    const version=parseInt(data && data.quality_version !== undefined ? data.quality_version : 0,10);
    return Number.isFinite(version) ? version : 0;
}
function hasQualityInitialization(data){
    return qualityDataVersion(data) >= 2;
}
function defaultQualityValue(data,field){
    if(!data || typeof data!=='object') return 100;
    const version=qualityDataVersion(data);
    const rawAt=parseFloat(data.a_tiempo);
    const rawEf=parseFloat(data.en_forma);
    const hasAt=Number.isFinite(rawAt);
    const hasEf=Number.isFinite(rawEf);
    if(version < 2 && ((!hasAt && !hasEf) || ((hasAt ? rawAt : 0)===0 && (hasEf ? rawEf : 0)===0))){
        return 100;
    }
    const value=parseFloat(data[field]);
    return Number.isFinite(value)?Math.max(0,Math.min(100,value)):100;
}

function calcRowPct(programado, cumplido, aTiempo, enForma) {
    programado = parseFloat(programado) || 0;
    cumplido = parseFloat(cumplido) || 0;
    aTiempo = parseFloat(aTiempo) || 0;
    enForma = parseFloat(enForma) || 0;

    if (programado <= 0 || cumplido <= 0) return 0;
    let avanceCantidad = (cumplido / programado) * 100;
    let calidad = (aTiempo + enForma) / 2;
    let pct = avanceCantidad * (calidad / 100);

    if (!isFinite(pct)) return 0;
    return Math.max(0, Math.min(100, pct));
}
function badgePct(p){return `<span class="pct-badge ${pctClass(p)}">${Math.round(p)}%</span>`;}

function sanitizeExportName(value){
    return String(value||'datos').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/[^a-zA-Z0-9_-]+/g,'_').replace(/^_+|_+$/g,'')||'datos';
}
function cleanTableForExport(table){
    const clone=table.cloneNode(true);
    clone.querySelectorAll('.hidden-team-month,.d-none,script,style,button,.fill-drag-handle').forEach(el=>el.remove());
    clone.querySelectorAll('input,select,textarea').forEach(control=>{
        if(control.type==='hidden'){control.remove();return;}
        let value='';
        if(control.type==='checkbox') value=control.checked?'Sí':'No';
        else if(control.tagName==='SELECT') value=Array.from(control.selectedOptions||[]).map(o=>o.textContent.trim()).filter(Boolean).join(', ');
        else value=control.value||'';
        const span=document.createElement('span');
        span.textContent=value;
        control.replaceWith(span);
    });
    clone.querySelectorAll('[contenteditable="true"]').forEach(el=>el.removeAttribute('contenteditable'));
    clone.querySelectorAll('[style*="display: none"],[style*="display:none"]').forEach(el=>el.remove());
    return clone;
}
function exportTableXlsx(tableRef,fileName='tabla',sheetName='Datos'){
    if(typeof XLSX==='undefined'){alert('No se pudo cargar el generador XLSX. Recargue la página.');return;}
    const table=typeof tableRef==='string'?document.querySelector(tableRef):tableRef;
    if(!table){alert('No se encontró la tabla para exportar.');return;}
    const clone=cleanTableForExport(table);
    const host=document.createElement('div');
    host.className='export-clean-copy';
    host.appendChild(clone);
    document.body.appendChild(host);
    try{
        const wb=XLSX.utils.table_to_book(clone,{sheet:String(sheetName||'Datos').slice(0,31),raw:true});
        XLSX.writeFile(wb,`${sanitizeExportName(fileName)}.xlsx`);
    }finally{host.remove();}
}
function exportClosestTable(button,fileName='tabla'){
    const container=button.closest('.subgrid-card,.detail-base-section,.stage-scroll,.modal-tab-content')||document;
    const table=container.querySelector('table');
    exportTableXlsx(table,fileName);
}

function activityPctClass(p){
    p = parseFloat(p) || 0;
    if (p <= 25) return 'activity-red';
    if (p <= 50) return 'activity-orange';
    if (p <= 75) return 'activity-softgreen';
    return 'activity-green';
}

function initials(name){return String(name||'').split(/\s+/).filter(Boolean).slice(0,2).map(x=>x[0]).join('').toUpperCase() || 'T';}

function updateSaveIndicator(state) {
    const btn = $('#btn-force-save');
    const icon = $('#save-icon');
    const txt = $('#save-text');

    if (state === 'saving') {
        btn.css({ 'color': '#0284c7', 'background': '#e0f2fe' });
        icon.attr('class', 'fa-solid fa-cloud-arrow-up fa-fade');
        txt.text('Guardando...');
    } else if (state === 'saved') {
        btn.css({ 'color': '#166534', 'background': '#dcfce7' });
        icon.attr('class', 'fa-solid fa-cloud-check');
        txt.text('¡Guardado!');
        setTimeout(() => {
            if (txt.text() === '¡Guardado!') {
                btn.css({ 'background': 'transparent' });
                txt.text('Guardado automático activo');
                icon.attr('class', 'fa-solid fa-cloud-arrow-up');
            }
        }, 3000);
    } else if (state === 'error') {
        btn.css({ 'color': '#991b1b', 'background': '#fee2e2' });
        icon.attr('class', 'fa-solid fa-triangle-exclamation fa-beat');
        txt.text('Error al guardar');
    }
}

function showAutosave(msg='Guardado'){}
function flashSaved(el, ok=true){ updateSaveIndicator(ok ? 'saved' : 'error'); }
function flashSaving(el){ updateSaveIndicator('saving'); }

let monitoreoEditorReady=false;
function initMonitoreoEditor(){
    if(monitoreoEditorReady||typeof tinymce==='undefined') return;
    monitoreoEditorReady=true;
    tinymce.init({
        selector:'#upd_info_adicional',
        plugins:'table lists link autolink image code fullscreen advlist',
        toolbar:'undo redo | blocks | bold italic underline | alignleft aligncenter alignright | bullist numlist | table link image | code fullscreen',
        menubar:true,
        branding:false,
        height:420,
        setup:function(ed){
            ed.on('change keyup undo redo',()=>{ed.save();scheduleFullAutosave(document.getElementById('upd_info_adicional'));});
        }
    }).catch(()=>{monitoreoEditorReady=false;});
}
function switchModalTab(tabId, btn){
    $('.modal-tab-btn').removeClass('active');$('.modal-tab-content').removeClass('active');
    if(btn)$(btn).addClass('active');else $(`.modal-tab-btn[onclick*="${tabId}"]`).addClass('active');
    $('#'+tabId).addClass('active');
    if(tabId==='tab-notas')initMonitoreoEditor();
    if(tabId==='tab-etapas'&&!modalEtapasBuilt&&currentTaskData){
        modalEtapasBuilt=true;
        $('#tabla_etapas_body').html('<tr><td colspan="4" style="text-align:center;padding:35px"><i class="fa-solid fa-spinner fa-spin"></i> Cargando agenda...</td></tr>');
        requestAnimationFrame(()=>buildEtapasTable(currentTaskData));
    }
}

async function closeModal(id){
    if(id==='updateModal'){
        document.getElementById(id).style.display='none';

        const drawer=$('#centrosDrawer');
        if(drawer.hasClass('open')){
            const index=Number(drawer.data('index'));
            const key=String(drawer.data('key')||'');
            updateHiddenCentrosJsonFromDrawer();
            if(key!==''){
                clearTimeout(centerRowAutosaveTimers[`${index}|${key}`]);
                autosaveCenterRow(index,key);
            }
            closeCentrosDrawer(false);
        }

        autosaveFullForm(true);
    } else {
        document.getElementById(id).style.display='none';
    }
}

function updateCardVisuals() {
    if (!currentTaskButton || !currentTaskData) return;
    const card = $(currentTaskButton).closest('.task-card');

    const actObj = currentTaskData.m_act_obj || 0;
    const actAlc = currentTaskData.m_act_alc || 0;
    const partObj = currentTaskData.m_part_obj || 0;
    const partAlc = currentTaskData.m_part_alc || 0;
    const estadoActual = currentTaskData.estado || '0%';

    card.find('.badge').text(estadoActual);

    const metaContainer = card.find('.task-meta').eq(1);
    metaContainer.find('div').eq(1).html(`<i class="fa-solid fa-clipboard-check"></i> Actividades: <strong>${formatNum(actAlc)} / ${formatNum(actObj)}</strong>`);
    metaContainer.find('div').eq(2).html(`<i class="fa-solid fa-user-check"></i> Alcanzados: <strong>${formatNum(partAlc)} / ${formatNum(partObj)}</strong>`);

    card.attr('data-metap', partObj);
    card.attr('data-alcp', partAlc);

    updateDynamicMetrics();
}

function updateDynamicMetrics(metrics){
    if(!metrics){metrics={total:0,comp:0,proc:0,sum:0,count:0};document.querySelectorAll('.data-card:not(.filtered-out)').forEach(card=>{const est=card.dataset.est||'',mo=parseFloat(card.dataset.metap)||0,ma=parseFloat(card.dataset.alcp)||0;metrics.total++;if(est==='Completado')metrics.comp++;if(est==='En Proceso')metrics.proc++;if(mo>0){metrics.sum+=Math.min((ma/mo)*100,100);metrics.count++;}});}
    $('#count-total').text(metrics.total);$('#count-comp').text(metrics.comp);$('#count-proc').text(metrics.proc);$('#count-rend').text(metrics.count?(metrics.sum/metrics.count).toFixed(1)+'%':'0%');
}
function normalizeFilterText(v){return String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,'_');}
let monitorSearchTouched = false;
let monitorPage=1;
const monitorPageSize=20;
let monitorMatchedCards=[];
function getSafeMonitorSearch(){
    const $search = $('#filter-search');
    let value = String($search.val() || '').trim();
    if (!monitorSearchTouched && /@/.test(value)) {
        $search.val('');
        value = '';
    }
    return value.toLowerCase();
}
function renderMonitorPage(){
    const total=monitorMatchedCards.length,pages=Math.max(1,Math.ceil(total/monitorPageSize));
    monitorPage=Math.min(Math.max(1,monitorPage),pages);
    const start=(monitorPage-1)*monitorPageSize,end=start+monitorPageSize;
    monitorMatchedCards.forEach((card,index)=>card.classList.toggle('paged-out',index<start||index>=end));
    const host=document.getElementById('monitor-pagination');
    if(!host)return;
    if(total<=monitorPageSize){host.innerHTML=total?`<span class="monitor-pagination-info">Mostrando ${total} actividad${total===1?'':'es'}</span>`:'';return;}
    let html=`<button type="button" data-page="${monitorPage-1}" ${monitorPage===1?'disabled':''}>‹ Anterior</button>`;
    const from=Math.max(1,monitorPage-2),to=Math.min(pages,monitorPage+2);
    for(let p=from;p<=to;p++)html+=`<button type="button" data-page="${p}" class="${p===monitorPage?'active':''}">${p}</button>`;
    html+=`<button type="button" data-page="${monitorPage+1}" ${monitorPage===pages?'disabled':''}>Siguiente ›</button><span class="monitor-pagination-info">${start+1}-${Math.min(end,total)} de ${total}</span>`;
    host.innerHTML=html;
}
function applySmartFilters(resetPage=true){
    const search=getSafeMonitorSearch(),filterRaw=$('#filter-prog').val()||'',tec=$('#filter-tec').val(),estFilter=$('#filter-est').val(),hideAdmin=$('#toggle-admin').is(':checked'),hideZero=$('#toggle-zero').is(':checked'),onlyMain=$('#toggle-main-progs').is(':checked');
    const months=Array.from(document.querySelectorAll('.toggle-month:checked'),el=>el.value),parts=filterRaw.split(':'),filterType=parts.length>1?parts[0]:'',filterValue=normalizeFilterText(parts.length>1?parts.slice(1).join(':'):filterRaw),metrics={total:0,comp:0,proc:0,sum:0,count:0};
    monitorMatchedCards=[];
    document.querySelectorAll('.data-card').forEach(card=>{const d=card.dataset,prog=normalizeFilterText(d.prog||''),sec=normalizeFilterText(d.sec||''),meta=parseFloat(d.metap)||0,meses=String(d.meses||'').split(',').filter(Boolean),text=card.dataset.search||card.textContent.toLowerCase();if(!card.dataset.search)card.dataset.search=text;let matchCategory=true;if(filterType==='programa')matchCategory=prog.includes(filterValue);else if(filterType==='sector')matchCategory=sec.includes(filterValue);else if(filterValue)matchCategory=prog.includes(filterValue)||sec.includes(filterValue);const ok=(search===''||text.includes(search))&&matchCategory&&(tec===''||d.tec===tec)&&(estFilter===''||d.est===estFilter)&&(filterType==='sector'||!hideAdmin||(!sec.includes('z_administracion')&&!sec.includes('z_gastos')&&!sec.includes('administra')))&&(!hideZero||meta>0)&&(!(onlyMain&&!filterRaw)||prog.includes('crecer')||prog.includes('redes')||prog.includes('tejiendo'))&&(months.length===0||months.some(m=>meses.includes(m)));card.classList.toggle('filtered-out',!ok);if(ok){monitorMatchedCards.push(card);metrics.total++;if(d.est==='Completado')metrics.comp++;if(d.est==='En Proceso')metrics.proc++;if(meta>0){metrics.sum+=Math.min(((parseFloat(d.alcp)||0)/meta)*100,100);metrics.count++;}}});
    if(resetPage)monitorPage=1;
    renderMonitorPage();
    updateDynamicMetrics(metrics);
}
$(document).ready(function(){const monthKeys=['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];const currentMonthKey=monthKeys[new Date().getMonth()];const $search=$('#filter-search');
    $search.val('').attr({'autocomplete':'new-password','readonly':'readonly'});
    $search.on('pointerdown focus',function(){this.removeAttribute('readonly');});
    $search.on('keydown paste',function(){monitorSearchTouched=true;});
    let searchTimer=null;
    $search.on('input',function(e){if(e.originalEvent&&e.originalEvent.isTrusted)monitorSearchTouched=true;clearTimeout(searchTimer);searchTimer=setTimeout(()=>applySmartFilters(true),180);});
    $('#filter-prog').val('programa:crecer');$('#filter-tec,#filter-est').val('');
    $('.toggle-month').prop('checked',false).closest('label').removeClass('active');
    $(`.toggle-month[value="${currentMonthKey}"]`).prop('checked',true).closest('label').addClass('active');
    requestAnimationFrame(function(){if(!monitorSearchTouched)$search.val('');applySmartFilters();});
    window.addEventListener('pageshow',function(){if(!monitorSearchTouched){$search.val('');}applySmartFilters();});
    $('#filter-prog,#filter-tec,#filter-est').on('change',applySmartFilters);
    $('#toggle-admin,#toggle-zero,#toggle-main-progs').on('change',function(){$(this).closest('label').toggleClass('active-toggle',$(this).is(':checked'));applySmartFilters();});
    $('.toggle-month').on('change',function(){$(this).closest('label').toggleClass('active',$(this).is(':checked'));applySmartFilters();});
    $('#btn-show-all').on('click',function(){monitorSearchTouched=false;$('#toggle-admin,#toggle-zero,#toggle-main-progs').prop('checked',false).closest('label').removeClass('active-toggle');$('.toggle-month').prop('checked',false).closest('label').removeClass('active');$search.val('');$('#filter-prog,#filter-tec,#filter-est').val('');applySmartFilters();});
    $('#monitor-pagination').on('click','button[data-page]',function(){if(this.disabled)return;monitorPage=parseInt(this.dataset.page,10)||1;renderMonitorPage();document.getElementById('task-container').scrollIntoView({behavior:'smooth',block:'start'});});
    applySmartFilters();
});
function getFechaMaximaEtapa(index){let d=new Date(),y=d.getFullYear(),m=d.getMonth();if(index===0)return `${y}-${String(m+1).padStart(2,'0')}-03`;if(index===1)return `${y}-${String(m+1).padStart(2,'0')}-06`;if(index===2)return `${y}-${String(m+1).padStart(2,'0')}-20`;let last=new Date(y,m+1,0).getDate();return `${y}-${String(m+1).padStart(2,'0')}-${String(last).padStart(2,'0')}`;}

let _centrosIndex=null;
function preprocesarCentros(){
    if(_centrosIndex) return;
    _centrosIndex={};
    centrosCatalogo.forEach(c=>{
        const base=normalizarTxt(c.comunidad_base),tipo=normalizarTxt(c.tipo);
        let cat='otro';
        if(tipo.includes('preescolar')) cat='preescolar';
        else if(tipo.includes('adn')) cat='adn';
        else if(tipo.includes('uaps')||tipo.includes('cis')) cat='uaps/cis';
        else if(tipo.includes('basica')||tipo.includes('media')||tipo.includes('educativo')) cat='basica';
        const key=base+'|'+cat;
        (_centrosIndex[key]||(_centrosIndex[key]=[])).push(c);
    });
    Object.values(_centrosIndex).forEach(rows=>rows.sort((a,b)=>String(a.nombre||'').localeCompare(String(b.nombre||''),'es',{sensitivity:'base'})));
}

function lugarToTipo(lugar){
    const l=normalizarTxt(Array.isArray(lugar)?lugar[0]:lugar);
    if(!l) return '';
    if(l.includes('preescolar')) return 'preescolar';
    if(l.includes('adn')) return 'adn';
    if(l.includes('uaps')||l.includes('cis')) return 'uaps/cis';
    if(l.includes('centro educativo')||l.includes('educativo')||l.includes('basica')||l.includes('media')) return 'basica';
    return '';
}

function isCenterLugar(lugar){return lugarToTipo(lugar)!=='';}

function getBasesByTecnico(tecnico){let bases=tecnicosBases.filter(x=>x.nombre===tecnico&&(x.nombre_base||'').trim()!=='').map(x=>x.nombre_base);bases=[...new Set(bases)];return bases.length?bases:[''];}
function getBaseByTecnico(tecnico){let bases=getBasesByTecnico(tecnico).filter(Boolean);return bases.length?bases[0]:'';}

function getCentrosPorTecnicoYLugar(baseTecnico,lugarRaw){
    preprocesarCentros();
    const base=normalizarTxt(baseTecnico);
    const tipoReq=lugarToTipo(lugarRaw);
    if(!base || !tipoReq) return [];
    return _centrosIndex[base+'|'+tipoReq]||[];
}

// FUNCION DE POBLACION INTELIGENTE SEGÚN LA UNIDAD DE LA ETAPA 3
// FUNCION DE POBLACION INTELIGENTE SEGÚN LA UNIDAD DE LA ETAPA 3
function getUnidadTargetPopulation(c, unidad) {
    let tipo = normalizarTxt(String(c.tipo || ''));
    let total = parseFloat(c.pob_total) || 0;

    // 1. Escuelas y Preescolares NO dependen de la unidad. Siempre traen su total de alumnos.
    if (tipo.includes('preescolar') || tipo.includes('basica') || tipo.includes('educativo') || tipo.includes('media')) {
        return total;
    }

    // 2. ADN y UAPS/CIS filtran por la unidad (Edades o Líderes)
    let u = normalizarTxt(String(unidad || ''));

    // Extracción limpia para evitar NaNs
    let pm05f = parseFloat(c.pm_0_5_9_f); if(isNaN(pm05f)) pm05f = 0;
    let pm05m = parseFloat(c.pm_0_5_9_m); if(isNaN(pm05m)) pm05m = 0;
    let c_0_5 = pm05f + pm05m;
    if (c_0_5 === 0) c_0_5 = parseFloat(c.pob_0_5) || 0;

    let pm614f = parseFloat(c.pm_6_14_9_f); if(isNaN(pm614f)) pm614f = 0;
    let pm614m = parseFloat(c.pm_6_14_9_m); if(isNaN(pm614m)) pm614m = 0;
    let c_6_14 = pm614f + pm614m;

    let pm1517f = parseFloat(c.pm_15_17_9_f); if(isNaN(pm1517f)) pm1517f = 0;
    let pm1517m = parseFloat(c.pm_15_17_9_m); if(isNaN(pm1517m)) pm1517m = 0;
    let c_15_17 = pm1517f + pm1517m;

    let c_6_17 = (c_6_14 + c_15_17) > 0 ? (c_6_14 + c_15_17) : (parseFloat(c.pob_6_17) || 0);

    let pm1824f = parseFloat(c.pm_18_24_f); if(isNaN(pm1824f)) pm1824f = 0;
    let pm1824m = parseFloat(c.pm_18_24_m); if(isNaN(pm1824m)) pm1824m = 0;
    let c_18_24 = pm1824f + pm1824m;
    if (c_18_24 === 0) c_18_24 = parseFloat(c.pob_18_24) || 0;

    let pmlidf = parseFloat(c.pm_lideres_f); if(isNaN(pmlidf)) pmlidf = 0;
    let pmlidm = parseFloat(c.pm_lideres_m); if(isNaN(pmlidm)) pmlidm = 0;
    let c_lideres = pmlidf + pmlidm;
    if (c_lideres === 0) c_lideres = (parseFloat(c.lideres_f) || 0) + (parseFloat(c.lideres_m) || 0);

    // Búsqueda inteligente de Unidades
    if (u.includes('lider')) return c_lideres;
    if (u.includes('infante') || u.includes('0 a 5')) return c_0_5;
    if (u.includes('nino') || u.includes('nina') || u.includes('6 a 14') || u.includes('nnaj')) return c_6_14 > 0 ? c_6_14 : c_6_17;
    if (u.includes('adolescente') || u.includes('15 a 17')) return c_15_17 > 0 ? c_15_17 : 0;
    if (u.includes('joven') || u.includes('18 a 24')) return c_18_24;

    return total;
}

// PARAMETRO KEY EN OPTIONSCHECKBOXES
function optionsCheckboxes(options,selected,name,index,type,isMain=false, key=''){
    let panelClass=`panel-${type}-box`,selectedArr=Array.isArray(selected)?selected:(selected?[selected]:[]);
    const lazyOptions=type==='verific_sub'||type==='lugar_sub';
    const renderedOptions=lazyOptions?selectedArr:options;
    let html=`<div class="custom-multiselect ${panelClass}" data-index="${index}" data-type="${type}" data-name="${escHtml(name)}"><div class="multiselect-select-box" draggable="true" ondragstart="dragFillStart(event,this)" ondragenter="dragFillOver(event,this)" ondragover="dragFillOver(event,this)" ondragleave="dragFillLeave(this)" ondrop="dragFillDrop(event,this)" onclick="event.stopPropagation();toggleDropdownPanel(this)"><span class="multi-label">Seleccione...</span><span class="fill-drag-handle" title="Arrastrar este valor hacia otro combo"><i class="fa-solid fa-grip-vertical"></i></span></div><div class="multiselect-dropdown-panel" data-lazy-options="${lazyOptions?'1':'0'}" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">`;
    renderedOptions.forEach(v=>{
        let chk=selectedArr.includes(v)?'checked':'';
        html+=`<label class="multiselect-option" onclick="event.stopPropagation()"><input type="checkbox" name="${name}" value="${escHtml(v)}" ${chk}> ${escHtml(v)}</label>`;
    });
    html+=`<a href="#" class="multiselect-add-new-btn" onclick="event.stopPropagation();openCatalogModal('${type}', ${index}, '${type}', '${key}');return false;"><i class="fa-solid fa-plus"></i> Crear nuevo...</a></div></div>`;
    return html;
}

function hydrateLazyOptions(panel){
    if(panel.attr('data-lazy-options')!=='1')return;
    panel.attr('data-lazy-options','0');
    const ms=panel.closest('.custom-multiselect'),type=ms.data('type'),index=Number(ms.data('index'));
    const currentProg=currentTaskData?`${currentTaskData.programa||''} ${currentTaskData.sector||''}`:'';
    const options=type==='verific_sub'?getFilteredCatalog(masterVerificacionesRaw,currentProg,`E-${index+1}`):masterLugares;
    const existing=new Set(panel.find('input[type="checkbox"]').map(function(){return this.value;}).get());
    let html='';
    options.forEach(v=>{if(!existing.has(String(v)))html+=`<label class="multiselect-option" onclick="event.stopPropagation()"><input type="checkbox" name="${escHtml(ms.data('name')||'')}" value="${escHtml(v)}"> ${escHtml(v)}</label>`;});
    panel.find('.multiselect-add-new-btn').before(html);
}

function toggleDropdownPanel(box){
    let panel=$(box).next('.multiselect-dropdown-panel');
    hydrateLazyOptions(panel);
    let isVisible=panel.is(':visible');
    $('.multiselect-dropdown-panel').hide();
    if(!isVisible){
        let rect=box.getBoundingClientRect();
        let width=Math.max(rect.width, 240);
        panel.css({position:'fixed',visibility:'hidden',display:'block',width:width+'px',zIndex:999999});
        let panelH=panel.outerHeight()||250;
        let spaceBelow=window.innerHeight-rect.bottom;
        let top=(spaceBelow < Math.min(panelH,250)+20) ? Math.max(10, rect.top-Math.min(panelH,250)-8) : rect.bottom+6;
        let left=Math.min(rect.left, window.innerWidth-width-12);
        panel.css({top:top+'px',left:Math.max(10,left)+'px',visibility:'visible',display:'block'});
    }
}

function updateMultiselectText(panelDOM){let panel=$(panelDOM),box=panel.prev('.multiselect-select-box'),checked=panel.find('input:checked').map(function(){return $(this).val();}).get();let content=checked.length?checked.slice(0,4).map(v=>`<span class="multi-tag">${escHtml(v)}</span>`).join('')+(checked.length>4?`<span class="multi-tag">+${checked.length-4}</span>`:''):'Seleccione...';box.find('.multi-label').html(content);}
let dragFillPayload=null;
function dragFillStart(ev,box){let panel=$(box).next('.multiselect-dropdown-panel');dragFillPayload={type:$(box).closest('.custom-multiselect').data('type'),values:panel.find('input:checked').map(function(){return $(this).val();}).get()};ev.dataTransfer.setData('text/plain',JSON.stringify(dragFillPayload));}
function dragFillOver(ev,box){
    ev.preventDefault();
    ev.stopPropagation();
    if(ev.dataTransfer) ev.dataTransfer.dropEffect = 'copy';
    $(box).addClass('drag-over');
}
function dragFillLeave(box){
    $(box).removeClass('drag-over');
}

function refreshStage1RowFromPlaces(row) {
    let b = String(row.data('base')||'').split('|').filter(x=>String(x||'').trim()!=='');
    let lc = row.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get().filter(isCenterLugar);
    let lr = lc.length ? lc : window.lastGlobalCenterPlaces;
    if (lr && lr.length) {
        let ce = centrosByBasesYLugares(b, lr);
        row.find('input[name^="inv_alograr"]').val(ce.length);
        const prog = ce.length;
        const cum = parseFloat(row.find('input[name^="inv_cumplido"]').val()) || 0;
        const at = parseFloat(row.find('input[name^="inv_a_tiempo"]').val()) || 0;
        const ef = parseFloat(row.find('input[name^="inv_en_forma"]').val()) || 0;
        row.find('.pct-cell').html(badgePct(calcRowPct(prog,cum,at,ef)));
    }
    captureCurrentInvData(1);
    updateActivityProgress();
}

function dragFillDrop(ev,box){
    ev.preventDefault();
    ev.stopPropagation();
    $(box).removeClass('drag-over');

    let payload = dragFillPayload;
    try {
        let data = ev.dataTransfer.getData('text/plain');
        if (data) payload = JSON.parse(data) || payload;
    } catch(e) {}

    if(!payload || !payload.values) return;

    let target = $(box).closest('.custom-multiselect');
    if(target.data('type') !== payload.type) return;

    let panel = target.find('.multiselect-dropdown-panel');
    panel.find('input[type="checkbox"]').prop('checked', false);

    let idx = target.data('index');

    payload.values.forEach(v => {
        let checkbox = panel.find('input[type="checkbox"]').filter(function(){ return this.value === String(v); });

        if(checkbox.length === 0){
            let nameAttr = panel.find('input[type="checkbox"]').first().attr('name') || '';
            if(!nameAttr){
                let pKey = target.closest('.inv-row').data('key') || '';
                if(payload.type === 'verific_sub') nameAttr = `inv_verifics[${idx}][${pKey}][]`;
                else if(payload.type === 'lugar_sub') nameAttr = `inv_lugar[${idx}][${pKey}][]`;
            }
            let newLabel = `<label class="multiselect-option" onclick="event.stopPropagation()"><input type="checkbox" name="${nameAttr}" value="${escHtml(v)}"> ${escHtml(v)}</label>`;
            panel.find('.multiselect-add-new-btn').before(newLabel);
            checkbox = panel.find('input[type="checkbox"]').filter(function(){ return this.value === String(v); });
        }

        checkbox.prop('checked', true);

        if (target.data('type') === 'responsable') {
            $(`#subgrid-${idx} .deleted-inv-holder`).filter(function(){ return $(this).find(`input[name^="inv_persona"]`).val() === v; }).remove();
        } else if (target.data('type') === 'unidad') {
            $(`#subgrid-${idx} .deleted-inv-holder`).filter(function(){ return $(this).find(`input[name^="inv_unidad"]`).val() === v; }).remove();
        }
        if(window.savedInvData[idx]) {
            for (let k in window.savedInvData[idx]) {
                if (target.data('type') === 'responsable' && window.savedInvData[idx][k].persona === v) window.savedInvData[idx][k].deleted = false;
                if (target.data('type') === 'unidad' && window.savedInvData[idx][k].unidad === v) window.savedInvData[idx][k].deleted = false;
            }
        }
    });

    updateMultiselectText(panel[0]);

    setTimeout(() => {
    if(payload.type==='responsable'||payload.type==='unidad'){
        triggerAgendaRebuild(idx);
    }else if(payload.type==='lugar_sub'){
        const msBox = target;
        const row = msBox.closest('.inv-row');
        const rowIdx = Number(row.data('index'));
        if(rowIdx === 2){
            refreshStage3RowFromPlaces(row, true);
        }else if(rowIdx === 1){
            refreshStage1RowFromPlaces(row);
        }else{
            captureCurrentInvData(idx);
        }
    }else if(payload.type==='team_global_lugar'||payload.type==='team_lugar'){
        applyGlobalTeamPlaces(true, target.find('.multiselect-select-box')[0]);
        return;
    }else{
        captureCurrentInvData(idx);
    }

    scheduleFullAutosave();
    }, 10);
}
$(document).on('mousedown click','.custom-multiselect,.multiselect-dropdown-panel,.multiselect-option,.multiselect-add-new-btn',function(e){e.stopPropagation();});

$(document).on('change','.multiselect-dropdown-panel input',function(e){
    e.stopPropagation();
    const panel=$(this).closest('.multiselect-dropdown-panel');
    updateMultiselectText(panel[0]);
    const ms=panel.closest('.custom-multiselect');
    const type=ms.data('type');
    const idx=ms.data('index');

    if (this.checked) {
        let val = $(this).val();

        if (type === 'responsable') {
            $(`#subgrid-${idx} .deleted-inv-holder`).filter(function(){
                return $(this).find(`input[name^="inv_persona"]`).val() === val;
            }).remove();
        } else if (type === 'unidad') {
            $(`#subgrid-${idx} .deleted-inv-holder`).filter(function(){
                return $(this).find(`input[name^="inv_unidad"]`).val() === val;
            }).remove();
        }

        if (window.savedInvData[idx]) {
            for (let k in window.savedInvData[idx]) {
                if (type === 'responsable' && window.savedInvData[idx][k].persona === val) {
                    window.savedInvData[idx][k].deleted = false;
                }
                if (type === 'unidad' && window.savedInvData[idx][k].unidad === val) {
                    window.savedInvData[idx][k].deleted = false;
                }
            }
        }
    }

    setTimeout(() => {
    if(type==='responsable' || type==='unidad'){
        try{ triggerAgendaRebuild(idx); }catch(ex){}
    }else if(type==='lugar_sub'){
        const row=ms.closest('.inv-row');
        const rowIdx = Number(row.data('index'));
        if(rowIdx===2){
            refreshStage3RowFromPlaces(row,true);
        }else if(rowIdx === 1){
            refreshStage1RowFromPlaces(row);
        }else{
            captureCurrentInvData(idx);
        }
    }else if(type==='team_global_lugar' || type==='team_lugar'){
        applyGlobalTeamPlaces(true, ms.find('.multiselect-select-box')[0]);
        return;
    }else{
        captureCurrentInvData(idx);
    }
    scheduleFullAutosave();
    }, 10);
});
$(document).on('mousedown',function(e){if(!$(e.target).closest('.custom-multiselect,.multiselect-dropdown-panel').length)$('.multiselect-dropdown-panel').hide();});
function selectedFromPanel(selector){return $(selector).find('input:checked').map(function(){return $(this).val();}).get();}

function captureCurrentInvData(index){
    if(!window.savedInvData[index]) window.savedInvData[index]={};

    // 1. Capturar las líneas visibles
    $(`#subgrid-${index} tr.inv-row`).each(function(){
        let row=$(this),key=row.data('key');
        if(!key) return;
        let prev=window.savedInvData[index][key]||{};
        window.savedInvData[index][key]={...prev,
            persona:row.find(`input[name^="inv_persona"]`).val()||prev.persona||'',
            base:row.find(`input[name^="inv_base"]`).val()||prev.base||'',
            unidad:row.find(`input[name^="inv_unidad"]`).val()||prev.unidad||'',
            mes:row.find(`input[name^="inv_mes"]`).val()||prev.mes||currentTeamMonth,
            a_lograr:row.find(`input[name^="inv_alograr"]`).val()||0,
            cumplido:row.find(`input[name^="inv_cumplido"]`).val()||0,
            deleted:row.find(`input[name^="inv_deleted"]`).val()==='1',
            a_tiempo:row.find(`input[name^="inv_a_tiempo"]`).val()||100,
            en_forma:row.find(`input[name^="inv_en_forma"]`).val()||100,
            quality_initialized:true,
            quality_version:2,
            verifics:row.find(`.panel-verific_sub-box input:checked`).map(function(){return $(this).val();}).get(),
            lugar:row.find(`.panel-lugar_sub-box input:checked`).map(function(){return $(this).val();}).get(),
            centros:readHiddenCenters(index,key)
        };
    });

    // 2. Capturar las líneas eliminadas para que la BD recuerde que se borraron
    $(`#subgrid-${index} .deleted-inv-holder`).each(function(){
        let holder=$(this);
        let nameAttr = holder.find(`input[name^="inv_persona"]`).attr('name');
        if(nameAttr) {
            let match = nameAttr.match(/\[([^\]]+)\]$/);
            if(match && match[1]) {
                let key = match[1];
                let prev=window.savedInvData[index][key]||{};
                window.savedInvData[index][key] = {...prev,
                    persona:holder.find(`input[name^="inv_persona"]`).val()||prev.persona||'',
                    base:holder.find(`input[name^="inv_base"]`).val()||prev.base||'',
                    unidad:holder.find(`input[name^="inv_unidad"]`).val()||prev.unidad||'',
                    mes:holder.find(`input[name^="inv_mes"]`).val()||prev.mes||currentTeamMonth,
                    deleted:true,
                    a_lograr:0
                };
            }
        }
    });
    $(`input[data-etapa-json="${index}"]`).val(JSON.stringify(window.savedInvData[index]||{}));
}

function triggerAgendaRebuild(index){captureCurrentInvData(index);const cont=$(`#subgrid-${index}`);if(cont.attr('data-built')!=='1')return;let resps=selectedFromPanel(`.panel-responsable-box[data-index="${index}"]`);let unidades=selectedFromPanel(`.panel-unidad-box[data-index="${index}"]`);buildSubgrid(index,resps,unidades);}
function rowKey(persona,unidad){return btoa(unescape(encodeURIComponent(persona+'|'+unidad))).replace(/=/g,'');}
function getSaved(index,key){return (window.savedInvData[index]&&window.savedInvData[index][key])?window.savedInvData[index][key]:{};}
function mergeUniqueArrays(a,b){a=Array.isArray(a)?a:[];b=Array.isArray(b)?b:[];return [...new Set([...a,...b])];}
function combineSavedForBases(index, persona, bases, unidad){
    let combined={persona:persona,base:bases.join('|'),unidad:unidad,a_lograr:0,cumplido:0,a_tiempo:0,en_forma:0,quality_initialized:true,quality_version:2,verifics:[],lugar:[],centros:{}};
    let count=0,has=false;
    bases.forEach(base=>{
        let old=getSaved(index,rowKey(persona+'|'+base,unidad));
        if(old&&Object.keys(old).length){
            has=true;
            combined.a_lograr+=parseFloat(old.a_lograr)||0;
            combined.cumplido+=parseFloat(old.cumplido)||0;
            combined.a_tiempo+=defaultQualityValue(old,'a_tiempo');
            combined.en_forma+=defaultQualityValue(old,'en_forma');
            combined.verifics=mergeUniqueArrays(combined.verifics,old.verifics);
            combined.lugar=mergeUniqueArrays(combined.lugar,old.lugar);
            if(old.centros) combined.centros=Object.assign(combined.centros,old.centros);
            count++;
        }
    });
    if(count>0){
        combined.a_tiempo=combined.a_tiempo/count;
        combined.en_forma=combined.en_forma/count;
    }else{
        combined.a_tiempo=100;
        combined.en_forma=100;
    }
    return has?combined:{};
}
function centrosByBasesYLugares(bases,lugares){let centros=[],seen=new Set();(lugares||[]).forEach(l=>{(bases||['']).forEach(base=>{getCentrosPorTecnicoYLugar(base,l).forEach(c=>{if(!seen.has(c.id)){seen.add(c.id);centros.push(c);}});});});return centros;}
function sumMatriculaCentros(centros){return (centros||[]).reduce((acc,c)=>acc+(parseFloat(c.pob_total)||0),0);}

function readHiddenCenters(index,key){
    const row=$(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    const raw=row.find('input[name^="inv_centros_json"]').val()||'{}';
    try{
        const parsed=JSON.parse(raw);
        return parsed && typeof parsed==='object' ? parsed : {};
    }catch(e){
        return {};
    }
}

function updateCurrentTaskStageRow(index,key,rowData){
    if(!currentTaskData || !Array.isArray(currentTaskData.etapas)) return;
    if(!currentTaskData.etapas[index]) return;

    let inv={};
    try{
        inv=JSON.parse(currentTaskData.etapas[index].involucrados_json||'{}')||{};
    }catch(e){
        inv={};
    }

    inv[key]=rowData;
    currentTaskData.etapas[index].involucrados_json=JSON.stringify(inv);

    if(currentTaskButton){
        $(currentTaskButton).attr('data-task',JSON.stringify(currentTaskData));
    }
}

function collectStageRowData(index,key){
    const row=$(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    if(!row.length) return null;

    const data={
        persona:row.find('input[name^="inv_persona"]').val()||'',
        base:row.find('input[name^="inv_base"]').val()||'',
        unidad:row.find('input[name^="inv_unidad"]').val()||'',
        mes:row.find('input[name^="inv_mes"]').val()||currentTeamMonth,
        deleted:row.find('input[name^="inv_deleted"]').val()==='1',
        a_lograr:parseFloat(row.find('input[name^="inv_alograr"]').val())||0,
        cumplido:parseFloat(row.find('input[name^="inv_cumplido"]').val())||0,
        a_tiempo:Math.max(0,Math.min(100,parseFloat(row.find('input[name^="inv_a_tiempo"]').val())||0)),
        en_forma:Math.max(0,Math.min(100,parseFloat(row.find('input[name^="inv_en_forma"]').val())||0)),
        quality_initialized:true,
        quality_version:2,
        verifics:row.find('.panel-verific_sub-box input:checked').map(function(){return $(this).val();}).get(),
        lugar:row.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get(),
        centros:readHiddenCenters(index,key)
    };

    if(!window.savedInvData[index]) window.savedInvData[index]={};
    window.savedInvData[index][key]=data;
    updateCurrentTaskStageRow(index,key,data);
    return data;
}

function refreshParentStage3Totals(index,key,centersPayload,changeProgramado=true){
    if(Number(index)!==2) return;

    const row=$(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    if(!row.length) return;

    const centros=Object.values(centersPayload||{});
    const programado=centros.reduce((s,c)=>s+(parseFloat(c.a_lograr)||0),0);
    const cumplido=centros.reduce((s,c)=>s+(parseFloat(c.cumplido)||0),0);

    if(changeProgramado){
        row.find('input[name^="inv_alograr"]').val(programado);
    }

    row.find('input[name^="inv_cumplido"]').val(cumplido);

    const prog=parseFloat(row.find('input[name^="inv_alograr"]').val())||0;
    const at=parseFloat(row.find('input[name^="inv_a_tiempo"]').val())||0;
    const ef=parseFloat(row.find('input[name^="inv_en_forma"]').val())||0;
    row.find('.pct-cell').html(badgePct(calcRowPct(prog,cumplido,at,ef)));
}

function mergeSelectedCentersIntoMemory(index,key,centros,unidad=''){
    const row=$(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    const existing=Object.assign({},getSaved(index,key).centros||{},readHiddenCenters(index,key)||{});
    (centros||[]).forEach(c=>{
        const id=String(c.id);
        const prev=existing[id]||{};
        existing[id]={
            ...prev,
            id:id,
            mes:prev.mes||currentTeamMonth,
            nombre:c.nombre||prev.nombre||'',
            tipo:c.tipo||prev.tipo||'',
            comunidad_base:c.comunidad_base||prev.comunidad_base||'',
            caserio:c.caserio||prev.caserio||'',
            pob_0_5:parseFloat(c.pob_0_5)||parseFloat(prev.pob_0_5)||0,
            pob_6_17:parseFloat(c.pob_6_17)||parseFloat(prev.pob_6_17)||0,
            pob_18_24:parseFloat(c.pob_18_24)||parseFloat(prev.pob_18_24)||0,
            a_lograr:getUnidadTargetPopulation(c, unidad),
            cumplido:parseFloat(prev.cumplido)||0,
            a_tiempo:defaultQualityValue(prev,'a_tiempo'),
            en_forma:defaultQualityValue(prev,'en_forma'),
            quality_initialized:true,
            quality_version:2
        };
    });
    row.find('input[name^="inv_centros_json"]').val(JSON.stringify(existing));
    if(!window.savedInvData[index]) window.savedInvData[index]={};
    window.savedInvData[index][key]={...(window.savedInvData[index][key]||{}),centros:existing};
    return existing;
}

function getActiveCenterPayload(row,allMemory){
    const bases=String(row.data('base')||'').split('|').filter(b=>String(b||'').trim()!=='');
    const lugares=row.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get().filter(isCenterLugar);
    const selected=centrosByBasesYLugares(bases,lugares);
    const active={};
    selected.forEach(c=>{
        const id=String(c.id);
        if(allMemory[id]) active[id]=allMemory[id];
    });
    return {lugares,centros:selected,payload:active};
}

function refreshStage3RowFromPlaces(rowOrTarget,autosaveNow=false){
    const row=$(rowOrTarget).closest('.inv-row').length ? $(rowOrTarget).closest('.inv-row') : $(rowOrTarget);
    if(!row.length || Number(row.data('index'))!==2) return;

    const index=Number(row.data('index'));
    const key=String(row.data('key'));
    const unidad=row.find('input[name^="inv_unidad"]').val();
    const bases=String(row.data('base')||'').split('|').filter(b=>String(b||'').trim()!=='');
    const todosLugares=row.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get();
    const lugaresCentro=todosLugares.filter(isCenterLugar);
    const centrosActivos=centrosByBasesYLugares(bases,lugaresCentro);
    const allMemory=mergeSelectedCentersIntoMemory(index,key,centrosActivos,unidad);
    const activePayload={};
    centrosActivos.forEach(c=>{const id=String(c.id);if(allMemory[id])activePayload[id]=allMemory[id];});

    if(lugaresCentro.length){
        refreshParentStage3Totals(index,key,activePayload,true);
    }else{
        row.find('input[name^="inv_alograr"]').val(0);
        row.find('input[name^="inv_cumplido"]').val(0);
        row.find('.pct-cell').html(badgePct(0));
    }

    const data=collectStageRowData(index,key);
    updateActivityProgress();
    if(autosaveNow && data){
        scheduleCenterRowAutosave(index,key,row.find('.panel-lugar_sub-box .multiselect-select-box')[0]);
    }
}

const centerRowAutosaveTimers={};

function scheduleCenterRowAutosave(index,key,target=null){
    const timerKey=`${index}|${key}`;
    clearTimeout(centerRowAutosaveTimers[timerKey]);
    centerRowAutosaveTimers[timerKey]=setTimeout(()=>{
        autosaveCenterRow(index,key,target);
    },350);
}

function autosaveCenterRow(index,key,target=null){
    const rowData=collectStageRowData(index,key);
    if(!rowData) return Promise.resolve();
    const fd=new FormData();
    fd.append('action','autosave_centros_etapa3');
    fd.append('id_poa',$('#upd_task_id').val());
    fd.append('row_key',key);
    fd.append('row_json',JSON.stringify(rowData));
    updateSaveIndicator('saving');
    return enqueueAutosave(async()=>{
        const response=await fetch(window.location.pathname,{method:'POST',body:fd});
        const result=await response.json();
        if(!response.ok||result.status!=='ok') throw new Error(result.msg||'No se pudo guardar el detalle de centros.');
        updateSaveIndicator('saved');
        updateCurrentTaskStageRow(index,key,result.row||rowData);
        return result;
    }).catch(error=>{
        console.error('Autoguardado de centros:',error);
        updateSaveIndicator('error');
        throw error;
    });
}

function splitResponsibleNameHtml(name){
    const parts = String(name || '').trim().split(/\s+/).filter(Boolean);
    if (parts.length <= 2) {
        return `<div class="resp-name"><span class="resp-first">${escHtml(parts.join(' '))}</span></div>`;
    }
    const first = parts.slice(0, 2).join(' ');
    const last = parts.slice(2).join(' ');
    return `<div class="resp-name"><span class="resp-first">${escHtml(first)}</span><span class="resp-last">${escHtml(last)}</span></div>`;
}

function buildEtapasTable(taskData){
    $('#etapas_loaded').val('1');
    window.savedInvData={};
    let etapas=Array.isArray(taskData.etapas)&&taskData.etapas.length?taskData.etapas:etapasDefault.map((e,i)=>({codigo_etapa:e.codigo,nombre_etapa:e.nombre,descripcion_etapa:e.descripcion,unidad_medida:'[]',responsable:'[]',involucrados_json:'{}',fecha_recepcion:getFechaMaximaEtapa(i)}));
    let html='';
    let currentProg = (taskData.programa || '') + ' ' + (taskData.sector || '');

    etapas.forEach((e,i)=>{
        let resps=[],unis=[];
        try{resps=JSON.parse(e.responsable||'[]')}catch(ex){if(e.responsable)resps=[e.responsable]}
        try{unis=JSON.parse(e.unidad_medida||'[]')}catch(ex){if(e.unidad_medida)unis=[e.unidad_medida]}
        try{window.savedInvData[i]=JSON.parse(e.involucrados_json||'{}')}catch(ex){window.savedInvData[i]={}}

        let fecha=e.fecha_recepcion||getFechaMaximaEtapa(i);
        let filteredUnis = getFilteredCatalog(masterUnidadesRaw, currentProg, `E-${i+1}`);
        let rowUnis = [...new Set([...filteredUnis, ...unis])];

        const savedJson=JSON.stringify(window.savedInvData[i]||{});
        html+=`<tr class="stage-main-row"><td><div class="stage-info"><div class="stage-code">${escHtml(e.codigo_etapa||etapasDefault[i]?.codigo||'')}</div><div><div class="stage-name">${escHtml(e.nombre_etapa||etapasDefault[i]?.nombre||'')}</div><div class="stage-desc">${escHtml(e.descripcion_etapa||etapasDefault[i]?.descripcion||'')}</div><input type="hidden" name="etapa_codigo[]" value="${escHtml(e.codigo_etapa||etapasDefault[i]?.codigo||'')}"><input type="hidden" name="etapa_nombre[]" value="${escHtml(e.nombre_etapa||etapasDefault[i]?.nombre||'')}"><input type="hidden" name="etapa_descripcion[]" value="${escHtml(e.descripcion_etapa||etapasDefault[i]?.descripcion||'')}"><input type="hidden" name="etapa_involucrados_json[]" data-etapa-json="${i}" value="${escHtml(savedJson)}"></div></div></td><td>${optionsCheckboxes(rowUnis,unis,`etapa_unidades[${i}][]`,i,'unidad',true,'')}</td><td>${optionsCheckboxes(masterResponsables,resps,`etapa_resps[${i}][]`,i,'responsable',true,'')}</td><td><span class="global-date-pill"><i class="fa-solid fa-calendar-check"></i><input type="date" name="etapa_fecha_recepcion[${i}]" value="${escHtml(fecha)}" class="table-input date-input-compact"></span></td></tr><tr><td colspan="4"><div id="subgrid-${i}" data-built="0"><div style="padding:12px;text-align:center;color:#64748b"><i class="fa-solid fa-spinner fa-spin"></i> Preparando programación...</div></div></td></tr>`;
    });

    $('#tabla_etapas_body').html(html);
    $('.multiselect-dropdown-panel').each(function(){updateMultiselectText(this);});
    scheduleStageSubgrids(etapas.length);
}

function loadStageSubgrid(index){
    const cont=$(`#subgrid-${index}`);
    if(cont.attr('data-built')==='1')return;
    cont.attr('data-built','1');
    const resps=selectedFromPanel(`.panel-responsable-box[data-index="${index}"]`);
    const unis=selectedFromPanel(`.panel-unidad-box[data-index="${index}"]`);
    buildSubgrid(index,resps,unis);
}

function scheduleStageSubgrids(total,index=0){
    if(index>=total)return;
    const run=()=>{if(!modalEtapasBuilt)return;loadStageSubgrid(index);setTimeout(()=>scheduleStageSubgrids(total,index+1),0);};
    if('requestIdleCallback'in window)requestIdleCallback(run,{timeout:350});else setTimeout(run,20);
}

function buildSubgrid(index,resps,unidades){
    let cont=$(`#subgrid-${index}`);
    if(!resps.length||!unidades.length){cont.hide().html('');return;}
    let currentProg = currentTaskData ? ((currentTaskData.programa || '') + ' ' + (currentTaskData.sector || '')) : '';
    let filteredVerifs = getFilteredCatalog(masterVerificacionesRaw, currentProg, `E-${index+1}`);


    let html=`<div class="subgrid-wrapper"><div class="subgrid-card"><div class="subgrid-header subgrid-toolbar"><h5><i class="fa-solid fa-users-viewfinder"></i> Programación por responsable y unidad</h5><div style="display:flex;align-items:center;gap:8px"><span class="sticky-mini-note"><i class="fa-solid fa-calendar-days"></i> Mes actual: <b>${escHtml((mesesEquipo.find(x=>x.k===currentTeamMonth)||{}).n||currentTeamMonth)}</b></span><button type="button" class="btn-action btn-mini btn-xlsx" onclick="exportClosestTable(this,'etapa_${index+1}_responsables')"><i class="fa-solid fa-file-excel"></i></button></div></div><table class="subgrid-table"><thead><tr><th style="width:22%">Responsable</th><th style="width:10%">Unidad</th><th style="width:9%">PROG.</th><th style="width:9%">CUMPL.</th><th style="width:9%">A tiempo (%)</th><th style="width:9%">En forma (%)</th><th style="width:8%">%</th><th style="width:15%">Medios verificación</th><th style="width:11%">Lugar</th><th style="width:86px">Acción</th>${index===2?'<th style="width:92px">Detalle</th>':''}</tr></thead><tbody>`;

    let hiddenHoldersHtml = '';

    resps.forEach(p=>{
        let bases=getBasesByTecnico(p);
        let basesVisibles=bases.filter(b=>String(b||'').trim()!=='');
        unidades.forEach(u=>{
            let key=rowKey(p+'|__ALLBASES',u);
            let d=getSaved(index,key);

            let isDeleted = false;
            if(d && d.deleted === true) {
                isDeleted = true;
            } else if(!d || Object.keys(d).length===0) {
                d=combineSavedForBases(index,p,bases,u);
                if(d && d.deleted === true) isDeleted = true;
            }

            // Si está borrado, lo agregamos como input oculto pero NO dibujamos la fila
            if (isDeleted) {
                hiddenHoldersHtml += `<div class="deleted-inv-holder" style="display:none"><input type="hidden" name="inv_persona[${index}][${key}]" value="${escHtml(p)}"><input type="hidden" name="inv_base[${index}][${key}]" value="${escHtml(bases.join('|'))}"><input type="hidden" name="inv_unidad[${index}][${key}]" value="${escHtml(u)}"><input type="hidden" name="inv_mes[${index}][${key}]" value="${escHtml(currentTeamMonth)}"><input type="hidden" name="inv_deleted[${index}][${key}]" value="1"><input type="hidden" name="inv_alograr[${index}][${key}]" value="0"></div>`;
                return;
            }

            if(!d || Object.keys(d).length===0)d={};
            let lugar=Array.isArray(d.lugar)?d.lugar:[];
            let lugaresCentro=lugar.filter(isCenterLugar);
            let centrosEstimados=(index===2&&lugaresCentro.length)?centrosByBasesYLugares(bases,lugaresCentro):[];

            let programadoDefault = parseFloat(d.a_lograr) || 0;
            if (index === 2 && lugaresCentro.length) {
                let sumU = 0;
                centrosEstimados.forEach(c => sumU += getUnidadTargetPopulation(c, u));
                programadoDefault = sumU;
            } else if (index === 1) {
                let lugaresParaConteo = lugaresCentro.length ? lugaresCentro : (window.lastGlobalCenterPlaces || []);
                if (lugaresParaConteo.length) {
                    let centrosEtapa2 = centrosByBasesYLugares(bases, lugaresParaConteo);
                    if (centrosEtapa2.length > 0) programadoDefault = centrosEtapa2.length;
                }
            }
            if (programadoDefault === 0 && (!d.a_lograr || parseFloat(d.a_lograr) === 0)) programadoDefault = '';

            let cumplidoDefault=d.cumplido||0;
            let aTiempoDefault=defaultQualityValue(d,'a_tiempo');
            let enFormaDefault=defaultQualityValue(d,'en_forma');
            let pct=calcRowPct(programadoDefault,cumplidoDefault,aTiempoDefault,enFormaDefault);
            let baseLabels=basesVisibles.length?`<div class="base-labels-wrap">${basesVisibles.map(b=>`<span class="base-badge">${escHtml(b)}</span>`).join('')}</div>`:'<br><span style="color:#94a3b8;font-size:.76rem">Sin base asignada</span>';
            let rowVerifs = [...new Set([...filteredVerifs, ...(Array.isArray(d.verifics)?d.verifics:[])])];

            html+=`<tr class="inv-row" data-index="${index}" data-key="${key}" data-persona="${escHtml(p)}" data-base="${escHtml(bases.join('|'))}"><td><input type="hidden" name="inv_centros_json[${index}][${key}]" value="${escHtml(JSON.stringify(d.centros||{}))}"><input type="hidden" name="inv_persona[${index}][${key}]" value="${escHtml(p)}"><input type="hidden" name="inv_base[${index}][${key}]" value="${escHtml(bases.join('|'))}"><input type="hidden" name="inv_deleted[${index}][${key}]" value="0"><input type="hidden" name="inv_mes[${index}][${key}]" value="${escHtml(currentTeamMonth)}"><input type="hidden" name="inv_quality_initialized[${index}][${key}]" value="1"><input type="hidden" name="inv_quality_version[${index}][${key}]" value="2"><div class="resp-cell"><i class="fa-solid fa-user-check resp-icon"></i>${splitResponsibleNameHtml(p)}</div>${baseLabels}</td><td><span class="code-pill ext">${escHtml(u)}</span><input type="hidden" name="inv_unidad[${index}][${key}]" value="${escHtml(u)}"></td><td><input type="number" step="0.01" name="inv_alograr[${index}][${key}]" value="${escHtml(programadoDefault)}" class="table-input a-lograr-input auto-full-save"></td><td><input type="number" step="0.01" name="inv_cumplido[${index}][${key}]" value="${escHtml(cumplidoDefault)}" class="table-input cumplido-input auto-full-save"></td><td><input type="number" min="0" max="100" step="1" name="inv_a_tiempo[${index}][${key}]" value="${escHtml(aTiempoDefault)}" class="table-input score-input inv-score auto-full-save"></td><td><input type="number" min="0" max="100" step="1" name="inv_en_forma[${index}][${key}]" value="${escHtml(enFormaDefault)}" class="table-input score-input inv-score auto-full-save"></td><td class="pct-cell">${badgePct(pct)}</td><td>${optionsCheckboxes(rowVerifs,Array.isArray(d.verifics)?d.verifics:[],`inv_verifics[${index}][${key}][]`,index,'verific_sub',false,key)}</td><td>${optionsCheckboxes(masterLugares,lugar,`inv_lugar[${index}][${key}][]`,index,'lugar_sub',false,key)}</td><td><button type="button" class="btn-action btn-mini btn-delete-row" onclick="deleteInvRow(${index}, '${key}')"><i class="fa-solid fa-trash"></i></button></td>${index===2?`<td><button type="button" class="btn-action btn-mini btn-eye" onclick="toggleDetalleCentros(${index}, '${key}', this)"><i class="fa-solid fa-eye"></i> Ver</button></td>`:''}</tr><tr id="detalle-centros-${index}-${key}" class="detail-centros-row d-none"><td colspan="${index===2?11:10}"></td></tr>`;
        });
    });

    html+='</tbody></table>' + hiddenHoldersHtml + '</div></div>';
    cont.html(html).show();
    cont.find('.multiselect-dropdown-panel').each(function(){updateMultiselectText(this);});
    updateActivityProgress();
}
function deleteInvRow(index,key){
    if(!window.savedInvData[index]) window.savedInvData[index]={};

    let row = $(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    let persona = row.find(`input[name^="inv_persona"]`).val() || '';
    let unidad = row.find(`input[name^="inv_unidad"]`).val() || '';
    let base = row.find(`input[name^="inv_base"]`).val() || '';

    // Lo guardamos explícitamente en memoria como deleted
    window.savedInvData[index][key] = {
        ...(window.savedInvData[index][key]||{}),
        persona, base, unidad, deleted:true, mes:currentTeamMonth
    };

    // Agregar el holder invisible de respaldo antes de borrar
    $(`#subgrid-${index}`).append(`<div class="deleted-inv-holder" style="display:none"><input type="hidden" name="inv_persona[${index}][${key}]" value="${escHtml(persona)}"><input type="hidden" name="inv_base[${index}][${key}]" value="${escHtml(base)}"><input type="hidden" name="inv_unidad[${index}][${key}]" value="${escHtml(unidad)}"><input type="hidden" name="inv_mes[${index}][${key}]" value="${escHtml(currentTeamMonth)}"><input type="hidden" name="inv_deleted[${index}][${key}]" value="1"><input type="hidden" name="inv_alograr[${index}][${key}]" value="0"></div>`);

    // Lo borramos del DOM visual
    row.next('.detail-centros-row').remove();
    row.remove();

    // Evaluar si le quedan otras unidades activas a esa persona en esta etapa
    if (persona !== '') {
        let activeForPersona = $(`#subgrid-${index} tr.inv-row`).filter(function() {
            return $(this).find(`input[name^="inv_persona"]`).val() === persona;
        }).length;

        // Si no quedan unidades, desmarcamos a la persona del Dropdown original
        if (activeForPersona === 0) {
            let respPanel = $(`.panel-responsable-box[data-index="${index}"] .multiselect-dropdown-panel`);
            let checkbox = respPanel.find(`input[value="${escHtml(persona).replace(/"/g,'\\"')}"]`);
            if (checkbox.length && checkbox.prop('checked')) {
                checkbox.prop('checked', false);
                updateMultiselectText(respPanel[0]);
            }
        }
    }

    // Evaluar si le quedan otros responsables a esa unidad en esta etapa
    if (unidad !== '') {
        let activeForUnidad = $(`#subgrid-${index} tr.inv-row`).filter(function() {
            return $(this).find(`input[name^="inv_unidad"]`).val() === unidad;
        }).length;

        // Si no quedan responsables para esta unidad, la desmarcamos del Dropdown
        if (activeForUnidad === 0) {
            let uniPanel = $(`.panel-unidad-box[data-index="${index}"] .multiselect-dropdown-panel`);
            let checkbox = uniPanel.find(`input[value="${escHtml(unidad).replace(/"/g,'\\"')}"]`);
            if (checkbox.length && checkbox.prop('checked')) {
                checkbox.prop('checked', false);
                updateMultiselectText(uniPanel[0]);
            }
        }
    }

    updateActivityProgress();
    scheduleFullAutosave();
}

function closeCentrosDrawer(save=true){
    const drawer=$('#centrosDrawer');
    const index=Number(drawer.data('index'));
    const key=String(drawer.data('key')||'');

    updateHiddenCentrosJsonFromDrawer();

    if(save && key!==''){
        scheduleCenterRowAutosave(index,key);
    }

    drawer.removeClass('open').attr('aria-hidden','true').data('index','').data('key','');
    $('#centrosDrawerBody').empty();
    $('#centrosDrawerTitle').html('<i class="fa-solid fa-building-columns"></i> Centros');
    $('#centrosDrawerSub').text('');
    $('.btn-eye').removeClass('active').html('<i class="fa-solid fa-eye"></i> Ver');
}

function updateHiddenCentrosJsonFromDrawer(){
    const drawer=$('#centrosDrawer');
    if(!drawer.hasClass('open')) return {};

    const index=Number(drawer.data('index'));
    const key=String(drawer.data('key')||'');
    if(!Number.isFinite(index) || key==='') return {};

    const row=$(`tr.inv-row[data-index="${index}"][data-key="${key}"]`);
    const allMemory=Object.assign({},getSaved(index,key).centros||{},readHiddenCenters(index,key)||{});
    const visibleIds=[];

    drawer.find('tr[data-centro-id]').each(function(){
        const tr=$(this);
        const id=String(tr.data('centro-id'));
        visibleIds.push(id);
        const prev=allMemory[id]||{};
        allMemory[id]={
            ...prev,
            id:id,
            mes:currentTeamMonth,
            nombre:tr.find('input[data-field="nombre"]').val()||prev.nombre||'',
            tipo:tr.find('input[data-field="tipo"]').val()||prev.tipo||'',
            comunidad_base:tr.find('input[data-field="comunidad_base"]').val()||prev.comunidad_base||'',
            caserio:tr.find('input[data-field="caserio"]').val()||prev.caserio||'',
            pob_0_5:tr.find('input[data-field="pob_0_5"]').val()||prev.pob_0_5||0,
            pob_6_17:tr.find('input[data-field="pob_6_17"]').val()||prev.pob_6_17||0,
            pob_18_24:tr.find('input[data-field="pob_18_24"]').val()||prev.pob_18_24||0,
            a_lograr:tr.find('input[name$="[a_lograr]"]').val()||prev.a_lograr||0,
            cumplido:tr.find('input[name$="[cumplido]"]').val()||0,
            a_tiempo:tr.find('input[name$="[a_tiempo]"]').val()||100,
            en_forma:tr.find('input[name$="[en_forma]"]').val()||100,
            quality_initialized:true,
            quality_version:2
        };
    });

    row.find('input[name^="inv_centros_json"]').val(JSON.stringify(allMemory));
    if(!window.savedInvData[index]) window.savedInvData[index]={};
    window.savedInvData[index][key]={...(window.savedInvData[index][key]||{}),centros:allMemory};

    const activePayload={};
    visibleIds.forEach(id=>{if(allMemory[id])activePayload[id]=allMemory[id];});
    refreshParentStage3Totals(index,key,activePayload,true);
    collectStageRowData(index,key);
    return allMemory;
}

function renderCenterTable(index,key,centros,savedCentros,showAges){
    const ageHeaders=showAges?'<th class="col-age">0-5</th><th class="col-age">6-17</th><th class="col-age">18-24</th>':'';
    let html=`<div class="centros-detail-body"><table class="centros-table ${showAges?'with-ages':'without-ages'}"><thead><tr><th class="col-type">Tipo</th><th class="col-center">Centro</th><th class="col-community">Comunidad</th><th class="col-caserio">Caserío</th><th class="col-prog">PROG.</th><th class="col-cumpl">CUMPL.</th>${ageHeaders}<th class="col-score">A tiempo (%)</th><th class="col-score">En forma (%)</th><th class="col-pct">%</th></tr></thead><tbody>`;
    centros.forEach(c=>{
        const sc=savedCentros[String(c.id)]||{};
        const prog=parseFloat(sc.a_lograr)||0;
        const cum=parseFloat(sc.cumplido)||0;
        const at=defaultQualityValue(sc,'a_tiempo');
        const ef=defaultQualityValue(sc,'en_forma');
        const pct=calcRowPct(prog,cum,at,ef);
        const hiddenAges=`<input type="hidden" name="inv_centros[${index}][${key}][${c.id}][pob_0_5]" data-field="pob_0_5" value="${escHtml(c.pob_0_5||0)}"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][pob_6_17]" data-field="pob_6_17" value="${escHtml(c.pob_6_17||0)}"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][pob_18_24]" data-field="pob_18_24" value="${escHtml(c.pob_18_24||0)}">`;
        const ageCells=showAges?`<td class="col-age">${escHtml(c.pob_0_5||0)}</td><td class="col-age">${escHtml(c.pob_6_17||0)}</td><td class="col-age">${escHtml(c.pob_18_24||0)}</td>`:'';
        html+=`<tr data-centro-id="${escHtml(c.id)}"><td class="col-type"><span class="prog-badge">${escHtml(c.tipo)}</span></td><td class="col-center"><div class="center-name">${escHtml(c.nombre)}</div><div class="center-meta">ID ${escHtml(c.id)}</div><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][id]" value="${escHtml(c.id)}"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][mes]" value="${escHtml(currentTeamMonth)}"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][quality_initialized]" value="1"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][quality_version]" value="2"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][nombre]" data-field="nombre" value="${escHtml(c.nombre)}"><input type="hidden" name="inv_centros[${index}][${key}][${c.id}][tipo]" data-field="tipo" value="${escHtml(c.tipo)}">${hiddenAges}</td><td class="col-community">${escHtml(c.comunidad_base||'')}<input type="hidden" name="inv_centros[${index}][${key}][${c.id}][comunidad_base]" data-field="comunidad_base" value="${escHtml(c.comunidad_base||'')}"></td><td class="col-caserio">${escHtml(c.caserio||'')}<input type="hidden" name="inv_centros[${index}][${key}][${c.id}][caserio]" data-field="caserio" value="${escHtml(c.caserio||'')}"></td><td class="col-prog"><input type="number" name="inv_centros[${index}][${key}][${c.id}][a_lograr]" value="${escHtml(prog)}" class="table-input a-lograr-input centro-programado" readonly></td><td class="col-cumpl"><input type="number" step="0.01" name="inv_centros[${index}][${key}][${c.id}][cumplido]" value="${escHtml(cum)}" class="table-input cumplido-input centro-cumplido"></td>${ageCells}<td class="col-score"><input type="number" min="0" max="100" name="inv_centros[${index}][${key}][${c.id}][a_tiempo]" value="${escHtml(at)}" class="table-input score-input centro-score"></td><td class="col-score"><input type="number" min="0" max="100" name="inv_centros[${index}][${key}][${c.id}][en_forma]" value="${escHtml(ef)}" class="table-input score-input centro-score"></td><td class="centro-pct col-pct">${badgePct(pct)}</td></tr>`;
    });
    return html+'</tbody></table></div>';
}

function toggleDetalleCentros(index,key,btn){
    const drawer=$('#centrosDrawer');
    if(drawer.hasClass('open')&&String(drawer.data('key'))===String(key)&&String(drawer.data('index'))===String(index)){
        closeCentrosDrawer();return;
    }
    updateHiddenCentrosJsonFromDrawer();
    $('.btn-eye').removeClass('active').html('<i class="fa-solid fa-eye"></i> Ver');

    const invRow=$(`tr.inv-row[data-key="${key}"][data-index="${index}"]`);
    const persona=invRow.data('persona');
    const unidad=invRow.find('input[name^="inv_unidad"]').val();
    let bases=String(invRow.data('base')||'').split('|').filter(b=>String(b||'').trim()!=='');
    if(!bases.length) bases=[''];
    const todosLugares=invRow.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get();
    const lugares=todosLugares.filter(isCenterLugar);
    // Pasamos la "unidad" para que el merge cruce la población exacta (Infantes, Jóvenes, Líderes, etc.)
    const savedCentros=mergeSelectedCentersIntoMemory(index,key,centrosByBasesYLugares(bases,lugares), unidad);
    let totalMatricula=0,headerCount=0,sections='';

    lugares.forEach(lugar=>{
        const tipo=lugarToTipo(lugar);
        const showAges=tipo==='adn'||tipo==='uaps/cis';
        bases.forEach(base=>{
            const centros=getCentrosPorTecnicoYLugar(base,lugar);
            if(!centros.length) return;
            headerCount+=centros.length;
            // Sumamos lo que requiere esta unidad específicamente para esta fila
            centros.forEach(c => totalMatricula += getUnidadTargetPopulation(c, unidad));
            sections+=`<div class="detail-base-section"><div class="detail-base-title"><span><i class="fa-solid fa-building"></i> ${escHtml(lugar)} · <i class="fa-solid fa-location-dot"></i> Base: ${escHtml(base||'Sin base')}</span><span style="display:flex;align-items:center;gap:8px"><span class="count">${centros.length} centros</span><button type="button" class="btn-action btn-mini btn-xlsx" onclick="exportClosestTable(this,'centros_${sanitizeExportName(lugar)}_${sanitizeExportName(base||'sin_base')}')"><i class="fa-solid fa-file-excel"></i></button></span></div>${renderCenterTable(index,key,centros,savedCentros,showAges)}</div>`;
        });
    });

    let body='';
    if(!lugares.length){
        body=`<div class="centros-drawer-empty"><i class="fa-solid fa-triangle-exclamation"></i> Los lugares seleccionados no corresponden a centros. Seleccione Centro Preescolar, Centro Educativo, Centro ADN o UAPS/CIS.</div>`;
    }else if(!headerCount){
        body=`<div class="centros-drawer-empty"><i class="fa-solid fa-triangle-exclamation"></i> No hay centros registrados para <b>${escHtml(persona)}</b> en las bases y lugares seleccionados.</div>`;
    }else body=sections;

    $('#centrosDrawerTitle').html(`<i class="fa-solid fa-building-columns"></i> Centros de ${escHtml(persona||'')}`);
    $('#centrosDrawerSub').html(`Lugar(es): <b>${escHtml(lugares.join(', ')||'Sin centros')}</b> · Unidad (Población): <b>${escHtml(unidad||'General')}</b> · Centros: <b>${headerCount}</b> · PROG Total: <b>${formatNum(totalMatricula)}</b>`);
    $('#centrosDrawerBody').html(body);
    drawer.data('index',index).data('key',key).addClass('open').attr('aria-hidden','false');
    $(btn).addClass('active').html('<i class="fa-solid fa-eye-slash"></i> Ocultar');
    if(headerCount){
        updateHiddenCentrosJsonFromDrawer();
        updateActivityProgress();
    }
}

$(document).on('input change','.centro-score,.centro-cumplido',function(){
    const input=this;
    const tr=$(input).closest('tr');
    const vals=tr.find('.centro-score');
    const prog=tr.find('input[name$="[a_lograr]"]').val();
    const cum=tr.find('input[name$="[cumplido]"]').val();
    tr.find('.centro-pct').html(badgePct(calcRowPct(prog,cum,vals.eq(0).val(),vals.eq(1).val())));
    updateHiddenCentrosJsonFromDrawer();
    const drawer=$('#centrosDrawer');
    const index=Number(drawer.data('index'));
    const key=String(drawer.data('key')||'');
    updateActivityProgress();
    if(key!=='') scheduleCenterRowAutosave(index,key,input);
    scheduleFullAutosave();
});
$(document).on('blur change','.table-input,select,textarea,input[type=number],input[type=date]',function(){ if($(this).closest('#tabla_tecnicos_body,#centrosDrawer').length) return; scheduleFullAutosave(); });

function syncAgendaStickyMini(taskData){
    $('#agenda_codigo_mini').html('<i class="fa-solid fa-hashtag"></i> '+escHtml(taskData.codigo||''));
    $('#agenda_ext_mini').html('<i class="fa-solid fa-code-branch"></i> EXT '+escHtml(taskData.extension||''));
    $('#agenda_nombre_mini').text(taskData.actividad||'');
    $('#agenda_mes_mini').text((mesesEquipo.find(x=>x.k===currentTeamMonth)||{}).n||currentTeamMonth);
}

function updateActivityProgress(){
    let totalEtapas = 4;
    let suma = 0;
    for(let i=0;i<totalEtapas;i++){
        let rows = $(`#subgrid-${i} tr.inv-row`);
        let vals=[];
        rows.each(function(){
            let r=$(this);
            let prog=parseFloat(r.find('input[name^="inv_alograr"]').val())||0;
            let cum=parseFloat(r.find('input[name^="inv_cumplido"]').val())||0;
            let at=parseFloat(r.find('input[name^="inv_a_tiempo"]').val())||0;
            let ef=parseFloat(r.find('input[name^="inv_en_forma"]').val())||0;
            vals.push(calcRowPct(prog,cum,at,ef));
        });
        let etapaPct = vals.length ? vals.reduce((a,b)=>a+b,0)/vals.length : 0;
        suma += etapaPct;
    }
    let total = suma / totalEtapas;
    let porcentaje = Math.round(total)+'%';

    $('#lbl_estado_porcentaje').attr('class','activity-pct-badge '+activityPctClass(total)).text(porcentaje);
    $('#upd_estado').val(porcentaje);

    if (currentTaskData) {
        currentTaskData.estado = porcentaje;
        updateCardVisuals();
    }

    return total;
}

function openUpdateModal(btn){
    try {
        let raw = btn.getAttribute('data-task');
        let task = JSON.parse(raw);
        currentTaskData = task;
        currentTaskButton = btn;
        modalEtapasBuilt = false;
        $('#etapas_loaded').val('0');

        document.getElementById('updateModal').style.display = 'flex';
        $('#tabla_etapas_body').html('<tr><td colspan="4" style="text-align:center;padding:40px"><i class="fa-solid fa-spinner fa-spin fa-2x" style="color:var(--ah-primary)"></i><br><br>Cargando programación...</td></tr>');

        $('#upd_task_id').val(task.id);
        $('#btn-historial-actividad').attr('href',`historial_actividad.php?id=${encodeURIComponent(task.id)}`);
        $('#lbl_actividad').text(task.actividad || 'Actividad');
        $('#lbl_meta_global').text(formatNum(task.m_part_obj || 0));
        $('#lbl_meta_mes_actual').text(formatNum((task.op_part && task.op_part[currentTeamMonth]) ? task.op_part[currentTeamMonth] : 0));
        $('#upd_estado').val(task.estado || '0%');
        $('#lbl_mes_actual_modal').text((mesesEquipo.find(x => x.k === currentTeamMonth) || {}).n || currentTeamMonth);
        $('#upd_m_act_obj').val(task.m_act_obj || 0);
        $('#upd_m_act_alc').val(task.m_act_alc || 0);
        $('#upd_m_part_obj').val(task.m_part_obj || 0);
        $('#upd_m_part_alc').val(task.m_part_alc || 0);

        if (task.codigo) { $('#lbl_codigo_modal').show().find('span').text(task.codigo); }
        else { $('#lbl_codigo_modal').hide(); }

        if (task.extension) { $('#lbl_ext_modal').show().find('span').text(task.extension); }
        else { $('#lbl_ext_modal').hide(); }

        setTimeout(()=>{
            fillMonths(task);
            buildTeamTable(task);
            $('#tabla_etapas_body').empty();
            updateActivityProgress();
            if(typeof tinymce!=='undefined'&&tinymce.get('upd_info_adicional')) tinymce.get('upd_info_adicional').setContent(task.info_adicional||'');
            else $('#upd_info_adicional').val(task.info_adicional||'');
            switchModalTab('tab-equipo');
        },50);
    } catch (err) {
        console.error('Error al abrir modal de monitoreo:', err);
        alert('No se pudo abrir el panel. Error: ' + err.message);
    }
}

function monthValue(source,m){if(!source)return 0;if(Object.prototype.hasOwnProperty.call(source,m))return parseFloat(source[m])||0;return 0;}
function fillMonths(task){let m1=['jul','aug','sep','oct','nov','dec'],m2=['jan','feb','mar','apr','may','jun'];let cell=(kind,m,val)=>`<td><input type="number" step="0.01" name="${kind}[${m}]" value="${val}" class="table-input metas-edit-field"></td>`;$('#tabla_act_body_1').html('<tr>'+m1.map(m=>cell('op_act',m,monthValue(task.op_act,m))).join('')+'</tr>');$('#tabla_act_body_2').html('<tr>'+m2.map(m=>cell('op_act',m,monthValue(task.op_act,m))).join('')+'</tr>');$('#tabla_part_body_1').html('<tr>'+m1.map(m=>cell('op_part',m,monthValue(task.op_part,m))).join('')+'</tr>');$('#tabla_part_body_2').html('<tr>'+m2.map(m=>cell('op_part',m,monthValue(task.op_part,m))).join('')+'</tr>');setMetasLocked(true);}
let metasUnlocked=false;
function setMetasLocked(locked){metasUnlocked=!locked;$('#metas_authorized').val(locked?'0':'1');$('#metas-fieldset').prop('disabled',locked);$('#btn-unlock-metas').toggle(locked);$('#metas-unlocked-badge').toggle(!locked);}
function openMetasPasswordModal(){$('#metas-password-error').hide().text('');$('#metas-password-input').val('');$('#metasPasswordModal').css('display','flex');setTimeout(()=>$('#metas-password-input').trigger('focus'),80);}
function closeMetasPasswordModal(){$('#metasPasswordModal').hide();}
async function verifyMetasPassword(){let password=$('#metas-password-input').val(),error=$('#metas-password-error');error.hide();try{let fd=new FormData();fd.append('action','verify_metas_password');fd.append('password',password);let response=await fetch(window.location.pathname,{method:'POST',body:fd});let res=await response.json();if(!response.ok||res.status!=='ok')throw new Error(res.msg||'No fue posible validar la contraseña.');setMetasLocked(false);closeMetasPasswordModal();$('#metas-fieldset input:first').trigger('focus');}catch(err){error.text(err.message).show();}}
$(document).on('keydown','#metas-password-input',function(e){if(e.key==='Enter'){e.preventDefault();verifyMetasPassword();}});

function parseArrayValue(value){
    if(Array.isArray(value)) return value;
    if(value===null||value===undefined||value==='') return [];
    try{const p=JSON.parse(value);return Array.isArray(p)?p:[];}catch(e){return String(value).split(',').map(x=>x.trim()).filter(Boolean);}
}

function teamGlobalLocationControl(selected){
    const vals=Array.isArray(selected)?selected:[];
    let html=`<div class="custom-multiselect team-global-location-control" data-type="team_global_lugar" data-index="global"><div class="multiselect-select-box" onclick="event.stopPropagation();toggleDropdownPanel(this)"><span class="multi-label">Seleccione...</span></div><div class="multiselect-dropdown-panel" onclick="event.stopPropagation()" onmousedown="event.stopPropagation()">`;
    masterLugares.forEach(v=>{html+=`<label class="multiselect-option" onclick="event.stopPropagation()"><input type="checkbox" class="team-global-lugar-check" value="${escHtml(v)}" ${vals.includes(v)?'checked':''}> ${escHtml(v)}</label>`;});
    html+='</div></div>';
    return html;
}

function selectedGlobalTeamPlaces(){
    return $('#team-global-location-host .team-global-lugar-check:checked').map(function(){return $(this).val();}).get();
}

function calculateTeamProgramado(row,places=null){
    const base = row.find('.team-base').val() || '';
    const lugares = (places || selectedGlobalTeamPlaces()).filter(isCenterLugar);
    const centros = centrosByBasesYLugares(base ? [base] : [], lugares);
    return { lugares, centros, total: sumMatriculaCentros(centros) };
}

window.lastGlobalCenterPlaces = window.lastGlobalCenterPlaces || [];
function updateCurrentTaskGlobalPlaces(){
    if(!currentTaskData) return;
    const lugares=selectedGlobalTeamPlaces();
    currentTaskData.team_lugares=JSON.stringify(lugares);
    if(currentTaskButton) $(currentTaskButton).attr('data-task',JSON.stringify(currentTaskData));
}

function updateCurrentTaskAssignmentFromRow(row){
    if(!currentTaskData) return;
    if(!Array.isArray(currentTaskData.asignaciones)) currentTaskData.asignaciones=[];
    const tecnico=row.find('.team-tecnico').val()||'';
    const base=row.find('.team-base').val()||'';
    const lugares=selectedGlobalTeamPlaces();
    let hasValues=row.find('.team-month-input').toArray().some(el=>(parseFloat(el.value)||0)!==0);
    const selected=row.find('.team-selected').is(':checked');
    const idx=currentTaskData.asignaciones.findIndex(x=>x.tecnico===tecnico&&(x.base_asignada||'')===base);
    if(!selected&&!hasValues){
        if(idx>=0) currentTaskData.asignaciones.splice(idx,1);
        if(currentTaskButton) $(currentTaskButton).attr('data-task',JSON.stringify(currentTaskData));
        return;
    }
    let a=idx>=0?currentTaskData.asignaciones[idx]:null;
    if(!a){a={tecnico,base_asignada:base};currentTaskData.asignaciones.push(a);}
    a.lugares_json=JSON.stringify(lugares);
    mesesEquipo.forEach(m=>{a['meta_'+m.k]=parseFloat(row.find(`.team-month-prog[data-mes="${m.k}"]`).val())||0;a['logro_'+m.k]=parseFloat(row.find(`.team-month-logro[data-mes="${m.k}"]`).val())||0;});
    if(currentTaskButton) $(currentTaskButton).attr('data-task',JSON.stringify(currentTaskData));
}


function applyGlobalTeamPlaces(autosave=true, target=null){
    const allPlaces = selectedGlobalTeamPlaces();
    const centerPlaces = allPlaces.filter(isCenterLugar);
    const mustRecalculate = centerPlaces.length > 0 || window.lastGlobalCenterPlaces.length > 0;

    updateCurrentTaskGlobalPlaces();

    $('#tabla_tecnicos_body .team-row').each(function(){
        const row = $(this);
        if(mustRecalculate){
            const result = calculateTeamProgramado(row, centerPlaces);
            row.find(`.team-month-prog[data-mes="${currentTeamMonth}"]`).val(result.total);
            const logro = parseFloat(row.find(`.team-month-logro[data-mes="${currentTeamMonth}"]`).val()) || 0;
            if(result.total > 0){
                row.find('.team-selected').prop('checked', true);
            } else if(centerPlaces.length > 0 && logro <= 0){
                row.find('.team-selected').prop('checked', false);
            }
        }
        row.toggleClass('row-selected', row.find('.team-selected').is(':checked'));
        updateCurrentTaskAssignmentFromRow(row);
    });

    window.lastGlobalCenterPlaces = centerPlaces.slice();
    recalcTeamRows();

    $(`#subgrid-1 tr.inv-row`).each(function(){
        let r = $(this);
        let b = String(r.data('base')||'').split('|').filter(x=>String(x||'').trim()!=='');
        let lc = r.find('.panel-lugar_sub-box input:checked').map(function(){return $(this).val();}).get().filter(isCenterLugar);
        let lr = lc.length ? lc : window.lastGlobalCenterPlaces;
        if (lr && lr.length) {
            let ce = centrosByBasesYLugares(b, lr);
            r.find('input[name^="inv_alograr"]').val(ce.length);
        }
        const prog = parseFloat(r.find('input[name^="inv_alograr"]').val()) || 0;
        const cum = parseFloat(r.find('input[name^="inv_cumplido"]').val()) || 0;
        const at = parseFloat(r.find('input[name^="inv_a_tiempo"]').val()) || 0;
        const ef = parseFloat(r.find('input[name^="inv_en_forma"]').val()) || 0;
        r.find('.pct-cell').html(badgePct(calcRowPct(prog,cum,at,ef)));
    });

    captureCurrentInvData(1);
    updateActivityProgress();

    if(autosave) autosaveTeamTable(target || $('#team-global-location-host .multiselect-select-box')[0]);
}

function buildTeamTable(task){
    let html='',asig=task.asignaciones||[];
    let globalPlaces=parseArrayValue(task.team_lugares);
    if(!globalPlaces.length){
        asig.forEach(a=>{globalPlaces=mergeUniqueArrays(globalPlaces,parseArrayValue(a.lugares_json));});
    }
    $('#team-global-location-host').html(teamGlobalLocationControl(globalPlaces));
    $('#team-global-location-host .multiselect-dropdown-panel').each(function(){updateMultiselectText(this);});
    lastGlobalCenterPlaces=globalPlaces.filter(isCenterLugar);

    tecnicosBases.forEach((tb,rowIndex)=>{
        const tecnico=tb.nombre,base=tb.nombre_base||'';
        const a=asig.find(x=>x.tecnico===tecnico&&(x.base_asignada||'')===base);
        if(hideNoBaseRowState&&!base&&!a) return;
        const sel=a?'checked':'',cls=a?'row-selected':'';
        const rowId=`team_${rowIndex}`;
        html+=`<tr class="team-row ${cls} ${base?'':'no-base-row'}" data-row-id="${rowId}"><td><input type="checkbox" class="team-selected" ${sel}></td><td><div style="display:flex;gap:10px;align-items:center"><div class="avatar">${initials(tecnico)}</div><strong>${escHtml(tecnico)}</strong></div></td><td>${base?`<span class="base-badge">${escHtml(base)}</span>`:'<span style="color:#94a3b8">Sin base</span>'}<input type="hidden" class="team-tecnico" value="${escHtml(tecnico)}"><input type="hidden" class="team-base" value="${escHtml(base)}"></td>`;
        mesesEquipo.forEach(m=>{html+=`<td class="team-month-col team-month-${m.k}"><input type="number" class="table-input team-month-input team-month-prog" data-mes="${m.k}" value="${a?parseFloat(a['meta_'+m.k]||0):0}"></td><td class="team-month-col team-month-${m.k}"><input type="number" class="table-input team-month-input team-month-logro" data-mes="${m.k}" value="${a?parseFloat(a['logro_'+m.k]||0):0}"></td><td class="team-month-col team-month-${m.k} team-dif">0</td><td class="team-month-col team-month-${m.k} team-pct">${badgePct(0)}</td>`;});
        html+=`<td class="team-total-meta">0</td><td class="team-total-logro">0</td><td class="team-total-dif">0</td><td class="team-total-pct">${badgePct(0)}</td></tr>`;
    });
    $('#tabla_tecnicos_body').html(html);
    applyTeamMonthVisibility();
    if(lastGlobalCenterPlaces.length) applyGlobalTeamPlaces(false);
    recalcTeamRows();
    $('.team-month-input,.team-selected').on('change input',function(){let r=$(this).closest('tr');r.toggleClass('row-selected',r.find('.team-selected').is(':checked'));updateCurrentTaskAssignmentFromRow(r);autosaveTeamTable(this);recalcTeamRows();});
}

function applyTeamMonthVisibility(){
    mesesEquipo.forEach(m=>{
        $(`.team-month-${m.k}`).toggleClass('hidden-team-month',!showAllTeamMonths&&m.k!==currentTeamMonth);
    });
    $('#team-assign-table').toggleClass('show-all-months',showAllTeamMonths);
    $('.no-base-row').toggleClass('d-none',hideNoBaseRowState);
}

function toggleTeamMonths(){
    showAllTeamMonths=!showAllTeamMonths;
    applyTeamMonthVisibility();
}

function toggleNoBaseTechs(){
    hideNoBaseRowState=!hideNoBaseRowState;
    if(!hideNoBaseRowState&&currentTaskData) buildTeamTable(currentTaskData);
    else applyTeamMonthVisibility();
}

function recalcTeamRows(){
    $('.team-row').each(function(){
        let tm=0,tl=0;
        $(this).find('.team-month-prog').each(function(){
            let mes=$(this).data('mes'),
                meta=parseFloat($(this).val())||0,
                logro=parseFloat($(this).closest('tr').find(`.team-month-logro[data-mes="${mes}"]`).val())||0,
                dif=meta-logro,
                p=meta>0?(logro/meta)*100:0;
            tm+=meta;
            tl+=logro;
            let cells=$(this).closest('tr').find(`.team-month-${mes}`);
            cells.eq(2).text(formatNum(dif));
            cells.eq(3).html(badgePct(p));
        });
        let p=tm>0?(tl/tm)*100:0;
        $(this).find('.team-total-meta').text(formatNum(tm));
        $(this).find('.team-total-logro').text(formatNum(tl));
        $(this).find('.team-total-dif').text(formatNum(tm-tl));
        $(this).find('.team-total-pct').html(badgePct(p));
    });
}

function autosaveTeamTable(editedEl = null, silent = false) {
    let rows = [];
    $('#tabla_tecnicos_body .team-row').each(function() {
        let row = $(this);
        let rowData = {
            tecnico: row.find('.team-tecnico').val(),
            base_asignada: row.find('.team-base').val(),
            selected: row.find('.team-selected').is(':checked') ? 1 : 0,
            metas: {},
            logros: {}
        };
        mesesEquipo.forEach(m => {
            rowData.metas[m.k] = parseFloat(row.find(`.team-month-prog[data-mes="${m.k}"]`).val()) || 0;
            rowData.logros[m.k] = parseFloat(row.find(`.team-month-logro[data-mes="${m.k}"]`).val()) || 0;
        });
        rows.push(rowData);
        updateCurrentTaskAssignmentFromRow(row);
    });

    let fd = new FormData();
    fd.append('action', 'autosave_team_assignment_bulk');
    fd.append('id_poa', $('#upd_task_id').val());
    fd.append('rows', JSON.stringify(rows));
    selectedGlobalTeamPlaces().forEach(l => fd.append('lugares[]', l));

    const target = editedEl || $('#team-global-location-host .multiselect-select-box')[0];
    if (!silent) flashSaving(target);

    return enqueueAutosave(async () => {
        const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const res = await r.json();
        if (!r.ok || res.status === 'error') throw new Error(res.msg || 'No se pudo guardar la asignación.');
        if (!silent) flashSaved(target, true);
        return res;
    }).catch(err => {
        if (!silent) flashSaved(target, false);
        console.error(err);
    });
}

function autosaveTeamRow(row, editedEl=null, silent=false) { return autosaveTeamTable(editedEl, silent); }
function autosaveGlobalTeamPlaces(target=null) { return autosaveTeamTable(target, false); }

let fullAutosaveTimer=null;
let pendingFullSaveTarget=null;

function snapshotCurrentTaskFromForm(){
    if(!currentTaskData) return;
    if(typeof tinymce!=='undefined'&&tinymce.get('upd_info_adicional')) tinymce.get('upd_info_adicional').save();

    currentTaskData.info_adicional=$('#upd_info_adicional').val()||'';
    currentTaskData.estado=$('#upd_estado').val()||'0%';
    currentTaskData.m_act_obj=parseFloat($('#upd_m_act_obj').val())||0;
    currentTaskData.m_act_alc=parseFloat($('#upd_m_act_alc').val())||0;
    currentTaskData.m_part_obj=parseFloat($('#upd_m_part_obj').val())||0;
    currentTaskData.m_part_alc=parseFloat($('#upd_m_part_alc').val())||0;
    currentTaskData.op_act=currentTaskData.op_act||{};
    currentTaskData.op_part=currentTaskData.op_part||{};

    mesesEquipo.forEach(m=>{currentTaskData.op_act[m.k]=parseFloat($(`input[name="op_act[${m.k}]"]`).val())||0;currentTaskData.op_part[m.k]=parseFloat($(`input[name="op_part[${m.k}]"]`).val())||0;});

    const etapas=[];
    $('#tabla_etapas_body tr.stage-main-row').each(function(i){
        captureCurrentInvData(i);
        const row=$(this);
        etapas.push({
            codigo_etapa:row.find('input[name="etapa_codigo[]"]').val()||etapasDefault[i]?.codigo||'',
            nombre_etapa:row.find('input[name="etapa_nombre[]"]').val()||etapasDefault[i]?.nombre||'',
            descripcion_etapa:row.find('input[name="etapa_descripcion[]"]').val()||etapasDefault[i]?.descripcion||'',
            unidad_medida:JSON.stringify(selectedFromPanel(`.panel-unidad-box[data-index="${i}"]`)),
            responsable:JSON.stringify(selectedFromPanel(`.panel-responsable-box[data-index="${i}"]`)),
            involucrados_json:JSON.stringify(window.savedInvData[i]||{}),
            fecha_recepcion:row.find('input[name="etapa_fecha_recepcion[]"]').val()||''
        });
    });

    if(modalEtapasBuilt) currentTaskData.etapas=etapas;
    if(currentTaskButton) $(currentTaskButton).attr('data-task',JSON.stringify(currentTaskData));

    updateCardVisuals();
}

function scheduleFullAutosave(target=null){
    if(target) pendingFullSaveTarget=target;
    clearTimeout(fullAutosaveTimer);
    fullAutosaveTimer=setTimeout(()=>autosaveFullForm(false),550);
}

function autosaveFullForm(force=false){
    const form=document.getElementById('formUpdate');
    if(!form||(!force&&!$('#updateModal').is(':visible'))) return Promise.resolve();
    snapshotCurrentTaskFromForm();
    const fd=new FormData(form);
    fd.set('action','update_task');
    fd.set('autosave_full','1');
    const active=pendingFullSaveTarget||document.activeElement;
    pendingFullSaveTarget=null;
    if(active&&$('#updateModal').is(':visible')) flashSaving(active);
    return enqueueAutosave(async()=>{
        const r=await fetch(window.location.pathname,{method:'POST',body:fd});
        const text=await r.text();
        let res;
        try{res=JSON.parse(text);}catch(e){throw new Error(text||'Respuesta inválida del servidor.');}
        if(!r.ok||res.status!=='ok')throw new Error(res.msg||'No se pudo autoguardar.');
        if(active&&$('#updateModal').is(':visible')) flashSaved(active,true);
        return res;
    }).catch(err=>{
        console.error('Autoguardado completo:',err);
        if(active&&$('#updateModal').is(':visible')) flashSaved(active,false);
        return false;
    });
}

$('#formUpdate').on('submit',function(e){
    e.preventDefault();
    autosaveFullForm(true);
});

$(document).on('input change','#formUpdate input,#formUpdate textarea,#formUpdate select',function(e){
    const field=$(this);

    if(field.closest('#tabla_tecnicos_body').length) return;
    if(field.closest('#tab-metas').length && !metasUnlocked) return;

    const currentPartInput=$(`input[name="op_part[${currentTeamMonth}]"]`);
    if(currentPartInput.length){
        $('#lbl_meta_mes_actual').text(formatNum(currentPartInput.val()||0));
    }

    if(field.is('.centro-score,.centro-cumplido')){
        return;
    }

    const row=field.closest('.inv-row');
    if(row.length){
        const index=Number(row.data('index'));
        const key=String(row.data('key')||'');

        if(index===2 && field.closest('.panel-lugar_sub-box').length){
            refreshStage3RowFromPlaces(row,true);
        }else if(index === 1 && field.closest('.panel-lugar_sub-box').length){
            refreshStage1RowFromPlaces(row);
        }else{
            const prog=parseFloat(row.find('input[name^="inv_alograr"]').val())||0;
            const cum=parseFloat(row.find('input[name^="inv_cumplido"]').val())||0;
            const at=parseFloat(row.find('input[name^="inv_a_tiempo"]').val())||0;
            const ef=parseFloat(row.find('input[name^="inv_en_forma"]').val())||0;

            row.find('.pct-cell').html(badgePct(calcRowPct(prog,cum,at,ef)));
            collectStageRowData(index,key);
            updateActivityProgress();

            if(index===2){
                scheduleCenterRowAutosave(index,key,this);
            }
        }
    }

    scheduleFullAutosave(this);
});

function openCatalogModal(type,index,label,key=''){
    $('#mini-modal-type').val(type);
    $('#mini-modal-target-index').val(index);
    $('#mini-modal-target-key').val(key);
    $('#mini-modal-title span').text(label);
    $('#mini-modal-input').val('');

    if (type === 'responsable' || type === 'lugar_sub') {
        $('#mini-modal-prog-etapa').hide();
    } else {
        $('#mini-modal-prog-etapa').show();
        let currentProg = currentTaskData ? normalizeProg((currentTaskData.programa || '') + ' ' + (currentTaskData.sector || '')) : 'GENERAL';
        $('#mini-modal-prog').val(currentProg);
        $('#mini-modal-stg').val(`E-${index+1}`);
    }

    $('#catalogMiniModal').css('display','flex');
}

function submitNewCatalogItem(){
    let type=$('#mini-modal-type').val(),
        index=$('#mini-modal-target-index').val(),
        key=$('#mini-modal-target-key').val(),
        val=$('#mini-modal-input').val().trim();

    if(!val)return;

    let list=type==='responsable'?masterResponsables:(type==='unidad'?masterUnidadesRaw:(type==='verific_sub'?masterVerificacionesRaw:masterLugares));

    let catalogTypePHP = type.replace('_sub','');
    if (catalogTypePHP === 'verific') catalogTypePHP = 'verificacion';

    let reqData = new URLSearchParams({
        action: 'add_to_catalog',
        catalog_type: catalogTypePHP,
        catalog_value: val
    });

    if(type==='responsable'||type==='lugar_sub'){
        if(!list.some(x=>normalizarTxt(x)===normalizarTxt(val))) list.push(val);
    }else{
        let p = $('#mini-modal-prog').val() || 'GENERAL';
        let e = $('#mini-modal-stg').val() || 'TODAS';
        reqData.append('programa', p);
        reqData.append('etapa', e);
        if(!list.some(x=>normalizarTxt(x.nombre)===normalizarTxt(val))) {
            list.push({nombre:val, programa:p, etapa:e});
        }
    }

    $('#catalogMiniModal').hide();

    let panelSelector = `.panel-${type}-box .multiselect-dropdown-panel`;

    $(panelSelector).each(function() {
        let panel = $(this);
        let exists = false;
        panel.find('input[type="checkbox"]').each(function() {
            if (normalizarTxt($(this).val()) === normalizarTxt(val)) exists = true;
        });

        if (!exists) {
            let nameAttr = panel.find('input[type="checkbox"]').first().attr('name') || '';
            if (!nameAttr) {
                let parentBox = panel.closest('.custom-multiselect');
                let pType = parentBox.data('type');
                let pIdx = parentBox.data('index');
                let pKey = parentBox.closest('.inv-row').data('key') || '';

                if (pType === 'unidad') nameAttr = `etapa_unidades[${pIdx}][]`;
                else if (pType === 'responsable') nameAttr = `etapa_resps[${pIdx}][]`;
                else if (pType === 'verific_sub') nameAttr = `inv_verifics[${pIdx}][${pKey}][]`;
                else if (pType === 'lugar_sub') nameAttr = `inv_lugar[${pIdx}][${pKey}][]`;
            }

            let newLabel = `<label class="multiselect-option" onclick="event.stopPropagation()"><input type="checkbox" name="${nameAttr}" value="${escHtml(val)}"> ${escHtml(val)}</label>`;
            panel.find('.multiselect-add-new-btn').before(newLabel);
        }
    });

    let originPanel = null;
    if (key) {
        originPanel = $(`tr.inv-row[data-key="${key}"] .panel-${type}-box .multiselect-dropdown-panel`);
    } else {
        originPanel = $(`.panel-${type}-box[data-index="${index}"]`).first().find('.multiselect-dropdown-panel');
    }

    if (originPanel && originPanel.length) {
        let chk = originPanel.find('input[type="checkbox"]').filter(function(){ return this.value === String(val); });
        if (chk.length) {
            chk.prop('checked', true);
            updateMultiselectText(originPanel[0]);

            if (type === 'responsable' || type === 'unidad') {
                triggerAgendaRebuild(index);
            } else if (type === 'lugar_sub') {
                let row = originPanel.closest('.inv-row');
                const rowIdx = Number(row.data('index'));
                if(rowIdx === 2) refreshStage3RowFromPlaces(row, true);
                else if(rowIdx === 1) refreshStage1RowFromPlaces(row);
                else captureCurrentInvData(index);
            } else {
                captureCurrentInvData(index);
            }
        }
    }

    scheduleFullAutosave();
    fetch(window.location.pathname,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:reqData});
}

window.addEventListener('keydown',e=>{if(e.key==='Escape')closeModal('updateModal')});
</script>
</body>
</html>
