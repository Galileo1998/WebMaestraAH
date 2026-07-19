<?php
/**
 * Panel Técnico - Acción Honduras
 * Actividades del mes en curso, metas personales y actualización de logros por centro.
 *
 * Este módulo NO permite modificar la programación. El técnico solamente puede:
 * - Actualizar el LOGRADO mensual de sus asignaciones sin centros.
 * - Actualizar el CUMPLIDO de los centros que aparecen en su fila de la Etapa 3.
 */

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once dirname(__DIR__) . '/config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$auth = new Auth($db);
$auth->requireLogin(['admin', 'portal']);

$monthMap = [
    1 => ['key' => 'jan', 'name' => 'Enero', 'short' => 'Ene'],
    2 => ['key' => 'feb', 'name' => 'Febrero', 'short' => 'Feb'],
    3 => ['key' => 'mar', 'name' => 'Marzo', 'short' => 'Mar'],
    4 => ['key' => 'apr', 'name' => 'Abril', 'short' => 'Abr'],
    5 => ['key' => 'may', 'name' => 'Mayo', 'short' => 'May'],
    6 => ['key' => 'jun', 'name' => 'Junio', 'short' => 'Jun'],
    7 => ['key' => 'jul', 'name' => 'Julio', 'short' => 'Jul'],
    8 => ['key' => 'aug', 'name' => 'Agosto', 'short' => 'Ago'],
    9 => ['key' => 'sep', 'name' => 'Septiembre', 'short' => 'Sep'],
    10 => ['key' => 'oct', 'name' => 'Octubre', 'short' => 'Oct'],
    11 => ['key' => 'nov', 'name' => 'Noviembre', 'short' => 'Nov'],
    12 => ['key' => 'dec', 'name' => 'Diciembre', 'short' => 'Dic'],
];

$currentMonth = $monthMap[(int)date('n')];
$validMonthKeys = array_column($monthMap, 'key');

// Determinar el mes seleccionado por el cintillo, por defecto el actual
$monthKey = $_GET['mes'] ?? $currentMonth['key'];
if (!in_array($monthKey, $validMonthKeys, true)) {
    $monthKey = $currentMonth['key'];
}

$monthName = '';
foreach ($monthMap as $m) {
    if ($m['key'] === $monthKey) {
        $monthName = $m['name'];
        break;
    }
}
$currentPeriod = date('Y-m');
$today = date('Y-m-d');

if (empty($_SESSION['panel_tecnico_csrf'])) {
    $_SESSION['panel_tecnico_csrf'] = bin2hex(random_bytes(24));
}
$csrfToken = (string)$_SESSION['panel_tecnico_csrf'];

function json_array($value, array $default = []): array
{
    if (is_array($value)) return $value;
    if (!is_string($value) || trim($value) === '') return $default;
    $decoded = json_decode($value, true);
    return is_array($decoded) ? $decoded : $default;
}

function text_lower(string $value): string
{
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function normalize_text($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';

    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') $value = $ascii;

    $value = text_lower($value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
}

function same_text($a, $b): bool
{
    return normalize_text($a) !== '' && normalize_text($a) === normalize_text($b);
}

function number_value($value): float
{
    if (is_string($value)) {
        $value = str_replace([',', 'L.', 'L ', ' '], '', $value);
    }
    return is_numeric($value) ? (float)$value : 0.0;
}

function clamp_value(float $value, float $min, float $max): float
{
    return max($min, min($max, $value));
}

function first_non_empty(array $row, array $keys, string $fallback = ''): string
{
    foreach ($keys as $key) {
        $value = trim((string)($row[$key] ?? ''));
        if ($value !== '') return $value;
    }
    return $fallback;
}

function short_code($value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    $parts = preg_split('/\s+/', $value);
    return (string)($parts[0] ?? $value);
}

function quality_value(array $row, string $field): float
{
    if (!array_key_exists($field, $row) || $row[$field] === '' || $row[$field] === null) return 100.0;
    $value = number_value($row[$field]);
    $version = (int)($row['quality_version'] ?? 0);
    if ($version < 2 && $value === 0.0) return 100.0;
    return clamp_value($value, 0, 100);
}

function row_progress(array $row): float
{
    $programmed = number_value($row['a_lograr'] ?? 0);
    $achieved = number_value($row['cumplido'] ?? ($row['logrado'] ?? 0));

    $centers = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
    if ($centers) {
        $programmed = 0;
        $achieved = 0;
        foreach ($centers as $center) {
            if (!is_array($center)) continue;
            $programmed += number_value($center['a_lograr'] ?? 0);
            $achieved += number_value($center['cumplido'] ?? 0);
        }
    }

    if ($programmed <= 0) return 0.0;
    $quantity = min(1, max(0, $achieved / $programmed));
    $quality = (quality_value($row, 'a_tiempo') + quality_value($row, 'en_forma')) / 200;
    return round($quantity * $quality * 100, 2);
}

function parse_activity_percent($value): float
{
    if (is_numeric($value)) return clamp_value((float)$value, 0, 100);
    if (preg_match('/(-?\d+(?:\.\d+)?)\s*%/', (string)$value, $match)) {
        return clamp_value((float)$match[1], 0, 100);
    }
    return 0.0;
}

function display_status(float $percent, string $stored = ''): array
{
    $storedNorm = normalize_text($stored);
    if (str_contains($storedNorm, 'cancelado')) return ['text' => 'Cancelada', 'class' => 'cancelled'];
    if (str_contains($storedNorm, 'reprogramado')) return ['text' => 'Reprogramada', 'class' => 'rescheduled'];
    if ($percent >= 100) return ['text' => 'Completada', 'class' => 'completed'];
    if ($percent > 0) return ['text' => 'En proceso', 'class' => 'progress'];
    return ['text' => 'Pendiente', 'class' => 'pending'];
}

function month_is_assigned($raw, string $monthKey): bool
{
    if (is_array($raw)) {
        foreach ($raw as $value) if (normalize_text($value) === normalize_text($monthKey)) return true;
        return false;
    }

    $raw = trim((string)$raw);
    if ($raw === '') return false;
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return month_is_assigned($decoded, $monthKey);

    $aliases = [
        'jan' => ['jan', 'ene', 'enero'], 'feb' => ['feb', 'febrero'],
        'mar' => ['mar', 'marzo'], 'apr' => ['apr', 'abr', 'abril'],
        'may' => ['may', 'mayo'], 'jun' => ['jun', 'junio'],
        'jul' => ['jul', 'julio'], 'aug' => ['aug', 'ago', 'agosto'],
        'sep' => ['sep', 'sept', 'septiembre'], 'oct' => ['oct', 'octubre'],
        'nov' => ['nov', 'noviembre'], 'dec' => ['dec', 'dic', 'diciembre'],
    ];
    $parts = preg_split('/[,;|\s]+/', normalize_text($raw)) ?: [];
    foreach ($aliases[$monthKey] ?? [$monthKey] as $alias) {
        if (in_array(normalize_text($alias), $parts, true)) return true;
    }
    return false;
}

function row_belongs_to_technician(array $row, string $technician): bool
{
    return same_text($row['persona'] ?? '', $technician);
}

function row_is_current_month(array $row, string $monthKey): bool
{
    $rowMonth = trim((string)($row['mes'] ?? ''));
    return $rowMonth === '' || normalize_text($rowMonth) === normalize_text($monthKey);
}

function assignment_is_owned(PDO $db, int $assignmentId, string $technician): ?array
{
    $stmt = $db->prepare(
        "SELECT * FROM ah_poa_asignaciones
         WHERE id = ? AND LOWER(TRIM(tecnico)) = LOWER(TRIM(?))
         LIMIT 1"
    );
    $stmt->execute([$assignmentId, $technician]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function update_assignment_total(PDO $db, int $assignmentId, string $monthKey, float $achieved): void
{
    $allowed = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
    if (!in_array($monthKey, $allowed, true)) throw new RuntimeException('Mes inválido.');

    $sum = implode(' + ', array_map(static fn($m) => "COALESCE(logro_{$m},0)", $allowed));
    $sql = "UPDATE ah_poa_asignaciones
            SET logro_{$monthKey} = ?, logro_asignado = {$sum}
            WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute([$achieved, $assignmentId]);
}

function sync_assignment_from_centers(PDO $db, int $idPoa, string $technician, string $monthKey): float
{
    $stageStmt = $db->prepare(
        "SELECT * FROM ah_poa_etapas
         WHERE id_poa = ? AND (orden = 3 OR codigo_etapa = 'E-3')
         ORDER BY CASE WHEN orden = 3 THEN 0 ELSE 1 END, id ASC
         LIMIT 1"
    );
    $stageStmt->execute([$idPoa]);
    $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);

    if (!$stage) return 0.0;

    $rows = json_array($stage['involucrados_json'] ?? '{}');
    $centerTotals = [];
    $allTotal = 0.0;
    $hasCenters = false;

    foreach ($rows as $row) {
        if (!is_array($row) || !row_belongs_to_technician($row, $technician) || !row_is_current_month($row, $monthKey) || !empty($row['deleted'])) continue;
        $centers = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
        if (!$centers) continue;

        $hasCenters = true;
        $rowBases = array_values(array_filter(array_map('trim', explode('|', (string)($row['base'] ?? '')))));
        foreach ($centers as $center) {
            if (!is_array($center)) continue;
            $achieved = number_value($center['cumplido'] ?? 0);
            $centerBase = trim((string)($center['comunidad_base'] ?? ''));
            if ($centerBase === '' && count($rowBases) === 1) $centerBase = $rowBases[0];
            $baseKey = normalize_text($centerBase);
            if ($baseKey !== '') $centerTotals[$baseKey] = ($centerTotals[$baseKey] ?? 0) + $achieved;
            $allTotal += $achieved;
        }
    }

    if (!$hasCenters) return 0.0;

    $assignStmt = $db->prepare(
        "SELECT * FROM ah_poa_asignaciones
         WHERE id_poa = ? AND LOWER(TRIM(tecnico)) = LOWER(TRIM(?))
         FOR UPDATE"
    );
    $assignStmt->execute([$idPoa, $technician]);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($assignments as $assignment) {
        $base = trim((string)($assignment['base_asignada'] ?? ''));
        $value = $base === '' ? $allTotal : ($centerTotals[normalize_text($base)] ?? 0.0);
        update_assignment_total($db, (int)$assignment['id'], $monthKey, $value);
    }

    return $allTotal;
}

function recalculate_activity(PDO $db, int $idPoa, string $monthKey): array
{
    $stmt = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? ORDER BY orden ASC, id ASC");
    $stmt->execute([$idPoa]);
    $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stagePercentages = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
    $peopleAchieved = 0.0;

    foreach ($stages as $stage) {
        $order = (int)($stage['orden'] ?? 0);
        if ($order < 1 || $order > 4) continue;

        $rows = json_array($stage['involucrados_json'] ?? '{}');
        $rowPercentages = [];

        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['deleted']) || !row_is_current_month($row, $monthKey)) continue;
            $rowPercentages[] = row_progress($row);

            if ($order === 3) {
                $centers = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
                if ($centers) {
                    foreach ($centers as $center) {
                        if (is_array($center)) $peopleAchieved += number_value($center['cumplido'] ?? 0);
                    }
                } else {
                    $peopleAchieved += number_value($row['cumplido'] ?? ($row['logrado'] ?? 0));
                }
            }
        }

        if ($rowPercentages) {
            $stagePercentages[$order] = array_sum($rowPercentages) / count($rowPercentages);
        }
    }

    $activityPercent = round(array_sum($stagePercentages) / 4, 0);
    $update = $db->prepare("UPDATE ah_poa SET operativo_estado = ?, operativo_meta_alc = ? WHERE id = ?");
    $update->execute([$activityPercent . '%', $peopleAchieved, $idPoa]);

    return [
        'activity_percent' => $activityPercent,
        'people_achieved' => $peopleAchieved,
        'stages' => $stagePercentages,
    ];
}

