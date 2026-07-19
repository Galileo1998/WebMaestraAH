<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth = new Auth($db);
$auth->requireLogin();

$current_script = basename($_SERVER['PHP_SELF']);
$is_admin = isset($_SESSION['rol']) && $_SESSION['rol'] === 'Administrador';
$user_perms = $_SESSION['permisos'] ?? [];
if (!function_exists('canView')) {
    function canView($script, $admin, $perms) { return true; }
}

$msg = '';

/* ========================================================
   UTILIDADES
   ======================================================== */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function tableExists(PDO $db, string $table): bool {
    // Validación estricta del identificador para poder consultarlo directamente.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;

    // La prueba más confiable: intentar leer directamente la tabla con el
    // mismo usuario y la misma conexión que utilizará la importación.
    try {
        $db->query("SELECT 1 FROM `{$table}` LIMIT 0");
        return true;
    } catch (Throwable $directError) {
        // Continúa con verificaciones de catálogo por compatibilidad.
    }

    try {
        $st = $db->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $st->execute([$table]);
        if ((int)$st->fetchColumn() > 0) return true;
    } catch (Throwable $catalogError) {
        // Algunos hostings restringen information_schema.
    }

    try {
        // No se usa LIKE porque los guiones bajos del nombre serían comodines.
        $quoted = $db->quote($table);
        $st = $db->query("SHOW TABLES WHERE Tables_in_" . $db->query('SELECT DATABASE()')->fetchColumn() . " = {$quoted}");
        return $st && (bool)$st->fetchColumn();
    } catch (Throwable $showError) {
        return false;
    }
}

function columnExists(PDO $db, string $table, string $column): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function addColIfNotExists(PDO $db, string $table, string $column, string $definition): bool {
    if (columnExists($db, $table, $column)) return true;
    try {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function norm_key($value): string {
    $value = mb_strtolower(trim((string)$value), 'UTF-8');
    $value = str_replace(
        ['á','é','í','ó','ú','ñ','ü'],
        ['a','e','i','o','u','n','u'],
        $value
    );
    $value = preg_replace('/[^a-z0-9]+/u', '_', $value);
    return trim((string)$value, '_');
}

function val_any(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
        $normalized = norm_key($key);
        if (array_key_exists($normalized, $row) && trim((string)$row[$normalized]) !== '') {
            return $row[$normalized];
        }
    }
    return $default;
}

function int_clean($value): int {
    if ($value === null || trim((string)$value) === '') return 0;
    $clean = str_replace([',', 'L', 'l', ' '], '', (string)$value);
    return max(0, (int)round((float)$clean));
}

function normalize_tipo($raw): string {
    $value = mb_strtolower(trim((string)$raw), 'UTF-8');
    $value = str_replace(['á','é','í','ó','ú'], ['a','e','i','o','u'], $value);

    if (strpos($value, 'preescolar') !== false || strpos($value, 'kinder') !== false || strpos($value, 'jardin') !== false) {
        return 'Preescolar';
    }
    if (strpos($value, 'basica') !== false || strpos($value, 'media') !== false || strpos($value, 'educativo') !== false || strpos($value, 'escuela') !== false || strpos($value, 'instituto') !== false) {
        return 'Básica';
    }
    if (strpos($value, 'adn') !== false) return 'ADN';
    if (strpos($value, 'uaps') !== false || strpos($value, 'cis') !== false || strpos($value, 'salud') !== false) return 'UAPS/CIS';

    return trim((string)$raw);
}

function validPeriod(string $period): bool {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) return false;
    [$year, $month] = array_map('intval', explode('-', $period));
    return $year >= 2020 && $year <= 2100 && $month >= 1 && $month <= 12;
}

function monthLabelEs(string $period): string {
    $months = [
        1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril',
        5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto',
        9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'
    ];
    if (!validPeriod($period)) return $period;
    [$year, $month] = array_map('intval', explode('-', $period));
    return ($months[$month] ?? '') . ' ' . $year;
}

function normalizeRowKeys(array $row): array {
    $normalized = [];
    foreach ($row as $key => $value) {
        $normalized[norm_key($key)] = $value;
    }
    return $normalized;
}

function demographicFromRow(array $row): array {
    $fields = [
        'pob_0_5_9_f'   => ['pob_0_5_9_f', 'pob_0_5.9_f', '0_5_9_f', '0_5.9_f'],
        'pob_0_5_9_m'   => ['pob_0_5_9_m', 'pob_0_5.9_m', '0_5_9_m', '0_5.9_m'],
        'pob_6_14_9_f'  => ['pob_6_14_9_f', 'pob_6_14.9_f', '6_14_9_f', '6_14.9_f'],
        'pob_6_14_9_m'  => ['pob_6_14_9_m', 'pob_6_14.9_m', '6_14_9_m', '6_14.9_m'],
        'pob_15_17_9_f' => ['pob_15_17_9_f', 'pob_15_17.9_f', '15_17_9_f', '15_17.9_f'],
        'pob_15_17_9_m' => ['pob_15_17_9_m', 'pob_15_17.9_m', '15_17_9_m', '15_17.9_m'],
        'pob_18_24_f'   => ['pob_18_24_f', '18_24_f'],
        'pob_18_24_m'   => ['pob_18_24_m', '18_24_m'],
    ];

    $data = [];
    foreach ($fields as $field => $aliases) {
        $data[$field] = int_clean(val_any($row, $aliases, 0));
    }

    $data['pob_fem'] = $data['pob_0_5_9_f'] + $data['pob_6_14_9_f'] + $data['pob_15_17_9_f'] + $data['pob_18_24_f'];
    $data['pob_masc'] = $data['pob_0_5_9_m'] + $data['pob_6_14_9_m'] + $data['pob_15_17_9_m'] + $data['pob_18_24_m'];
    $data['pob_total'] = $data['pob_fem'] + $data['pob_masc'];
    $data['pob_0_5'] = $data['pob_0_5_9_f'] + $data['pob_0_5_9_m'];
    $data['pob_6_17'] = $data['pob_6_14_9_f'] + $data['pob_6_14_9_m'] + $data['pob_15_17_9_f'] + $data['pob_15_17_9_m'];
    $data['pob_18_24'] = $data['pob_18_24_f'] + $data['pob_18_24_m'];
    return $data;
}

function findCentro(PDO $db, string $tipo, string $nombre, string $base, string $caserio): ?int {
    $st = $db->prepare(
        "SELECT id FROM ah_centros
         WHERE tipo=?
           AND LOWER(TRIM(nombre))=LOWER(TRIM(?))
           AND LOWER(TRIM(COALESCE(comunidad_base,'')))=LOWER(TRIM(?))
           AND LOWER(TRIM(COALESCE(caserio,'')))=LOWER(TRIM(?))
         ORDER BY id ASC LIMIT 1"
    );
    $st->execute([$tipo, $nombre, $base, $caserio]);
    $id = $st->fetchColumn();
    return $id ? (int)$id : null;
}

function createCentro(PDO $db, string $tipo, string $nombre, string $municipio, string $base, string $caserio): int {
    // El municipio no se almacena en ah_centros. Se obtiene siempre desde
    // ah_bases_geograficas mediante comunidad_base = nombre_base.
    $st = $db->prepare(
        'INSERT INTO ah_centros (tipo,nombre,comunidad_base,caserio,pob_total,pob_fem,pob_masc,pob_0_5,pob_6_17,pob_18_24)
         VALUES (?,?,?,?,0,0,0,0,0,0)'
    );
    $st->execute([$tipo, $nombre, $base, $caserio]);
    return (int)$db->lastInsertId();
}

function updateCentroLocation(PDO $db, int $id, string $tipo, string $nombre, string $municipio, string $base, string $caserio): void {
    // El parámetro municipio se conserva por compatibilidad con las llamadas,
    // pero la fuente oficial es ah_bases_geograficas.
    $st = $db->prepare('UPDATE ah_centros SET tipo=?,nombre=?,comunidad_base=?,caserio=? WHERE id=?');
    $st->execute([$tipo, $nombre, $base, $caserio, $id]);
}