function update_noncenter_stage_row(PDO $db, int $idPoa, string $technician, string $monthKey): void
{
    $stageStmt = $db->prepare(
        "SELECT * FROM ah_poa_etapas
         WHERE id_poa = ? AND (orden = 3 OR codigo_etapa = 'E-3')
         ORDER BY CASE WHEN orden = 3 THEN 0 ELSE 1 END, id ASC
         LIMIT 1 FOR UPDATE"
    );
    $stageStmt->execute([$idPoa]);
    $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);
    if (!$stage) return;

    $assignStmt = $db->prepare(
        "SELECT base_asignada, logro_{$monthKey} AS logro_mes
         FROM ah_poa_asignaciones
         WHERE id_poa = ? AND LOWER(TRIM(tecnico)) = LOWER(TRIM(?))"
    );
    $assignStmt->execute([$idPoa, $technician]);
    $assignments = $assignStmt->fetchAll(PDO::FETCH_ASSOC);

    $byBase = [];
    $total = 0.0;
    foreach ($assignments as $assignment) {
        $value = number_value($assignment['logro_mes'] ?? 0);
        $baseKey = normalize_text($assignment['base_asignada'] ?? '');
        if ($baseKey !== '') $byBase[$baseKey] = ($byBase[$baseKey] ?? 0) + $value;
        $total += $value;
    }

    $rows = json_array($stage['involucrados_json'] ?? '{}');
    $changed = false;
    foreach ($rows as $key => &$row) {
        if (!is_array($row) || !row_belongs_to_technician($row, $technician) || !row_is_current_month($row, $monthKey) || !empty($row['deleted'])) continue;
        $centers = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
        if ($centers) continue;

        $bases = array_values(array_filter(array_map('trim', explode('|', (string)($row['base'] ?? '')))));
        if (!$bases) {
            $row['cumplido'] = $total;
        } else {
            $rowTotal = 0.0;
            foreach ($bases as $base) $rowTotal += $byBase[normalize_text($base)] ?? 0.0;
            $row['cumplido'] = $rowTotal;
        }
        $changed = true;
    }
    unset($row);

    if ($changed) {
        $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('No fue posible guardar el avance de la etapa.');
        $update = $db->prepare("UPDATE ah_poa_etapas SET involucrados_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $update->execute([$json, (int)$stage['id']]);
    }
}

// -----------------------------------------------------------------------------
// Usuario autenticado y nombre técnico canónico
// -----------------------------------------------------------------------------
$userId = (int)($_SESSION['user_id'] ?? $_SESSION['usuario_id'] ?? 0);
if ($userId === 0 && isset($_SESSION['user']['id'])) {
    $userId = (int)$_SESSION['user']['id'];
}

$userName = '';

// 1. Obtener el nombre del usuario desde la tabla correcta (users)
if ($userId > 0) {
    try {
        $stmtUser = $db->prepare("SELECT name FROM users WHERE id = ? LIMIT 1");
        $stmtUser->execute([$userId]);
        $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
        if ($userRow) {
            $userName = trim((string)($userRow['name'] ?? ''));
        }
    } catch (Throwable $e) {}
}

// Fallback estricto si la base de datos no trajo la info
if ($userName === '') {
    $userName = trim((string)($_SESSION['name'] ?? $_SESSION['nombre'] ?? $_SESSION['user_name'] ?? ''));
    if ($userName === '' && isset($_SESSION['user']['nombre'])) {
        $userName = trim((string)$_SESSION['user']['nombre']);
    }
    if ($userName === '' && isset($_SESSION['user']['name'])) {
        $userName = trim((string)$_SESSION['user']['name']);
    }
}

if ($userName === '') {
    http_response_code(403);
    exit('No fue posible identificar el nombre del usuario autenticado.');
}

$technicianName = $userName;