function syncLegacyPopulation(PDO $db, int $centroId, array $population): void {
    $st = $db->prepare(
        'UPDATE ah_centros
         SET pob_total=?,pob_fem=?,pob_masc=?,pob_0_5=?,pob_6_17=?,pob_18_24=?
         WHERE id=?'
    );
    $st->execute([
        (int)$population['pob_total'],
        (int)$population['pob_fem'],
        (int)$population['pob_masc'],
        (int)$population['pob_0_5'],
        (int)$population['pob_6_17'],
        (int)$population['pob_18_24'],
        $centroId,
    ]);
}

function saveMonthlyPopulation(PDO $db, int $centroId, string $period, array $population): void {
    $st = $db->prepare(
        'INSERT INTO ah_centros_poblacion_mensual
        (centro_id,periodo,pob_0_5_9_f,pob_0_5_9_m,pob_6_14_9_f,pob_6_14_9_m,pob_15_17_9_f,pob_15_17_9_m,pob_18_24_f,pob_18_24_m,pob_fem,pob_masc,pob_total)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            pob_0_5_9_f=VALUES(pob_0_5_9_f),
            pob_0_5_9_m=VALUES(pob_0_5_9_m),
            pob_6_14_9_f=VALUES(pob_6_14_9_f),
            pob_6_14_9_m=VALUES(pob_6_14_9_m),
            pob_15_17_9_f=VALUES(pob_15_17_9_f),
            pob_15_17_9_m=VALUES(pob_15_17_9_m),
            pob_18_24_f=VALUES(pob_18_24_f),
            pob_18_24_m=VALUES(pob_18_24_m),
            pob_fem=VALUES(pob_fem),
            pob_masc=VALUES(pob_masc),
            pob_total=VALUES(pob_total),
            updated_at=CURRENT_TIMESTAMP'
    );
    $st->execute([
        $centroId,
        $period,
        $population['pob_0_5_9_f'],
        $population['pob_0_5_9_m'],
        $population['pob_6_14_9_f'],
        $population['pob_6_14_9_m'],
        $population['pob_15_17_9_f'],
        $population['pob_15_17_9_m'],
        $population['pob_18_24_f'],
        $population['pob_18_24_m'],
        $population['pob_fem'],
        $population['pob_masc'],
        $population['pob_total'],
    ]);
}