// 2. Vincular "users.name" con "ah_tecnicos.nombre" inteligentemente
try {
    $matched = false;
    
    // Búsqueda exacta primero
    $stmtExact = $db->prepare("SELECT nombre FROM ah_tecnicos WHERE LOWER(TRIM(nombre)) = LOWER(TRIM(?)) AND activo = 1 LIMIT 1");
    $stmtExact->execute([$userName]);
    $exactMatch = $stmtExact->fetchColumn();
    
    if ($exactMatch) {
        $technicianName = trim((string)$exactMatch);
        $matched = true;
    }

    // Búsqueda inteligente (si el nombre en 'users' está incompleto respecto a 'ah_tecnicos')
    if (!$matched) {
        $allTechnicians = $db->query("SELECT nombre FROM ah_tecnicos WHERE activo = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN);
        $normUserParts = array_filter(explode(' ', normalize_text($userName)));
        
        if (count($normUserParts) > 0) {
            foreach ($allTechnicians as $candidate) {
                $normCand = normalize_text($candidate);
                if ($normCand === '') continue;
                
                $allPartsMatch = true;
                foreach ($normUserParts as $part) {
                    if (!str_contains($normCand, $part)) {
                        $allPartsMatch = false;
                        break;
                    }
                }
                if ($allPartsMatch) {
                    $technicianName = trim((string)$candidate);
                    break;
                }
            }
        }
    }
} catch (Throwable $e) {}

$nameParts = preg_split('/\s+/', trim($technicianName)) ?: [];
$firstName = $nameParts[0] ?? 'Compañero';
$initials = '';
foreach (array_slice($nameParts, 0, 2) as $part) {
    if ($part !== '') $initials .= function_exists('mb_substr') ? mb_substr($part, 0, 1, 'UTF-8') : substr($part, 0, 1);
}
$initials = strtoupper($initials ?: 'AH');

// -----------------------------------------------------------------------------
// Funciones de validación mejoradas para evitar problemas con los sufijos de cargo
// -----------------------------------------------------------------------------
function row_belongs_to_technician_improved(array $row, string $technician): bool
{
    $persona = trim((string)($row['persona'] ?? ''));
    if (same_text($persona, $technician)) return true;
    
    $normPersona = normalize_text($persona);
    $normTech = normalize_text($technician);
    
    if ($normTech !== '' && str_starts_with($normPersona, $normTech)) return true;
    
    $techParts = explode(' ', $normTech);
    $allPartsMatch = true;
    foreach ($techParts as $part) {
        if ($part !== '' && !str_contains($normPersona, $part)) {
            $allPartsMatch = false;
            break;
        }
    }
    return $allPartsMatch && $normPersona !== '';
}

// -----------------------------------------------------------------------------
// Acciones AJAX seguras
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json; charset=utf-8');

    try {
        $postedCsrf = (string)($_POST['csrf_token'] ?? '');
        if (!hash_equals($csrfToken, $postedCsrf)) throw new RuntimeException('La sesión de seguridad venció. Recargue la página.');

        $action = (string)$_POST['action'];

        if ($action === 'save_assignment_achievement') {
            $assignmentId = (int)($_POST['assignment_id'] ?? 0);
            $value = clamp_value(number_value($_POST['achieved'] ?? 0), 0, 999999999);
            
            // Query mejorada para permitir sufijos (Ej: "David Ramos - Técnico")
            $stmtAuth = $db->prepare("SELECT * FROM ah_poa_asignaciones WHERE id = ? AND (LOWER(TRIM(tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%')) LIMIT 1");
            $stmtAuth->execute([$assignmentId, $technicianName, $technicianName]);
            $assignment = $stmtAuth->fetch(PDO::FETCH_ASSOC);
            
            if (!$assignment) throw new RuntimeException('La asignación no pertenece al usuario autenticado.');

            $db->beginTransaction();
            update_assignment_total($db, $assignmentId, $monthKey, $value);
            
            // Lógica de update_noncenter_stage_row simplificada directamente
            $stageStmt = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa = ? AND (orden = 3 OR codigo_etapa = 'E-3') ORDER BY CASE WHEN orden = 3 THEN 0 ELSE 1 END, id ASC LIMIT 1 FOR UPDATE");
            $stageStmt->execute([(int)$assignment['id_poa']]);
            $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);
            
            if ($stage) {
                $assignStmt = $db->prepare("SELECT base_asignada, logro_{$monthKey} AS logro_mes FROM ah_poa_asignaciones WHERE id_poa = ? AND (LOWER(TRIM(tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%'))");
                $assignStmt->execute([(int)$assignment['id_poa'], $technicianName, $technicianName]);
                $byBase = []; $total = 0.0;
                foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $asg) {
                    $val = number_value($asg['logro_mes'] ?? 0);
                    $baseKey = normalize_text($asg['base_asignada'] ?? '');
                    if ($baseKey !== '') $byBase[$baseKey] = ($byBase[$baseKey] ?? 0) + $val;
                    $total += $val;
                }
                $rows = json_array($stage['involucrados_json'] ?? '{}');
                $changed = false;
                foreach ($rows as $key => &$row) {
                    if (!is_array($row) || !row_belongs_to_technician_improved($row, $technicianName) || !row_is_current_month($row, $monthKey) || !empty($row['deleted'])) continue;
                    if (!empty($row['centros'])) continue;
                    
                    $bases = array_values(array_filter(array_map('trim', explode('|', (string)($row['base'] ?? '')))));
                    if (!$bases) { $row['cumplido'] = $total; } else {
                        $rowTotal = 0.0;
                        foreach ($bases as $base) $rowTotal += $byBase[normalize_text($base)] ?? 0.0;
                        $row['cumplido'] = $rowTotal;
                    }
                    $changed = true;
                }
                unset($row);
                if ($changed) {
                    $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                    $db->prepare("UPDATE ah_poa_etapas SET involucrados_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$json, (int)$stage['id']]);
                }
            }

            $activity = recalculate_activity($db, (int)$assignment['id_poa'], $monthKey);

            $totalStmt = $db->prepare("SELECT COALESCE(SUM(logro_{$monthKey}),0) FROM ah_poa_asignaciones WHERE id_poa = ? AND (LOWER(TRIM(tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%'))");
            $totalStmt->execute([(int)$assignment['id_poa'], $technicianName, $technicianName]);
            $technicianTotal = (float)$totalStmt->fetchColumn();
            $db->commit();

            echo json_encode(['status' => 'success', 'message' => 'Logro guardado.', 'id_poa' => (int)$assignment['id_poa'], 'technician_achieved' => $technicianTotal, 'activity' => $activity], JSON_UNESCAPED_UNICODE);
            exit;
        }

        if ($action === 'save_center_achievement') {
            $idPoa = (int)($_POST['id_poa'] ?? 0);
            $stageId = (int)($_POST['stage_id'] ?? 0);
            $rowKey = trim((string)($_POST['row_key'] ?? ''));
            $centerId = trim((string)($_POST['center_id'] ?? ''));
            $value = clamp_value(number_value($_POST['achieved'] ?? 0), 0, 999999999);
            if ($idPoa <= 0 || $stageId <= 0 || $rowKey === '' || $centerId === '') throw new RuntimeException('Datos incompletos para guardar el centro.');

            $ownership = $db->prepare("SELECT COUNT(*) FROM ah_poa_asignaciones WHERE id_poa = ? AND (LOWER(TRIM(tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%'))");
            $ownership->execute([$idPoa, $technicianName, $technicianName]);
            if ((int)$ownership->fetchColumn() <= 0) throw new RuntimeException('La actividad no pertenece al usuario autenticado.');

            $db->beginTransaction();
            $stageStmt = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id = ? AND id_poa = ? AND (orden = 3 OR codigo_etapa = 'E-3') LIMIT 1 FOR UPDATE");
            $stageStmt->execute([$stageId, $idPoa]);
            $stage = $stageStmt->fetch(PDO::FETCH_ASSOC);
            if (!$stage) throw new RuntimeException('No se encontró el detalle de centros de la actividad.');

            $rows = json_array($stage['involucrados_json'] ?? '{}');
            if (!isset($rows[$rowKey]) || !is_array($rows[$rowKey])) throw new RuntimeException('No se encontró la fila asignada al técnico.');
            if (!row_belongs_to_technician_improved($rows[$rowKey], $technicianName)) throw new RuntimeException('La fila no pertenece al usuario autenticado.');

            $centers = isset($rows[$rowKey]['centros']) && is_array($rows[$rowKey]['centros']) ? $rows[$rowKey]['centros'] : [];
            $centerKey = null;
            foreach ($centers as $key => $center) {
                $storedId = is_array($center) ? (string)($center['id'] ?? $key) : (string)$key;
                if ((string)$key === $centerId || $storedId === $centerId) { $centerKey = $key; break; }
            }
            if ($centerKey === null) throw new RuntimeException('El centro no está asignado a este técnico.');

            if (!is_array($centers[$centerKey])) $centers[$centerKey] = [];
            $centers[$centerKey]['id'] = (string)($centers[$centerKey]['id'] ?? $centerId);
            $centers[$centerKey]['cumplido'] = $value;
            $rows[$rowKey]['centros'] = $centers;
            $rows[$rowKey]['cumplido'] = array_sum(array_map(static fn($center) => is_array($center) ? number_value($center['cumplido'] ?? 0) : 0, $centers));

            $json = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) throw new RuntimeException('No fue posible convertir el detalle del centro.');

            $updateStage = $db->prepare("UPDATE ah_poa_etapas SET involucrados_json = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $updateStage->execute([$json, $stageId]);

            // Sync from centers (inline para soportar LIKE)
            $centerTotals = []; $allTotal = 0.0; $hasCenters = false;
            foreach ($rows as $r) {
                if (!is_array($r) || !row_belongs_to_technician_improved($r, $technicianName) || !row_is_current_month($r, $monthKey) || !empty($r['deleted'])) continue;
                $rc = isset($r['centros']) && is_array($r['centros']) ? $r['centros'] : [];
                if (!$rc) continue;
                $hasCenters = true;
                $rowBases = array_values(array_filter(array_map('trim', explode('|', (string)($r['base'] ?? '')))));
                foreach ($rc as $c) {
                    if (!is_array($c)) continue;
                    $achieved = number_value($c['cumplido'] ?? 0);
                    $centerBase = trim((string)($c['comunidad_base'] ?? ''));
                    if ($centerBase === '' && count($rowBases) === 1) $centerBase = $rowBases[0];
                    $baseKey = normalize_text($centerBase);
                    if ($baseKey !== '') $centerTotals[$baseKey] = ($centerTotals[$baseKey] ?? 0) + $achieved;
                    $allTotal += $achieved;
                }
            }
            $technicianTotal = 0.0;
            if ($hasCenters) {
                $assignStmt = $db->prepare("SELECT * FROM ah_poa_asignaciones WHERE id_poa = ? AND (LOWER(TRIM(tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%')) FOR UPDATE");
                $assignStmt->execute([$idPoa, $technicianName, $technicianName]);
                foreach ($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment) {
                    $base = trim((string)($assignment['base_asignada'] ?? ''));
                    $valueC = $base === '' ? $allTotal : ($centerTotals[normalize_text($base)] ?? 0.0);
                    update_assignment_total($db, (int)$assignment['id'], $monthKey, $valueC);
                }
                $technicianTotal = $allTotal;
            }

            $activity = recalculate_activity($db, $idPoa, $monthKey);
            $db->commit();

            echo json_encode(['status' => 'success', 'message' => 'Logro del centro guardado.', 'id_poa' => $idPoa, 'center_id' => $centerId, 'center_achieved' => $value, 'row_achieved' => number_value($rows[$rowKey]['cumplido'] ?? 0), 'technician_achieved' => $technicianTotal, 'activity' => $activity], JSON_UNESCAPED_UNICODE);
            exit;
        }

        throw new RuntimeException('Acción no reconocida.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// -----------------------------------------------------------------------------
// Cargar asignaciones del técnico y agruparlas por actividad
// -----------------------------------------------------------------------------
$assignmentsStmt = $db->prepare(
    "SELECT p.*, a.id AS assignment_id, a.tecnico, a.base_asignada, a.meses_asignados,
            a.meta_asignada, a.logro_asignado,
            a.meta_jul, a.meta_aug, a.meta_sep, a.meta_oct, a.meta_nov, a.meta_dec,
            a.meta_jan, a.meta_feb, a.meta_mar, a.meta_apr, a.meta_may, a.meta_jun,
            a.logro_jul, a.logro_aug, a.logro_sep, a.logro_oct, a.logro_nov, a.logro_dec,
            a.logro_jan, a.logro_feb, a.logro_mar, a.logro_apr, a.logro_may, a.logro_jun
     FROM ah_poa_asignaciones a
     INNER JOIN ah_poa p ON p.id = a.id_poa
     WHERE LOWER(TRIM(a.tecnico)) = LOWER(TRIM(?)) OR LOWER(TRIM(a.tecnico)) LIKE CONCAT(LOWER(TRIM(?)), ' -%')
     ORDER BY p.id ASC, a.base_asignada ASC, a.id ASC"
);
$assignmentsStmt->execute([$technicianName, $technicianName]);
$rawAssignments = $assignmentsStmt->fetchAll(PDO::FETCH_ASSOC);

$grouped = [];
foreach ($rawAssignments as $assignment) {
    $idPoa = (int)($assignment['id'] ?? 0);
    if ($idPoa <= 0) continue;

    $metaCurrent = number_value($assignment['meta_' . $monthKey] ?? 0);
    $achievementCurrent = number_value($assignment['logro_' . $monthKey] ?? 0);
    $globalMonth = number_value($assignment['op_act_' . $monthKey] ?? 0) + number_value($assignment['op_part_' . $monthKey] ?? 0);
    $isCurrent = $metaCurrent > 0 || $achievementCurrent > 0 || $globalMonth > 0 || month_is_assigned($assignment['meses_asignados'] ?? '', $monthKey);
    if (!$isCurrent) continue;

    if (!isset($grouped[$idPoa])) {
        $grouped[$idPoa] = [
            'poa' => $assignment,
            'assignments' => [],
            'programmed' => 0.0,
            'achieved' => 0.0,
            'bases' => [],
        ];
    }

    $grouped[$idPoa]['assignments'][] = $assignment;
    $grouped[$idPoa]['programmed'] += $metaCurrent;
    $grouped[$idPoa]['achieved'] += $achievementCurrent;
    $base = trim((string)($assignment['base_asignada'] ?? ''));
    if ($base !== '' && !in_array($base, $grouped[$idPoa]['bases'], true)) $grouped[$idPoa]['bases'][] = $base;
}

$activityIds = array_keys($grouped);
$stagesMap = [];
if ($activityIds) {
    $placeholders = implode(',', array_fill(0, count($activityIds), '?'));
    $stageQuery = $db->prepare("SELECT * FROM ah_poa_etapas WHERE id_poa IN ({$placeholders}) ORDER BY id_poa ASC, orden ASC, id ASC");
    $stageQuery->execute($activityIds);
    foreach ($stageQuery->fetchAll(PDO::FETCH_ASSOC) as $stage) {
        $stagesMap[(int)$stage['id_poa']][] = $stage;
    }
}

// Centro catalogado, utilizado como respaldo si el JSON no conserva todos sus textos.
$centerCatalog = [];
try {
    $centerRows = $db->query("SELECT id, tipo, nombre, comunidad_base, caserio, pob_total, pob_0_5, pob_6_17, pob_18_24 FROM ah_centros")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($centerRows as $center) $centerCatalog[(string)$center['id']] = $center;
} catch (Throwable $e) {
    $centerCatalog = [];
}

$activities = [];
$allCenters = [];
$summaryProgrammed = 0.0;
$summaryAchieved = 0.0;
$summaryCenters = 0;
$summaryAlerts = 0;

foreach ($grouped as $idPoa => $group) {
    $poa = $group['poa'];
    $stages = $stagesMap[$idPoa] ?? [];
    $technicianStages = [];
    $centers = [];
    $activityStagePercentages = [1 => 0.0, 2 => 0.0, 3 => 0.0, 4 => 0.0];
    $activityStageCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    $closestDeadline = null;
    $closestStageName = '';
    $evidence = [];

    foreach ($stages as $stage) {
        $order = (int)($stage['orden'] ?? 0);
        $rows = json_array($stage['involucrados_json'] ?? '{}');

        foreach ($rows as $rowKey => $row) {
            if (!is_array($row) || !empty($row['deleted']) || !row_is_current_month($row, $monthKey)) continue;

            if ($order >= 1 && $order <= 4) {
                $activityStagePercentages[$order] += row_progress($row);
                $activityStageCounts[$order]++;
            }

            if (!row_belongs_to_technician_improved($row, $technicianName)) continue;

            $rowPct = row_progress($row);
            $verifications = isset($row['verifics']) && is_array($row['verifics']) ? $row['verifics'] : [];
            foreach ($verifications as $verification) {
                $verification = trim((string)$verification);
                if ($verification !== '' && !in_array($verification, $evidence, true)) $evidence[] = $verification;
            }

            $deadline = trim((string)($stage['fecha_recepcion'] ?? ''));
            if ($deadline !== '' && ($closestDeadline === null || $deadline < $closestDeadline) && $rowPct < 100) {
                $closestDeadline = $deadline;
                $closestStageName = trim((string)($stage['nombre_etapa'] ?? $stage['codigo_etapa'] ?? 'Etapa'));
            }

            $technicianStages[] = [
                'order' => $order,
                'code' => trim((string)($stage['codigo_etapa'] ?? 'E-' . $order)),
                'name' => trim((string)($stage['nombre_etapa'] ?? 'Etapa ' . $order)),
                'deadline' => $deadline,
                'percent' => $rowPct,
                'programmed' => number_value($row['a_lograr'] ?? 0),
                'achieved' => number_value($row['cumplido'] ?? ($row['logrado'] ?? 0)),
            ];

            if ($order !== 3) continue;
            $rowCenters = isset($row['centros']) && is_array($row['centros']) ? $row['centros'] : [];
            foreach ($rowCenters as $centerKey => $center) {
                if (!is_array($center)) continue;
                $centerId = (string)($center['id'] ?? $centerKey);
                $catalog = $centerCatalog[$centerId] ?? [];
                $programmed = number_value($center['a_lograr'] ?? 0);
                if ($programmed <= 0) $programmed = number_value($catalog['pob_total'] ?? 0);
                $achieved = number_value($center['cumplido'] ?? 0);
                $centerData = [
                    'id_poa' => $idPoa,
                    'stage_id' => (int)$stage['id'],
                    'row_key' => (string)$rowKey,
                    'center_id' => $centerId,
                    'activity_code' => first_non_empty($poa, ['codigo_marco_logico','codigo_actividad','codigo'], short_code($poa['marco_logico'] ?? '')),
                    'activity_title' => first_non_empty($poa, ['descripcion_actividad','actividad','marco_logico'], 'Actividad'),
                    'type' => first_non_empty($center, ['tipo'], first_non_empty($catalog, ['tipo'], 'Centro')),
                    'name' => first_non_empty($center, ['nombre'], first_non_empty($catalog, ['nombre'], 'Centro #' . $centerId)),
                    'base' => first_non_empty($center, ['comunidad_base'], first_non_empty($catalog, ['comunidad_base'], trim((string)($row['base'] ?? '')))),
                    'caserio' => first_non_empty($center, ['caserio'], first_non_empty($catalog, ['caserio'], '')),
                    'programmed' => $programmed,
                    'achieved' => $achieved,
                    'percent' => $programmed > 0 ? min(100, round(($achieved / $programmed) * 100, 1)) : 0,
                ];
                $centers[] = $centerData;
                $allCenters[] = $centerData;
            }
        }
    }

    foreach ($activityStagePercentages as $order => $sum) {
        if ($activityStageCounts[$order] > 0) $activityStagePercentages[$order] = $sum / $activityStageCounts[$order];
    }
    $calculatedActivityPct = round(array_sum($activityStagePercentages) / 4, 0);
    $storedActivityPct = parse_activity_percent($poa['operativo_estado'] ?? '');
    $activityPct = $storedActivityPct > 0 || str_contains((string)($poa['operativo_estado'] ?? ''), '%')
        ? $storedActivityPct
        : $calculatedActivityPct;

    $centerProgrammed = array_sum(array_column($centers, 'programmed'));
    $centerAchieved = array_sum(array_column($centers, 'achieved'));
    $hasCenters = count($centers) > 0;
    $personalProgrammed = (float)$group['programmed'];
    $personalAchieved = $hasCenters ? $centerAchieved : (float)$group['achieved'];
    $personalPct = $personalProgrammed > 0 ? min(100, round(($personalAchieved / $personalProgrammed) * 100, 1)) : 0;

    $deadlineState = 'none';
    if ($closestDeadline !== null) {
        if ($closestDeadline < $today) {
            $deadlineState = 'overdue';
            $summaryAlerts++;
        } elseif ($closestDeadline <= date('Y-m-d', strtotime('+3 days'))) {
            $deadlineState = 'soon';
            $summaryAlerts++;
        } else {
            $deadlineState = 'normal';
        }
    }

    $status = display_status($activityPct, (string)($poa['operativo_estado'] ?? ''));
    $code = first_non_empty($poa, ['codigo_marco_logico','codigo_actividad','codigo'], short_code($poa['marco_logico'] ?? ''));
    $title = first_non_empty($poa, ['descripcion_actividad','actividad'], trim((string)($poa['marco_logico'] ?? 'Actividad')));
    $extension = first_non_empty($poa, ['ext','extension'], '');
    $places = json_array($poa['equipo_lugares_json'] ?? '[]');

    $activities[] = [
        'id' => $idPoa,
        'code' => $code,
        'extension' => $extension,
        'title' => $title,
        'program' => first_non_empty($poa, ['programa'], 'General'),
        'sector' => first_non_empty($poa, ['sector'], ''),
        'subsector' => first_non_empty($poa, ['sub_sector','subsector'], ''),
        'participant_type' => first_non_empty($poa, ['tipo_participante','tipo_part'], ''),
        'bases' => $group['bases'],
        'places' => $places,
        'assignments' => $group['assignments'],
        'programmed' => $personalProgrammed,
        'achieved' => $personalAchieved,
        'personal_percent' => $personalPct,
        'activity_percent' => $activityPct,
        'status' => $status,
        'global_goal' => number_value($poa['operativo_meta_obj'] ?? 0),
        'global_achieved' => number_value($poa['operativo_meta_alc'] ?? 0),
        'centers' => $centers,
        'center_programmed' => $centerProgrammed,
        'center_achieved' => $centerAchieved,
        'stages' => $technicianStages,
        'evidence' => $evidence,
        'deadline' => $closestDeadline,
        'deadline_stage' => $closestStageName,
        'deadline_state' => $deadlineState,
        'notes' => trim((string)($poa['operativo_info_adicional'] ?? $poa['operativo_obs'] ?? '')),
    ];

    $summaryProgrammed += $personalProgrammed;
    $summaryAchieved += $personalAchieved;
    $summaryCenters += count($centers);
}

usort($activities, static function (array $a, array $b): int {
    $ad = $a['deadline'] ?? '9999-12-31';
    $bd = $b['deadline'] ?? '9999-12-31';
    if ($ad === $bd) return strcmp($a['code'], $b['code']);
    return strcmp($ad, $bd);
});

$summaryPercent = $summaryProgrammed > 0 ? min(100, round(($summaryAchieved / $summaryProgrammed) * 100, 1)) : 0;

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function format_number($value): string
{
    $number = number_value($value);
    $decimals = abs($number - round($number)) < 0.00001 ? 0 : 1;
    return number_format($number, $decimals, '.', ',');
}

function spanish_date(?string $date): string
{
    if (!$date) return 'Sin fecha';
    $months = [1=>'enero',2=>'febrero',3=>'marzo',4=>'abril',5=>'mayo',6=>'junio',7=>'julio',8=>'agosto',9=>'septiembre',10=>'octubre',11=>'noviembre',12=>'diciembre'];
    $time = strtotime($date);
    if (!$time) return $date;
    return date('j', $time) . ' de ' . $months[(int)date('n', $time)] . ' de ' . date('Y', $time);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi Panel Técnico | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://unpkg.com/jquery@3.7.0/dist/jquery.min.js"></script>
<style>
:root{
    --primary:#34859b;--primary-dark:#246779;--green:#16a34a;--green-soft:#dcfce7;
    --blue-soft:#e0f2fe;--amber:#d97706;--amber-soft:#fef3c7;--red:#dc2626;
    --red-soft:#fee2e2;--ink:#172033;--muted:#64748b;--line:#dfe7ef;--canvas:#f4f7fa;
    --white:#fff;--shadow:0 10px 30px rgba(15,23,42,.07)
}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;background:var(--canvas);font-family:'Inter',sans-serif;color:var(--ink)}
button,input{font:inherit}.main-wrapper{flex:1;min-width:0;padding:28px 34px 50px;overflow:auto}
.page-shell{max-width:1580px;margin:0 auto}
.hero{display:grid;grid-template-columns:1fr auto;gap:22px;align-items:center;padding:26px 30px;border-radius:20px;background:linear-gradient(120deg,#286f82,#34859b 58%,#48aa91);color:#fff;box-shadow:0 16px 38px rgba(52,133,155,.18);position:relative;overflow:hidden}
.hero:after{content:'\f5a0';font-family:'Font Awesome 6 Free';font-weight:900;position:absolute;right:210px;bottom:-70px;font-size:230px;color:rgba(255,255,255,.055)}
.hero-main{display:flex;align-items:center;gap:20px;position:relative;z-index:1}.avatar{width:72px;height:72px;border-radius:20px;background:rgba(255,255,255,.17);border:1px solid rgba(255,255,255,.3);display:grid;place-items:center;font-size:1.65rem;font-weight:900}
.hero h1{margin:0;font-size:clamp(1.55rem,2.3vw,2.25rem);line-height:1.15}.hero p{margin:8px 0 0;opacity:.9;line-height:1.45}.month-box{position:relative;z-index:1;min-width:210px;background:rgba(255,255,255,.14);border:1px solid rgba(255,255,255,.28);border-radius:15px;padding:15px 18px;text-align:center}.month-box span{font-size:.75rem;font-weight:800;text-transform:uppercase;letter-spacing:.6px}.month-box strong{display:block;margin-top:4px;font-size:1.4rem}
.summary-grid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:14px;margin:20px 0}.summary-card{background:#fff;border:1px solid var(--line);border-radius:15px;padding:17px 18px;box-shadow:0 3px 12px rgba(15,23,42,.025)}.summary-top{display:flex;justify-content:space-between;align-items:center;gap:10px}.summary-card span{font-size:.72rem;font-weight:850;text-transform:uppercase;color:var(--muted);letter-spacing:.35px}.summary-icon{width:34px;height:34px;border-radius:10px;display:grid;place-items:center;background:var(--blue-soft);color:var(--primary)}.summary-card strong{display:block;font-size:1.65rem;margin-top:9px}.summary-card small{display:block;color:var(--muted);margin-top:4px}
.tabs{display:flex;gap:8px;margin:8px 0 18px;padding:5px;background:#e9eff4;border-radius:13px;width:max-content}.tab-btn{border:0;background:transparent;color:var(--muted);padding:10px 17px;border-radius:9px;font-weight:800;cursor:pointer}.tab-btn.active{background:#fff;color:var(--primary);box-shadow:0 2px 8px rgba(15,23,42,.08)}.tab-panel{display:none}.tab-panel.active{display:block}
.section-title{display:flex;justify-content:space-between;align-items:flex-end;gap:15px;margin:0 0 14px}.section-title h2{margin:0;font-size:1.2rem}.section-title p{margin:4px 0 0;color:var(--muted);font-size:.88rem}.autosave-pill{display:inline-flex;align-items:center;gap:7px;color:#166534;background:#effdf3;border:1px solid #bbf7d0;border-radius:999px;padding:8px 12px;font-size:.78rem;font-weight:850}
/* DISEÑO EXPANSIVO Y ELEGANTE DE LA LISTA DE ACTIVIDADES */
.activity-grid { display: flex; flex-direction: column; gap: 18px; width: 100%; max-width: 100%; }

.task-card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 16px; padding: 26px 30px; display: grid; grid-template-columns: 3.5fr 2.5fr 2.2fr 160px; gap: 28px; align-items: center; transition: all 0.3s ease; box-shadow: 0 4px 15px rgba(15, 23, 42, 0.02); position: relative; overflow: hidden; }
.task-card:hover { border-color: #bae6fd; box-shadow: 0 15px 35px rgba(52, 133, 155, 0.1); transform: translateY(-4px); }

.task-card.completed { border-left: 6px solid #16a34a; }
.task-card.progress { border-left: 6px solid #0ea5e9; }
.task-card.pending { border-left: 6px solid #cbd5e1; }

.task-main .task-title { margin: 0 0 12px; font-size: 1.15rem; font-weight: 900; line-height: 1.4; color: #0f172a; }

.task-meta { font-size: 0.88rem; display: flex; flex-direction: column; gap: 13px; color: #475569; }
.task-meta div { display: flex; align-items: flex-start; gap: 9px; line-height: 1.35; }
.task-meta i { color: var(--primary); width: 22px; font-size: 1.1rem; flex-shrink: 0; margin-top: 2px; text-align: center; }
.task-meta strong { color: #0f172a; font-weight: 800; }

.task-actions { display: flex; flex-direction: column; gap: 10px; align-items: stretch; justify-content: center; }
.btn-action.btn-detail { width: 100%; justify-content: center; padding: 11px 14px; border-radius: 10px; background: #f8fafc; color: var(--primary); border: 1px solid #cbd5e1; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.25s; font-size: .88rem; }
.btn-action.btn-detail:hover { background: var(--primary); color: #fff; border-color: var(--primary); }

.prog-badge { background: #f8fafc; color: #334155; margin-top: 5px; border-radius: 8px; font-size: 0.75rem; font-weight: 800; padding: 5px 10px; border: 1px solid #e2e8f0; display: inline-flex; align-items: center; gap: 6px; }
.code-pill { border-radius: 999px; padding: 6px 12px; display: inline-flex; align-items: center; gap: 6px; font-size: 0.75rem; font-weight: 800; border: 1px solid #e2e8f0; }
.code-pill.ml { background: #e0f2fe; color: #075985; border-color: #bae6fd; }
.code-pill.ext { background: #fef3c7; color: #92400e; border-color: #fde68a; }
.code-corner { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 12px; }

/* RESPONSIVE: Adaptarse a pantallas pequeñas */
@media(max-width: 1200px) {
    .task-card { grid-template-columns: 1fr 1fr; gap: 20px; }
    .task-actions { flex-direction: row; grid-column: 1 / -1; justify-content: flex-end; border-top: 1px solid #f1f5f9; padding-top: 15px; }
    .task-actions .badge { width: auto !important; }
    .btn-action.btn-detail { width: auto; }
}
@media(max-width: 768px) {
    .task-card { grid-template-columns: 1fr; }
}

.month-ribbon{display:flex;gap:10px;overflow-x:auto;padding:5px 0 20px;margin-bottom:10px;scrollbar-width:none}
.month-ribbon::-webkit-scrollbar{display:none}
.month-ribbon-btn{padding:9px 18px;border-radius:999px;background:#fff;border:1px solid var(--line);color:var(--muted);font-size:.85rem;font-weight:800;cursor:pointer;text-decoration:none;white-space:nowrap;transition:all .2s ease;display:flex;align-items:center;gap:6px}
.month-ribbon-btn:hover{border-color:var(--primary);color:var(--primary);background:#f0f9ff}
.month-ribbon-btn.active{background:var(--primary);color:#fff;border-color:var(--primary);box-shadow:0 4px 12px rgba(52,133,155,.25)}

.activity-card{background:#fff;border:1px solid var(--line);border-radius:17px;box-shadow:0 5px 18px rgba(15,23,42,.035);overflow:hidden}.activity-head{padding:18px 20px 14px;border-bottom:1px solid #edf1f5}.badges{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:10px}.badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:.7rem;font-weight:850;border:1px solid var(--line);background:#f8fafc;color:#475569}.badge.code{background:#e0f2fe;border-color:#bae6fd;color:#075985}.badge.ext{background:#fef3c7;border-color:#fde68a;color:#92400e}.badge.status-completed{background:var(--green-soft);color:#166534;border-color:#bbf7d0}.badge.status-progress{background:#e0f2fe;color:#075985;border-color:#bae6fd}.badge.status-pending{background:#f1f5f9;color:#475569}.badge.status-cancelled{background:var(--red-soft);color:#991b1b;border-color:#fecaca}.badge.status-rescheduled{background:#ffedd5;color:#9a3412;border-color:#fed7aa}.activity-title{margin:0;font-size:1.02rem;line-height:1.42}.activity-sub{display:flex;flex-wrap:wrap;gap:11px;margin-top:10px;color:var(--muted);font-size:.78rem}.activity-sub i{color:var(--primary)}
.activity-body{padding:16px 20px}.metric-row{display:grid;grid-template-columns:repeat(4,1fr);gap:9px}.metric{border:1px solid #e6edf3;background:#f8fafc;border-radius:11px;padding:10px;text-align:center}.metric span{display:block;font-size:.64rem;font-weight:850;color:var(--muted);text-transform:uppercase}.metric strong{display:block;margin-top:5px;font-size:1.02rem}.progress-wrap{margin-top:14px}.progress-label{display:flex;justify-content:space-between;font-size:.75rem;font-weight:800;color:#475569;margin-bottom:6px}.progress-track{height:8px;background:#e8eef3;border-radius:99px;overflow:hidden}.progress-fill{height:100%;background:linear-gradient(90deg,#34859b,#46b094);border-radius:99px;transition:width .25s ease}.activity-progress-fill{background:linear-gradient(90deg,#60a5fa,#16a34a)}
.info-strip{display:grid;grid-template-columns:1fr 1fr;gap:9px;margin-top:14px}.info-box{border:1px solid #e6edf3;border-radius:10px;padding:9px 10px;font-size:.75rem;color:#475569;min-height:48px}.info-box b{color:#1e293b}.info-box.deadline-overdue{background:#fff1f2;border-color:#fecdd3;color:#9f1239}.info-box.deadline-soon{background:#fffbeb;border-color:#fde68a;color:#92400e}.info-box i{color:var(--primary);margin-margin-right:5px}
.stage-line{display:flex;gap:6px;margin-top:13px}.stage-chip{flex:1;min-width:0;text-align:center;padding:7px 4px;border-radius:8px;background:#f1f5f9;color:#64748b;font-size:.66rem;font-weight:850}.stage-chip.done{background:#dcfce7;color:#166534}.stage-chip.doing{background:#e0f2fe;color:#075985}
.base-table{width:100%;border-collapse:collapse;margin-top:14px;font-size:.77rem}.base-table th{padding:7px 8px;color:#64748b;text-align:left;border-bottom:1px solid #e5ebf0;text-transform:uppercase;font-size:.64rem}.base-table td{padding:8px;border-bottom:1px solid #edf2f6}.base-table th:nth-child(n+2),.base-table td:nth-child(n+2){text-align:right}.readonly-number{font-weight:850}.achievement-input{width:95px;border:1px solid #cbd5e1;border-radius:7px;padding:7px 8px;text-align:right;font-weight:850;background:#fff}.achievement-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(52,133,155,.12)}.achievement-input.saving{border-color:#0ea5e9;background:#f0f9ff}.achievement-input.saved{border-color:#16a34a;background:#f0fdf4}.achievement-input.error{border-color:#dc2626;background:#fef2f2}
.activity-actions{padding:12px 20px 17px;display:flex;gap:9px}.action-btn{border:1px solid #cbd5e1;background:#fff;color:#334155;border-radius:9px;padding:9px 12px;font-weight:800;cursor:pointer;display:inline-flex;align-items:center;gap:7px}.action-btn.primary{background:var(--primary);border-color:var(--primary);color:#fff}.action-btn:hover{filter:brightness(.98)}
.centers-toolbar{display:flex;gap:10px;align-items:center;margin-bottom:12px}.search-box{position:relative;flex:1}.search-box i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:#94a3b8}.search-box input{width:100%;border:1px solid var(--line);border-radius:11px;padding:11px 13px 11px 38px;background:#fff}.search-box input:focus{outline:none;border-color:var(--primary)}.center-table-wrap{background:#fff;border:1px solid var(--line);border-radius:15px;overflow:auto;max-height:68vh;box-shadow:var(--shadow)}.center-table{width:100%;border-collapse:separate;border-spacing:0;min-width:1100px}.center-table th{position:sticky;top:0;z-index:2;background:#edf7fb;color:#075985;text-transform:uppercase;font-size:.68rem;letter-spacing:.3px;padding:11px 12px;text-align:left;border-bottom:1px solid #cfe7f0}.center-table td{padding:11px 12px;border-bottom:1px solid #edf2f6;font-size:.8rem;vertical-align:middle}.center-table tbody tr:hover td{background:#fafcfd}.center-name{font-weight:850;color:#172033}.center-meta{color:var(--muted);font-size:.7rem;margin-top:3px}.center-programmed{font-weight:900}.center-achieved-input{width:105px;border:1px solid #cbd5e1;border-radius:8px;padding:8px;text-align:right;font-weight:900}.center-achieved-input:focus{outline:none;border-color:var(--primary);box-shadow:0 0 0 3px rgba(52,133,155,.12)}.save-state{font-size:.7rem;font-weight:800;color:#64748b;white-space:nowrap}.save-state.saving{color:#0284c7}.save-state.saved{color:#15803d}.save-state.error{color:#b91c1c}.pct-pill{display:inline-flex;min-width:60px;justify-content:center;border-radius:999px;padding:5px 9px;font-size:.72rem;font-weight:900;background:#f1f5f9;color:#64748b}.pct-pill.mid{background:#fef3c7;color:#92400e}.pct-pill.good{background:#dcfce7;color:#166534}
.empty{grid-column:1/-1;background:#fff;border:2px dashed #cbd5e1;border-radius:16px;padding:55px 25px;text-align:center;color:#64748b}.empty i{font-size:2.7rem;color:#cbd5e1}.empty h3{color:#334155;margin:12px 0 5px}.toast{position:fixed;right:24px;bottom:24px;z-index:9999;display:none;max-width:420px;padding:13px 16px;border-radius:10px;color:#fff;font-size:.83rem;font-weight:750;box-shadow:0 12px 30px rgba(15,23,42,.22)}.toast.success{background:#15803d}.toast.error{background:#b91c1c}
@media(max-width:1200px){.summary-grid{grid-template-columns:repeat(3,1fr)}.metric-row{grid-template-columns:repeat(2,1fr)}}
@media(max-width:760px){.main-wrapper{padding:16px}.hero{grid-template-columns:1fr;padding:22px}.month-box{text-align:left}.summary-grid{grid-template-columns:1fr 1fr}.info-strip{grid-template-columns:1fr}.tabs{width:100%}.tab-btn{flex:1}.hero:after{display:none}}
/* ESTILOS DE TARJETAS COMPACTAS TIPO MONITOREO Y MODALES */
.task-card{background:#fff;border:1px solid var(--line);border-radius:12px;padding:22px;display:grid;grid-template-columns:3fr 2.5fr 2.5fr auto;gap:20px;align-items:center;transition:.2s}
.task-card:hover{border-color:#cbd5e1;box-shadow:0 8px 20px rgba(0,0,0,.06);transform:translateY(-2px)}
.task-card.completed{border-left:6px solid var(--green)}
.task-card.pending{border-left:6px solid #cbd5e1}
.task-card.progress{border-left:6px solid var(--blue-soft)}
.task-main .task-title{margin:0 0 8px;font-size:1.1rem;line-height:1.4}
.task-meta{font-size:.9rem;display:flex;flex-direction:column;gap:8px}
.task-meta i{color:var(--primary);width:18px;text-align:center}
.prog-badge, .code-pill{display:inline-flex;align-items:center;gap:6px;border-radius:8px;font-size:.75rem;font-weight:800;padding:4px 8px;border:1px solid #e2e8f0}
.prog-badge{background:#f1f5f9;color:#334155;margin-top:5px}
.code-corner{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.code-pill{border-radius:999px;padding:6px 10px}
.code-pill.ml{background:#e0f2fe;color:#075985;border-color:#bae6fd}
.code-pill.ext{background:#fef3c7;color:#92400e;border-color:#fde68a}

.modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.7);z-index:1000;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);padding:20px}
.modal-content{background:white;width:96%;max-width:850px;border-radius:16px;display:flex;flex-direction:column;height:auto;max-height:95vh;box-shadow:0 25px 50px -12px rgba(0,0,0,.25);overflow:hidden}
.modal-header{display:flex;justify-content:space-between;align-items:center;padding:18px 26px;border-bottom:1px solid var(--line);flex-shrink:0}
.modal-body{padding:22px 26px;overflow-y:auto;flex-grow:1;}
.modal-footer{padding:14px 26px;border-top:1px solid var(--line);background:#f8fafc;text-align:right;flex-shrink:0;display:flex;align-items:center;justify-content:space-between;}

@media(max-width: 1100px) {
    .task-card { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main-wrapper">
<div class="page-shell">
    <section class="hero">
        <div class="hero-main">
            <div class="avatar"><?php echo h($initials); ?></div>
            <div>
                <h1>Hola, <?php echo h($firstName); ?></h1>
                <p>Esta es tu agenda operativa. Revisa lo programado, las fechas clave y actualiza únicamente los logros de tus actividades y centros.</p>
            </div>
        </div>
        <div class="month-box">
            <span>Mes en curso</span>
            <strong><?php echo h($monthName . ' ' . date('Y')); ?></strong>
        </div>
    </section>

    <section class="summary-grid" id="summaryGrid">
        <div class="summary-card"><div class="summary-top"><span>Actividades del mes</span><div class="summary-icon"><i class="fa-solid fa-list-check"></i></div></div><strong id="sumActivities"><?php echo count($activities); ?></strong><small>Asignadas a tu usuario</small></div>
        <div class="summary-card"><div class="summary-top"><span>Programado</span><div class="summary-icon"><i class="fa-solid fa-bullseye"></i></div></div><strong id="sumProgrammed"><?php echo format_number($summaryProgrammed); ?></strong><small>Meta personal del mes</small></div>
        <div class="summary-card"><div class="summary-top"><span>Logrado</span><div class="summary-icon"><i class="fa-solid fa-check-double"></i></div></div><strong id="sumAchieved"><?php echo format_number($summaryAchieved); ?></strong><small>Actualizado por ti</small></div>
        <div class="summary-card"><div class="summary-top"><span>Avance personal</span><div class="summary-icon"><i class="fa-solid fa-chart-line"></i></div></div><strong id="sumPercent"><?php echo h($summaryPercent); ?>%</strong><small>Logrado / Programado</small></div>
        <div class="summary-card"><div class="summary-top"><span>Centros asignados</span><div class="summary-icon"><i class="fa-solid fa-building-columns"></i></div></div><strong id="sumCenters"><?php echo $summaryCenters; ?></strong><small><?php echo $summaryAlerts > 0 ? h($summaryAlerts . ' fecha(s) requieren atención') : 'Sin alertas inmediatas'; ?></small></div>
    </section>

    <!-- CINTILLO DE MESES -->
    <div class="month-ribbon">
        <?php foreach ($monthMap as $m): ?>
            <a href="?mes=<?php echo $m['key']; ?>" class="month-ribbon-btn <?php echo $m['key'] === $monthKey ? 'active' : ''; ?>">
                <i class="fa-regular fa-calendar"></i> <?php echo h($m['name']); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <nav class="tabs" aria-label="Secciones del panel">
        <button type="button" class="tab-btn active" data-tab="agenda"><i class="fa-solid fa-calendar-check"></i> Agenda del mes</button>
        <button type="button" class="tab-btn" data-tab="centers"><i class="fa-solid fa-building"></i> Mis centros <span id="centerTabCount">(<?php echo $summaryCenters; ?>)</span></button>
    </nav>

    <section id="tab-agenda" class="tab-panel active">
        <div class="section-title">
            <div><h2>Actividades que debes atender en <?php echo h($monthName); ?></h2><p>La programación es de solo lectura. Los campos de Logrado se guardan automáticamente.</p></div>
            <div class="autosave-pill"><i class="fa-solid fa-cloud-arrow-up"></i> Guardado automático activo</div>
        </div>

        <div class="activity-grid">
        <?php if (!$activities): ?>
            <div class="empty"><i class="fa-solid fa-mug-hot"></i><h3>No tienes actividades programadas este mes</h3><p>Cuando se te asigne una actividad para <?php echo h($monthName); ?> aparecerá en este panel.</p></div>
        <?php endif; ?>

        <?php foreach ($activities as $activity): 
            $estado_actual = $activity['status']['text'] ?? 'Pendiente';
            $card_class = ($estado_actual === 'Completada') ? 'completed' : (($estado_actual === 'En proceso') ? 'progress' : 'pending');
        ?>
            <!-- TARJETA COMPACTA EXPANSIVA -->
            <article class="task-card activity-card <?php echo $card_class; ?>" id="activity-<?php echo (int)$activity['id']; ?>" data-id-poa="<?php echo (int)$activity['id']; ?>" data-programmed="<?php echo h($activity['programmed']); ?>" data-achieved="<?php echo h($activity['achieved']); ?>">
                
                <div class="task-main">
                    <div class="code-corner">
                        <?php if ($activity['code'] !== ''): ?><span class="code-pill ml"><i class="fa-solid fa-hashtag"></i> <?php echo h($activity['code']); ?></span><?php endif; ?>
                        <?php if ($activity['extension'] !== ''): ?><span class="code-pill ext"><i class="fa-solid fa-code-branch"></i> EXT <?php echo h($activity['extension']); ?></span><?php endif; ?>
                    </div>
                    <h3 class="task-title"><?php echo h($activity['title']); ?></h3>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <span class="prog-badge"><i class="fa-solid fa-layer-group"></i> <?php echo h($activity['program']); ?></span>
                        <?php if ($activity['sector'] !== ''): ?><span class="prog-badge" style="background:#e0f2fe;color:#0284c7;border-color:#bae6fd;"><i class="fa-solid fa-tag"></i> <?php echo h($activity['sector']); ?></span><?php endif; ?>
                    </div>
                </div>

                <div class="task-meta">
                    <div><i class="fa-solid fa-map-pin"></i> <span>Base(s): <strong><?php echo h(implode(', ', $activity['bases']) ?: 'General'); ?></strong></span></div>
                    <div><i class="fa-regular fa-calendar"></i> <span>Próxima fecha: <strong><?php echo h($activity['deadline'] ? spanish_date($activity['deadline']) : 'Sin fecha'); ?></strong></span></div>
                    <?php if ($activity['participant_type'] !== ''): ?><div><i class="fa-solid fa-people-group"></i> <span>Público: <strong><?php echo h($activity['participant_type']); ?></strong></span></div><?php endif; ?>
                </div>

                <div class="task-meta">
                    <div><i class="fa-solid fa-bullseye" style="color:#0ea5e9"></i> <span>Mi Programado: <strong style="color:#0284c7"><?php echo format_number($activity['programmed']); ?></strong></span></div>
                    <div><i class="fa-solid fa-check-double" style="color:#16a34a"></i> <span>Mi Logrado: <strong class="activity-achieved-value" style="color:#166534"><?php echo format_number($activity['achieved']); ?></strong></span></div>
                    <div><i class="fa-solid fa-chart-line" style="color:#8b5cf6"></i> <span>Avance personal: <strong class="personal-pct-value" style="color:#5b21b6"><?php echo h($activity['personal_percent']); ?>%</strong></span></div>
                </div>

                <div class="task-actions">
                    <span class="badge status-<?php echo h($activity['status']['class']); ?> activity-status" style="width:100%;justify-content:center;padding:10px 0;font-size:0.8rem;"><?php echo h($activity['status']['text']); ?></span>
                    <button type="button" class="btn-action btn-detail" onclick="$('#modal-<?php echo (int)$activity['id']; ?>').css('display','flex')">
                        <i class="fa-solid fa-pen-to-square"></i> Detallar
                    </button>
                </div>
            </article>

            <!-- VENTANA MODAL (Oculta por defecto) -->
            <div class="modal-overlay" id="modal-<?php echo (int)$activity['id']; ?>">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2 style="margin:0;font-size:1.25rem;color:var(--primary-dark)"><i class="fa-solid fa-list-check"></i> Detalle de Ejecución</h2>
                        <button type="button" onclick="$('#modal-<?php echo (int)$activity['id']; ?>').hide()" style="background:none;border:0;font-size:1.45rem;cursor:pointer;color:#64748b;transition:0.2s" onmouseover="this.style.color='#ef4444'" onmouseout="this.style.color='#64748b'"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                    
                    <div class="modal-body">
                        <h3 style="margin-top:0;margin-bottom:15px;font-size:1.15rem;line-height:1.4;color:var(--ink)"><?php echo h($activity['title']); ?></h3>
                        
                        <div class="activity-body" style="padding:0">
                            <div class="metric-row">
                                <div class="metric"><span>Mi programado</span><strong><?php echo format_number($activity['programmed']); ?></strong></div>
                                <div class="metric"><span>Mi logrado</span><strong class="activity-achieved-value"><?php echo format_number($activity['achieved']); ?></strong></div>
                                <div class="metric"><span>Mi avance</span><strong class="personal-pct-value"><?php echo h($activity['personal_percent']); ?>%</strong></div>
                                <div class="metric"><span>Avance actividad</span><strong class="activity-pct-value"><?php echo h($activity['activity_percent']); ?>%</strong></div>
                            </div>

                            <div class="progress-wrap">
                                <div class="progress-label"><span>Mi cumplimiento mensual</span><span class="personal-pct-label"><?php echo h($activity['personal_percent']); ?>%</span></div>
                                <div class="progress-track"><div class="progress-fill personal-progress-fill" style="width:<?php echo h($activity['personal_percent']); ?>%"></div></div>
                            </div>
                            <div class="progress-wrap">
                                <div class="progress-label"><span>Progreso general de la actividad</span><span class="activity-pct-label"><?php echo h($activity['activity_percent']); ?>%</span></div>
                                <div class="progress-track"><div class="progress-fill activity-progress-fill" style="width:<?php echo h($activity['activity_percent']); ?>%"></div></div>
                            </div>

                            <div class="info-strip">
                                <div class="info-box <?php echo $activity['deadline_state'] === 'overdue' ? 'deadline-overdue' : ($activity['deadline_state'] === 'soon' ? 'deadline-soon' : ''); ?>">
                                    <i class="fa-solid fa-calendar-day"></i><b>Próxima fecha:</b><br>
                                    <?php echo h($activity['deadline'] ? spanish_date($activity['deadline']) : 'Sin fecha pendiente'); ?>
                                    <?php if ($activity['deadline_stage'] !== ''): ?><div><?php echo h($activity['deadline_stage']); ?></div><?php endif; ?>
                                </div>
                                <div class="info-box"><i class="fa-solid fa-location-dot"></i><b>Base(s) y lugar(es):</b><br><?php echo h(implode(', ', $activity['bases']) ?: 'Sin base'); ?><?php if ($activity['places']): ?><div><?php echo h(implode(', ', $activity['places'])); ?></div><?php endif; ?></div>
                            </div>

                            <?php if ($activity['stages']): ?>
                            <div class="stage-line" title="Avance de tus etapas">
                                <?php foreach ($activity['stages'] as $stage): $stageClass = $stage['percent'] >= 100 ? 'done' : ($stage['percent'] > 0 ? 'doing' : ''); ?>
                                    <div class="stage-chip <?php echo $stageClass; ?>"><?php echo h($stage['code']); ?><br><?php echo h(round($stage['percent'])); ?>%</div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <table class="base-table">
                                <thead><tr><th>Base</th><th>Programado</th><th>Logrado</th></tr></thead>
                                <tbody>
                                <?php foreach ($activity['assignments'] as $assignment):
                                    $assignmentProgram = number_value($assignment['meta_' . $monthKey] ?? 0);
                                    $assignmentAchieved = number_value($assignment['logro_' . $monthKey] ?? 0);
                                ?>
                                    <tr>
                                        <td><?php echo h(trim((string)($assignment['base_asignada'] ?? '')) ?: 'General'); ?></td>
                                        <td class="readonly-number"><?php echo format_number($assignmentProgram); ?></td>
                                        <td>
                                            <?php if ($activity['centers']): ?>
                                                <span class="readonly-number assignment-derived" data-assignment-id="<?php echo (int)$assignment['assignment_id']; ?>"><?php echo format_number($assignmentAchieved); ?></span>
                                            <?php else: ?>
                                                <input type="number" min="0" step="1" class="achievement-input assignment-input" value="<?php echo h($assignmentAchieved); ?>" data-assignment-id="<?php echo (int)$assignment['assignment_id']; ?>" data-id-poa="<?php echo (int)$activity['id']; ?>" aria-label="Logrado de la base">
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>

                            <?php if ($activity['evidence']): ?><div class="info-box" style="margin-top:12px;background:#f8fafc"><i class="fa-solid fa-paperclip"></i><b>Medios de verificación:</b> <?php echo h(implode(', ', $activity['evidence'])); ?></div><?php endif; ?>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <?php if ($activity['centers']): ?>
                            <button type="button" class="action-btn primary" onclick="$('#modal-<?php echo (int)$activity['id']; ?>').hide(); openCentersForActivity(<?php echo (int)$activity['id']; ?>)">
                                <i class="fa-solid fa-building-circle-check"></i> Actualizar <?php echo count($activity['centers']); ?> centro(s)
                            </button>
                        <?php else: ?>
                            <span style="color:var(--muted);font-size:.8rem;font-weight:700;"><i class="fa-solid fa-circle-info"></i> Modifica las casillas de "Logrado" arriba.</span>
                        <?php endif; ?>
                        <button type="button" class="action-btn" onclick="$('#modal-<?php echo (int)$activity['id']; ?>').hide()">Cerrar panel</button>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
    </section>

    <section id="tab-centers" class="tab-panel">
        <div class="section-title">
            <div><h2>Centros asignados para <?php echo h($monthName); ?></h2><p>Programado es de solo lectura. Modifica Logrado y el sistema lo guarda y suma a tu actividad.</p></div>
            <div class="autosave-pill"><i class="fa-solid fa-cloud-arrow-up"></i> Guardado automático activo</div>
        </div>
        <div class="centers-toolbar">
            <div class="search-box"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="centerSearch" placeholder="Buscar actividad, centro, base o caserío..."></div>
            <button type="button" class="action-btn" id="clearCenterFilter"><i class="fa-solid fa-filter-circle-xmark"></i> Ver todos</button>
        </div>
        <div class="center-table-wrap">
            <table class="center-table">
                <thead><tr><th>Actividad</th><th>Tipo</th><th>Centro</th><th>Base / Caserío</th><th>Programado</th><th>Logrado</th><th>%</th><th>Guardado</th></tr></thead>
                <tbody id="centerTableBody">
                <?php if (!$allCenters): ?><tr><td colspan="8"><div class="empty" style="border:0"><i class="fa-solid fa-building-circle-xmark"></i><h3>No tienes centros asignados este mes</h3></div></td></tr><?php endif; ?>
                <?php foreach ($allCenters as $center): ?>
                    <tr class="center-row" data-id-poa="<?php echo (int)$center['id_poa']; ?>" data-search="<?php echo h(normalize_text($center['activity_code'] . ' ' . $center['activity_title'] . ' ' . $center['name'] . ' ' . $center['base'] . ' ' . $center['caserio'])); ?>" data-programmed="<?php echo h($center['programmed']); ?>">
                        <td><span class="badge code"><?php echo h($center['activity_code']); ?></span><div class="center-meta"><?php echo h($center['activity_title']); ?></div></td>
                        <td><?php echo h($center['type']); ?></td>
                        <td><div class="center-name"><?php echo h($center['name']); ?></div><div class="center-meta">ID <?php echo h($center['center_id']); ?></div></td>
                        <td><?php echo h($center['base']); ?><div class="center-meta"><?php echo h($center['caserio']); ?></div></td>
                        <td class="center-programmed"><?php echo format_number($center['programmed']); ?></td>
                        <td><input type="number" min="0" step="1" class="center-achieved-input" value="<?php echo h($center['achieved']); ?>" data-id-poa="<?php echo (int)$center['id_poa']; ?>" data-stage-id="<?php echo (int)$center['stage_id']; ?>" data-row-key="<?php echo h($center['row_key']); ?>" data-center-id="<?php echo h($center['center_id']); ?>" aria-label="Logrado del centro"></td>
                        <td><span class="pct-pill <?php echo $center['percent'] >= 85 ? 'good' : ($center['percent'] > 0 ? 'mid' : ''); ?>"><?php echo h($center['percent']); ?>%</span></td>
                        <td><span class="save-state"><i class="fa-solid fa-cloud"></i> Sin cambios</span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </section>
</div>
</main>
<div id="toast" class="toast"></div>
<script>
const CSRF_TOKEN = <?php echo json_encode($csrfToken); ?>;
const saveTimers = new Map();

function parseNumber(value){
    const n = parseFloat(String(value ?? '').replace(/,/g,''));
    return Number.isFinite(n) ? n : 0;
}
function formatNumber(value){
    const n = parseNumber(value);
    return new Intl.NumberFormat('en-US',{maximumFractionDigits:Number.isInteger(n)?0:1}).format(n);
}
function percent(programmed, achieved){
    const p = parseNumber(programmed), a = parseNumber(achieved);
    return p > 0 ? Math.min(100, Math.round((a/p)*1000)/10) : 0;
}
function showToast(message, type='success'){
    const toast = $('#toast');
    toast.stop(true,true).removeClass('success error').addClass(type).text(message).fadeIn(120);
    setTimeout(()=>toast.fadeOut(180),2600);
}
function postData(data){
    return $.ajax({url:window.location.href,type:'POST',data:{...data,csrf_token:CSRF_TOKEN},dataType:'json',timeout:20000});
}
function setInputState(input,state){
    $(input).removeClass('saving saved error').addClass(state);
    if(state==='saved') setTimeout(()=>$(input).removeClass('saved'),1100);
}
function updateActivityCard(idPoa, achieved, activityPercent){
    const card = $(`#activity-${idPoa}`);
    const modal = $(`#modal-${idPoa}`); // Capturamos también el modal de esta actividad
    if(!card.length) return;
    
    const programmed = parseNumber(card.attr('data-programmed'));
    const personalPct = percent(programmed, achieved);
    card.attr('data-achieved', achieved);
    
    // Actualizamos textos y barras en LA TARJETA COMPACTA
    card.find('.activity-achieved-value').text(formatNumber(achieved));
    card.find('.personal-pct-value').text(personalPct+'%');
    
    // Actualizamos textos y barras en EL MODAL
    modal.find('.activity-achieved-value').text(formatNumber(achieved));
    modal.find('.personal-pct-value,.personal-pct-label').text(personalPct+'%');
    modal.find('.personal-progress-fill').css('width',personalPct+'%');
    
    if(activityPercent !== undefined){
        const ap = Math.max(0,Math.min(100,parseNumber(activityPercent)));
        
        // Actualizamos porcentajes generales
        modal.find('.activity-pct-value,.activity-pct-label').text(ap+'%');
        modal.find('.activity-progress-fill').css('width',ap+'%');
        
        // Actualizar badges (etiquetas de estado) en la tarjeta
        const badge = card.find('.activity-status');
        badge.removeClass('status-completed status-progress status-pending');
        card.removeClass('completed progress pending');

        if(ap>=100) { badge.addClass('status-completed').text('Completada'); card.addClass('completed'); }
        else if(ap>0) { badge.addClass('status-progress').text('En proceso'); card.addClass('progress'); }
        else { badge.addClass('status-pending').text('Pendiente'); card.addClass('pending'); }
    }
    updateSummary();
}

// Opcional: Cerrar cualquier modal al presionar la tecla Escape
window.addEventListener('keydown', e => {
    if (e.key === 'Escape') { $('.modal-overlay').hide(); }
});
function updateSummary(){
    let programmed=0, achieved=0;
    $('.activity-card').each(function(){
        programmed += parseNumber($(this).attr('data-programmed'));
        achieved += parseNumber($(this).attr('data-achieved'));
    });
    $('#sumProgrammed').text(formatNumber(programmed));
    $('#sumAchieved').text(formatNumber(achieved));
    $('#sumPercent').text(percent(programmed,achieved)+'%');
}

$('.tab-btn').on('click',function(){
    const tab=$(this).data('tab');
    $('.tab-btn').removeClass('active');
    $(this).addClass('active');
    $('.tab-panel').removeClass('active');
    $(`#tab-${tab}`).addClass('active');
    sessionStorage.setItem('tecnicoActiveTab', tab); // Guardar memoria de la pestaña activa
});

$(document).ready(function() {
    // Al cargar la página, recuperar la pestaña en la que estábamos trabajando
    const activeTab = sessionStorage.getItem('tecnicoActiveTab');
    if (activeTab) {
        $(`.tab-btn[data-tab="${activeTab}"]`).trigger('click');
    }
});

function openCentersForActivity(idPoa){
    $('.tab-btn[data-tab="centers"]').trigger('click');
    $('#centerSearch').val('');
    $('.center-row').each(function(){
        $(this).toggle(String($(this).data('id-poa'))===String(idPoa));
    });
    document.getElementById('tab-centers')?.scrollIntoView({behavior:'smooth',block:'start'});
}
$('#clearCenterFilter').on('click',function(){ $('#centerSearch').val(''); $('.center-row').show(); });
$('#centerSearch').on('input',function(){
    const query=String($(this).val()||'').toLowerCase().trim();
    $('.center-row').each(function(){ $(this).toggle(!query || String($(this).data('search')||'').includes(query)); });
});

$(document).on('input','.assignment-input',function(){
    const input=this;
    setInputState(input,'saving');
    const key='assignment-'+$(input).data('assignment-id');
    clearTimeout(saveTimers.get(key));
    saveTimers.set(key,setTimeout(()=>{
        postData({action:'save_assignment_achievement',assignment_id:$(input).data('assignment-id'),achieved:$(input).val()})
        .done(resp=>{
            if(resp.status!=='success') throw new Error(resp.message||'No se pudo guardar.');
            setInputState(input,'saved');
            updateActivityCard(resp.id_poa,resp.technician_achieved,resp.activity?.activity_percent);
        })
        .fail(xhr=>{setInputState(input,'error');showToast(xhr.responseJSON?.message||'No se pudo guardar el logro.','error');});
    },450));
});

$(document).on('input','.center-achieved-input',function(){
    const input=this, row=$(input).closest('tr');
    const programmed=parseNumber(row.attr('data-programmed'));
    const achieved=parseNumber($(input).val());
    const pct=percent(programmed,achieved);
    row.find('.pct-pill').text(pct+'%').removeClass('mid good').addClass(pct>=85?'good':(pct>0?'mid':''));
    row.find('.save-state').removeClass('saved error').addClass('saving').html('<i class="fa-solid fa-spinner fa-spin"></i> Guardando');
    const key='center-'+$(input).data('stage-id')+'-'+$(input).data('row-key')+'-'+$(input).data('center-id');
    clearTimeout(saveTimers.get(key));
    saveTimers.set(key,setTimeout(()=>{
        postData({
            action:'save_center_achievement',id_poa:$(input).data('id-poa'),stage_id:$(input).data('stage-id'),
            row_key:$(input).data('row-key'),center_id:$(input).data('center-id'),achieved:$(input).val()
        }).done(resp=>{
            if(resp.status!=='success') throw new Error(resp.message||'No se pudo guardar.');
            row.find('.save-state').removeClass('saving error').addClass('saved').html('<i class="fa-solid fa-circle-check"></i> Guardado');
            setTimeout(()=>row.find('.save-state').removeClass('saved').html('<i class="fa-solid fa-cloud"></i> Sin cambios'),1400);
            updateActivityCard(resp.id_poa,resp.technician_achieved,resp.activity?.activity_percent);
        }).fail(xhr=>{
            row.find('.save-state').removeClass('saving saved').addClass('error').html('<i class="fa-solid fa-triangle-exclamation"></i> Error');
            showToast(xhr.responseJSON?.message||'No se pudo guardar el centro.','error');
        });
    },450));
});
</script>
</body>
</html>