function getMonthlyPopulation(PDO $db, int $centroId, string $period): array {
    $empty = [
        'pob_0_5_9_f'=>0, 'pob_0_5_9_m'=>0,
        'pob_6_14_9_f'=>0, 'pob_6_14_9_m'=>0,
        'pob_15_17_9_f'=>0, 'pob_15_17_9_m'=>0,
        'pob_18_24_f'=>0, 'pob_18_24_m'=>0,
        'pob_fem'=>0, 'pob_masc'=>0, 'pob_total'=>0,
    ];
    $st = $db->prepare('SELECT * FROM ah_centros_poblacion_mensual WHERE centro_id=? AND periodo=? LIMIT 1');
    $st->execute([$centroId, $period]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? array_merge($empty, $row) : $empty;
}

/* ========================================================
   ESTRUCTURA DE DATOS
   ======================================================== */
$monthlySchemaReady = false;
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_centros (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo VARCHAR(50) NOT NULL,
        nombre VARCHAR(200) NOT NULL,
        comunidad_base VARCHAR(150) NULL,
        caserio VARCHAR(150) NULL,
        pob_total INT DEFAULT 0,
        pob_fem INT DEFAULT 0,
        pob_masc INT DEFAULT 0,
        pob_0_5 INT DEFAULT 0,
        pob_6_17 INT DEFAULT 0,
        pob_18_24 INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_centros_base (comunidad_base),
        INDEX idx_centros_tipo (tipo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    addColIfNotExists($db, 'ah_centros', 'caserio', 'VARCHAR(150) NULL AFTER comunidad_base');
    addColIfNotExists($db, 'ah_centros', 'pob_fem', 'INT DEFAULT 0');
    addColIfNotExists($db, 'ah_centros', 'pob_masc', 'INT DEFAULT 0');
    addColIfNotExists($db, 'ah_centros', 'pob_0_5', 'INT DEFAULT 0');
    addColIfNotExists($db, 'ah_centros', 'pob_6_17', 'INT DEFAULT 0');
    addColIfNotExists($db, 'ah_centros', 'pob_18_24', 'INT DEFAULT 0');

    $db->exec("CREATE TABLE IF NOT EXISTS ah_centros_poblacion_mensual (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        centro_id INT NOT NULL,
        periodo CHAR(7) NOT NULL,
        pob_0_5_9_f INT NOT NULL DEFAULT 0,
        pob_0_5_9_m INT NOT NULL DEFAULT 0,
        pob_6_14_9_f INT NOT NULL DEFAULT 0,
        pob_6_14_9_m INT NOT NULL DEFAULT 0,
        pob_15_17_9_f INT NOT NULL DEFAULT 0,
        pob_15_17_9_m INT NOT NULL DEFAULT 0,
        pob_18_24_f INT NOT NULL DEFAULT 0,
        pob_18_24_m INT NOT NULL DEFAULT 0,
        pob_fem INT NOT NULL DEFAULT 0,
        pob_masc INT NOT NULL DEFAULT 0,
        pob_total INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_centro_periodo (centro_id, periodo),
        INDEX idx_poblacion_periodo (periodo),
        INDEX idx_poblacion_centro (centro_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $monthlySchemaReady = tableExists($db, 'ah_centros_poblacion_mensual');
    if (!$monthlySchemaReady) {
        throw new RuntimeException('La conexión actual no puede leer ah_centros_poblacion_mensual en la base ' . (string)$db->query('SELECT DATABASE()')->fetchColumn() . '.');
    }
} catch (Throwable $e) {
    $msg = '<div class="alert error"><strong>No se pudo preparar la estructura demográfica mensual.</strong> ' . h($e->getMessage()) . '</div>';
}

$currentPeriod = isset($_GET['periodo']) && validPeriod((string)$_GET['periodo'])
    ? (string)$_GET['periodo']
    : date('Y-m');

/* ========================================================
   ENDPOINTS AJAX
   ======================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];

    if ($action === 'update_centro') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            $field = (string)($_POST['campo'] ?? '');
            $value = $_POST['valor'] ?? '';
            $allowed = ['nombre','comunidad_base','caserio','tipo','pob_total','pob_fem','pob_masc'];
            if ($id <= 0 || !in_array($field, $allowed, true)) {
                throw new RuntimeException('Campo no permitido o identificador inválido.');
            }

            if (in_array($field, ['pob_total','pob_fem','pob_masc'], true)) {
                $value = int_clean($value);
            } elseif ($field === 'tipo') {
                $value = normalize_tipo($value);
            } else {
                $value = trim((string)$value);
            }

            $st = $db->prepare("UPDATE ah_centros SET `{$field}`=? WHERE id=?");
            $st->execute([$value, $id]);

            $total = null;
            if (in_array($field, ['pob_fem','pob_masc'], true)) {
                $st = $db->prepare('SELECT pob_fem,pob_masc FROM ah_centros WHERE id=?');
                $st->execute([$id]);
                $row = $st->fetch(PDO::FETCH_ASSOC) ?: ['pob_fem'=>0,'pob_masc'=>0];
                $total = (int)$row['pob_fem'] + (int)$row['pob_masc'];
                $db->prepare('UPDATE ah_centros SET pob_total=? WHERE id=?')->execute([$total, $id]);
            }

            jsonOut(['status'=>'ok','msg'=>'Guardado','valor'=>$value,'pob_total'=>$total]);
        } catch (Throwable $e) {
            jsonOut(['status'=>'error','msg'=>$e->getMessage()], 400);
        }
    }

    if ($action === 'update_population_monthly') {
        try {
            if (!$monthlySchemaReady) throw new RuntimeException('La tabla de población mensual no está disponible.');
            $id = (int)($_POST['id'] ?? 0);
            $period = (string)($_POST['periodo'] ?? '');
            $field = (string)($_POST['campo'] ?? '');
            $allowed = [
                'pob_0_5_9_f','pob_0_5_9_m','pob_6_14_9_f','pob_6_14_9_m',
                'pob_15_17_9_f','pob_15_17_9_m','pob_18_24_f','pob_18_24_m'
            ];
            if ($id <= 0 || !validPeriod($period) || !in_array($field, $allowed, true)) {
                throw new RuntimeException('Datos de actualización mensual inválidos.');
            }
            $value = int_clean($_POST['valor'] ?? 0);

            $db->beginTransaction();
            $db->prepare('INSERT IGNORE INTO ah_centros_poblacion_mensual (centro_id,periodo) VALUES (?,?)')->execute([$id,$period]);
            $db->prepare("UPDATE ah_centros_poblacion_mensual SET `{$field}`=? WHERE centro_id=? AND periodo=?")->execute([$value,$id,$period]);
            $population = getMonthlyPopulation($db, $id, $period);
            $population['pob_fem'] = (int)$population['pob_0_5_9_f'] + (int)$population['pob_6_14_9_f'] + (int)$population['pob_15_17_9_f'] + (int)$population['pob_18_24_f'];
            $population['pob_masc'] = (int)$population['pob_0_5_9_m'] + (int)$population['pob_6_14_9_m'] + (int)$population['pob_15_17_9_m'] + (int)$population['pob_18_24_m'];
            $population['pob_total'] = $population['pob_fem'] + $population['pob_masc'];
            $population['pob_0_5'] = (int)$population['pob_0_5_9_f'] + (int)$population['pob_0_5_9_m'];
            $population['pob_6_17'] = (int)$population['pob_6_14_9_f'] + (int)$population['pob_6_14_9_m'] + (int)$population['pob_15_17_9_f'] + (int)$population['pob_15_17_9_m'];
            $population['pob_18_24'] = (int)$population['pob_18_24_f'] + (int)$population['pob_18_24_m'];

            $db->prepare('UPDATE ah_centros_poblacion_mensual SET pob_fem=?,pob_masc=?,pob_total=? WHERE centro_id=? AND periodo=?')
               ->execute([$population['pob_fem'],$population['pob_masc'],$population['pob_total'],$id,$period]);
            syncLegacyPopulation($db, $id, $population);
            $db->commit();

            jsonOut(['status'=>'ok','msg'=>'Guardado','valor'=>$value,'population'=>$population]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonOut(['status'=>'error','msg'=>$e->getMessage()], 400);
        }
    }

    if ($action === 'delete_centro') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) throw new RuntimeException('Identificador inválido.');
            $db->beginTransaction();
            if ($monthlySchemaReady) {
                $db->prepare('DELETE FROM ah_centros_poblacion_mensual WHERE centro_id=?')->execute([$id]);
            }
            $db->prepare('DELETE FROM ah_centros WHERE id=?')->execute([$id]);
            $db->commit();
            jsonOut(['status'=>'ok','msg'=>'Centro eliminado']);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonOut(['status'=>'error','msg'=>$e->getMessage()], 400);
        }
    }

    if ($action === 'importar_centros' || $action === 'importar_poblacion_mensual') {
        try {
            $rows = json_decode((string)($_POST['data'] ?? ''), true);
            if (!is_array($rows) || !$rows) throw new RuntimeException('No se detectaron filas en el archivo Excel.');

            $isMonthly = $action === 'importar_poblacion_mensual';
            $targetType = $isMonthly ? normalize_tipo((string)($_POST['tipo_objetivo'] ?? '')) : '';
            $period = $isMonthly ? (string)($_POST['periodo'] ?? '') : $currentPeriod;

            if ($isMonthly) {
                if (!$monthlySchemaReady) throw new RuntimeException('La estructura de población mensual no está disponible.');
                if (!in_array($targetType, ['ADN','UAPS/CIS'], true)) throw new RuntimeException('Seleccione Centros ADN o UAPS/CIS.');
                if (!validPeriod($period)) throw new RuntimeException('El mes de actualización es inválido.');
            }

            $db->beginTransaction();
            $inserted = 0;
            $updated = 0;
            $ignored = 0;
            $withPopulation = 0;

            foreach ($rows as $rawRow) {
                if (!is_array($rawRow)) { $ignored++; continue; }
                $row = normalizeRowKeys($rawRow);
                $typeFromFile = normalize_tipo((string)val_any($row, ['Tipo','tipo','nivel','categoria']));
                $type = $isMonthly ? $targetType : $typeFromFile;

                if ($isMonthly && $typeFromFile !== '' && $typeFromFile !== $targetType) {
                    $ignored++;
                    continue;
                }

                $name = trim((string)val_any($row, ['Nombre_Centro_Educativo','nombre del centro','centro','nombre']));
                $municipality = trim((string)val_any($row, ['Municipio','municipio']));
                $base = trim((string)val_any($row, ['Comunidad_Base','comunidad base','base','comunidad']));
                $caserio = trim((string)val_any($row, ['Caserio','Caserío','caserio']));

                if ($type === '' || $name === '') { $ignored++; continue; }

                $id = findCentro($db, $type, $name, $base, $caserio);
                if ($id === null) {
                    $id = createCentro($db, $type, $name, $municipality, $base, $caserio);
                    $inserted++;
                } else {
                    updateCentroLocation($db, $id, $type, $name, $municipality, $base, $caserio);
                    $updated++;
                }

                if (in_array($type, ['ADN','UAPS/CIS'], true)) {
                    $population = demographicFromRow($row);
                    $hasDetail = array_sum([
                        $population['pob_0_5_9_f'],$population['pob_0_5_9_m'],
                        $population['pob_6_14_9_f'],$population['pob_6_14_9_m'],
                        $population['pob_15_17_9_f'],$population['pob_15_17_9_m'],
                        $population['pob_18_24_f'],$population['pob_18_24_m']
                    ]) > 0 || $isMonthly;

                    if ($hasDetail && $monthlySchemaReady) {
                        saveMonthlyPopulation($db, $id, $period, $population);
                        syncLegacyPopulation($db, $id, $population);
                        $withPopulation++;
                    }
                } else {
                    $female = int_clean(val_any($row, ['Matricula_F','Matrícula_F','matricula_fem','femenino','mujeres','f']));
                    $male = int_clean(val_any($row, ['Matricula_M','Matrícula_M','matricula_masc','masculino','hombres','m']));
                    $total = int_clean(val_any($row, ['Matricula','Matrícula','matricula_total','pob_total','total']));
                    if ($total === 0 && ($female + $male) > 0) $total = $female + $male;
                    $st = $db->prepare('UPDATE ah_centros SET pob_total=?,pob_fem=?,pob_masc=? WHERE id=?');
                    $st->execute([$total,$female,$male,$id]);
                }
            }

            $db->commit();
            $message = $isMonthly
                ? "Actualización mensual completada: {$inserted} centros nuevos, {$updated} actualizados, {$withPopulation} poblaciones cargadas y {$ignored} filas omitidas."
                : "Importación completada: {$inserted} centros nuevos, {$updated} actualizados y {$ignored} filas omitidas.";
            jsonOut(['status'=>'ok','msg'=>$message,'periodo'=>$period,'tipo'=>$targetType]);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            jsonOut(['status'=>'error','msg'=>$e->getMessage()], 400);
        }
    }

    jsonOut(['status'=>'error','msg'=>'Acción no reconocida.'], 400);
}

/* ========================================================
   DATOS PARA LA VISTA
   ======================================================== */
$types = [
    'Básica' => 'C. Básica / Media',
    'Preescolar' => 'Preescolares',
    'ADN' => 'Centros ADN',
    'UAPS/CIS' => 'UAPS / CIS',
];
$icons = [
    'Básica' => 'fa-graduation-cap',
    'Preescolar' => 'fa-child',
    'ADN' => 'fa-hands-holding-child',
    'UAPS/CIS' => 'fa-house-medical',
];

$activeTab = isset($_GET['tab']) && isset($types[(string)$_GET['tab']]) ? (string)$_GET['tab'] : 'Básica';
$centers = [];
$byType = array_fill_keys(array_keys($types), []);
$centerCount = array_fill_keys(array_keys($types), 0);
$populationTotals = array_fill_keys(array_keys($types), 0);

$hasMunicipioColumn = columnExists($db, 'ah_centros', 'municipio');
try {
    $municipalityExpr = $hasMunicipioColumn
        ? "COALESCE(NULLIF(c.municipio,''), NULLIF(b.municipio,''), 'Sin asignar')"
        : "COALESCE(NULLIF(b.municipio,''), 'Sin asignar')";

    $monthlyJoin = $monthlySchemaReady
        ? "LEFT JOIN ah_centros_poblacion_mensual pm ON pm.centro_id=c.id AND pm.periodo=" . $db->quote($currentPeriod)
        : '';

    $monthlySelect = $monthlySchemaReady
        ? ", pm.periodo AS poblacion_periodo,
             COALESCE(pm.pob_0_5_9_f,0) AS pob_0_5_9_f,
             COALESCE(pm.pob_0_5_9_m,0) AS pob_0_5_9_m,
             COALESCE(pm.pob_6_14_9_f,0) AS pob_6_14_9_f,
             COALESCE(pm.pob_6_14_9_m,0) AS pob_6_14_9_m,
             COALESCE(pm.pob_15_17_9_f,0) AS pob_15_17_9_f,
             COALESCE(pm.pob_15_17_9_m,0) AS pob_15_17_9_m,
             COALESCE(pm.pob_18_24_f,0) AS pob_18_24_f,
             COALESCE(pm.pob_18_24_m,0) AS pob_18_24_m,
             COALESCE(pm.pob_fem,0) AS mensual_fem,
             COALESCE(pm.pob_masc,0) AS mensual_masc,
             COALESCE(pm.pob_total,0) AS mensual_total"
        : ", NULL AS poblacion_periodo,
             0 AS pob_0_5_9_f,0 AS pob_0_5_9_m,0 AS pob_6_14_9_f,0 AS pob_6_14_9_m,
             0 AS pob_15_17_9_f,0 AS pob_15_17_9_m,0 AS pob_18_24_f,0 AS pob_18_24_m,
             0 AS mensual_fem,0 AS mensual_masc,0 AS mensual_total";

    $sql = "SELECT c.*, {$municipalityExpr} AS municipio_vista,
                   COALESCE(t.nombre,'Sin técnico') AS tecnico
                   {$monthlySelect}
            FROM ah_centros c
            LEFT JOIN ah_bases_geograficas b ON LOWER(TRIM(c.comunidad_base))=LOWER(TRIM(b.nombre_base))
            LEFT JOIN ah_tecnicos t ON b.identidad_tecnico=t.identidad
            {$monthlyJoin}
            ORDER BY c.tipo,c.nombre";
    $centers = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $centers = [];
    if ($msg === '') $msg = '<div class="alert error">No fue posible cargar los centros: ' . h($e->getMessage()) . '</div>';
}

foreach ($centers as $center) {
    $type = (string)$center['tipo'];
    if (!isset($byType[$type])) $byType[$type] = [];

    if (in_array($type, ['ADN','UAPS/CIS'], true)) {
        $center['display_total'] = (int)$center['mensual_total'];
        $center['display_fem'] = (int)$center['mensual_fem'];
        $center['display_masc'] = (int)$center['mensual_masc'];
    } else {
        $center['display_total'] = (int)$center['pob_total'];
        $center['display_fem'] = (int)$center['pob_fem'];
        $center['display_masc'] = (int)$center['pob_masc'];
    }

    $byType[$type][] = $center;
    if (isset($centerCount[$type])) $centerCount[$type]++;
    if (isset($populationTotals[$type])) $populationTotals[$type] += (int)$center['display_total'];
}

$communities = [];
$municipalities = [];
$technicians = [];
foreach ($centers as $center) {
    $community = trim((string)($center['comunidad_base'] ?? ''));
    $municipality = trim((string)($center['municipio_vista'] ?? ''));
    $technician = trim((string)($center['tecnico'] ?? ''));
    if ($community !== '') $communities[$community] = $community;
    if ($municipality !== '') $municipalities[$municipality] = $municipality;
    if ($technician !== '') $technicians[$technician] = $technician;
}
natcasesort($communities);
natcasesort($municipalities);
natcasesort($technicians);

function typeSlug(string $type): string {
    return preg_replace('/[^a-z0-9]+/', '-', norm_key($type));
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Catálogo Demográfico de Centros | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --ah-primary:#34859b;
            --ah-secondary:#46b094;
            --ah-soft:#eaf7fb;
            --canvas:#f6f8fb;
            --border:#dbe4ee;
            --ink:#172033;
            --muted:#66758c;
            --success:#15803d;
            --danger:#b91c1c;
        }
        * { box-sizing:border-box; }
        body { margin:0; min-height:100vh; display:flex; background:var(--canvas); color:var(--ink); font-family:'Inter',sans-serif; }
        .main-wrapper { flex:1; min-width:0; padding:32px; overflow:auto; }
        .page-head { display:flex; justify-content:space-between; align-items:flex-start; gap:18px; margin-bottom:20px; }
        .page-head h1 { margin:0 0 6px; font-size:2rem; font-weight:900; letter-spacing:-.035em; }
        .page-head p { margin:0; color:var(--muted); font-weight:500; }
        .period-badge { background:white; border:1px solid var(--border); border-left:5px solid var(--ah-primary); border-radius:14px; padding:11px 15px; font-weight:900; white-space:nowrap; }
        .alert { padding:14px 16px; border-radius:13px; margin-bottom:18px; font-weight:700; }
        .alert.error { background:#fee2e2; border:1px solid #fecaca; color:#991b1b; }
        .toast { position:fixed; right:24px; bottom:24px; display:none; z-index:9999; padding:12px 17px; border-radius:12px; background:#166534; color:white; font-weight:900; box-shadow:0 15px 35px rgba(15,23,42,.2); }

        .metrics-grid { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:16px; margin-bottom:18px; }
        .metric-card { border:1px solid var(--border); background:white; border-radius:17px; padding:17px; display:flex; justify-content:space-between; gap:12px; align-items:center; cursor:pointer; transition:.2s; box-shadow:0 4px 14px rgba(15,23,42,.035); }
        .metric-card:hover { transform:translateY(-2px); box-shadow:0 12px 25px rgba(15,23,42,.09); border-color:#9cc9d6; }
        .metric-card.active { border:2px solid var(--ah-primary); background:#f0fbff; }
        .metric-card h4 { margin:0 0 5px; color:var(--muted); text-transform:uppercase; letter-spacing:.04em; font-size:.76rem; }
        .metric-card .count { font-size:1.55rem; font-weight:900; }
        .metric-card small { display:block; margin-top:4px; color:#617087; font-weight:700; }
        .metric-icon { width:48px; height:48px; flex:0 0 auto; border-radius:14px; display:grid; place-items:center; background:#e5f5fb; color:#08789a; font-size:1.3rem; }

        .import-grid { display:grid; grid-template-columns:1.05fr 1.65fr; gap:16px; margin-bottom:18px; }
        .panel { background:white; border:1px solid var(--border); border-radius:17px; padding:18px; box-shadow:0 4px 14px rgba(15,23,42,.03); }
        .panel-title { display:flex; gap:10px; align-items:center; margin:0 0 12px; font-size:1rem; font-weight:900; color:#123f53; }
        .upload-zone { position:relative; min-height:126px; border:2px dashed #a7b7c9; border-radius:15px; display:flex; flex-direction:column; justify-content:center; align-items:center; text-align:center; padding:18px; transition:.2s; cursor:pointer; }
        .upload-zone:hover { border-color:var(--ah-primary); background:#f3fbfe; }
        .upload-zone input[type=file] { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .upload-zone i { font-size:2rem; color:#55a6ba; margin-bottom:8px; }
        .upload-zone strong { font-size:.96rem; }
        .upload-zone span { margin-top:5px; color:var(--muted); font-size:.78rem; line-height:1.45; }
        .monthly-toolbar { display:grid; grid-template-columns:190px 1fr 1fr; gap:12px; align-items:end; }
        .field label { display:block; margin-bottom:6px; font-size:.75rem; font-weight:900; color:#506079; text-transform:uppercase; }
        .form-control { width:100%; border:1px solid #cdd8e4; border-radius:11px; padding:10px 12px; font:inherit; background:white; outline:none; }
        .form-control:focus { border-color:var(--ah-primary); box-shadow:0 0 0 3px rgba(52,133,155,.12); }
        .btn-upload { position:relative; overflow:hidden; display:flex; align-items:center; justify-content:center; gap:8px; min-height:42px; border:1px solid #b9dce5; border-radius:11px; background:#edf9fc; color:#075c74; font-weight:900; cursor:pointer; padding:10px 13px; }
        .btn-upload:hover { background:#dff3f8; border-color:#6eb5c7; }
        .btn-upload input { position:absolute; inset:0; opacity:0; cursor:pointer; }
        .monthly-note { margin:12px 0 0; padding:10px 12px; border-radius:10px; background:#f8fafc; color:#66758c; font-size:.78rem; line-height:1.55; }

        .tabs-bar { display:flex; flex-wrap:wrap; gap:9px; background:white; border:1px solid var(--border); border-radius:15px; padding:9px; margin-bottom:14px; }
        .tab-btn { border:0; border-radius:11px; padding:10px 15px; background:#f6f8fb; color:#45546b; font:inherit; font-weight:900; cursor:pointer; display:flex; align-items:center; gap:8px; }
        .tab-btn:hover { background:#e7f5f9; color:#075c74; }
        .tab-btn.active { background:var(--ah-primary); color:white; }

        .filter-panel { display:grid; grid-template-columns:2fr repeat(4,minmax(150px,1fr)) auto; gap:10px; align-items:center; background:white; border:1px solid var(--border); border-radius:15px; padding:13px; margin-bottom:14px; }
        .btn-clear { border:1px solid #cdd8e4; background:white; border-radius:10px; padding:10px 13px; font-weight:900; color:#425168; cursor:pointer; white-space:nowrap; }
        .btn-clear:hover { border-color:var(--ah-primary); color:var(--ah-primary); }

        .tab-content { display:none; }
        .tab-content.active { display:block; }
        .table-card { background:white; border:1px solid var(--border); border-radius:17px; overflow:hidden; box-shadow:0 4px 14px rgba(15,23,42,.03); }
        .table-heading { display:flex; justify-content:space-between; align-items:center; gap:15px; padding:13px 16px; border-bottom:1px solid var(--border); background:#fbfcfe; }
        .table-heading strong { font-size:.92rem; }
        .table-heading span { color:var(--muted); font-size:.78rem; font-weight:700; }
        .table-wrap { overflow:auto; max-height:66vh; }
        table.centers-table { width:100%; border-collapse:separate; border-spacing:0; min-width:1120px; }
        table.centers-table.demographic { min-width:1740px; }
        .centers-table th, .centers-table td { border-bottom:1px solid #e9eef4; border-right:1px solid #edf1f5; padding:10px 11px; vertical-align:middle; background:white; }
        .centers-table th:last-child, .centers-table td:last-child { border-right:0; }
        .centers-table thead th { position:sticky; top:0; z-index:8; background:#eef3f8; color:#3e4d62; font-size:.72rem; text-transform:uppercase; letter-spacing:.04em; font-weight:900; text-align:left; }
        .centers-table thead tr:nth-child(2) th { top:37px; background:#f6f8fb; text-align:center; }
        .centers-table tbody tr:hover td { background:#fbfdff; }
        .centers-table tfoot td { position:sticky; bottom:0; z-index:7; background:#eaf7fb; border-top:2px solid #8bc3d2; border-bottom:0; font-weight:900; color:#123f53; }
        .center { text-align:center !important; }
        .right { text-align:right !important; }
        .badge { display:inline-flex; align-items:center; padding:5px 9px; border-radius:999px; font-size:.71rem; font-weight:900; white-space:nowrap; }
        .badge-basica { background:#e0f2fe; color:#0369a1; border:1px solid #bae6fd; }
        .badge-pre { background:#fef3c7; color:#a45208; border:1px solid #fde68a; }
        .badge-adn { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .badge-uaps { background:#f3e8ff; color:#6d28d9; border:1px solid #e9d5ff; }
        .editable-text { min-width:180px; padding:7px 8px; border:1px solid transparent; border-radius:8px; font-weight:800; color:#243149; }
        .editable-text:hover, .editable-text:focus { outline:none; border-color:#bed0df; background:white; box-shadow:0 0 0 3px rgba(52,133,155,.09); }
        .location { display:grid; gap:4px; min-width:240px; color:#56657b; font-size:.76rem; }
        .location strong { color:#243149; }
        .num-input { width:78px; max-width:100%; border:1px solid #ccd7e2; border-radius:9px; padding:8px 6px; text-align:center; font:inherit; font-weight:900; color:#075c74; outline:none; }
        .num-input:focus { border-color:var(--ah-primary); box-shadow:0 0 0 3px rgba(52,133,155,.12); }
        .total-display { display:inline-flex; min-width:78px; justify-content:center; border-radius:9px; padding:8px 9px; background:#eff6f8; color:#123f53; font-weight:900; }
        .no-month { display:block; margin-top:5px; color:#b45309; font-size:.68rem; font-weight:800; }
        .btn-delete { border:0; width:34px; height:34px; border-radius:10px; background:#fee2e2; color:#b91c1c; cursor:pointer; }
        .btn-delete:hover { background:#ef4444; color:white; }
        .empty-row { padding:46px !important; text-align:center; color:#8b99ac; font-weight:800; }
        .saved-field { animation:savedPulse .9s ease; }
        @keyframes savedPulse { 0%{box-shadow:0 0 0 0 rgba(34,197,94,.45)} 100%{box-shadow:0 0 0 7px rgba(34,197,94,0)} }

        @media (max-width:1250px) {
            .metrics-grid { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .import-grid { grid-template-columns:1fr; }
            .filter-panel { grid-template-columns:repeat(2,minmax(0,1fr)); }
            .filter-panel #filter-search { grid-column:1/-1; }
        }
        @media (max-width:760px) {
            .main-wrapper { padding:18px; }
            .metrics-grid, .monthly-toolbar, .filter-panel { grid-template-columns:1fr; }
            .page-head { flex-direction:column; }
        }
    </style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<div class="toast" id="toast"></div>
<main class="main-wrapper">
    <header class="page-head">
        <div>
            <h1><i class="fa-solid fa-school" style="color:var(--ah-primary)"></i> Catálogo Demográfico de Centros</h1>
            <p>Gestión de centros educativos, Centros ADN y UAPS/CIS con población mensual desagregada por edad y sexo.</p>
        </div>
        <div class="period-badge"><i class="fa-regular fa-calendar"></i> Datos mensuales: <?php echo h(monthLabelEs($currentPeriod)); ?></div>
    </header>

    <?php echo $msg; ?>

    <section class="metrics-grid">
        <?php foreach ($types as $typeKey => $label):
            $badgeClass = $typeKey === 'Preescolar' ? 'badge-pre' : ($typeKey === 'ADN' ? 'badge-adn' : ($typeKey === 'UAPS/CIS' ? 'badge-uaps' : 'badge-basica'));
        ?>
        <article class="metric-card <?php echo $activeTab === $typeKey ? 'active' : ''; ?>" data-open-tab="<?php echo h($typeKey); ?>">
            <div>
                <h4><?php echo h($label); ?></h4>
                <div class="count"><?php echo number_format($centerCount[$typeKey] ?? 0); ?></div>
                <small>Matrícula / población: <?php echo number_format($populationTotals[$typeKey] ?? 0); ?></small>
            </div>
            <div class="metric-icon <?php echo $badgeClass; ?>"><i class="fa-solid <?php echo h($icons[$typeKey]); ?>"></i></div>
        </article>
        <?php endforeach; ?>
    </section>

    <section class="import-grid">
        <div class="panel">
            <h2 class="panel-title"><i class="fa-solid fa-file-circle-plus"></i> Carga general de centros</h2>
            <label class="upload-zone">
                <input type="file" id="general-excel-file" accept=".xlsx,.xls">
                <i class="fa-solid fa-file-excel" id="general-excel-icon"></i>
                <strong id="general-upload-text">Verificando lector de Excel…</strong>
                <span>Admite matrícula escolar y también las nuevas columnas demográficas para ADN/UAPS.</span>
            </label>
        </div>

        <div class="panel">
            <h2 class="panel-title"><i class="fa-solid fa-calendar-check"></i> Actualización mensual ADN y UAPS/CIS</h2>
            <div class="monthly-toolbar">
                <div class="field">
                    <label for="monthly-period">Mes que se actualizará</label>
                    <input type="month" id="monthly-period" class="form-control" value="<?php echo h($currentPeriod); ?>">
                </div>
                <label class="btn-upload">
                    <i class="fa-solid fa-hands-holding-child"></i> Actualizar Centros ADN
                    <input type="file" class="monthly-file" data-target-type="ADN" accept=".xlsx,.xls">
                </label>
                <label class="btn-upload">
                    <i class="fa-solid fa-house-medical"></i> Actualizar UAPS / CIS
                    <input type="file" class="monthly-file" data-target-type="UAPS/CIS" accept=".xlsx,.xls">
                </label>
            </div>
            <p class="monthly-note">
                <strong>Columnas requeridas:</strong> Tipo, Nombre_Centro_Educativo, Municipio, Comunidad_Base, Caserio,
                pob_0_5.9_f, pob_0_5.9_m, pob_6_14.9_f, pob_6_14.9_m,
                pob_15_17.9_f, pob_15_17.9_m, pob_18_24_f y pob_18_24_m.
                La columna <strong>Matrícula / Pob.</strong> se calcula automáticamente como la suma de los ocho campos.
            </p>
        </div>
    </section>

    <nav class="tabs-bar">
        <?php foreach ($types as $typeKey => $label): ?>
        <button type="button" class="tab-btn <?php echo $activeTab === $typeKey ? 'active' : ''; ?>" data-tab="<?php echo h($typeKey); ?>">
            <i class="fa-solid <?php echo h($icons[$typeKey]); ?>"></i> <?php echo h($label); ?>
        </button>
        <?php endforeach; ?>
    </nav>

    <section class="filter-panel">
        <input type="search" id="filter-search" class="form-control" placeholder="Buscar centro, comunidad, caserío, municipio o técnico…">
        <select id="filter-population" class="form-control">
            <option value="">Toda la población</option>
            <option value="zero">Población en 0</option>
            <option value="with">Con población</option>
        </select>
        <select id="filter-municipality" class="form-control">
            <option value="">Todos los municipios</option>
            <?php foreach ($municipalities as $municipality): ?>
                <option value="<?php echo h(mb_strtolower($municipality,'UTF-8')); ?>"><?php echo h($municipality); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filter-community" class="form-control">
            <option value="">Todas las comunidades</option>
            <?php foreach ($communities as $community): ?>
                <option value="<?php echo h(mb_strtolower($community,'UTF-8')); ?>"><?php echo h($community); ?></option>
            <?php endforeach; ?>
        </select>
        <select id="filter-tech" class="form-control">
            <option value="">Todos los técnicos</option>
            <?php foreach ($technicians as $technician): ?>
                <option value="<?php echo h(mb_strtolower($technician,'UTF-8')); ?>"><?php echo h($technician); ?></option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="btn-clear" id="btn-clear-filters"><i class="fa-solid fa-eraser"></i> Limpiar</button>
    </section>

    <?php foreach ($types as $typeKey => $label):
        $slug = typeSlug($typeKey);
        $isDemographic = in_array($typeKey, ['ADN','UAPS/CIS'], true);
        $badge = $typeKey === 'Preescolar' ? 'badge-pre' : ($typeKey === 'ADN' ? 'badge-adn' : ($typeKey === 'UAPS/CIS' ? 'badge-uaps' : 'badge-basica'));
    ?>
    <section class="tab-content <?php echo $activeTab === $typeKey ? 'active' : ''; ?>" data-tab-content="<?php echo h($typeKey); ?>">
        <div class="table-card">
            <div class="table-heading">
                <div>
                    <strong><?php echo h($label); ?></strong>
                    <span> · Las sumatorias inferiores cambian automáticamente con los filtros.</span>
                </div>
                <?php if ($isDemographic): ?>
                    <span><i class="fa-regular fa-calendar"></i> <?php echo h(monthLabelEs($currentPeriod)); ?></span>
                <?php endif; ?>
            </div>
            <div class="table-wrap">
                <?php if (!$isDemographic): ?>
                <table class="centers-table" data-type="<?php echo h($typeKey); ?>">
                    <thead>
                        <tr>
                            <th style="width:125px">Tipo</th>
                            <th>Nombre del centro</th>
                            <th style="width:320px">Ubicación y equipo</th>
                            <th class="center" style="width:110px">Femenino</th>
                            <th class="center" style="width:110px">Masculino</th>
                            <th class="center" style="width:130px">Matrícula / Pob.</th>
                            <th class="center" style="width:70px">Acción</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($byType[$typeKey])): ?>
                        <tr class="empty"><td colspan="7" class="empty-row"><i class="fa-solid fa-folder-open"></i><br>No hay registros.</td></tr>
                    <?php else: foreach ($byType[$typeKey] as $center):
                        $search = mb_strtolower(trim(
                            ($center['nombre'] ?? '') . ' ' . ($center['comunidad_base'] ?? '') . ' ' .
                            ($center['caserio'] ?? '') . ' ' . ($center['municipio_vista'] ?? '') . ' ' . ($center['tecnico'] ?? '')
                        ), 'UTF-8');
                    ?>
                        <tr class="center-row"
                            data-id="<?php echo (int)$center['id']; ?>"
                            data-search="<?php echo h($search); ?>"
                            data-community="<?php echo h(mb_strtolower((string)$center['comunidad_base'],'UTF-8')); ?>"
                            data-municipality="<?php echo h(mb_strtolower((string)$center['municipio_vista'],'UTF-8')); ?>"
                            data-tech="<?php echo h(mb_strtolower((string)$center['tecnico'],'UTF-8')); ?>"
                            data-total="<?php echo (int)$center['display_total']; ?>">
                            <td><span class="badge <?php echo $badge; ?>"><?php echo h($center['tipo']); ?></span></td>
                            <td><div class="editable-text" contenteditable="true" data-field="nombre" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['nombre']); ?></div></td>
                            <td>
                                <div class="location">
                                    <strong><i class="fa-solid fa-location-crosshairs"></i> Base: <span class="editable-text" contenteditable="true" data-field="comunidad_base" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['comunidad_base']); ?></span></strong>
                                    <span><i class="fa-solid fa-location-dot"></i> Caserío: <span class="editable-text" contenteditable="true" data-field="caserio" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['caserio']); ?></span></span>
                                    <span><i class="fa-solid fa-city"></i> Municipio: <?php echo h($center['municipio_vista']); ?></span>
                                    <span><i class="fa-solid fa-user-tie"></i> Técnico: <?php echo h($center['tecnico']); ?></span>
                                </div>
                            </td>
                            <td class="center"><input type="number" min="0" class="num-input legacy-input" data-field="pob_fem" data-id="<?php echo (int)$center['id']; ?>" value="<?php echo (int)$center['display_fem']; ?>"></td>
                            <td class="center"><input type="number" min="0" class="num-input legacy-input" data-field="pob_masc" data-id="<?php echo (int)$center['id']; ?>" value="<?php echo (int)$center['display_masc']; ?>"></td>
                            <td class="center"><span class="total-display row-total"><?php echo number_format((int)$center['display_total']); ?></span></td>
                            <td class="center"><button type="button" class="btn-delete" data-id="<?php echo (int)$center['id']; ?>"><i class="fa-solid fa-trash-can"></i></button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Totales visibles · <span data-total-field="centers">0</span> centros</td>
                            <td class="center" data-total-field="pob_fem">0</td>
                            <td class="center" data-total-field="pob_masc">0</td>
                            <td class="center" data-total-field="pob_total">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php else: ?>
                <table class="centers-table demographic" data-type="<?php echo h($typeKey); ?>">
                    <thead>
                        <tr>
                            <th rowspan="2" style="width:120px">Tipo</th>
                            <th rowspan="2" style="width:230px">Nombre del centro</th>
                            <th rowspan="2" style="width:320px">Ubicación y equipo</th>
                            <th colspan="2" class="center">0–5.9 años</th>
                            <th colspan="2" class="center">6–14.9 años</th>
                            <th colspan="2" class="center">15–17.9 años</th>
                            <th colspan="2" class="center">18–24 años</th>
                            <th rowspan="2" class="center" style="width:135px">Matrícula / Pob.</th>
                            <th rowspan="2" class="center" style="width:70px">Acción</th>
                        </tr>
                        <tr>
                            <th>F</th><th>M</th><th>F</th><th>M</th><th>F</th><th>M</th><th>F</th><th>M</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if (empty($byType[$typeKey])): ?>
                        <tr class="empty"><td colspan="13" class="empty-row"><i class="fa-solid fa-folder-open"></i><br>No hay registros.</td></tr>
                    <?php else: foreach ($byType[$typeKey] as $center):
                        $search = mb_strtolower(trim(
                            ($center['nombre'] ?? '') . ' ' . ($center['comunidad_base'] ?? '') . ' ' .
                            ($center['caserio'] ?? '') . ' ' . ($center['municipio_vista'] ?? '') . ' ' . ($center['tecnico'] ?? '')
                        ), 'UTF-8');
                        $hasMonth = !empty($center['poblacion_periodo']);
                    ?>
                        <tr class="center-row"
                            data-id="<?php echo (int)$center['id']; ?>"
                            data-search="<?php echo h($search); ?>"
                            data-community="<?php echo h(mb_strtolower((string)$center['comunidad_base'],'UTF-8')); ?>"
                            data-municipality="<?php echo h(mb_strtolower((string)$center['municipio_vista'],'UTF-8')); ?>"
                            data-tech="<?php echo h(mb_strtolower((string)$center['tecnico'],'UTF-8')); ?>"
                            data-total="<?php echo (int)$center['display_total']; ?>">
                            <td><span class="badge <?php echo $badge; ?>"><?php echo h($center['tipo']); ?></span></td>
                            <td>
                                <div class="editable-text" contenteditable="true" data-field="nombre" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['nombre']); ?></div>
                                <?php if (!$hasMonth): ?><span class="no-month">Sin carga para <?php echo h(monthLabelEs($currentPeriod)); ?></span><?php endif; ?>
                            </td>
                            <td>
                                <div class="location">
                                    <strong><i class="fa-solid fa-location-crosshairs"></i> Base: <span class="editable-text" contenteditable="true" data-field="comunidad_base" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['comunidad_base']); ?></span></strong>
                                    <span><i class="fa-solid fa-location-dot"></i> Caserío: <span class="editable-text" contenteditable="true" data-field="caserio" data-id="<?php echo (int)$center['id']; ?>"><?php echo h($center['caserio']); ?></span></span>
                                    <span><i class="fa-solid fa-city"></i> Municipio: <?php echo h($center['municipio_vista']); ?></span>
                                    <span><i class="fa-solid fa-user-tie"></i> Técnico: <?php echo h($center['tecnico']); ?></span>
                                </div>
                            </td>
                            <?php foreach (['pob_0_5_9_f','pob_0_5_9_m','pob_6_14_9_f','pob_6_14_9_m','pob_15_17_9_f','pob_15_17_9_m','pob_18_24_f','pob_18_24_m'] as $field): ?>
                                <td class="center"><input type="number" min="0" class="num-input monthly-input" data-field="<?php echo h($field); ?>" data-id="<?php echo (int)$center['id']; ?>" value="<?php echo (int)$center[$field]; ?>"></td>
                            <?php endforeach; ?>
                            <td class="center"><span class="total-display row-total"><?php echo number_format((int)$center['display_total']); ?></span></td>
                            <td class="center"><button type="button" class="btn-delete" data-id="<?php echo (int)$center['id']; ?>"><i class="fa-solid fa-trash-can"></i></button></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="3">Totales visibles · <span data-total-field="centers">0</span> centros</td>
                            <td class="center" data-total-field="pob_0_5_9_f">0</td>
                            <td class="center" data-total-field="pob_0_5_9_m">0</td>
                            <td class="center" data-total-field="pob_6_14_9_f">0</td>
                            <td class="center" data-total-field="pob_6_14_9_m">0</td>
                            <td class="center" data-total-field="pob_15_17_9_f">0</td>
                            <td class="center" data-total-field="pob_15_17_9_m">0</td>
                            <td class="center" data-total-field="pob_18_24_f">0</td>
                            <td class="center" data-total-field="pob_18_24_m">0</td>
                            <td class="center" data-total-field="pob_total">0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
                <?php endif; ?>
            </div>
        </div>
    </section>
    <?php endforeach; ?>
</main>

<script>
const currentPeriod = <?php echo json_encode($currentPeriod); ?>;
const initialTab = <?php echo json_encode($activeTab); ?>;
const spreadsheetSources = [
    'https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js',
    'https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js',
    'https://cdn.sheetjs.com/xlsx-0.20.0/package/dist/xlsx.full.min.js'
];

function showToast(message, isError = false) {
    const toast = document.getElementById('toast');
    toast.innerHTML = (isError ? '<i class="fa-solid fa-triangle-exclamation"></i> ' : '<i class="fa-solid fa-check"></i> ') + message;
    toast.style.background = isError ? '#991b1b' : '#166534';
    toast.style.display = 'block';
    clearTimeout(window.__centerToast);
    window.__centerToast = setTimeout(() => toast.style.display = 'none', 2200);
}

function postForm(payload) {
    const data = new FormData();
    Object.keys(payload).forEach(key => data.append(key, payload[key]));
    return fetch(window.location.pathname + window.location.search, {method:'POST', body:data})
        .then(async response => {
            const result = await response.json().catch(() => ({status:'error', msg:'Respuesta inválida del servidor.'}));
            if (!response.ok && result.status !== 'error') result.status = 'error';
            return result;
        });
}

function loadSpreadsheetReader(index = 0) {
    if (window.XLSX) {
        document.getElementById('general-upload-text').textContent = 'Lector de Excel listo. Seleccione o arrastre un archivo.';
        return;
    }
    if (index >= spreadsheetSources.length) {
        document.getElementById('general-upload-text').textContent = 'No fue posible cargar el lector de Excel.';
        document.getElementById('general-excel-icon').style.color = '#dc2626';
        return;
    }
    const script = document.createElement('script');
    script.src = spreadsheetSources[index];
    script.async = true;
    script.onload = () => {
        document.getElementById('general-upload-text').textContent = 'Lector de Excel listo. Seleccione o arrastre un archivo.';
        document.getElementById('general-excel-icon').style.color = '#15803d';
    };
    script.onerror = () => loadSpreadsheetReader(index + 1);
    document.head.appendChild(script);
}
loadSpreadsheetReader();

function readExcel(file) {
    return new Promise((resolve, reject) => {
        if (!window.XLSX) {
            reject(new Error('El lector de Excel todavía no está disponible. Espere unos segundos.'));
            return;
        }
        const reader = new FileReader();
        reader.onload = event => {
            try {
                const workbook = XLSX.read(new Uint8Array(event.target.result), {type:'array'});
                const sheet = workbook.Sheets[workbook.SheetNames[0]];
                const rows = XLSX.utils.sheet_to_json(sheet, {defval:''});
                if (!rows.length) throw new Error('El archivo no contiene registros.');
                resolve(rows);
            } catch (error) {
                reject(error);
            }
        };
        reader.onerror = () => reject(new Error('No fue posible leer el archivo.'));
        reader.readAsArrayBuffer(file);
    });
}

async function importRows(file, action, extra = {}) {
    const rows = await readExcel(file);
    const payload = Object.assign({action, data:JSON.stringify(rows)}, extra);
    const result = await postForm(payload);
    if (result.status !== 'ok') throw new Error(result.msg || 'No fue posible importar los datos.');
    return result;
}

document.getElementById('general-excel-file').addEventListener('change', async function () {
    const file = this.files[0];
    if (!file) return;
    const label = document.getElementById('general-upload-text');
    try {
        label.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Procesando archivo…';
        const result = await importRows(file, 'importar_centros');
        alert(result.msg);
        location.reload();
    } catch (error) {
        alert(error.message);
        label.textContent = 'Lector de Excel listo. Seleccione o arrastre un archivo.';
    } finally {
        this.value = '';
    }
});

document.querySelectorAll('.monthly-file').forEach(input => {
    input.addEventListener('change', async function () {
        const file = this.files[0];
        if (!file) return;
        const targetType = this.dataset.targetType;
        const period = document.getElementById('monthly-period').value;
        if (!period) {
            alert('Seleccione el mes que desea actualizar.');
            this.value = '';
            return;
        }
        try {
            const result = await importRows(file, 'importar_poblacion_mensual', {
                tipo_objetivo:targetType,
                periodo:period
            });
            alert(result.msg);
            const url = new URL(window.location.href);
            url.searchParams.set('periodo', period);
            url.searchParams.set('tab', targetType);
            window.location.href = url.toString();
        } catch (error) {
            alert(error.message);
        } finally {
            this.value = '';
        }
    });
});

document.getElementById('monthly-period').addEventListener('change', function () {
    if (!this.value) return;
    const url = new URL(window.location.href);
    url.searchParams.set('periodo', this.value);
    url.searchParams.set('tab', activeTab);
    window.location.href = url.toString();
});

let activeTab = initialTab;
function setTab(type) {
    activeTab = type;
    document.querySelectorAll('.tab-btn').forEach(button => button.classList.toggle('active', button.dataset.tab === type));
    document.querySelectorAll('.tab-content').forEach(content => content.classList.toggle('active', content.dataset.tabContent === type));
    document.querySelectorAll('.metric-card').forEach(card => card.classList.toggle('active', card.dataset.openTab === type));
    applyFilters();
}
document.querySelectorAll('.tab-btn').forEach(button => button.addEventListener('click', () => setTab(button.dataset.tab)));
document.querySelectorAll('.metric-card').forEach(card => card.addEventListener('click', () => setTab(card.dataset.openTab)));

function numberValue(element) {
    return Math.max(0, parseInt(element?.value || '0', 10) || 0);
}

function recalculateRowTotal(row) {
    const monthlyInputs = row.querySelectorAll('.monthly-input');
    let total = 0;
    if (monthlyInputs.length) {
        monthlyInputs.forEach(input => total += numberValue(input));
    } else {
        const female = row.querySelector('[data-field="pob_fem"]');
        const male = row.querySelector('[data-field="pob_masc"]');
        total = numberValue(female) + numberValue(male);
    }
    row.dataset.total = String(total);
    const display = row.querySelector('.row-total');
    if (display) display.textContent = total.toLocaleString('es-HN');
    return total;
}

function recalculateTableFooter(section) {
    if (!section) return;
    const table = section.querySelector('table.centers-table');
    if (!table) return;
    const rows = [...table.querySelectorAll('tbody .center-row')].filter(row => row.style.display !== 'none');
    const totals = {centers:rows.length, pob_total:0, pob_fem:0, pob_masc:0};
    const monthlyFields = [
        'pob_0_5_9_f','pob_0_5_9_m','pob_6_14_9_f','pob_6_14_9_m',
        'pob_15_17_9_f','pob_15_17_9_m','pob_18_24_f','pob_18_24_m'
    ];
    monthlyFields.forEach(field => totals[field] = 0);

    rows.forEach(row => {
        const total = recalculateRowTotal(row);
        totals.pob_total += total;
        const monthly = row.querySelectorAll('.monthly-input');
        if (monthly.length) {
            monthlyFields.forEach(field => {
                totals[field] += numberValue(row.querySelector(`[data-field="${field}"]`));
            });
        } else {
            totals.pob_fem += numberValue(row.querySelector('[data-field="pob_fem"]'));
            totals.pob_masc += numberValue(row.querySelector('[data-field="pob_masc"]'));
        }
    });

    table.querySelectorAll('[data-total-field]').forEach(cell => {
        const field = cell.dataset.totalField;
        cell.textContent = (totals[field] || 0).toLocaleString('es-HN');
    });
}

function applyFilters() {
    const search = (document.getElementById('filter-search').value || '').toLocaleLowerCase('es');
    const populationFilter = document.getElementById('filter-population').value;
    const municipality = document.getElementById('filter-municipality').value;
    const community = document.getElementById('filter-community').value;
    const tech = document.getElementById('filter-tech').value;
    const section = document.querySelector(`.tab-content[data-tab-content="${CSS.escape(activeTab)}"]`);
    if (!section) return;

    let visible = 0;
    section.querySelectorAll('.center-row').forEach(row => {
        const total = recalculateRowTotal(row);
        const matches = (
            (search === '' || (row.dataset.search || '').includes(search)) &&
            (populationFilter === '' || (populationFilter === 'zero' ? total === 0 : total > 0)) &&
            (municipality === '' || row.dataset.municipality === municipality) &&
            (community === '' || row.dataset.community === community) &&
            (tech === '' || row.dataset.tech === tech)
        );
        row.style.display = matches ? '' : 'none';
        if (matches) visible++;
    });
    section.querySelectorAll('tr.empty').forEach(row => row.style.display = visible === 0 ? '' : 'none');
    recalculateTableFooter(section);
}

['filter-search','filter-population','filter-municipality','filter-community','filter-tech'].forEach(id => {
    const element = document.getElementById(id);
    element.addEventListener('input', applyFilters);
    element.addEventListener('change', applyFilters);
});
document.getElementById('btn-clear-filters').addEventListener('click', () => {
    ['filter-search','filter-population','filter-municipality','filter-community','filter-tech'].forEach(id => {
        document.getElementById(id).value = '';
    });
    applyFilters();
});

function markSaved(element) {
    element.classList.remove('saved-field');
    void element.offsetWidth;
    element.classList.add('saved-field');
}

async function updateCenter(id, field, value, element) {
    const result = await postForm({action:'update_centro', id, campo:field, valor:value});
    if (result.status !== 'ok') throw new Error(result.msg || 'No fue posible guardar.');
    if (element) markSaved(element);
    return result;
}

document.querySelectorAll('.legacy-input').forEach(input => {
    input.addEventListener('change', async function () {
        try {
            const row = this.closest('.center-row');
            const result = await updateCenter(this.dataset.id, this.dataset.field, this.value, this);
            if (result.pob_total !== null && result.pob_total !== undefined) {
                row.dataset.total = String(result.pob_total);
                row.querySelector('.row-total').textContent = Number(result.pob_total).toLocaleString('es-HN');
            }
            recalculateTableFooter(row.closest('.tab-content'));
            showToast('Guardado');
        } catch (error) {
            showToast(error.message, true);
        }
    });
});

document.querySelectorAll('.monthly-input').forEach(input => {
    input.addEventListener('change', async function () {
        try {
            const row = this.closest('.center-row');
            const result = await postForm({
                action:'update_population_monthly',
                id:this.dataset.id,
                campo:this.dataset.field,
                valor:this.value,
                periodo:currentPeriod
            });
            if (result.status !== 'ok') throw new Error(result.msg || 'No fue posible guardar.');
            markSaved(this);
            const total = Number(result.population?.pob_total || 0);
            row.dataset.total = String(total);
            row.querySelector('.row-total').textContent = total.toLocaleString('es-HN');
            row.querySelector('.no-month')?.remove();
            recalculateTableFooter(row.closest('.tab-content'));
            showToast('Guardado');
        } catch (error) {
            showToast(error.message, true);
        }
    });
});

document.querySelectorAll('.editable-text').forEach(element => {
    element.dataset.original = element.textContent.trim();
    element.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            element.blur();
        }
    });
    element.addEventListener('blur', async function () {
        const value = this.textContent.trim();
        if (value === this.dataset.original) return;
        try {
            await updateCenter(this.dataset.id, this.dataset.field, value, this);
            this.dataset.original = value;
            showToast('Guardado');
        } catch (error) {
            this.textContent = this.dataset.original;
            showToast(error.message, true);
        }
    });
});

document.querySelectorAll('.btn-delete').forEach(button => {
    button.addEventListener('click', async function () {
        if (!confirm('¿Eliminar este centro y todo su historial mensual?')) return;
        try {
            const result = await postForm({action:'delete_centro', id:this.dataset.id});
            if (result.status !== 'ok') throw new Error(result.msg || 'No fue posible eliminar.');
            const row = this.closest('.center-row');
            const section = row.closest('.tab-content');
            row.remove();
            recalculateTableFooter(section);
            showToast('Centro eliminado');
        } catch (error) {
            showToast(error.message, true);
        }
    });
});

setTab(initialTab);
</script>
</body>
</html>
