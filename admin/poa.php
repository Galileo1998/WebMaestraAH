<?php
if (PHP_SAPI !== 'cli'
    && extension_loaded('zlib')
    && !ini_get('zlib.output_compression')
    && ob_get_level() === 0
) {
    ob_start('ob_gzhandler');
}

session_start();

require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';
require_once __DIR__ . '/includes/poa_audit.php';
$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth = new Auth($db);
$isDashboardSummary = isset($_GET['dashboard_summary']);
$accessScript = $isDashboardSummary ? 'dashboard.php' : basename($_SERVER['PHP_SELF']);
$auth->requireLogin(['admin'], $accessScript);
$auth->checkAccess($accessScript, $db);

if (empty($_SESSION['poa_audit_schema_v1'])) {
    poaEnsureAuditTable($db);
    $_SESSION['poa_audit_schema_v1'] = 1;
}

$msg = "";
$tabla_poa = 'ah_poa';
$col_id = 'id';

// ========================================================
// 0. AUTO-MIGRACIÓN DE TABLA POA Y CAMPOS DE SEGUIMIENTO
// ========================================================
function addCol($db, $table, $col, $def) {
    try {
        $stmt = $db->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
        if ($stmt !== false && $stmt->rowCount() == 0) { 
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def"); 
        }
    } catch(Exception $e) { }
}


function poaColumnExists(PDO $db, string $column) {
    try {
        $st = $db->query("SHOW COLUMNS FROM `ah_poa` LIKE " . $db->quote($column));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function poaTableExists(PDO $db, string $table) {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
        $st = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        $cache[$table] = $st && $st->rowCount() > 0;
        return $cache[$table];
    } catch (Throwable $e) {
        return false;
    }
}

function getPoaEjecucionMensualCache(PDO $db, bool $clear = false) {
    static $cache = null;
    if ($clear) { $cache = null; return []; }
    if ($cache !== null) return $cache;
    $cache = [];
    if (poaTableExists($db, 'ah_poa_ejecucion_mensual')) {
        try {
            $st = $db->query('SELECT poa_hash, mes, monto FROM ah_poa_ejecucion_mensual');
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $item) {
                if (!isset($cache[$item['poa_hash']])) $cache[$item['poa_hash']] = [];
                $cache[$item['poa_hash']][$item['mes']] = (float)$item['monto'];
            }
        } catch (Throwable $e) {}
    }
    return $cache;
}

function ensurePoaExecutionStore(PDO $db) {
    // La ejecución puede funcionar sin tabla auxiliar: las compras autorizadas
    // se leen directamente y los ajustes manuales se conservan en JSON.
    return true;
}

function poaStorageFilePath(string $filename): string {
    static $paths = [];
    if (isset($paths[$filename])) return $paths[$filename];
    $base = dirname(__DIR__);
    foreach ([$base . '/storage', $base . '/data', __DIR__] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $paths[$filename] = rtrim($dir, '/\\') . '/' . $filename;
            return $paths[$filename];
        }
    }
    return $paths[$filename] = '';
}

function poaReadJsonFile(string $filename): array {
    $path = poaStorageFilePath($filename);
    if ($path === '' || !is_file($path)) return [];
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function poaWriteJsonFile(string $filename, array $data): bool {
    $path = poaStorageFilePath($filename);
    if ($path === '') return false;
    $handle = @fopen($path, 'c+');
    if (!$handle) return false;
    try {
        if (!@flock($handle, LOCK_EX)) return false;
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) return false;
        rewind($handle);
        ftruncate($handle, 0);
        $ok = fwrite($handle, $encoded) !== false;
        fflush($handle);
        @flock($handle, LOCK_UN);
        return $ok;
    } finally {
        fclose($handle);
    }
}

function poaNormalizeMonth(string $month): string {
    $month = strtolower(trim($month));
    $valid = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    return in_array($month, $valid, true) ? $month : 'jul';
}

function poaMonthFromDate(string $date): string {
    $timestamp = strtotime($date ?: date('Y-m-d'));
    $n = $timestamp ? (int)date('n', $timestamp) : (int)date('n');
    $map = [1=>'jan',2=>'feb',3=>'mar',4=>'apr',5=>'may',6=>'jun',7=>'jul',8=>'aug',9=>'sep',10=>'oct',11=>'nov',12=>'dec'];
    return $map[$n] ?? 'jul';
}

function poaColumnExistsGeneric(PDO $db, string $table, string $column): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function poaCompraMonthMap(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;
    $cache = [];
    if (poaTableExists($db, 'ah_compras') && poaColumnExistsGeneric($db, 'ah_compras', 'mes_ejecucion')) {
        try {
            $st = $db->query("SELECT id, mes_ejecucion FROM ah_compras WHERE mes_ejecucion IS NOT NULL AND mes_ejecucion<>''");
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[(string)$row['id']] = poaNormalizeMonth((string)$row['mes_ejecucion']);
            }
        } catch (Throwable $e) {}
    }
    foreach (poaReadJsonFile('compras_meses_ejecucion_store.json') as $id => $entry) {
        $value = is_array($entry) ? ($entry['mes'] ?? '') : $entry;
        if (trim((string)$value) !== '') $cache[(string)$id] = poaNormalizeMonth((string)$value);
    }
    return $cache;
}

function poaNormalizeLineCode(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $parts = preg_split('/\s+/', $value);
    $code = strtoupper(trim((string)($parts[0] ?? $value)));
    return preg_replace('/[^A-Z0-9._-]+/', '', $code);
}

function poaNormalizeExtensionKey(string $value): string {
    $value = strtoupper(trim($value));
    return preg_replace('/[^A-Z0-9._-]+/', '', $value);
}

function poaNormalizeAccountKey(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '') return '';
    // La parte inicial identifica la cuenta aun cuando la descripción cambie.
    $parts = preg_split('/\s+-\s+/', $value, 2);
    $head = trim((string)($parts[0] ?? $value));
    return preg_replace('/[^A-Z0-9._-]+/', '', $head);
}

function poaCodeExtKey(string $marcoLogico, string $extension): string {
    return poaNormalizeLineCode($marcoLogico) . '|' . poaNormalizeExtensionKey($extension);
}

function poaFullLineKey(string $marcoLogico, string $extension, string $account): string {
    return poaCodeExtKey($marcoLogico, $extension) . '|' . poaNormalizeAccountKey($account);
}

/**
 * Índice de afectaciones provenientes del módulo de compras.
 *
 * - pending: líneas ya imputadas/reservadas, todavía pendientes de autorización.
 * - authorized: compras autorizadas o recibidas en almacén.
 *
 * La coincidencia prioriza poa_hash. Si el POA fue sincronizado nuevamente y
 * cambió el hash, usa código del marco lógico + extensión + cuenta contable.
 */
function poaPurchaseExecutionIndex(PDO $db): array {
    static $cache = null;
    if ($cache !== null) return $cache;

    $emptyBucket = ['hash'=>[], 'full'=>[], 'code_ext'=>[], 'details'=>[]];
    $cache = ['pending'=>$emptyBucket, 'authorized'=>$emptyBucket];

    if (!poaTableExists($db, 'ah_compras') || !poaTableExists($db, 'ah_compras_poa')) {
        return $cache;
    }

    try {
        $hasCpMonth = poaColumnExistsGeneric($db, 'ah_compras_poa', 'mes_ejecucion');
        $hasPurchaseMonth = poaColumnExistsGeneric($db, 'ah_compras', 'mes_ejecucion');
        $cpMonthSelect = $hasCpMonth ? ', cp.mes_ejecucion AS cp_mes' : ", '' AS cp_mes";
        $purchaseMonthSelect = $hasPurchaseMonth ? ', c.mes_ejecucion AS compra_mes' : ", '' AS compra_mes";

        $sql = "SELECT cp.id AS compra_poa_id, cp.compra_id, cp.poa_hash, cp.marco_logico,
                       cp.ext, cp.cuenta_contable, cp.monto, c.fecha, c.estado
                       {$cpMonthSelect}{$purchaseMonthSelect}
                FROM ah_compras_poa cp
                INNER JOIN ah_compras c ON c.id = cp.compra_id
                WHERE c.estado IN (
                    '3_Transferencia','4_Imputacion',
                    '4_Autorizada','5_Autorizada','5_Almacen','6_Almacen'
                )";
        $rows = $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $monthMap = poaCompraMonthMap($db);

        foreach ($rows as $row) {
            $state = (string)($row['estado'] ?? '');
            $bucketName = in_array($state, ['3_Transferencia','4_Imputacion'], true)
                ? 'pending'
                : 'authorized';

            $purchaseId = (string)($row['compra_id'] ?? '');
            $rowMonth = trim((string)($row['cp_mes'] ?? ''));
            if ($rowMonth === '') $rowMonth = trim((string)($row['compra_mes'] ?? ''));
            if ($rowMonth === '') $rowMonth = (string)($monthMap[$purchaseId] ?? '');
            if ($rowMonth === '') $rowMonth = poaMonthFromDate((string)($row['fecha'] ?? ''));
            $month = poaNormalizeMonth($rowMonth);

            $amount = round((float)($row['monto'] ?? 0), 2);
            if ($amount == 0.0) continue;

            $hash = trim((string)($row['poa_hash'] ?? ''));
            $fullKey = poaFullLineKey(
                (string)($row['marco_logico'] ?? ''),
                (string)($row['ext'] ?? ''),
                (string)($row['cuenta_contable'] ?? '')
            );
            $codeExtKey = poaCodeExtKey(
                (string)($row['marco_logico'] ?? ''),
                (string)($row['ext'] ?? '')
            );

            foreach ([['hash',$hash], ['full',$fullKey], ['code_ext',$codeExtKey]] as $indexInfo) {
                $indexName = $indexInfo[0];
                $indexKey = $indexInfo[1];
                if ($indexKey === '' || $indexKey === '|' || $indexKey === '||') continue;
                if (!isset($cache[$bucketName][$indexName][$indexKey])) {
                    $cache[$bucketName][$indexName][$indexKey] = [];
                }
                if (!isset($cache[$bucketName][$indexName][$indexKey][$month])) {
                    $cache[$bucketName][$indexName][$indexKey][$month] = 0.0;
                }
                $cache[$bucketName][$indexName][$indexKey][$month] += $amount;
            }

            $detailKey = $hash !== '' ? 'H:' . $hash : 'F:' . $fullKey;
            if (!isset($cache[$bucketName]['details'][$detailKey])) {
                $cache[$bucketName]['details'][$detailKey] = [];
            }
            if (!isset($cache[$bucketName]['details'][$detailKey][$month])) {
                $cache[$bucketName]['details'][$detailKey][$month] = [];
            }
            $cache[$bucketName]['details'][$detailKey][$month][] = [
                'compra_id'=>(int)($row['compra_id'] ?? 0),
                'monto'=>$amount,
                'estado'=>$state
            ];
        }
    } catch (Throwable $e) {
        // El POA continúa disponible aunque el componente de compras no pueda leerse.
    }

    return $cache;
}

function poaPurchaseExecutionForRow(PDO $db, string $hash, array $row): array {
    $index = poaPurchaseExecutionIndex($db);
    $fullKey = poaFullLineKey(
        (string)($row['marco_logico'] ?? ''),
        (string)($row['ext'] ?? ''),
        (string)($row['cuenta_contable'] ?? '')
    );
    $codeExtKey = poaCodeExtKey(
        (string)($row['marco_logico'] ?? ''),
        (string)($row['ext'] ?? '')
    );
    $codeExtUnique = !empty($row['_poa_code_ext_unique']);

    $result = ['pending'=>[], 'authorized'=>[], 'source'=>'none'];
    foreach (['pending','authorized'] as $bucketName) {
        $values = [];
        if ($hash !== '' && isset($index[$bucketName]['hash'][$hash])) {
            $values = $index[$bucketName]['hash'][$hash];
            $result['source'] = 'hash';
        } elseif ($fullKey !== '' && isset($index[$bucketName]['full'][$fullKey])) {
            $values = $index[$bucketName]['full'][$fullKey];
            if ($result['source'] === 'none') $result['source'] = 'marco_ext_cuenta';
        } elseif ($codeExtUnique && $codeExtKey !== '' && isset($index[$bucketName]['code_ext'][$codeExtKey])) {
            $values = $index[$bucketName]['code_ext'][$codeExtKey];
            if ($result['source'] === 'none') $result['source'] = 'marco_ext';
        }
        $result[$bucketName] = $values;
    }
    return $result;
}

function poaManualExecutionStore(bool $reload = false): array {
    if ($reload || !isset($GLOBALS['poa_manual_execution_cache'])) {
        $GLOBALS['poa_manual_execution_cache'] = poaReadJsonFile('poa_ejecucion_manual_store.json');
    }
    return is_array($GLOBALS['poa_manual_execution_cache']) ? $GLOBALS['poa_manual_execution_cache'] : [];
}

function poaQueueManualExecution(string $hash, string $month, float $delta): void {
    $month = poaNormalizeMonth($month);
    $data = poaManualExecutionStore();
    if (!isset($data[$hash]) || !is_array($data[$hash])) $data[$hash] = [];
    $data[$hash][$month] = ['delta'=>round($delta,2),'updated_at'=>date('c')];
    $GLOBALS['poa_manual_execution_cache'] = $data;
    $GLOBALS['poa_manual_execution_dirty'] = true;
}

function poaFlushManualExecutionStore(): void {
    if (empty($GLOBALS['poa_manual_execution_dirty'])) return;
    if (!poaWriteJsonFile('poa_ejecucion_manual_store.json', poaManualExecutionStore())) {
        throw new Exception('No fue posible guardar el ajuste mensual. Revise permisos de storage/data.');
    }
    $GLOBALS['poa_manual_execution_dirty'] = false;
}

function poaExecutionColumnMap(PDO $db) {
    static $map = null;
    if ($map !== null) return $map;
    $map = [];
    foreach (['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'] as $m) {
        $map[$m] = poaColumnExists($db, 'eje_' . $m);
    }
    return $map;
}

function poaExecutionBaseValue(PDO $db, string $hash, string $month, array $row = []): float {
    $purchase = poaPurchaseExecutionForRow($db, $hash, $row);
    $month = poaNormalizeMonth($month);
    return round(
        (float)($purchase['authorized'][$month] ?? 0)
        + (float)($purchase['pending'][$month] ?? 0),
        2
    );
}

function poaPendingExecutionValue(PDO $db, string $hash, string $month, array $row = []): float {
    $purchase = poaPurchaseExecutionForRow($db, $hash, $row);
    return round((float)($purchase['pending'][poaNormalizeMonth($month)] ?? 0), 2);
}

function poaAuthorizedExecutionValue(PDO $db, string $hash, string $month, array $row = []): float {
    $purchase = poaPurchaseExecutionForRow($db, $hash, $row);
    return round((float)($purchase['authorized'][poaNormalizeMonth($month)] ?? 0), 2);
}

function poaSetExecution(PDO $db, string $hash, string $month, float $value) {
    $month = poaNormalizeMonth($month);
    if (!is_finite($value) || $value < 0) throw new Exception('El valor ejecutado debe ser un número mayor o igual a cero.');
    $stRow = $db->prepare('SELECT * FROM ah_poa WHERE hash_id=? LIMIT 1 FOR UPDATE');
    $stRow->execute([$hash]);
    $row = $stRow->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('No se encontró la línea del POA.');

    $pending = poaPendingExecutionValue($db, $hash, $month, $row);
    $authorized = poaAuthorizedExecutionValue($db, $hash, $month, $row);
    $automaticMinimum = round($pending + $authorized, 2);
    if ($value + 0.005 < $automaticMinimum) {
        throw new Exception('Esta celda ya incluye L. '.number_format($automaticMinimum,2).' provenientes de Compras. Ingrese un total igual o superior a ese monto.');
    }
    $columns = poaExecutionColumnMap($db);

    if (!empty($columns[$month])) {
        $storedValue = round(max(0, $value - $pending), 2);
        $db->prepare("UPDATE ah_poa SET `eje_{$month}`=? WHERE hash_id=?")->execute([$storedValue, $hash]);
        return round($value, 2);
    }

    if (poaTableExists($db, 'ah_poa_ejecucion_mensual')) {
        $storedValue = round(max(0, $value - $pending), 2);
        $db->prepare(
            'INSERT INTO ah_poa_ejecucion_mensual (poa_hash,mes,monto) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE monto=VALUES(monto), updated_at=CURRENT_TIMESTAMP'
        )->execute([$hash, $month, $storedValue]);
        getPoaEjecucionMensualCache($db, true); // Actualizar caché
        return round($value, 2);
    }

    poaQueueManualExecution($hash, $month, round($value - ($authorized + $pending), 2));
    return round($value, 2);
}

function poaExecutionBreakdown(PDO $db, string $hash, string $month, array $row = []): array {
    $month = poaNormalizeMonth($month);
    if (!$row) {
        $st = $db->prepare('SELECT * FROM ah_poa WHERE hash_id=? LIMIT 1');
        $st->execute([$hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    if (!$row) throw new Exception('No se encontró la línea del POA.');

    $values = poaExecutionValues($db, $hash, $row);
    $pending = poaPendingExecutionValue($db, $hash, $month, $row);
    $authorized = poaAuthorizedExecutionValue($db, $hash, $month, $row);
    $total = round((float)($values[$month] ?? 0), 2);
    return [
        'total' => $total,
        'manual' => round(max(0, $total - $pending - $authorized), 2),
        'authorized' => round($authorized, 2),
        'pending' => round($pending, 2),
        'poa_nombre' => (string)($row['nombre_poa'] ?? ''),
    ];
}
function poaExecutionValues(PDO $db, string $hash, array $row = []) {
    $months = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
    $columns = poaExecutionColumnMap($db);
    $hasTable = poaTableExists($db, 'ah_poa_ejecucion_mensual');
    
    $tableValues = [];
    if ($hasTable) {
        $allTableValues = getPoaEjecucionMensualCache($db);
        $tableValues = $allTableValues[$hash] ?? [];
    }

    $purchase = poaPurchaseExecutionForRow($db, $hash, $row);
    $manual = poaManualExecutionStore();
    $values = [];
    $row['_purchase_pending'] = [];
    $row['_purchase_authorized'] = [];

    foreach ($months as $month) {
        $pending = (float)($purchase['pending'][$month] ?? 0);
        $authorized = (float)($purchase['authorized'][$month] ?? 0);

        if (!empty($columns[$month])) {
            $stored = (float)($row['eje_'.$month] ?? 0);
            $values[$month] = round(max($stored, $authorized) + $pending, 2);
        } elseif ($hasTable) {
            $stored = (float)($tableValues[$month] ?? 0);
            $values[$month] = round(max($stored, $authorized) + $pending, 2);
        } else {
            $delta = (float)($manual[$hash][$month]['delta'] ?? $manual[$hash][$month] ?? 0);
            $values[$month] = round($authorized + $pending + $delta, 2);
        }

        $row['_purchase_pending'][$month] = $pending;
        $row['_purchase_authorized'][$month] = $authorized;
    }
    return $values;
}

function poaRecalcExecuted(PDO $db, string $hash) {
    $st = $db->prepare('SELECT * FROM ah_poa WHERE hash_id=? LIMIT 1');
    $st->execute([$hash]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new Exception('No se encontró la línea del POA.');
    $total = array_sum(poaExecutionValues($db, $hash, $row));
    $db->prepare('UPDATE ah_poa SET ejecutado=? WHERE hash_id=?')->execute([round($total,2), $hash]);
    return round($total,2);
}

function poaHydrateExecutionRows(PDO $db, array $rows) {
    $codeExtCounts = [];
    foreach ($rows as $rowCount) {
        $key = poaCodeExtKey(
            (string)($rowCount['marco_logico'] ?? ''),
            (string)($rowCount['ext'] ?? '')
        );
        if ($key !== '' && $key !== '|') {
            $codeExtCounts[$key] = ($codeExtCounts[$key] ?? 0) + 1;
        }
    }

    foreach ($rows as &$row) {
        $hash = (string)($row['hash_id'] ?? '');
        if ($hash === '') continue;
        $key = poaCodeExtKey(
            (string)($row['marco_logico'] ?? ''),
            (string)($row['ext'] ?? '')
        );
        $row['_poa_code_ext_unique'] = (($codeExtCounts[$key] ?? 0) === 1);

        $purchase = poaPurchaseExecutionForRow($db, $hash, $row);
        $row['_purchase_pending'] = $purchase['pending'];
        $row['_purchase_authorized'] = $purchase['authorized'];
        $row['_purchase_match_source'] = $purchase['source'];

        $values = poaExecutionValues($db, $hash, $row);
        $row['_manual_execution'] = [];
        foreach ($values as $month => $value) {
            $row['eje_'.$month] = $value;
            $row['_manual_execution'][$month] = round(max(
                0,
                (float)$value
                - (float)($row['_purchase_pending'][$month] ?? 0)
                - (float)($row['_purchase_authorized'][$month] ?? 0)
            ), 2);
        }
        $row['ejecutado'] = array_sum($values);
    }
    unset($row);
    return $rows;
}

function poaDashboardSummary(PDO $db): array {
    $stActive = $db->query("SELECT nombre_poa FROM ah_poa WHERE is_active=1 LIMIT 1");
    $poaName = trim((string)($stActive ? $stActive->fetchColumn() : ''));
    if ($poaName === '') {
        $stFallback = $db->query("SELECT nombre_poa FROM ah_poa ORDER BY id DESC LIMIT 1");
        $poaName = trim((string)($stFallback ? $stFallback->fetchColumn() : ''));
    }

    $rows = [];
    if ($poaName !== '') {
        $stRows = $db->prepare('SELECT * FROM ah_poa WHERE nombre_poa=? ORDER BY id ASC LIMIT 2000');
        $stRows->execute([$poaName]);
        $rows = poaHydrateExecutionRows($db, $stRows->fetchAll(PDO::FETCH_ASSOC));
    }

    $budget = 0.0;
    $executed = 0.0;
    $pendingTotal = 0.0;
    $authorizedTotal = 0.0;
    $sectorSummary = [];
    foreach ($rows as $row) {
        $rowBudget = (float)($row['presupuesto_anual'] ?? 0);
        $rowExecuted = (float)($row['ejecutado'] ?? 0);
        $budget += $rowBudget;
        $executed += $rowExecuted;
        $pendingTotal += array_sum(array_map('floatval', (array)($row['_purchase_pending'] ?? [])));
        $authorizedTotal += array_sum(array_map('floatval', (array)($row['_purchase_authorized'] ?? [])));

        $sector = trim((string)($row['sector'] ?? '')) ?: 'Sin sector';
        if (!isset($sectorSummary[$sector])) {
            $sectorSummary[$sector] = ['sector'=>$sector, 'presupuesto'=>0.0, 'ejecutado'=>0.0];
        }
        $sectorSummary[$sector]['presupuesto'] += $rowBudget;
        $sectorSummary[$sector]['ejecutado'] += $rowExecuted;
    }
    usort($sectorSummary, static function (array $a, array $b): int {
        return $b['presupuesto'] <=> $a['presupuesto'];
    });

    return [
        'ok'=>true,
        'poa'=>$poaName,
        'presupuesto'=>round($budget, 2),
        'ejecutado'=>round($executed, 2),
        'disponible'=>round($budget - $executed, 2),
        'porcentaje'=>$budget > 0 ? round(($executed / $budget) * 100, 1) : 0,
        'compras_pendientes'=>round($pendingTotal, 2),
        'compras_autorizadas'=>round($authorizedTotal, 2),
        'ejecucion_manual'=>round(max(0, $executed - $pendingTotal - $authorizedTotal), 2),
        'sectores'=>array_values($sectorSummary),
    ];
}

$poaExecutionStoreReady = ensurePoaExecutionStore($db);

if ($isDashboardSummary) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        echo json_encode(poaDashboardSummary($db), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('POA dashboard summary: ' . $e->getMessage());
        echo json_encode(['ok'=>false, 'message'=>'No fue posible calcular el resumen del POA.']);
    }
    exit;
}

$poaSchemaSessionKey = 'poa_schema_checked_20260714_v2';
if (empty($_SESSION[$poaSchemaSessionKey])) {
try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_poa (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nombre_poa VARCHAR(255) NOT NULL,
        programa TEXT, codigo_programa TEXT, sector TEXT, sub_sector TEXT,
        marco_logico TEXT, descripcion_actividad TEXT, rubro_contable TEXT, codigo_maestro TEXT,
        ext TEXT, fuente_financiamiento TEXT, cuenta_contable TEXT, participantes TEXT,
        pto_jan DECIMAL(15,2) DEFAULT 0, pto_feb DECIMAL(15,2) DEFAULT 0, pto_mar DECIMAL(15,2) DEFAULT 0,
        pto_apr DECIMAL(15,2) DEFAULT 0, pto_may DECIMAL(15,2) DEFAULT 0, pto_jun DECIMAL(15,2) DEFAULT 0,
        pto_jul DECIMAL(15,2) DEFAULT 0, pto_aug DECIMAL(15,2) DEFAULT 0, pto_sep DECIMAL(15,2) DEFAULT 0,
        pto_oct DECIMAL(15,2) DEFAULT 0, pto_nov DECIMAL(15,2) DEFAULT 0, pto_dec DECIMAL(15,2) DEFAULT 0,
        presupuesto_anual DECIMAL(15,2) DEFAULT 0,
        ejecutado DECIMAL(15,2) DEFAULT 0,
        is_active TINYINT(1) DEFAULT 0,
        hash_id VARCHAR(64) UNIQUE
    )");
    
    $meses_keys = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
    foreach($meses_keys as $m) { 
        addCol($db, 'ah_poa', 'eje_'.$m, 'DECIMAL(15,2) DEFAULT 0'); 
        addCol($db, 'ah_poa', 'uni_'.$m, 'VARCHAR(100) NULL');
        addCol($db, 'ah_poa', 'resp1_'.$m, 'VARCHAR(100) NULL');
        addCol($db, 'ah_poa', 'resp2_'.$m, 'VARCHAR(100) NULL');
        addCol($db, 'ah_poa', 'est_'.$m, 'VARCHAR(50) NULL');
        addCol($db, 'ah_poa', 'plan_'.$m, 'TEXT NULL');
        addCol($db, 'ah_poa', 'op_act_'.$m, 'DECIMAL(10,2) DEFAULT 0');
        addCol($db, 'ah_poa', 'op_part_'.$m, 'DECIMAL(10,2) DEFAULT 0');
        addCol($db, 'ah_poa', 'op_editado_'.$m, 'TINYINT(1) DEFAULT 0');
    }

    addCol($db, 'ah_poa', 'descripcion_actividad', 'TEXT NULL');
    addCol($db, 'ah_poa', 'tipo_participante', 'VARCHAR(150) NULL');
    addCol($db, 'ah_poa', 'meta_actividades', 'DECIMAL(10,2) DEFAULT 0');
    addCol($db, 'ah_poa', 'operativo_meta_obj', 'DECIMAL(10,2) DEFAULT 0');
    addCol($db, 'ah_poa', 'operativo_tecnico', "VARCHAR(100) DEFAULT 'Trabajo en Equipo'");
    addCol($db, 'ah_poa', 'operativo_comunidad', 'VARCHAR(150) NULL');
    addCol($db, 'ah_poa', 'operativo_periodo', 'VARCHAR(100) NULL');
    addCol($db, 'ah_poa', 'operativo_estado', "VARCHAR(50) DEFAULT 'Pendiente'");
    addCol($db, 'ah_poa', 'meta_actividades_alc', 'DECIMAL(10,2) DEFAULT 0');
    addCol($db, 'ah_poa', 'operativo_meta_alc', 'DECIMAL(10,2) DEFAULT 0');
    addCol($db, 'ah_poa', 'operativo_obs', 'TEXT NULL');
    addCol($db, 'ah_poa', 'operativo_meta_cualitativa', 'TEXT NULL');

    // Índice para acelerar la carga del POA sin obligar a MySQL a ordenar en /tmp.
    try {
        $idx = $db->query("SHOW INDEX FROM ah_poa WHERE Key_name = 'idx_poa_nombre_id'");
        if ($idx && $idx->rowCount() === 0) {
            $db->exec("ALTER TABLE ah_poa ADD INDEX idx_poa_nombre_id (nombre_poa(120), id)");
        }
    } catch (Throwable $ignored) {}

    $_SESSION[$poaSchemaSessionKey] = 1;
} catch(Exception $e) {}
}

$cat_unidades = ["Dirección", "Adm. Y RRHH", "Contabilidad", "Coordinación", "Patrocinio", "MEAL", "CRECER", "REDES", "Comunicaciones", "Protección", "Eficiencia Organizacional"];
$cat_responsables = ["Líder de Programa - CRECER", "Líder de Programa - REDES", "Líder de Programa - Tejiendo mi Futuro", "ET-Coordinación", "José Orlando Osorto Pérez", "Edwing Armando Lopez Esteves", "Patricia Carolina Vásquez Martínez", "William Misael Martinez", "Eduardo Hernandez Servellon", "Carlos Eduardo Martínez", "Beyquer Odan Maldonado", "Carmen Suyapa Pavón Almendarez", "Yessenia Milixa Amador", "José Adalid Funes", "David Alonzo Ramos", "Goel Garcia Alvarado", "Nubia Lisseth Medina Munguía", "Roger Neptaly Silva Sánchez", "Jeniffer Abigail Canales Flores", "Francisca Baiza García", "Alex Omar Alvarado A", "Alex Francisco Castillo", "Nancy Nicol Sevilla M", "Jhony Alfredo Amador", "Norma Waleska Hernandez", "Eliberth Fabricio Galileo García Martínez", "Jennifer Sabrina Romero", "Osterly Zamir Vásquez", "Yimi Alexander Hernandez", "Doris Rosario Chevez"];
$cat_estados = ["Realizado", "En proceso", "Reprogramado", "Incompleto", "Sin realizar"];

// ========================================================
// 1. ENDPOINTS AJAX (ACTUALIZAR)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'refresh_compras_eje') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $poaName = trim((string)($_POST['poa_name'] ?? ''));
            if ($poaName === '') throw new Exception('No se indicó el POA a actualizar.');
            $st = $db->prepare('SELECT * FROM ah_poa WHERE nombre_poa=? ORDER BY id ASC LIMIT 2000');
            $st->execute([$poaName]);
            $rows = poaHydrateExecutionRows($db, $st->fetchAll(PDO::FETCH_ASSOC));
            $responseRows = [];
            foreach ($rows as $row) {
                $hash = (string)($row['hash_id'] ?? '');
                if ($hash === '') continue;
                $months = [];
                foreach (['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $m) {
                    $pending = (float)($row['_purchase_pending'][$m] ?? 0);
                    $authorized = (float)($row['_purchase_authorized'][$m] ?? 0);
                    $total = (float)($row['eje_'.$m] ?? 0);
                    $months[$m] = [
                        'value'=>$total,
                        'manual'=>round(max(0, $total - $pending - $authorized), 2),
                        'pending'=>$pending,
                        'authorized'=>$authorized
                    ];
                }
                $responseRows[$hash] = $months;
            }
            echo json_encode(['status'=>'ok','rows'=>$responseRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($_POST['action'] == 'execution_history') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $hash = trim((string)($_POST['hash_id'] ?? ''));
            $mes = poaNormalizeMonth((string)($_POST['mes'] ?? ''));
            if ($hash === '') throw new Exception('No se indicó la línea del POA.');
            echo json_encode([
                'status'=>'ok',
                'items'=>poaExecutionHistory($db, $hash, $mes),
                'breakdown'=>poaExecutionBreakdown($db, $hash, $mes),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($_POST['action'] == 'update_eje') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $validMonths = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
            $hash = trim((string)($_POST['hash_id'] ?? ''));
            $mes = strtolower(trim((string)($_POST['mes'] ?? '')));
            $valor = (float)($_POST['valor'] ?? 0);

            if ($hash === '' || !in_array($mes, $validMonths, true)) {
                throw new Exception('La línea o el mes recibido no es válido.');
            }
            if (!is_finite($valor)) {
                throw new Exception('El valor ejecutado no es válido.');
            }

            $motivo = trim((string)($_POST['motivo'] ?? 'Edición manual desde la matriz POA'));
            $accion = trim((string)($_POST['accion'] ?? 'edicion'));
            $db->beginTransaction();
            $before = poaExecutionBreakdown($db, $hash, $mes);
            $savedMonthValue = poaSetExecution($db, $hash, $mes, $valor);
            poaFlushManualExecutionStore();
            $ejecutado = poaRecalcExecuted($db, $hash);
            $after = poaExecutionBreakdown($db, $hash, $mes);
            poaLogExecutionChange($db, [
                'poa_hash'=>$hash, 'poa_nombre'=>$after['poa_nombre'], 'mes'=>$mes,
                'valor_anterior'=>$before['total'], 'valor_nuevo'=>$after['total'],
                'manual_anterior'=>$before['manual'], 'manual_nuevo'=>$after['manual'],
                'compras_autorizadas'=>$after['authorized'], 'compras_pendientes'=>$after['pending'],
                'motivo'=>$motivo, 'accion'=>$accion,
            ]);
            $db->commit();

            echo json_encode(['status'=>'ok','ejecutado'=>$ejecutado,'valor_mes'=>$savedMonthValue,'breakdown'=>$after], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(400);
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($_POST['action'] == 'bulk_update_eje') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $validMonths = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
            $updates = json_decode((string)($_POST['updates'] ?? '[]'), true);
            if (!is_array($updates) || count($updates) === 0) {
                throw new Exception('No se recibieron valores para pegar.');
            }
            if (count($updates) > 5000) {
                throw new Exception('El pegado supera el máximo de 5,000 celdas por operación.');
            }

            $motivo = trim((string)($_POST['motivo'] ?? 'Pegado múltiple en la matriz POA'));
            $db->beginTransaction();
            $affectedHashes = [];
            foreach ($updates as $item) {
                if (!is_array($item)) continue;
                $hash = trim((string)($item['hash_id'] ?? ''));
                $mes = strtolower(trim((string)($item['mes'] ?? '')));
                $valor = (float)($item['valor'] ?? 0);
                if ($hash === '' || !in_array($mes, $validMonths, true) || !is_finite($valor)) continue;
                $before = poaExecutionBreakdown($db, $hash, $mes);
                poaSetExecution($db, $hash, $mes, $valor);
                $after = poaExecutionBreakdown($db, $hash, $mes);
                poaLogExecutionChange($db, [
                    'poa_hash'=>$hash, 'poa_nombre'=>$after['poa_nombre'], 'mes'=>$mes,
                    'valor_anterior'=>$before['total'], 'valor_nuevo'=>$after['total'],
                    'manual_anterior'=>$before['manual'], 'manual_nuevo'=>$after['manual'],
                    'compras_autorizadas'=>$after['authorized'], 'compras_pendientes'=>$after['pending'],
                    'motivo'=>$motivo, 'accion'=>'pegado',
                ]);
                $affectedHashes[$hash] = true;
            }

            if (!$affectedHashes) throw new Exception('No se encontró ninguna celda válida para guardar.');
            poaFlushManualExecutionStore();
            foreach (array_keys($affectedHashes) as $hash) poaRecalcExecuted($db, $hash);
            $db->commit();

            echo json_encode(['status'=>'ok','rows'=>count($affectedHashes),'cells'=>count($updates)], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            if ($db->inTransaction()) $db->rollBack();
            http_response_code(400);
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }
    
    if ($_POST['action'] == 'update_tracking') {
        header('Content-Type: application/json; charset=utf-8');
        try {
            $hash = trim((string)($_POST['hash_id'] ?? ''));
            $campo = trim((string)($_POST['campo'] ?? ''));
            $valor = (string)($_POST['valor'] ?? '');
            $validMonths = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
            $validFields = [];
            foreach ($validMonths as $m) {
                foreach (['uni_','resp1_','resp2_','est_','plan_'] as $prefix) $validFields[] = $prefix.$m;
            }
            if ($hash === '' || !in_array($campo, $validFields, true)) throw new Exception('Campo de seguimiento inválido.');
            $db->prepare("UPDATE ah_poa SET `{$campo}` = ? WHERE hash_id = ?")->execute([$valor, $hash]);
            echo json_encode(['status'=>'ok'], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            http_response_code(400);
            echo json_encode(['status'=>'error','msg'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    if ($_POST['action'] == 'activar_poa') {
        $poaName = $_POST['poa_name'] ?? '';
        $db->beginTransaction();
        $db->exec("UPDATE ah_poa SET is_active = 0");
        $db->prepare("UPDATE ah_poa SET is_active = 1 WHERE nombre_poa = ?")->execute([$poaName]);
        $db->commit();
        header("Location: " . $_SERVER['PHP_SELF'] . "?poa=" . urlencode($poaName));
        exit;
    }

    if ($_POST['action'] == 'delete_row') {
        $db->prepare("DELETE FROM ah_poa WHERE hash_id = ?")->execute([$_POST['hash_id']]);
        echo json_encode(['status' => 'ok']); exit;
    }

    // PROCESAMIENTO DEL EXCEL
    if ($_POST['action'] == 'guardar_excel_poa') {
        $nombre_poa = trim($_POST['real_nombre_poa'] ?? 'POA_General');
        $datos = json_decode($_POST['poa_json'] ?? '[]', true);

        if ($nombre_poa === '') {
            $nombre_poa = 'POA_General';
        }

        if (!is_array($datos) || count($datos) === 0) {
            $msg = "<div class='alert error'>No se recibieron datos válidos del Excel. Verifique el archivo y vuelva a intentar.</div>";
        } else {
            try {
                $db->beginTransaction();

                $sql = "INSERT INTO ah_poa (
                            nombre_poa, programa, codigo_programa, sector, sub_sector, marco_logico, descripcion_actividad,
                            ext, participantes, codigo_maestro, fuente_financiamiento, cuenta_contable, rubro_contable,
                            pto_jan, pto_feb, pto_mar, pto_apr, pto_may, pto_jun,
                            pto_jul, pto_aug, pto_sep, pto_oct, pto_nov, pto_dec,
                            op_act_jul, op_act_aug, op_act_sep, op_act_oct, op_act_nov, op_act_dec,
                            op_part_jul, op_part_aug, op_part_sep, op_part_oct, op_part_nov, op_part_dec,
                            presupuesto_anual, hash_id, meta_actividades, operativo_meta_obj, tipo_participante
                        ) VALUES (
                            ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?, ?,
                            ?, ?, ?, ?, ?
                        )
                        ON DUPLICATE KEY UPDATE
                            programa=VALUES(programa),
                            codigo_programa=VALUES(codigo_programa),
                            sector=VALUES(sector),
                            sub_sector=VALUES(sub_sector),
                            marco_logico=VALUES(marco_logico),
                            descripcion_actividad=VALUES(descripcion_actividad),
                            ext=VALUES(ext),
                            participantes=VALUES(participantes),
                            codigo_maestro=VALUES(codigo_maestro),
                            fuente_financiamiento=VALUES(fuente_financiamiento),
                            cuenta_contable=VALUES(cuenta_contable),
                            rubro_contable=VALUES(rubro_contable),
                            pto_jan=VALUES(pto_jan), pto_feb=VALUES(pto_feb), pto_mar=VALUES(pto_mar),
                            pto_apr=VALUES(pto_apr), pto_may=VALUES(pto_may), pto_jun=VALUES(pto_jun),
                            pto_jul=VALUES(pto_jul), pto_aug=VALUES(pto_aug), pto_sep=VALUES(pto_sep),
                            pto_oct=VALUES(pto_oct), pto_nov=VALUES(pto_nov), pto_dec=VALUES(pto_dec),
                            op_act_jul=VALUES(op_act_jul), op_act_aug=VALUES(op_act_aug), op_act_sep=VALUES(op_act_sep),
                            op_act_oct=VALUES(op_act_oct), op_act_nov=VALUES(op_act_nov), op_act_dec=VALUES(op_act_dec),
                            op_part_jul=VALUES(op_part_jul), op_part_aug=VALUES(op_part_aug), op_part_sep=VALUES(op_part_sep),
                            op_part_oct=VALUES(op_part_oct), op_part_nov=VALUES(op_part_nov), op_part_dec=VALUES(op_part_dec),
                            presupuesto_anual=VALUES(presupuesto_anual),
                            meta_actividades=VALUES(meta_actividades),
                            operativo_meta_obj=VALUES(operativo_meta_obj),
                            tipo_participante=VALUES(tipo_participante)";

                $stmt = $db->prepare($sql);
                $insertadas = 0;
                $saltadas = 0;

                foreach ($datos as $row) {
                    if (!is_array($row)) {
                        $saltadas++;
                        continue;
                    }

                    $valNum = function($k) use ($row) {
                        $v = $row[$k] ?? 0;
                        if (is_string($v)) {
                            $v = str_replace(['L', '$', ',', ' '], '', $v);
                        }
                        return is_numeric($v) ? (float)$v : 0;
                    };

                    $actividad = trim((string)($row['actividad'] ?? ''));
                    $sector = trim((string)($row['sector'] ?? ''));
                    $descripcion = trim((string)($row['descripcion'] ?? ''));

                    $totalAnual = $valNum('jan') + $valNum('feb') + $valNum('mar') + $valNum('apr') + $valNum('may') + $valNum('jun')
                                + $valNum('jul') + $valNum('aug') + $valNum('sep') + $valNum('oct') + $valNum('nov') + $valNum('dec');

                    $totalActividades = $valNum('meta_act');
                    $totalParticipantes = $valNum('meta_part');

                    if ($actividad === '' && $sector === '' && $descripcion === '' && $totalAnual == 0 && $totalActividades == 0 && $totalParticipantes == 0) {
                        $saltadas++;
                        continue;
                    }

                    if ($sector === '') {
                        $sector = 'Z_Gastos_No_Mapeados';
                    }
                    if ($actividad === '') {
                        $actividad = 'Línea Operativa ' . ($row['id_fila'] ?? uniqid());
                    }

                    $hashBase = $nombre_poa . '|' . $actividad . '|' . ($row['id_fila'] ?? '') . '|' . ($row['cod_mae'] ?? '') . '|' . ($row['cta'] ?? '');
                    $hash = md5($hashBase);

                    $stmt->execute([
                        $nombre_poa,
                        trim((string)($row['programa'] ?? '')),
                        trim((string)($row['cod_prog'] ?? '')),
                        $sector,
                        trim((string)($row['subsector'] ?? '')),
                        $actividad,
                        $descripcion,
                        trim((string)($row['ext'] ?? '')),
                        trim((string)($row['participantes'] ?? '0')),
                        trim((string)($row['cod_mae'] ?? '')),
                        trim((string)($row['fte'] ?? '')),
                        trim((string)($row['cta'] ?? '')),
                        trim((string)($row['rubro'] ?? '')),

                        $valNum('jan'), $valNum('feb'), $valNum('mar'), $valNum('apr'), $valNum('may'), $valNum('jun'),
                        $valNum('jul'), $valNum('aug'), $valNum('sep'), $valNum('oct'), $valNum('nov'), $valNum('dec'),

                        $valNum('act_jul'), $valNum('act_aug'), $valNum('act_sep'),
                        $valNum('act_oct'), $valNum('act_nov'), $valNum('act_dec'),

                        $valNum('part_jul'), $valNum('part_aug'), $valNum('part_sep'),
                        $valNum('part_oct'), $valNum('part_nov'), $valNum('part_dec'),

                        $totalAnual,
                        $hash,
                        $totalActividades,
                        $totalParticipantes,
                        trim((string)($row['tipo_part'] ?? ''))
                    ]);

                    $insertadas++;
                }

                if ($insertadas === 0) {
                    $db->rollBack();
                    $msg = "<div class='alert error'>El Excel fue leído, pero no se encontró ninguna fila válida para guardar. Revise que el archivo tenga datos desde la fila 6 del formato POA.</div>";
                } else {
                    $db->commit();
                    $msg = "<div class='alert success'>Sincronización completa. Filas guardadas/actualizadas: {$insertadas}. Filas omitidas: {$saltadas}.</div>";
                }
            } catch(Exception $e) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                $msg = "<div class='alert error'>Error al guardar el POA: " . htmlspecialchars($e->getMessage()) . "</div>";
            }
        }
    }

}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action']) && $_POST['action'] == 'update_task') {
    try {
        $db->beginTransaction();

        // 1. Actualizar campos principales
        $stmt = $db->prepare("UPDATE ah_poa SET 
            operativo_tecnico = :tec, 
            operativo_comunidad = :com, 
            operativo_periodo = :per, 
            operativo_estado = :est, 
            operativo_meta_alc = :alc, 
            operativo_obs = :obs, 
            operativo_meta_cualitativa = :cual,
            meta_actividades = :m_act, 
            operativo_meta_obj = :m_obj, 
            meta_actividades_alc = :m_alc
            WHERE id = :id");
            
        $stmt->execute([
            ':tec' => $_POST['tecnico_asignado'],
            ':com' => $_POST['comunidad_base'],
            ':per' => $_POST['periodo_estimado'],
            ':est' => $_POST['estado'],
            ':alc' => $_POST['meta_part_alc'],
            ':obs' => $_POST['observaciones'],
            ':cual'=> $_POST['meta_cualitativa'],
            ':m_act' => $_POST['meta_act_obj'],
            ':m_obj' => $_POST['meta_part_obj'],
            ':m_alc' => $_POST['meta_act_alc'],
            ':id'    => $_POST['task_id']
        ]);

        // 2. Actualizar Calendario Mensual (Actividades y Participantes)
        $meses = ['jan','feb','mar','apr','may','jun','jul','aug','sep','oct','nov','dec'];
        foreach($meses as $m) {
            $val_act = isset($_POST['op_act'][$m]) ? (float)$_POST['op_act'][$m] : 0;
            $val_part = isset($_POST['op_part'][$m]) ? (float)$_POST['op_part'][$m] : 0;
            
            $stmt_mes = $db->prepare("UPDATE ah_poa SET op_act_{$m} = :act, op_part_{$m} = :part, op_editado_{$m} = 1 WHERE id = :id");
            $stmt_mes->execute([':act' => $val_act, ':part' => $val_part, ':id' => $_POST['task_id']]);
        }

        $db->commit();
        $msg = "<div class='alert success'>Guardado correctamente.</div>";
    } catch(Exception $e) {
        $db->rollBack();
        $msg = "<div class='alert error'>Error: " . $e->getMessage() . "</div>";
    }
}
// ========================================================
// 4. OBTENER DATOS Y CÁLCULOS
// ========================================================
$lista_poas = [];
try { $stmt_p = $db->query("SELECT DISTINCT nombre_poa FROM ah_poa ORDER BY nombre_poa DESC"); if($stmt_p) $lista_poas = $stmt_p->fetchAll(PDO::FETCH_COLUMN); } catch(Exception $e){}

$poa_vigente = '';
try { $stmt_v = $db->query("SELECT nombre_poa FROM ah_poa WHERE is_active = 1 LIMIT 1"); if($stmt_v) $poa_vigente = $stmt_v->fetchColumn(); } catch(Exception $e){}

$poa_vigente_texto = $poa_vigente ? $poa_vigente : "Ninguno (Seleccione y active uno abajo)";
$filtro_poa = isset($_GET['poa']) ? $_GET['poa'] : ($poa_vigente ? $poa_vigente : ($lista_poas[0] ?? ''));

$m_map = ['01'=>'jan','02'=>'feb','03'=>'mar','04'=>'apr','05'=>'may','06'=>'jun','07'=>'jul','08'=>'aug','09'=>'sep','10'=>'oct','11'=>'nov','12'=>'dec'];
$current_month_key = $m_map[date('m')];

$poa_list = [];
$total_pto = 0; $total_ejec = 0;

$meses_ordenados = ['jul'=>'Jul', 'aug'=>'Ago', 'sep'=>'Sep', 'oct'=>'Oct', 'nov'=>'Nov', 'dec'=>'Dic', 'jan'=>'Ene', 'feb'=>'Feb', 'mar'=>'Mar', 'apr'=>'Abr', 'may'=>'May', 'jun'=>'Jun'];

$totales_mensuales = [];
foreach($meses_ordenados as $k => $nom) { 
    $totales_mensuales['pto_'.$k] = 0; 
    $totales_mensuales['acum_'.$k] = 0;
    $totales_mensuales['eje_'.$k] = 0; 
}

$sectores_unicos = [];
$marcos_unicos = [];
$cuentas_unicas = [];

if(!empty($filtro_poa)) {
    try {
        $stmt_poa = $db->prepare("SELECT * FROM ah_poa WHERE nombre_poa = ? ORDER BY id ASC LIMIT 2000");
        $stmt_poa->execute([$filtro_poa]);
        $poa_list = poaHydrateExecutionRows($db, $stmt_poa->fetchAll(PDO::FETCH_ASSOC));

        $total_pto = 0;
        $total_ejec = 0;
        foreach ($poa_list as $lineaPoa) {
            $total_pto += (float)($lineaPoa['presupuesto_anual'] ?? 0);
            $total_ejec += (float)($lineaPoa['ejecutado'] ?? 0);
        }

        foreach($poa_list as $p) {
            $sec = trim(explode('_', $p['sector'] ?? '')[0] ?? ($p['sector'] ?? ''));
            $ml = trim($p['marco_logico'] ?? '');
            $cta = trim(explode(' - ', $p['cuenta_contable'] ?? '')[0] ?? '');
            
            if(!empty($sec) && !in_array($sec, $sectores_unicos)) $sectores_unicos[] = $sec;
            if(!empty($ml) && !in_array($ml, $marcos_unicos)) $marcos_unicos[] = $ml;
            if(!empty($cta) && !in_array($cta, $cuentas_unicas)) $cuentas_unicas[] = $cta;
        }
        sort($sectores_unicos); sort($marcos_unicos); sort($cuentas_unicas);

    } catch (Exception $e) {}
}

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Matriz POA | Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg-canvas: #f8fafc; --border: #cbd5e1; }
        body { font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; background: var(--bg-canvas); margin: 0; }
        .main-wrapper { flex-grow: 1; padding: 40px; overflow-y: auto; width: 100%; box-sizing: border-box; position:relative;}
        .page-header { margin-bottom: 20px; }
        .page-header h1 { font-size: 1.8rem; margin: 0; color: #1e293b; display: flex; align-items: center; gap: 10px;}
        
        .card { background: white; padding: 25px; border-radius: 12px; border: 1px solid var(--border); margin-bottom: 25px; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .btn-submit { background: var(--ah-primary); color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 6px; cursor: pointer; transition:0.2s;}
        .btn-submit:hover { opacity:0.9; transform: translateY(-1px);}
        .btn-outline { background: white; color: #334155; border: 1px solid var(--border); padding: 10px 15px; font-weight: bold; border-radius: 6px; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:8px;}
        .btn-outline:hover { background: #f1f5f9; color: var(--ah-primary); border-color: var(--ah-primary);}
        .btn-danger { background: #ef4444; color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 6px; cursor: pointer; transition:0.2s; }
        
        /* BARRA DE FÓRMULAS */
        .formula-bar-container { display: flex; align-items: center; background: white; border: 1px solid var(--border); border-radius: 8px; padding: 8px 15px; margin-bottom: 15px; box-shadow: 0 2px 4px rgba(0,0,0,0.02); transition: 0.2s;}
        .formula-bar-container.active { border-color: var(--ah-primary); box-shadow: 0 0 0 3px rgba(52, 133, 155, 0.1); }
        .formula-icon { font-weight: 800; font-family: 'Times New Roman', Times, serif; font-style: italic; color: var(--ah-primary); margin-right: 15px; font-size: 1.2rem; user-select: none; }
        .formula-input { width: 100%; border: none; outline: none; font-family: 'Inter', monospace; font-size: 1.05rem; color: #0f172a; background: transparent; }
        .formula-input::placeholder { color: #94a3b8; font-style: italic; font-family: 'Inter', sans-serif;}

        .table-container { overflow-x: auto; max-width: 100%; max-height: 60vh; border: 1px solid var(--border); border-radius: 8px; position:relative; background:white;}
        .styled-table { width: auto; min-width: 100%; border-collapse: collapse; font-size: 0.75rem; white-space: nowrap; }
        
        .styled-table thead th { background: #0f172a; padding: 10px; color: white; border: 1px solid #1e293b; position: sticky; z-index: 5; text-align: center; font-weight: 600; letter-spacing: 0.5px;}
        .styled-table thead tr:nth-child(1) th { top: 0; }
        .styled-table thead tr:nth-child(2) th { top: 38px; font-size: 0.7rem; background:#1e293b; color:#cbd5e1; padding: 6px;}
        
        .styled-table th.sticky-col, .styled-table td.sticky-col { position: sticky; z-index: 10; background: #fff;}
        .styled-table thead th.sticky-col { z-index: 15; background: #0f172a;}
        .styled-table td.sticky-col { border-right: 2px solid #94a3b8; }
        .styled-table tr:hover td.sticky-col { background: #f8fafc; }

        .styled-table td { padding: 6px; border: 1px solid #e2e8f0; color: #334155; vertical-align:middle;}
        .styled-table tr:hover td { background: #f8fafc; }
        .styled-table tr.active-row td { background: #f0fdfa !important; }
        
        /* Dropdown Filtro */
        .filter-header { display: flex; align-items: center; justify-content: space-between; gap: 5px; }
        .btn-filter-icon { background: rgba(255,255,255,0.1); border: 1px solid rgba(255,255,255,0.2); color: #cbd5e1; cursor: pointer; padding: 3px 6px; border-radius: 4px; font-size: 0.8rem; transition: 0.2s; }
        .btn-filter-icon:hover { color: white; background: var(--ah-primary); border-color: var(--ah-primary); }
        .btn-filter-icon.active { color: #0f172a; background: #fde047; border-color: #eab308; }
        
        .filter-dropdown { position: absolute; background: white; border: 1px solid var(--border); border-radius: 6px; box-shadow: 0 10px 25px -5px rgba(0,0,0,0.2); padding: 12px; z-index: 2000; display: none; text-align: left; color: #0f172a; min-width: 240px; font-weight: normal; font-family: 'Inter', sans-serif;}
        .filter-search { width: 100%; padding: 8px; border: 1px solid var(--border); border-radius: 4px; margin-bottom: 10px; box-sizing: border-box; font-size: 0.85rem; }
        .filter-search:focus { outline: none; border-color: var(--ah-primary); }
        .filter-options { max-height: 250px; overflow-y: auto; font-size: 0.85rem; display: flex; flex-direction: column; gap: 6px; }
        .filter-options label { display: flex; align-items: flex-start; gap: 8px; cursor: pointer; white-space: normal; line-height: 1.3;}
        .filter-actions { display: flex; justify-content: space-between; margin-top: 12px; padding-top: 12px; border-top: 1px solid #e2e8f0; }
        .btn-filter-action { background: #e2e8f0; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 0.8rem; font-weight: bold; color: #334155; }
        .btn-filter-action.apply { background: var(--ah-primary); color: white; }

        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;} 
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;}

        .badge-fte { font-size:0.65rem; background:#e0e7ff; color:#1d4ed8; padding:3px 6px; border-radius:4px; display:inline-block; margin-bottom:4px;}
        .badge-cta { font-size:0.65rem; background:#f1f5f9; color:#475569; padding:3px 6px; border-radius:4px; display:inline-block;}
        
        .dashboard-widgets { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; margin-bottom: 25px; }
        .widget { background: white; padding: 20px; border-radius: 12px; border: 1px solid var(--border); border-left: 5px solid var(--ah-primary); }
        .widget h4 { margin:0 0 10px 0; color:#64748b; font-size:0.85rem; text-transform:uppercase;}
        .widget p { margin:0; font-size:1.5rem; font-weight:bold; color:#0f172a;}
        
        /* Celdas del Mes */
        .cell-pto { background: #f8fafc; text-align: right; color:#64748b; border-left: 2px solid #94a3b8 !important;}
        .cell-acum { background: #e0f2fe; text-align: right; font-weight: bold; color:#0284c7; }
        .cell-acum.neg { background: #fee2e2; color: #dc2626; }
        .cell-eje { text-align: right; font-weight: 600; color: #b45309; cursor: text; transition: 0.2s; position:relative;}
        .cell-eje.purchase-pending{background:#fff7ed!important;color:#9a3412!important;box-shadow:inset 3px 0 0 #f97316;}
        .cell-eje.purchase-pending::after{content:'COMPRA';position:absolute;right:3px;top:2px;font-size:.48rem;line-height:1;padding:2px 4px;border-radius:999px;background:#ffedd5;color:#9a3412;font-weight:900;letter-spacing:.3px;}
        .cell-eje.purchase-authorized{background:#ecfdf5!important;color:#166534!important;box-shadow:inset 3px 0 0 #22c55e;}
        .cell-eje.has-manual{font-weight:800;text-decoration:underline dotted #0ea5e9;text-underline-offset:3px;}
        .cell-eje:hover { background: #fef08a; border: 1px dashed #ca8a04; }
        .cell-eje:focus { background: #fff; border: 2px solid #b45309; outline: none; box-shadow: inset 0 0 5px rgba(0,0,0,0.1); }
        
        /* Controles de Seguimiento */
        .track-select { width: 100px; padding: 4px; font-size: 0.7rem; border: 1px solid transparent; background: transparent; cursor: pointer; border-radius: 4px; }
        .track-select:hover, .track-select:focus { border-color: #cbd5e1; background: white; outline: none; }
        .track-plan { width: 150px; min-height: 25px; max-height: 60px; overflow-y: auto; padding: 4px; font-size: 0.75rem; border: 1px solid transparent; cursor: text; white-space: pre-wrap; word-wrap: break-word;}
        .track-plan:hover, .track-plan:focus { border-color: #cbd5e1; background: white; outline: none; }
        
        /* Colores de Estado */
        .est-realizado { background: #16a34a !important; color: white !important; font-weight: bold; }
        .est-proceso { background: #ca8a04 !important; color: white !important; font-weight: bold; }
        .est-reprogramado { background: #ea580c !important; color: white !important; font-weight: bold; }
        .est-incompleto { background: #dc2626 !important; color: white !important; font-weight: bold; }
        .est-sinrealizar { background: #94a3b8 !important; color: white !important; font-weight: bold; }
        
        .desc-text { max-width: 250px; overflow: hidden; text-overflow: ellipsis; white-space: normal; line-height:1.3;}
        .toast-msg { position:fixed; bottom:20px; right:20px; background:#166534; color:white; padding:10px 20px; border-radius:6px; box-shadow:0 4px 6px rgba(0,0,0,0.1); display:none; z-index:9999; font-weight:bold;}

        #loader { display: none; position: fixed; top:0; left:0; width:100%; height:100%; background:rgba(255,255,255,0.8); z-index: 1000; align-items:center; justify-content:center; flex-direction:column; color:var(--ah-primary); font-size:1.2rem; font-weight:bold;}
        .spinner { border: 5px solid #f3f3f3; border-top: 5px solid var(--ah-primary); border-radius: 50%; width: 50px; height: 50px; animation: spin 1s linear infinite; margin-bottom:15px;}
        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
        
        .tooltip-cell { cursor: help; }
        .btn-trash { color:#ef4444; background:none; border:none; cursor:pointer; font-size:1rem; transition:0.2s; padding:5px;}
        .btn-trash:hover { color:#991b1b; transform:scale(1.2); }
        
        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.7); z-index: 2000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        @keyframes slideUp { from { transform: translateY(20px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }


        /* ESPACIO DE TRABAJO TIPO EXCEL */
        .matrix-workspace{background:#fff;border:1px solid var(--border);border-radius:12px;box-shadow:0 4px 12px rgba(15,23,42,.04);overflow:hidden;position:relative}
        .excel-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:10px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:80}
        .excel-toolbar .tool-separator{width:1px;height:28px;background:#cbd5e1;margin:0 3px}
        .excel-tool-btn{display:inline-flex;align-items:center;gap:7px;border:1px solid #cbd5e1;background:white;color:#334155;border-radius:7px;padding:8px 11px;font-size:.78rem;font-weight:800;cursor:pointer;white-space:nowrap}
        .excel-tool-btn:hover{background:#e0f2fe;color:#075985;border-color:#7dd3fc}
        .excel-tool-btn.primary{background:#166534;color:#fff;border-color:#166534}
        .excel-tool-btn.primary:hover{background:#14532d}
        .excel-help{margin-left:auto;color:#64748b;font-size:.74rem;font-weight:700}
        .matrix-workspace .formula-bar-container{margin:10px 12px;border-radius:7px}
        .execution-source-panel{display:none;align-items:center;gap:8px;flex-wrap:wrap;margin:0 12px 10px;padding:9px 11px;border:1px solid #dbe5ef;border-radius:9px;background:#f8fafc}
        .execution-source-panel.active{display:flex}
        .source-label{font-size:.7rem;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.35px}
        .source-chip{display:inline-flex;align-items:center;gap:5px;padding:5px 8px;border-radius:999px;font-size:.72rem;font-weight:850;border:1px solid transparent}
        .source-chip.total{background:#e0f2fe;color:#075985;border-color:#bae6fd}
        .source-chip.manual{background:#eef2ff;color:#3730a3;border-color:#c7d2fe}
        .source-chip.authorized{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
        .source-chip.pending{background:#fff7ed;color:#9a3412;border-color:#fed7aa}
        .execution-reason{min-width:220px;flex:1;padding:7px 9px;border:1px solid #cbd5e1;border-radius:7px;font:inherit;font-size:.75rem;background:#fff}
        .audit-modal-card{background:#fff;width:min(920px,94vw);max-height:82vh;border-radius:14px;box-shadow:0 24px 70px rgba(15,23,42,.3);overflow:hidden;animation:slideUp .2s ease}
        .audit-modal-head{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;background:#102039;color:#fff}
        .audit-modal-head h3{margin:0;font-size:1rem}.audit-modal-head button{border:0;background:rgba(255,255,255,.12);color:#fff;width:32px;height:32px;border-radius:8px;cursor:pointer}
        .audit-current{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;padding:15px 20px;background:#f8fafc;border-bottom:1px solid #e2e8f0}
        .audit-current div{padding:10px;border-radius:9px;background:#fff;border:1px solid #e2e8f0}.audit-current span{display:block;color:#64748b;font-size:.68rem;font-weight:850;text-transform:uppercase}.audit-current strong{display:block;margin-top:4px;color:#0f172a}
        .audit-table-wrap{max-height:52vh;overflow:auto;padding:0 20px 20px}.audit-table{width:100%;border-collapse:collapse;font-size:.76rem}.audit-table th{position:sticky;top:0;background:#e9eff5;color:#334155;padding:9px;text-align:left}.audit-table td{padding:9px;border-bottom:1px solid #e2e8f0;vertical-align:top}.audit-empty{text-align:center;color:#64748b;padding:35px!important}
        .matrix-workspace .table-container{border-radius:0;border-left:0;border-right:0;margin:0;max-height:62vh}
        .excel-statusbar{display:flex;align-items:center;gap:18px;min-height:34px;padding:5px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;color:#475569;font-size:.76rem;font-weight:700}
        .excel-statusbar strong{color:#0f172a}
        .editable-eje.selected-eje-cell{outline:2px solid #0ea5e9!important;outline-offset:-2px;background:#e0f2fe!important;position:relative;z-index:3}
        .editable-eje.active-eje-cell{outline:3px solid #0284c7!important;outline-offset:-3px;background:#fff!important}
        .editable-eje.saving-cell{background:#fef3c7!important}
        .editable-eje.saved-cell{background:#dcfce7!important;transition:background .5s ease}
        .editable-eje.error-cell{background:#fee2e2!important;color:#991b1b!important}
        .data-row.row-no-funds td{background:#fff1f2!important}
        .data-row.row-no-funds td.sticky-col{background:#fff1f2!important}
        .data-row.row-no-funds{box-shadow:inset 5px 0 0 #ef4444}
        .data-row.row-no-funds .row-saldo{background:#fecaca!important;color:#991b1b!important}
        .funding-alert{display:inline-flex;align-items:center;gap:4px;margin-top:4px;color:#b91c1c;font-size:.64rem;font-weight:900;text-transform:uppercase}
        .matrix-workspace:fullscreen{background:#fff;width:100vw;height:100vh;border:0;border-radius:0;display:flex;flex-direction:column}
        .matrix-workspace:fullscreen .excel-toolbar{flex:0 0 auto}
        .matrix-workspace:fullscreen .formula-bar-container{flex:0 0 auto}
        .matrix-workspace:fullscreen .table-container{flex:1 1 auto;max-height:none;height:auto}
        .matrix-workspace:fullscreen .excel-statusbar{flex:0 0 auto}
        body.matrix-is-fullscreen .sidebar, body.matrix-is-fullscreen aside{display:none!important}
        @media(max-width:900px){.excel-help{display:none}.excel-toolbar{gap:6px}.excel-tool-btn{padding:7px 9px}.audit-current{grid-template-columns:1fr 1fr}.execution-reason{min-width:100%}}
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    
    <div id="loader"><div class="spinner"></div> Trabajando...</div>
    <div id="toast" class="toast-msg"><i class="fa-solid fa-check"></i> Acción Guardada</div>

    <main class="main-wrapper">
        <div class="page-header">
            <h1><i class="fa-solid fa-file-invoice-dollar"></i> Matriz de Gestión Programática y Financiera</h1>
        </div>

        <div style="background:#e0e7ff; color:#1d4ed8; padding:15px 25px; border-radius:8px; border:1px solid #c7d2fe; margin-bottom:20px; font-weight:bold; font-size:1.1rem; display:flex; align-items:center; gap:10px;">
            <i class="fa-solid fa-bullseye" style="font-size:1.5rem;"></i> 
            <span>POA VIGENTE PARA COMPRAS: <span style="color:#0f172a;"><?php echo htmlspecialchars($poa_vigente_texto); ?></span></span>
        </div>
        
        <?php echo $msg; ?>

        <div class="card">
            <h3 style="margin-top:0;"><i class="fa-solid fa-file-excel"></i> Actualizar Presupuestos y Metas desde EXCEL (.xlsx)</h3>
            <form id="form-upload-excel" style="display:flex; gap:15px; align-items:center;">
                <input type="text" id="nombre_poa_input" placeholder="Ej: POA AF26" required style="padding:10px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; width: 250px;">
                <input type="file" id="excel_file" accept=".xlsx, .xls, .csv" required style="padding:8px; border:1px solid #cbd5e1; border-radius:6px; background:#f8fafc; flex-grow:1;">
                <button type="submit" class="btn-submit"><i class="fa-solid fa-microchip"></i> Sincronizar Excel</button>
            </form>
            <form id="real-form" method="POST" style="display:none;">
                <input type="hidden" name="action" value="guardar_excel_poa">
                <input type="hidden" name="real_nombre_poa" id="real_nombre_poa">
                <textarea name="poa_json" id="poa_json"></textarea>
            </form>
        </div>

        <?php if(count($lista_poas) > 0): ?>
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 25px; border-radius: 8px; border: 1px solid var(--border);">
            <div style="display: flex; gap: 15px; align-items: center;">
                <form method="GET" style="display: flex; gap: 15px; align-items: center; margin:0;">
                    <label style="font-weight: 600; color: #1e293b;"><i class="fa-solid fa-folder-open"></i> Visualizando Matriz:</label>
                    <select name="poa" onchange="this.form.submit()" style="padding: 8px 12px; border-radius: 6px; border: 1px solid var(--border); font-size:1rem; font-family:inherit;">
                        <?php foreach($lista_poas as $p): ?>
                            <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $filtro_poa == $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                
                <button type="button" class="btn-outline" onclick="toggleDropdown('dropdown-meses')">
                    <i class="fa-solid fa-calendar-days"></i> Mostrar/Ocultar Meses
                </button>
                <button type="button" class="btn-outline" id="btn-toggle-track" onclick="toggleTracking()">
                    <i class="fa-solid fa-eye-slash"></i> Ocultar Seguimiento
                </button>
            </div>
            
            <?php if($filtro_poa != $poa_vigente): ?>
                <form method="POST" style="margin:0;">
                    <input type="hidden" name="action" value="activar_poa">
                    <input type="hidden" name="poa_name" value="<?php echo htmlspecialchars($filtro_poa); ?>">
                    <button type="submit" class="btn-submit" style="background:#166534; margin:0;"><i class="fa-solid fa-check"></i> Activar POA para Compras</button>
                </form>
            <?php else: ?>
                <span style="color:#166534; font-weight:bold;"><i class="fa-solid fa-circle-check"></i> Activo en Sistema</span>
            <?php endif; ?>
        </div>

        <div class="dashboard-widgets">
            <div class="widget">
                <h4>Techo Presupuestario (Mostrado)</h4>
                <p id="pto_global_ui">L. <?php echo number_format($total_pto, 2); ?></p>
            </div>
            <div class="widget" style="border-left-color:#b45309;">
                <h4>Total Ejecutado (Mostrado)</h4>
                <p id="total_ejec_global_ui">L. <?php echo number_format($total_ejec, 2); ?></p>
            </div>
            <div class="widget" style="border-left-color:#166534;">
                <h4>Saldo Disponible (Mostrado)</h4>
                <p style="color:#166534;" id="saldo_global_ui">L. <?php echo number_format($total_pto - $total_ejec, 2); ?></p>
            </div>
        </div>

        <section id="matrix-workspace" class="matrix-workspace">
            <div class="excel-toolbar">
                <button type="button" class="excel-tool-btn" id="btn-matrix-fullscreen" onclick="toggleMatrixFullscreen()"><i class="fa-solid fa-expand"></i> Pantalla completa</button>
                <button type="button" class="excel-tool-btn primary" onclick="exportarMatrizXLSX()"><i class="fa-solid fa-file-excel"></i> Exportar XLSX</button>
                <span class="tool-separator"></span>
                <button type="button" class="excel-tool-btn" id="btn-undo-eje" onclick="undoEje()" disabled><i class="fa-solid fa-rotate-left"></i> Deshacer</button>
                <button type="button" class="excel-tool-btn" id="btn-redo-eje" onclick="redoEje()" disabled><i class="fa-solid fa-rotate-right"></i> Rehacer</button>
                <button type="button" class="excel-tool-btn" onclick="copiarSeleccionEje()"><i class="fa-solid fa-copy"></i> Copiar selección</button>
                <button type="button" class="excel-tool-btn" onclick="limpiarSeleccionEje()"><i class="fa-solid fa-eraser"></i> Limpiar selección</button>
                <button type="button" class="excel-tool-btn" onclick="recalcularTodasLasFilas()"><i class="fa-solid fa-calculator"></i> Recalcular</button>
                <button type="button" class="excel-tool-btn purchases-refresh-btn" id="btn-refresh-compras" onclick="refreshPurchaseExecutionCells(true)"><i class="fa-solid fa-cart-shopping"></i> Actualizar compras</button>
                <span class="excel-help">Fórmulas: =5000+150 · =SUMA(100;200) · =PROMEDIO(10;20) · Las compras imputadas se reflejan automáticamente en Eje.</span>
            </div>

        <div class="formula-bar-container" id="formula-container">
            <span class="formula-icon">fx</span>
            <input type="text" id="formula-bar" class="formula-input" placeholder="Haz clic en cualquier celda de 'Eje' para editarla matemáticamente (Ej: =5000+150)">
        </div>
        <div class="execution-source-panel" id="execution-source-panel">
            <span class="source-label">Origen del total</span>
            <span class="source-chip total"><i class="fa-solid fa-equals"></i> Total <strong id="source-total">L. 0.00</strong></span>
            <span class="source-chip manual"><i class="fa-solid fa-pen"></i> Manual <strong id="source-manual">L. 0.00</strong></span>
            <span class="source-chip authorized"><i class="fa-solid fa-cart-shopping"></i> Compras autorizadas <strong id="source-authorized">L. 0.00</strong></span>
            <span class="source-chip pending"><i class="fa-solid fa-clock"></i> Compras pendientes <strong id="source-pending">L. 0.00</strong></span>
            <input type="text" id="execution-reason" class="execution-reason" maxlength="500" placeholder="Motivo del ajuste manual (opcional)">
            <button type="button" class="excel-tool-btn" id="btn-execution-history" onclick="openExecutionHistory()"><i class="fa-solid fa-clock-rotate-left"></i> Ver bitácora</button>
        </div>

        <div class="table-container">
            <table class="styled-table" id="matrix-table">
                <thead>
                    <tr>
                        <th rowspan="2" class="sticky-col" style="left:0; width:150px; min-width:150px; vertical-align:middle;">
                            <div class="filter-header">
                                Sector / Programa
                                <button type="button" class="btn-filter-icon" onclick="toggleDropdown('dropdown-sector')"><i class="fa-solid fa-filter"></i></button>
                            </div>
                        </th>
                        <th rowspan="2" class="sticky-col" style="left:150px; width:220px; min-width:220px; vertical-align:middle;">
                            <div class="filter-header">
                                Marco Lógico
                                <button type="button" class="btn-filter-icon" onclick="toggleDropdown('dropdown-marco')"><i class="fa-solid fa-filter"></i></button>
                            </div>
                        </th>
                        <th rowspan="2" class="sticky-col" style="left:370px; width:180px; min-width:180px; vertical-align:middle;">
                            <div class="filter-header">
                                Imputación (Cta)
                                <button type="button" class="btn-filter-icon" onclick="toggleDropdown('dropdown-imput')"><i class="fa-solid fa-filter"></i></button>
                            </div>
                        </th>
                        
                        <?php foreach($meses_ordenados as $k => $nom): ?>
                            <th colspan="8" class="col-mes-<?php echo $k; ?> th-top-mes" style="border-left:2px solid #94a3b8; background:#0f172a;"><?php echo $nom; ?> - SEGUIMIENTO Y EJECUCIÓN</th>
                        <?php endforeach; ?>
                        
                        <th rowspan="2" style="border-left:2px solid #94a3b8; background:#1e293b;">Pto. Anual</th>
                        <th rowspan="2" style="background:#1e293b;">Eje. Total</th>
                        <th rowspan="2" style="background:#1e293b;">Saldo Final</th>
                        <th rowspan="2" style="background:#991b1b;"><i class="fa-solid fa-trash"></i></th>
                    </tr>
                    <tr>
                        <?php foreach($meses_ordenados as $k => $nom): ?>
                            <th class="col-mes-<?php echo $k; ?>" style="border-left:2px solid #94a3b8; min-width:60px;" title="Presupuesto Mensual">Pto</th>
                            <th class="col-mes-<?php echo $k; ?>" style="background:#0284c7; color:white; min-width:60px;" title="Acumulado del mes anterior">Acum</th>
                            <th class="col-mes-<?php echo $k; ?>" style="min-width:60px;" title="Ejecutado Real en el Mes">Eje</th>
                            <th class="col-mes-<?php echo $k; ?> track-col" style="background:#f8fafc; color:#1e293b;">Unidad Ej.</th>
                            <th class="col-mes-<?php echo $k; ?> track-col" style="background:#f8fafc; color:#1e293b;">Resp 1</th>
                            <th class="col-mes-<?php echo $k; ?> track-col" style="background:#f8fafc; color:#1e293b;">Resp 2</th>
                            <th class="col-mes-<?php echo $k; ?> track-col" style="background:#f8fafc; color:#1e293b;">Estado</th>
                            <th class="col-mes-<?php echo $k; ?> track-col" style="background:#f8fafc; color:#1e293b; min-width:150px;">Plan de Acción</th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    foreach($poa_list as $p): 
                        $disp = $p['presupuesto_anual'] - $p['ejecutado']; 
                        $hash = $p['hash_id'];
                        $saldo_cascada_final = 0.0;
                        foreach ($meses_ordenados as $mesSaldo => $nombreSaldo) {
                            $saldo_cascada_final += (float)($p['pto_'.$mesSaldo] ?? 0) - (float)($p['eje_'.$mesSaldo] ?? 0);
                        }
                        $sin_fondos = $saldo_cascada_final < -0.005;
                        
                        $rubro = !empty($p['rubro_contable']) ? $p['rubro_contable'] : 'No definido en Excel';
                        $descripcion_detalle = !empty($p['descripcion_actividad']) ? $p['descripcion_actividad'] : 'Sin descripción de actividad';
                        $part = !empty($p['participantes']) ? $p['participantes'] : '0';
                        $r_json = htmlspecialchars(json_encode($rubro . "\n\nDescripción de actividad:\n" . $descripcion_detalle), ENT_QUOTES, 'UTF-8');
                        $p_json = htmlspecialchars(json_encode($part), ENT_QUOTES, 'UTF-8');
                        $click_attr = "onclick='abrirDetalles($r_json, $p_json)' title='Ver Concepto y Participantes'";

                        $f_sec = htmlspecialchars(trim(explode('_', $p['sector'])[0] ?? $p['sector']));
                        $f_mar = htmlspecialchars(trim($p['marco_logico']));
                        $f_cta = htmlspecialchars(trim(explode(' - ', $p['cuenta_contable'])[0]));
                    ?>
                    <tr data-hash="<?php echo $hash; ?>" data-fsec="<?php echo $f_sec; ?>" data-fmar="<?php echo $f_mar; ?>" data-fcta="<?php echo $f_cta; ?>" data-final-balance="<?php echo htmlspecialchars((string)$saldo_cascada_final); ?>" class="data-row<?php echo $sin_fondos ? ' row-no-funds' : ''; ?>" title="<?php echo $sin_fondos ? 'Alerta: la ejecución total supera todos los fondos programados de esta línea.' : ''; ?>">
                        <td class="sticky-col tooltip-cell" style="left:0;" <?php echo $click_attr; ?>>
                            <strong style="color:var(--ah-primary);"><?php echo $f_sec; ?></strong><br>
                            <span style="color:#64748b; font-size:0.65rem;"><?php echo htmlspecialchars($p['programa']); ?></span>
                            <?php if($sin_fondos): ?><span class="funding-alert"><i class="fa-solid fa-triangle-exclamation"></i> Sin fondos suficientes</span><?php endif; ?>
                        </td>
                        <td class="sticky-col tooltip-cell" style="left:150px;" <?php echo $click_attr; ?>>
                            <div class="desc-text" style="font-weight:600;"><?php echo $f_mar; ?></div>
                            <?php if(!empty($p['descripcion_actividad'])): ?><div class="desc-text" style="font-size:0.68rem; color:#475569; margin-top:3px;">Desc: <?php echo htmlspecialchars($p['descripcion_actividad']); ?></div><?php endif; ?>
                            <span style="font-size:0.65rem; background:#fef3c7; color:#92400e; padding:2px 4px; border-radius:3px;">EXT: <?php echo htmlspecialchars($p['ext']); ?> | <?php echo htmlspecialchars($p['codigo_maestro']); ?></span>
                        </td>
                        <td class="sticky-col tooltip-cell" style="left:370px;" <?php echo $click_attr; ?>>
                            <div class="badge-fte"><?php echo htmlspecialchars($p['fuente_financiamiento']); ?></div><br>
                            <div class="badge-cta" title="<?php echo htmlspecialchars($p['cuenta_contable']); ?>"><?php echo $f_cta; ?></div>
                        </td>
                        
                        <?php 
                        $acumulado_anterior = 0; 
                        foreach($meses_ordenados as $k => $nom): 
                            $val_pto = (float)$p['pto_'.$k];
                            $val_eje = (float)$p['eje_'.$k];
                            
                            $val_acum = $acumulado_anterior;
                            $disponible_mes = $val_acum + $val_pto;
                            $acumulado_anterior = $disponible_mes - $val_eje;

                            $totales_mensuales['pto_'.$k] += $val_pto;
                            $totales_mensuales['acum_'.$k] += $val_acum;
                            $totales_mensuales['eje_'.$k] += $val_eje;

                            $val_uni = htmlspecialchars($p['uni_'.$k] ?? '');
                            $val_r1 = htmlspecialchars($p['resp1_'.$k] ?? '');
                            $val_r2 = htmlspecialchars($p['resp2_'.$k] ?? '');
                            $val_est = htmlspecialchars($p['est_'.$k] ?? '');
                            $val_plan = htmlspecialchars($p['plan_'.$k] ?? '');
                            $purchase_pending = (float)($p['_purchase_pending'][$k] ?? 0);
                            $purchase_authorized = (float)($p['_purchase_authorized'][$k] ?? 0);
                            $manual_execution = (float)($p['_manual_execution'][$k] ?? max(0, $val_eje - $purchase_pending - $purchase_authorized));
                            $purchase_cell_class = $purchase_pending > 0.005
                                ? ' purchase-pending'
                                : ($purchase_authorized > 0.005 ? ' purchase-authorized' : '');
                            if ($manual_execution > 0.005) $purchase_cell_class .= ' has-manual';
                            $purchase_title = 'Total: L. ' . number_format($val_eje, 2)
                                . ' | Manual: L. ' . number_format($manual_execution, 2)
                                . ' | Compras autorizadas: L. ' . number_format($purchase_authorized, 2)
                                . ' | Compras pendientes: L. ' . number_format($purchase_pending, 2);
                            
                            $est_class = '';
                            if($val_est == 'Realizado') $est_class = 'est-realizado';
                            else if($val_est == 'En proceso') $est_class = 'est-proceso';
                            else if($val_est == 'Reprogramado') $est_class = 'est-reprogramado';
                            else if($val_est == 'Incompleto') $est_class = 'est-incompleto';
                            else if($val_est == 'Sin realizar') $est_class = 'est-sinrealizar';
                        ?>
                            <td class="cell-pto col-mes-<?php echo $k; ?> cell-pto-<?php echo $k; ?>" data-val="<?php echo $val_pto; ?>"><?php echo $val_pto > 0 ? number_format($val_pto, 2) : '-'; ?></td>
                            <td class="cell-acum col-mes-<?php echo $k; ?> cell-acum-<?php echo $k; ?> <?php echo $val_acum < 0 ? 'neg' : ''; ?>" data-val="<?php echo $val_acum; ?>"><?php echo $val_acum != 0 ? number_format($val_acum, 2) : '-'; ?></td>
                            <td class="cell-eje editable-eje col-mes-<?php echo $k; ?><?php echo $purchase_cell_class; ?>" tabindex="0" contenteditable="true" data-hash="<?php echo $hash; ?>" data-mes="<?php echo $k; ?>" data-val="<?php echo $val_eje; ?>" data-manual="<?php echo $manual_execution; ?>" data-purchase-pending="<?php echo $purchase_pending; ?>" data-purchase-authorized="<?php echo $purchase_authorized; ?>" title="<?php echo htmlspecialchars($purchase_title); ?>"><?php echo $val_eje > 0 ? number_format($val_eje, 2) : '-'; ?></td>
                            
                           <td class="col-mes-<?php echo $k; ?> track-col">
                                <select class="track-select track-lazy-select" data-list="unidades" data-hash="<?php echo $hash; ?>" data-campo="uni_<?php echo $k; ?>">
                                    <option value="<?php echo $val_uni; ?>" selected><?php echo $val_uni; ?></option>
                                </select>
                            </td>
                            <td class="col-mes-<?php echo $k; ?> track-col">
                                <select class="track-select track-lazy-select" data-list="responsables" data-hash="<?php echo $hash; ?>" data-campo="resp1_<?php echo $k; ?>">
                                    <option value="<?php echo $val_r1; ?>" selected><?php echo $val_r1; ?></option>
                                </select>
                            </td>
                            <td class="col-mes-<?php echo $k; ?> track-col">
                                <select class="track-select track-lazy-select" data-list="responsables" data-hash="<?php echo $hash; ?>" data-campo="resp2_<?php echo $k; ?>">
                                    <option value="<?php echo $val_r2; ?>" selected><?php echo $val_r2; ?></option>
                                </select>
                            </td>
                            <td class="col-mes-<?php echo $k; ?> track-col">
                                <select class="track-select track-lazy-select track-estado <?php echo $est_class; ?>" data-list="estados" data-hash="<?php echo $hash; ?>" data-campo="est_<?php echo $k; ?>" onchange="updateEstadoColor(this)">
                                    <option value="<?php echo $val_est; ?>" selected><?php echo $val_est; ?></option>
                                </select>
                            </td>
                            <td class="col-mes-<?php echo $k; ?> track-col">
                                <div class="track-plan" contenteditable="true" data-hash="<?php echo $hash; ?>" data-campo="plan_<?php echo $k; ?>"><?php echo $val_plan; ?></div>
                            </td>
                        <?php endforeach; ?>
                        
                        <td class="row-tot-pto" data-val="<?php echo $p['presupuesto_anual']; ?>" style="text-align:right; font-weight:bold; background:#f1f5f9; border-left:2px solid #cbd5e1;"><?php echo number_format($p['presupuesto_anual'], 2); ?></td>
                        <td class="row-tot-eje" style="text-align:right; color:#b45309; font-weight:bold; background:#fffbeb;"><?php echo number_format($p['ejecutado'], 2); ?></td>
                        <td class="row-saldo" style="text-align:right; font-weight:bold; color:<?php echo $disp >= 0 ? '#166534' : '#dc2626'; ?>; background:<?php echo $disp >= 0 ? '#dcfce7' : '#fee2e2'; ?>;"><?php echo number_format($disp, 2); ?></td>
                        <td style="text-align:center;"><button type="button" class="btn-trash" data-hash="<?php echo $hash; ?>" title="Eliminar fila"><i class="fa-solid fa-trash-can"></i></button></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr style="background:#0f172a; color:white; font-weight:bold;">
                        <td colspan="3" class="sticky-col" style="left:0; text-align:right; background:#0f172a; color:white;">TOTALES VISIBLES:</td>
                        <?php foreach($meses_ordenados as $k => $nom): ?>
                            <td class="col-mes-<?php echo $k; ?>" style="text-align:right; border-left:2px solid #94a3b8;" id="foot-pto-<?php echo $k; ?>"><?php echo number_format($totales_mensuales['pto_'.$k], 2); ?></td>
                            <td class="col-mes-<?php echo $k; ?>" style="text-align:right; background:#0284c7; color:white;" id="foot-acum-<?php echo $k; ?>"><?php echo number_format($totales_mensuales['acum_'.$k], 2); ?></td>
                            <td class="col-mes-<?php echo $k; ?>" style="text-align:right; color:#fbbf24;" id="foot-eje-<?php echo $k; ?>"><?php echo number_format($totales_mensuales['eje_'.$k], 2); ?></td>
                            <td colspan="5" class="col-mes-<?php echo $k; ?> track-col"></td> 
                        <?php endforeach; ?>
                        <td style="text-align:right; border-left:2px solid #94a3b8;" id="foot-pto-global"><?php echo number_format($total_pto, 2); ?></td>
                        <td style="text-align:right; color:#fbbf24;" id="foot-eje-global"><?php echo number_format($total_ejec, 2); ?></td>
                        <td style="text-align:right; color:#34d399;" id="foot-saldo-global"><?php echo number_format($total_pto - $total_ejec, 2); ?></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>
            <div class="excel-statusbar" id="excel-statusbar">
                <span>Selección: <strong id="sel-count">0</strong></span>
                <span>Suma: <strong id="sel-sum">0.00</strong></span>
                <span>Promedio: <strong id="sel-avg">0.00</strong></span>
                <span>Mínimo: <strong id="sel-min">0.00</strong></span>
                <span>Máximo: <strong id="sel-max">0.00</strong></span>
                <span style="margin-left:auto">Eje se guarda automáticamente al salir de la celda.</span>
            </div>
        </section>
        <?php endif; ?>
    </main>

    <div id="execution-audit-modal" class="modal-overlay" style="display:none;">
        <div class="audit-modal-card" role="dialog" aria-modal="true" aria-labelledby="execution-audit-title">
            <div class="audit-modal-head">
                <h3 id="execution-audit-title"><i class="fa-solid fa-clock-rotate-left"></i> Bitácora de ejecución mensual</h3>
                <button type="button" onclick="closeExecutionHistory()" aria-label="Cerrar"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="audit-current">
                <div><span>Total actual</span><strong id="audit-total">L. 0.00</strong></div>
                <div><span>Ajuste manual</span><strong id="audit-manual">L. 0.00</strong></div>
                <div><span>Compras autorizadas</span><strong id="audit-authorized">L. 0.00</strong></div>
                <div><span>Compras pendientes</span><strong id="audit-pending">L. 0.00</strong></div>
            </div>
            <div class="audit-table-wrap">
                <table class="audit-table">
                    <thead><tr><th>Fecha</th><th>Usuario</th><th>Acción</th><th>Manual</th><th>Total</th><th>Motivo</th></tr></thead>
                    <tbody id="execution-audit-body"><tr><td colspan="6" class="audit-empty">Seleccione una celda para consultar su historial.</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="dropdown-meses" class="filter-dropdown" style="width: 180px;">
        <div style="font-weight:bold; color:var(--ah-primary); margin-bottom:8px; border-bottom:1px solid #e2e8f0; padding-bottom:5px;">Columnas Visibles</div>
        <div class="filter-options">
            <label><input type="checkbox" onchange="toggleAllCheckboxes(this, 'chk-mes')"> (Todos los Meses)</label>
            <?php foreach($meses_ordenados as $k => $nom): ?>
                <label><input type="checkbox" class="chk-mes filter-chk" id="chk-mes-<?php echo $k; ?>" value="<?php echo $k; ?>" <?php echo $k === $current_month_key ? 'checked' : ''; ?> onchange="aplicarFiltroMeses()"> <?php echo $nom; ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <div id="dropdown-sector" class="filter-dropdown">
        <input type="text" class="filter-search" placeholder="Buscar..." onkeyup="filterOptionsList(this)">
        <div class="filter-options">
            <label><input type="checkbox" class="check-all" onchange="toggleAllCheckboxes(this, 'chk-sec')" checked> (Seleccionar Todo)</label>
            <?php foreach($sectores_unicos as $su): ?>
                <label><input type="checkbox" class="chk-sec filter-chk" value="<?php echo htmlspecialchars($su); ?>" checked> <?php echo htmlspecialchars($su); ?></label>
            <?php endforeach; ?>
        </div>
        <div class="filter-actions">
            <button class="btn-filter-action" onclick="limpiarFiltro('chk-sec')">Limpiar</button>
            <button class="btn-filter-action apply" onclick="aplicarFiltros('dropdown-sector')">Aceptar</button>
        </div>
    </div>

    <div id="dropdown-marco" class="filter-dropdown">
        <input type="text" class="filter-search" placeholder="Buscar..." onkeyup="filterOptionsList(this)">
        <div class="filter-options">
            <label><input type="checkbox" class="check-all" onchange="toggleAllCheckboxes(this, 'chk-mar')" checked> (Seleccionar Todo)</label>
            <?php foreach($marcos_unicos as $mu): ?>
                <label><input type="checkbox" class="chk-mar filter-chk" value="<?php echo htmlspecialchars($mu); ?>" checked> <?php echo htmlspecialchars($mu); ?></label>
            <?php endforeach; ?>
        </div>
        <div class="filter-actions">
            <button class="btn-filter-action" onclick="limpiarFiltro('chk-mar')">Limpiar</button>
            <button class="btn-filter-action apply" onclick="aplicarFiltros('dropdown-marco')">Aceptar</button>
        </div>
    </div>

    <div id="dropdown-imput" class="filter-dropdown">
        <input type="text" class="filter-search" placeholder="Buscar..." onkeyup="filterOptionsList(this)">
        <div class="filter-options">
            <label><input type="checkbox" class="check-all" onchange="toggleAllCheckboxes(this, 'chk-cta')" checked> (Seleccionar Todo)</label>
            <?php foreach($cuentas_unicas as $cu): ?>
                <label><input type="checkbox" class="chk-cta filter-chk" value="<?php echo htmlspecialchars($cu); ?>" checked> <?php echo htmlspecialchars($cu); ?></label>
            <?php endforeach; ?>
        </div>
        <div class="filter-actions">
            <button class="btn-filter-action" onclick="limpiarFiltro('chk-cta')">Limpiar</button>
            <button class="btn-filter-action apply" onclick="aplicarFiltros('dropdown-imput')">Aceptar</button>
        </div>
    </div>

    <div id="infoModal" class="modal-overlay" style="display: none;">
        <div style="background: white; border-radius: 12px; width: 450px; max-width: 90%; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1); padding: 25px; animation: slideUp 0.3s ease;">
            <h3 style="margin-top: 0; font-size: 1.2rem; color: #1e293b; border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; display:flex; align-items:center; gap:10px;">
                <i class="fa-solid fa-circle-info" style="color:var(--ah-primary);"></i> Detalles de Planificación
            </h3>
            <div style="margin-bottom: 15px;">
                <label style="font-size: 0.8rem; font-weight: bold; color: #64748b; text-transform: uppercase;">Concepto (Rubro Contable)</label>
                <div id="modalRubro" style="font-size: 0.95rem; color: #0f172a; margin-top: 5px; background: #f8fafc; padding: 12px; border-radius: 6px; border: 1px solid #e2e8f0; line-height:1.4;"></div>
            </div>
            <div style="margin-bottom: 20px;">
                <label style="font-size: 0.8rem; font-weight: bold; color: #64748b; text-transform: uppercase;">Participantes</label>
                <div id="modalPart" style="font-size: 1rem; color: #0f172a; margin-top: 5px; background: #f8fafc; padding: 10px; border-radius: 6px; border: 1px solid #e2e8f0;"></div>
            </div>
            <div style="text-align: right;">
                <button onclick="document.getElementById('infoModal').style.display='none'" style="background: var(--ah-primary); color: white; padding: 10px 20px; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; transition: 0.2s;">Entendido</button>
            </div>
        </div>
    </div>
<!-- LIBRERÍAS DESDE UNPKG (ÚNICO DOMINIO PERMITIDO POR EL CSP DE SU SERVIDOR) -->
<script src="https://unpkg.com/jquery@3.7.0/dist/jquery.min.js"></script>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

    <script>
        const meses_array = ['jul', 'aug', 'sep', 'oct', 'nov', 'dec', 'jan', 'feb', 'mar', 'apr', 'may', 'jun'];
        const selectedPoaName = <?php echo json_encode((string)$filtro_poa, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        let trackingVisible = true;
        let activeCell = null;
        let currentDropdown = null;

        // ==========================================
        // FUNCIONES GLOBALES 
        // ==========================================
        window.abrirDetalles = function(rubro, participantes) {
            document.getElementById('modalRubro').innerText = rubro;
            document.getElementById('modalPart').innerText = participantes;
            document.getElementById('infoModal').style.display = 'flex';
        };

        window.toggleTracking = function() {
            trackingVisible = !trackingVisible;
            if(trackingVisible) {
                $('.track-col').show();
                $('.th-top-mes').attr('colspan', 8);
                $('#btn-toggle-track').html('<i class="fa-solid fa-eye-slash"></i> Ocultar Seguimiento');
            } else {
                $('.track-col').hide();
                $('.th-top-mes').attr('colspan', 3);
                $('#btn-toggle-track').html('<i class="fa-solid fa-eye"></i> Mostrar Seguimiento');
            }
            if (typeof window.aplicarFiltroMeses === 'function') window.aplicarFiltroMeses();
        };

        window.updateEstadoColor = function(select) {
            let val = $(select).val();
            $(select).removeClass('est-realizado est-proceso est-reprogramado est-incompleto est-sinrealizar');
            if(val === 'Realizado') $(select).addClass('est-realizado');
            else if(val === 'En proceso') $(select).addClass('est-proceso');
            else if(val === 'Reprogramado') $(select).addClass('est-reprogramado');
            else if(val === 'Incompleto') $(select).addClass('est-incompleto');
            else if(val === 'Sin realizar') $(select).addClass('est-sinrealizar');
        };

        window.toggleDropdown = function(id) {
            let dp = $('#' + id);
            if(dp.is(':visible')) {
                dp.hide(); currentDropdown = null;
            } else {
                $('.filter-dropdown').hide(); 
                let btn = $(`[onclick="toggleDropdown('${id}')"]`);
                let offset = btn.offset();
                dp.css({ top: offset.top + btn.outerHeight() + 5 + 'px', left: offset.left + 'px' });
                dp.show(); currentDropdown = id;
            }
        };

        window.filterOptionsList = function(input) {
            let filter = $(input).val().toLowerCase();
            $(input).siblings('.filter-options').find('label:not(:first)').each(function() {
                $(this).toggle($(this).text().toLowerCase().indexOf(filter) > -1);
            });
        };

        window.toggleAllCheckboxes = function(mainCheckbox, childClass) {
            $(`.${childClass}`).prop('checked', $(mainCheckbox).prop('checked'));
            if(childClass === 'chk-mes' && typeof window.aplicarFiltroMeses === 'function') { window.aplicarFiltroMeses(); }
        };

        window.limpiarFiltro = function(childClass) {
            $(`.${childClass}`).prop('checked', true);
            $(`.${childClass}`).closest('.filter-options').find('.check-all').prop('checked', true);
        };

        function safeMathEval(exp) {
            if (exp === null || exp === undefined || String(exp).trim() === '') return 0;
            let source = String(exp).trim().replace(/^=/, '');
            if (source === '' || source === '-') return 0;

            // Acepta cifras simples tanto en formato 1,250.50 como 1.250,50.
            if (!/[+*/%]/.test(source) && /^[-+()\sL.$HNL0-9.,]+$/i.test(source)) {
                return parsePto(source);
            }

            const evalListFunction = function(name, argsText) {
                const args = argsText.split(/[;,]/).map(v => safeMathEval(v)).filter(v => Number.isFinite(v));
                if (!args.length) return 0;
                switch (name) {
                    case 'SUM': case 'SUMA': return args.reduce((a,b) => a+b, 0);
                    case 'AVERAGE': case 'PROMEDIO': return args.reduce((a,b) => a+b, 0) / args.length;
                    case 'MIN': return Math.min(...args);
                    case 'MAX': return Math.max(...args);
                    case 'ABS': return Math.abs(args[0] || 0);
                    case 'ROUND': case 'REDONDEAR': return Number((args[0] || 0).toFixed(Math.max(0, Math.min(8, args[1] || 0))));
                    default: return 0;
                }
            };

            let previous;
            do {
                previous = source;
                source = source.replace(/\b(SUMA|SUM|PROMEDIO|AVERAGE|MIN|MAX|ABS|REDONDEAR|ROUND)\s*\(([^()]*)\)/gi,
                    (_, fn, args) => String(evalListFunction(fn.toUpperCase(), args))
                );
            } while (source !== previous);

            let cleanExp = source.replace(/[L$\s]/gi, '').replace(/,/g, '');
            try {
                if (!/^[0-9+\-*/.()%]+$/.test(cleanExp)) return parseFloat(cleanExp) || 0;
                cleanExp = cleanExp.replace(/(\d+(?:\.\d+)?)%/g, '($1/100)');
                const result = Function('"use strict"; return (' + cleanExp + ')')();
                return Number.isFinite(result) ? result : 0;
            } catch(e) {
                return parseFloat(cleanExp) || 0;
            }
        }

        function parsePto(val) {
            if (!val) return 0;
            if (typeof val === 'number') return isFinite(val) ? val : 0;
            let s = String(val).trim();
            if (s === '' || s === '-' || s.toUpperCase() === 'NULL') return 0;

            s = s.replace(/L\.?|\$|HNL/gi, '').replace(/\s/g, '');
            const negative = /^\(|^-/.test(s);
            s = s.replace(/[()]/g, '').replace(/^-/,'');
            
            if (s.includes(',') && s.includes('.')) {
                if (s.lastIndexOf(',') > s.lastIndexOf('.')) s = s.replace(/\./g, '').replace(',', '.');
                else s = s.replace(/,/g, '');
            } else if (s.includes(',') && !s.includes('.')) {
                let parts = s.split(',');
                if (parts.length === 2 && parts[1].length <= 2) s = parts[0].replace(/,/g,'') + '.' + parts[1];
                else s = s.replace(/,/g, '');
            }
            let n = parseFloat(s);
            return negative ? -(isFinite(n)?n:0) : (isFinite(n)?n:0);
        }

        // ==========================================
        // JQUERY SEGURO 
        // ==========================================
        jQuery(document).ready(function($) {
            
            // ==========================================
// OPTIMIZACIÓN DE DOM (LAZY LOAD SELECTS)
// ==========================================
const listData = {
    unidades: <?php echo json_encode($cat_unidades); ?>,
    responsables: <?php echo json_encode($cat_responsables); ?>,
    estados: <?php echo json_encode($cat_estados); ?>
};

$(document).on('focus', '.track-lazy-select', function() {
    if ($(this).data('loaded')) return;
    let currentVal = $(this).val();
    let listType = $(this).data('list');
    let options = listData[listType] || [];
    let html = '<option value=""></option>';
    options.forEach(opt => {
        let safeOpt = opt.replace(/"/g, '&quot;');
        let selected = (opt === currentVal) ? 'selected' : '';
        html += `<option value="${safeOpt}" ${selected}>${safeOpt}</option>`;
    });
    $(this).html(html).data('loaded', true);
});
const uploadForm = document.getElementById('form-upload-excel');

if (uploadForm) {
    uploadForm.addEventListener('submit', function (event) {
        event.preventDefault();
        event.stopPropagation();

        const nombreInput = document.getElementById('nombre_poa_input');
        const archivoInput = document.getElementById('excel_file');
        const loader = document.getElementById('loader');

        const nombrePoa = nombreInput.value.trim();
        const archivo = archivoInput.files[0];

        if (nombrePoa === '') {
            alert('Escriba un nombre para el POA.');
            nombreInput.focus();
            return;
        }

        if (!archivo) {
            alert('Seleccione el archivo Excel que desea importar.');
            archivoInput.focus();
            return;
        }

        const extensionesPermitidas = ['xlsx', 'xls', 'csv'];
        const extension = archivo.name.split('.').pop().toLowerCase();

        if (!extensionesPermitidas.includes(extension)) {
            alert('El archivo debe estar en formato XLSX, XLS o CSV.');
            archivoInput.value = '';
            return;
        }

        if (typeof XLSX === 'undefined') {
            alert(
                'No se pudo cargar el lector de Excel. ' +
                'Recargue la página o desactive temporalmente las extensiones del navegador.'
            );
            return;
        }

        loader.innerHTML =
            '<div class="spinner"></div>' +
            '<div>Analizando el archivo POA...</div>';

        loader.style.display = 'flex';

        const reader = new FileReader();

        reader.onerror = function () {
            loader.style.display = 'none';
            alert('No fue posible leer el archivo seleccionado.');
            archivoInput.value = '';
        };

        reader.onload = function (eventReader) {
            try {
                const contenido = new Uint8Array(eventReader.target.result);

                const workbook = XLSX.read(contenido, {
                    type: 'array',
                    cellDates: false,
                    cellText: false,
                    cellNF: false
                });

                if (
                    !workbook.SheetNames ||
                    workbook.SheetNames.length === 0
                ) {
                    throw new Error('El archivo Excel no contiene hojas.');
                }

                const hojaObjetivo =
                    workbook.SheetNames.find(nombre =>
                        String(nombre).toUpperCase().includes('TEMPLATE POA')
                    ) ||
                    workbook.SheetNames.find(nombre =>
                        String(nombre).toUpperCase().includes('POA SEMESTRE')
                    ) ||
                    workbook.SheetNames.find(nombre =>
                        String(nombre).toUpperCase().includes('POA')
                    ) ||
                    workbook.SheetNames[0];

                const worksheet = workbook.Sheets[hojaObjetivo];

                if (!worksheet) {
                    throw new Error('No se pudo abrir la hoja del POA.');
                }

                const filas = XLSX.utils.sheet_to_json(worksheet, {
                    header: 1,
                    defval: '',
                    raw: true
                });

                if (!Array.isArray(filas) || filas.length < 6) {
                    throw new Error(
                        'La hoja no contiene el formato oficial del POA.'
                    );
                }

                const finalData = [];
                let filasOmitidas = 0;

                // El formato comienza en la fila 6.
                for (let i = 5; i < filas.length; i++) {
                    const r = Array.isArray(filas[i]) ? filas[i] : [];

                    const programa = String(r[0] || '').trim();
                    const codProg = String(r[1] || '').trim();
                    let sector = String(r[2] || '').trim();
                    const subsector = String(r[3] || '').trim();
                    let actividad = String(r[4] || '').trim();
                    const ext = String(r[5] || '').trim();
                    const comunidad = String(r[6] || '').trim();
                    const descripcion = String(r[7] || '').trim();

                    const encabezadoActividad = actividad.toLowerCase();
                    const encabezadoSector = sector.toLowerCase();

                    if (
                        encabezadoSector === 'sector' ||
                        encabezadoActividad === 'actividad' ||
                        encabezadoActividad === 'marco lógico' ||
                        encabezadoActividad === 'marco logico'
                    ) {
                        filasOmitidas++;
                        continue;
                    }

                    // Actividades: J a O.
                    const actJul = parsePto(r[9]);
                    const actAgo = parsePto(r[10]);
                    const actSep = parsePto(r[11]);
                    const actOct = parsePto(r[12]);
                    const actNov = parsePto(r[13]);
                    const actDic = parsePto(r[14]);

                    // Tipo de participante: W.
                    const tipoPart = String(r[22] || 'No definido').trim();

                    // Participantes: X a AC.
                    const partJul = parsePto(r[23]);
                    const partAgo = parsePto(r[24]);
                    const partSep = parsePto(r[25]);
                    const partOct = parsePto(r[26]);
                    const partNov = parsePto(r[27]);
                    const partDic = parsePto(r[28]);

                    // Presupuesto financiero: AW a BH.
                    const jul = parsePto(r[48]);
                    const aug = parsePto(r[49]);
                    const sep = parsePto(r[50]);
                    const oct = parsePto(r[51]);
                    const nov = parsePto(r[52]);
                    const dec = parsePto(r[53]);
                    const jan = parsePto(r[54]);
                    const feb = parsePto(r[55]);
                    const mar = parsePto(r[56]);
                    const apr = parsePto(r[57]);
                    const may = parsePto(r[58]);
                    const jun = parsePto(r[59]);

                    const totalActividades =
                        actJul + actAgo + actSep +
                        actOct + actNov + actDic;

                    const totalParticipantes =
                        partJul + partAgo + partSep +
                        partOct + partNov + partDic;

                    const totalAnual =
                        jul + aug + sep + oct + nov + dec +
                        jan + feb + mar + apr + may + jun;

                    const contenidoFila = [
                        programa,
                        codProg,
                        sector,
                        subsector,
                        actividad,
                        ext,
                        comunidad,
                        descripcion,
                        String(r[37] || ''),
                        String(r[39] || r[40] || ''),
                        String(r[41] || r[42] || ''),
                        String(r[43] || '')
                    ].some(valor => String(valor).trim() !== '');

                    if (
                        !contenidoFila &&
                        totalAnual === 0 &&
                        totalActividades === 0 &&
                        totalParticipantes === 0
                    ) {
                        filasOmitidas++;
                        continue;
                    }

                    if (sector === '') {
                        sector = 'Z_Gastos_No_Mapeados';
                    }

                    if (actividad === '') {
                        actividad = 'Línea Operativa ' + (i + 1);
                    }

                    const participantesTexto =
                        tipoPart +
                        ' | Jul: ' + partJul +
                        ' | Ago: ' + partAgo +
                        ' | Sep: ' + partSep +
                        ' | Oct: ' + partOct +
                        ' | Nov: ' + partNov +
                        ' | Dic: ' + partDic +
                        ' | Total: ' + totalParticipantes;

                    finalData.push({
                        id_fila: i + 1,
                        programa: programa,
                        cod_prog: codProg,
                        sector: sector,
                        subsector: subsector,
                        actividad: actividad,
                        descripcion: descripcion,
                        comunidad: comunidad,
                        ext: ext,

                        participantes: participantesTexto,
                        tipo_part: tipoPart,

                        cod_mae: String(r[37] || '').trim(),
                        fte: String(r[39] || r[40] || '').trim(),
                        cta: String(r[41] || r[42] || '').trim(),
                        rubro: String(r[43] || '').trim(),

                        jul: jul,
                        aug: aug,
                        sep: sep,
                        oct: oct,
                        nov: nov,
                        dec: dec,
                        jan: jan,
                        feb: feb,
                        mar: mar,
                        apr: apr,
                        may: may,
                        jun: jun,

                        act_jul: actJul,
                        act_aug: actAgo,
                        act_sep: actSep,
                        act_oct: actOct,
                        act_nov: actNov,
                        act_dec: actDic,

                        part_jul: partJul,
                        part_aug: partAgo,
                        part_sep: partSep,
                        part_oct: partOct,
                        part_nov: partNov,
                        part_dec: partDic,

                        meta_act: totalActividades,
                        meta_part: totalParticipantes
                    });
                }

                if (finalData.length === 0) {
                    throw new Error(
                        'No se encontraron filas válidas para importar.'
                    );
                }

                const confirmar = confirm(
                    'Hoja detectada: ' + hojaObjetivo +
                    '\nFilas válidas: ' + finalData.length +
                    '\nFilas omitidas: ' + filasOmitidas +
                    '\n\n¿Desea sincronizar el POA?'
                );

                if (!confirmar) {
                    loader.style.display = 'none';
                    return;
                }

                document.getElementById('real_nombre_poa').value =
                    nombrePoa;

                document.getElementById('poa_json').value =
                    JSON.stringify(finalData);

                document.getElementById('real-form').submit();

            } catch (error) {
                console.error('Error importando POA:', error);
                loader.style.display = 'none';

                alert(
                    'No se pudo procesar el POA:\n\n' +
                    error.message
                );
            }
        };

        reader.readAsArrayBuffer(archivo);
    });
}
            $(document).mouseup(function(e) {
                if (currentDropdown !== null) {
                    let container = $("#" + currentDropdown);
                    let btn = $(`[onclick="toggleDropdown('${currentDropdown}')"]`);
                    if (!container.is(e.target) && container.has(e.target).length === 0 && !btn.is(e.target) && btn.has(e.target).length === 0) {
                        container.hide(); currentDropdown = null;
                    }
                }
            });

            window.aplicarFiltros = function(dropdownId) {
                $('#' + dropdownId).hide(); currentDropdown = null;
                
                let sect = []; $('.chk-sec:checked').each(function(){ sect.push($(this).val()); });
                let marc = []; $('.chk-mar:checked').each(function(){ marc.push($(this).val()); });
                let ctas = []; $('.chk-cta:checked').each(function(){ ctas.push($(this).val()); });

                $(`[onclick="toggleDropdown('dropdown-sector')"]`).toggleClass('active', sect.length !== $('.chk-sec').length);
                $(`[onclick="toggleDropdown('dropdown-marco')"]`).toggleClass('active', marc.length !== $('.chk-mar').length);
                $(`[onclick="toggleDropdown('dropdown-imput')"]`).toggleClass('active', ctas.length !== $('.chk-cta').length);

                $('.data-row').each(function() {
                    let row = $(this);
                    if(sect.includes(row.data('fsec')) && marc.includes(row.data('fmar')) && ctas.includes(row.data('fcta'))) { row.show(); } 
                    else { row.hide(); }
                });
                recalcularTotales();
            }

            window.aplicarFiltroMeses = function() {
                meses_array.forEach(m => {
                    if($('#chk-mes-' + m).prop('checked')) { 
                        $('.col-mes-' + m).show(); 
                        if(!trackingVisible) $('.col-mes-' + m + '.track-col').hide();
                    } else { $('.col-mes-' + m).hide(); }
                });
            }

            $('.track-select').on('change', function() {
                $.post('poa.php', { action: 'update_tracking', hash_id: $(this).data('hash'), campo: $(this).data('campo'), valor: $(this).val() }, function(res) {
                    if(JSON.parse(res).status === 'ok') $('#toast').html('<i class="fa-solid fa-check"></i> Selección Guardada').css('background', '#166534').fadeIn().delay(1500).fadeOut();
                });
            });

            $('.track-plan').on('blur', function() {
                $.post('poa.php', { action: 'update_tracking', hash_id: $(this).data('hash'), campo: $(this).data('campo'), valor: $(this).text() }, function(res) {
                    if(JSON.parse(res).status === 'ok') $('#toast').html('<i class="fa-solid fa-comment-dots"></i> Plan de Acción Guardado').css('background', '#166534').fadeIn().delay(1500).fadeOut();
                });
            });

            const undoStack = [];
            const redoStack = [];
            let selectionAnchor = null;
            let suppressHistory = false;

            function numberText(value) {
                const n = Number(value) || 0;
                return Math.abs(n) > 0.000001
                    ? n.toLocaleString('es-HN', {minimumFractionDigits:2, maximumFractionDigits:2})
                    : '-';
            }

            async function postAction(payload) {
                const body = new URLSearchParams();
                Object.keys(payload).forEach(key => body.append(key, payload[key]));
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    headers: {'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},
                    body: body.toString(),
                    credentials: 'same-origin'
                });
                const textResponse = await response.text();
                let data;
                try { data = JSON.parse(textResponse); }
                catch (_) { throw new Error('El servidor devolvió una respuesta no válida.'); }
                if (!response.ok || data.status !== 'ok') throw new Error(data.msg || 'No fue posible guardar.');
                return data;
            }

            function moneyText(value) {
                return 'L. ' + (Number(value) || 0).toLocaleString('es-HN', {minimumFractionDigits:2, maximumFractionDigits:2});
            }

            function escapeAuditText(value) {
                return String(value ?? '').replace(/[&<>'"]/g, char => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
            }

            function applyCellBreakdown(cell, breakdown) {
                if (!cell || !cell.length || !breakdown) return;
                const total = Number(breakdown.total) || 0;
                const manual = Number(breakdown.manual) || 0;
                const authorized = Number(breakdown.authorized) || 0;
                const pending = Number(breakdown.pending) || 0;
                cell.data('manual', manual).attr('data-manual', manual)
                    .data('purchase-authorized', authorized).attr('data-purchase-authorized', authorized)
                    .data('purchase-pending', pending).attr('data-purchase-pending', pending)
                    .toggleClass('has-manual', manual > 0.005)
                    .removeClass('purchase-pending purchase-authorized');
                if (pending > 0.005) cell.addClass('purchase-pending');
                else if (authorized > 0.005) cell.addClass('purchase-authorized');
                cell.attr('title', `Total: ${moneyText(total)} | Manual: ${moneyText(manual)} | Compras autorizadas: ${moneyText(authorized)} | Compras pendientes: ${moneyText(pending)}`);
                if (activeCell && activeCell[0] === cell[0]) updateSourcePanel(cell);
            }

            function updateSourcePanel(cell) {
                if (!cell || !cell.length) {
                    $('#execution-source-panel').removeClass('active');
                    return;
                }
                $('#source-total').text(moneyText(cell.data('val')));
                $('#source-manual').text(moneyText(cell.data('manual')));
                $('#source-authorized').text(moneyText(cell.data('purchase-authorized')));
                $('#source-pending').text(moneyText(cell.data('purchase-pending')));
                $('#execution-source-panel').addClass('active');
            }

            window.closeExecutionHistory = function() {
                $('#execution-audit-modal').hide();
            };

            window.openExecutionHistory = async function() {
                if (!activeCell || !activeCell.length) return;
                const modal = $('#execution-audit-modal').css('display','flex');
                const body = $('#execution-audit-body').html('<tr><td colspan="6" class="audit-empty"><i class="fa-solid fa-spinner fa-spin"></i> Cargando bitácora...</td></tr>');
                try {
                    const data = await postAction({action:'execution_history', hash_id:String(activeCell.data('hash')), mes:String(activeCell.data('mes'))});
                    const detail = data.breakdown || {};
                    $('#audit-total').text(moneyText(detail.total));
                    $('#audit-manual').text(moneyText(detail.manual));
                    $('#audit-authorized').text(moneyText(detail.authorized));
                    $('#audit-pending').text(moneyText(detail.pending));
                    const items = data.items || [];
                    if (!items.length) {
                        body.html('<tr><td colspan="6" class="audit-empty">Esta celda todavía no tiene ajustes registrados.</td></tr>');
                        return;
                    }
                    body.html(items.map(item => `<tr>
                        <td>${escapeAuditText(item.creado_en)}</td>
                        <td><strong>${escapeAuditText(item.usuario_nombre || 'Usuario')}</strong></td>
                        <td>${escapeAuditText(item.accion)}</td>
                        <td>${moneyText(item.manual_anterior)} → <strong>${moneyText(item.manual_nuevo)}</strong></td>
                        <td>${moneyText(item.valor_anterior)} → <strong>${moneyText(item.valor_nuevo)}</strong></td>
                        <td>${escapeAuditText(item.motivo || 'Sin detalle')}</td>
                    </tr>`).join(''));
                } catch (error) {
                    body.html(`<tr><td colspan="6" class="audit-empty">${escapeAuditText(error.message)}</td></tr>`);
                }
                modal.find('.audit-modal-card').focus();
            };

            $('#execution-audit-modal').on('click', function(event) {
                if (event.target === this) closeExecutionHistory();
            });

            let purchaseRefreshRunning = false;
            let lastPurchaseRefreshAt = Date.now();

            window.refreshPurchaseExecutionCells = async function(showFeedback) {
                if (purchaseRefreshRunning || !selectedPoaName) return;
                if (document.activeElement && $(document.activeElement).hasClass('editable-eje')) return;

                purchaseRefreshRunning = true;
                lastPurchaseRefreshAt = Date.now();
                const btn = $('#btn-refresh-compras');
                const originalHtml = btn.html();
                if (showFeedback) btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Actualizando');

                try {
                    const data = await postAction({
                        action: 'refresh_compras_eje',
                        poa_name: selectedPoaName
                    });
                    const changedRows = new Set();
                    Object.keys(data.rows || {}).forEach(hash => {
                        const monthValues = data.rows[hash] || {};
                        Object.keys(monthValues).forEach(month => {
                            const info = monthValues[month] || {};
                            const cell = $(`.editable-eje[data-hash="${CSS.escape(hash)}"][data-mes="${month}"]`).first();
                            if (!cell.length || cell.is(':focus')) return;

                            const value = Number(info.value) || 0;
                            const manual = Number(info.manual) || 0;
                            const pending = Number(info.pending) || 0;
                            const authorized = Number(info.authorized) || 0;
                            const previous = Number(cell.data('val')) || 0;

                            cell.data('val', value)
                                .attr('data-val', value)
                                .attr('data-purchase-pending', pending)
                                .attr('data-purchase-authorized', authorized)
                                .text(numberText(value))
                                .removeClass('purchase-pending purchase-authorized');

                            applyCellBreakdown(cell, {total:value, manual, pending, authorized});

                            if (Math.abs(previous - value) > 0.005) changedRows.add(cell.closest('tr')[0]);
                        });
                    });

                    changedRows.forEach(row => recalcularFilaLocalmente($(row)));
                    recalcularTotales();

                    if (showFeedback) {
                        $('#toast').html('<i class="fa-solid fa-cart-shopping"></i> Ejecución de compras actualizada')
                            .css('background','#166534').fadeIn().delay(1800).fadeOut();
                    }
                } catch (error) {
                    if (showFeedback) alert('No fue posible actualizar las compras: ' + error.message);
                } finally {
                    purchaseRefreshRunning = false;
                    if (showFeedback) btn.prop('disabled', false).html(originalHtml);
                }
            };

            function visibleRows() {
                return $('#matrix-table tbody tr.data-row:visible').toArray();
            }

            function visibleMonths() {
                return meses_array.filter(m => $(`.editable-eje[data-mes='${m}']:visible`).length > 0);
            }

            function cellAt(rowIndex, monthIndex) {
                const rows = visibleRows();
                const months = visibleMonths();
                if (!rows[rowIndex] || !months[monthIndex]) return $();
                return $(rows[rowIndex]).find(`.editable-eje[data-mes='${months[monthIndex]}']`);
            }

            function coordsForCell(cell) {
                const rows = visibleRows();
                const months = visibleMonths();
                return {
                    row: rows.indexOf(cell.closest('tr')[0]),
                    col: months.indexOf(String(cell.data('mes')))
                };
            }

            function updateSelectionStats() {
                const values = $('.editable-eje.selected-eje-cell:visible').map(function(){
                    return Number($(this).data('val')) || 0;
                }).get();
                const count = values.length;
                const sum = values.reduce((a,b)=>a+b,0);
                const avg = count ? sum/count : 0;
                const min = count ? Math.min(...values) : 0;
                const max = count ? Math.max(...values) : 0;
                $('#sel-count').text(count);
                $('#sel-sum').text(numberText(sum).replace('-', '0.00'));
                $('#sel-avg').text(numberText(avg).replace('-', '0.00'));
                $('#sel-min').text(numberText(min).replace('-', '0.00'));
                $('#sel-max').text(numberText(max).replace('-', '0.00'));
            }

            window.limpiarSeleccionEje = function() {
                $('.editable-eje').removeClass('selected-eje-cell');
                selectionAnchor = null;
                updateSelectionStats();
            };

            function selectRectangle(fromCell, toCell) {
                const a = coordsForCell(fromCell);
                const b = coordsForCell(toCell);
                if (a.row < 0 || a.col < 0 || b.row < 0 || b.col < 0) return;
                limpiarSeleccionEje();
                const r1 = Math.min(a.row,b.row), r2 = Math.max(a.row,b.row);
                const c1 = Math.min(a.col,b.col), c2 = Math.max(a.col,b.col);
                for(let r=r1;r<=r2;r++) for(let c=c1;c<=c2;c++) cellAt(r,c).addClass('selected-eje-cell');
                updateSelectionStats();
            }

            async function saveEjeCell(cell, value, options = {}) {
                value = Number(value) || 0;
                const oldValue = Number(cell.data('val')) || 0;
                if (!options.force && Math.abs(oldValue - value) < 0.000001) {
                    cell.text(numberText(value));
                    return;
                }

                if (!suppressHistory && options.history !== false) {
                    undoStack.push({hash:String(cell.data('hash')), mes:String(cell.data('mes')), oldValue, newValue:value});
                    if (undoStack.length > 200) undoStack.shift();
                    redoStack.length = 0;
                    refreshHistoryButtons();
                }

                cell.data('val', value).text(numberText(value)).removeClass('error-cell saved-cell').addClass('saving-cell');
                recalcularFilaLocalmente(cell.closest('tr'));
                try {
                    const reason = String(options.reason ?? $('#execution-reason').val() ?? '').trim();
                    const response = await postAction({
                        action:'update_eje',
                        hash_id:String(cell.data('hash')),
                        mes:String(cell.data('mes')),
                        valor:String(value),
                        motivo:reason || 'Edición manual desde la matriz POA',
                        accion:String(options.action || 'edicion')
                    });
                    const persistedValue = Number(response.valor_mes);
                    if (Number.isFinite(persistedValue)) {
                        value = persistedValue;
                        cell.data('val', persistedValue).attr('data-val', persistedValue).text(numberText(persistedValue));
                        recalcularFilaLocalmente(cell.closest('tr'));
                    }
                    applyCellBreakdown(cell, response.breakdown || {});
                    if (!options.keepReason) $('#execution-reason').val('');
                    cell.removeClass('saving-cell').addClass('saved-cell');
                    $('#toast').html('<i class="fa-solid fa-floppy-disk"></i> Ejecución mensual guardada')
                        .css('background','#166534').stop(true,true).fadeIn().delay(900).fadeOut();
                    setTimeout(()=>cell.removeClass('saved-cell'), 650);
                } catch (error) {
                    cell.removeClass('saving-cell').addClass('error-cell');
                    cell.data('val', oldValue).text(numberText(oldValue));
                    recalcularFilaLocalmente(cell.closest('tr'));
                    throw error;
                }
            }

            function refreshHistoryButtons() {
                $('#btn-undo-eje').prop('disabled', undoStack.length === 0);
                $('#btn-redo-eje').prop('disabled', redoStack.length === 0);
            }

            function findEjeCell(hash, mes) {
                return $(`.editable-eje[data-hash='${CSS.escape(hash)}'][data-mes='${mes}']`).first();
            }

            window.undoEje = async function() {
                const change = undoStack.pop();
                if (!change) return;
                const cell = findEjeCell(change.hash, change.mes);
                if (!cell.length) return;
                suppressHistory = true;
                try {
                    await saveEjeCell(cell, change.oldValue, {force:true,history:false,action:'deshacer',reason:'Deshacer ajuste desde la matriz POA'});
                    redoStack.push(change);
                } catch (error) { alert(error.message); undoStack.push(change); }
                finally { suppressHistory = false; refreshHistoryButtons(); }
            };

            window.redoEje = async function() {
                const change = redoStack.pop();
                if (!change) return;
                const cell = findEjeCell(change.hash, change.mes);
                if (!cell.length) return;
                suppressHistory = true;
                try {
                    await saveEjeCell(cell, change.newValue, {force:true,history:false,action:'rehacer',reason:'Rehacer ajuste desde la matriz POA'});
                    undoStack.push(change);
                } catch (error) { alert(error.message); redoStack.push(change); }
                finally { suppressHistory = false; refreshHistoryButtons(); }
            };

            $('.editable-eje').on('mousedown', function(e) {
                const cell = $(this);
                if (e.shiftKey && selectionAnchor && selectionAnchor.length) {
                    e.preventDefault();
                    selectRectangle(selectionAnchor, cell);
                } else if (e.ctrlKey || e.metaKey) {
                    cell.toggleClass('selected-eje-cell');
                    selectionAnchor = cell;
                    updateSelectionStats();
                } else {
                    limpiarSeleccionEje();
                    cell.addClass('selected-eje-cell');
                    selectionAnchor = cell;
                    updateSelectionStats();
                }
            });

            $('.editable-eje').on('focus', function() {
                activeCell = $(this).addClass('active-eje-cell');
                $('#formula-container').addClass('active');
                const val = Number(activeCell.data('val')) || 0;
                $('#formula-bar').val(val !== 0 ? val : '');
                updateSourcePanel(activeCell);
                $('.styled-table tbody tr').removeClass('active-row');
                activeCell.closest('tr').addClass('active-row');
            }).on('blur', async function() {
                const cell = $(this).removeClass('active-eje-cell');
                const raw = cell.text().trim();
                const value = safeMathEval(raw === '' || raw === '-' ? '0' : raw);
                $('#formula-container').removeClass('active');
                try { await saveEjeCell(cell, value); }
                catch (error) { alert('Error al guardar la ejecución: ' + error.message); }
            });

            $('.editable-eje').on('input', function() {
                if(activeCell && activeCell[0] === this) $('#formula-bar').val($(this).text());
            });
            $('#formula-bar').on('input', function() {
                if(activeCell) activeCell.text($(this).val());
            });
            $('#formula-bar').on('keydown', function(e) {
                if(e.key === 'Enter') {
                    e.preventDefault();
                    if(activeCell) activeCell.blur();
                }
            });

            $('.editable-eje').on('keydown', function(e) {
                const cell = $(this);
                const coord = coordsForCell(cell);
                let target = null;
                if (e.key === 'Enter') target = cellAt(coord.row + (e.shiftKey ? -1 : 1), coord.col);
                else if (e.key === 'ArrowDown') target = cellAt(coord.row+1, coord.col);
                else if (e.key === 'ArrowUp') target = cellAt(coord.row-1, coord.col);
                else if (e.key === 'ArrowRight' && !cell.text().trim()) target = cellAt(coord.row, coord.col+1);
                else if (e.key === 'ArrowLeft' && !cell.text().trim()) target = cellAt(coord.row, coord.col-1);
                else if (e.key === 'Tab') target = cellAt(coord.row, coord.col + (e.shiftKey ? -1 : 1));
                if (target && target.length) {
                    e.preventDefault();
                    cell.blur();
                    setTimeout(()=>target.focus(), 0);
                }
            });

            function recalcularFilaLocalmente(row, skipTotals = false) {
                let carry = 0;
                let totalEje = 0;
                meses_array.forEach(function(m) {
                    const tdPto = row.find(`.cell-pto-${m}`);
                    const tdAcum = row.find(`.cell-acum-${m}`);
                    const tdEje = row.find(`.editable-eje[data-mes='${m}']`);
                    const pto = Number(tdPto.data('val')) || 0;
                    const eje = Number(tdEje.data('val')) || 0;

                    // Acum muestra exactamente el sobrante o déficit recibido del mes anterior.
                    tdAcum.data('val', carry).text(numberText(carry));
                    tdAcum.toggleClass('neg', carry < -0.005);
                    carry = carry + pto - eje;
                    totalEje += eje;
                });

                const totalPto = Number(row.find('.row-tot-pto').data('val')) || 0;
                const saldo = totalPto - totalEje;
                row.attr('data-final-balance', carry).toggleClass('row-no-funds', carry < -0.005);
                row.find('.funding-alert').remove();
                if (carry < -0.005) {
                    row.find('td:first .sticky-col').remove();
                    row.find('td:first').append('<span class="funding-alert"><i class="fa-solid fa-triangle-exclamation"></i> Sin fondos suficientes</span>');
                }
                row.find('.row-tot-eje').text(numberText(totalEje));
                row.find('.row-saldo').text(numberText(saldo)).css({
                    color: saldo < 0 ? '#991b1b' : '#166534',
                    background: saldo < 0 ? '#fecaca' : '#dcfce7'
                });
                if (!skipTotals) recalcularTotales();
            }

            window.recalcularTodasLasFilas = function() {
                $('#matrix-table tbody tr.data-row').each(function(){ recalcularFilaLocalmente($(this), true); });
                recalcularTotales();
                $('#toast').html('<i class="fa-solid fa-calculator"></i> Acumulados recalculados').css('background','#166534').fadeIn().delay(1300).fadeOut();
            };

            function recalcularTotales() {
                let granTotalPto = 0, granTotalEje = 0;
                const rows = $('#matrix-table tbody tr.data-row:visible');
                rows.each(function(){ granTotalPto += Number($(this).find('.row-tot-pto').data('val')) || 0; });
                meses_array.forEach(function(m) {
                    let sumPto=0, sumAcum=0, sumEje=0;
                    rows.each(function(){
                        sumPto += Number($(this).find(`.cell-pto-${m}`).data('val')) || 0;
                        sumAcum += Number($(this).find(`.cell-acum-${m}`).data('val')) || 0;
                        sumEje += Number($(this).find(`.editable-eje[data-mes='${m}']`).data('val')) || 0;
                    });
                    $(`#foot-pto-${m}`).text(numberText(sumPto));
                    $(`#foot-acum-${m}`).text(numberText(sumAcum));
                    $(`#foot-eje-${m}`).text(numberText(sumEje));
                    granTotalEje += sumEje;
                });
                const saldo = granTotalPto-granTotalEje;
                $('#foot-pto-global').text(numberText(granTotalPto));
                $('#foot-eje-global').text(numberText(granTotalEje));
                $('#foot-saldo-global').text(numberText(saldo));
                $('#pto_global_ui').text('L. '+numberText(granTotalPto).replace('-','0.00'));
                $('#total_ejec_global_ui').text('L. '+numberText(granTotalEje).replace('-','0.00'));
                $('#saldo_global_ui').text('L. '+numberText(saldo).replace('-','0.00')).css('color',saldo<0?'#dc2626':'#166534');
                updateSelectionStats();
            }

            async function bulkSave(updates, cells) {
                if (!updates.length) return;
                cells.forEach(c=>c.addClass('saving-cell').removeClass('error-cell'));
                try {
                    const reason = String($('#execution-reason').val() || '').trim();
                    await postAction({action:'bulk_update_eje', updates:JSON.stringify(updates), motivo:reason || 'Pegado múltiple en la matriz POA'});
                    cells.forEach(c=>{
                        const total = Number(c.data('val')) || 0;
                        const pending = Number(c.data('purchase-pending')) || 0;
                        const authorized = Number(c.data('purchase-authorized')) || 0;
                        applyCellBreakdown(c, {total, pending, authorized, manual:Math.max(0,total-pending-authorized)});
                        c.removeClass('saving-cell').addClass('saved-cell');
                        setTimeout(()=>c.removeClass('saved-cell'),650);
                    });
                    $('#execution-reason').val('');
                } catch(error) {
                    cells.forEach(c=>c.removeClass('saving-cell').addClass('error-cell'));
                    throw error;
                }
            }

            $('.editable-eje').on('paste', async function(e) {
                e.preventDefault();
                const clipboard = (e.originalEvent || e).clipboardData.getData('text/plain');
                const matrix = clipboard.replace(/\r/g,'').split('\n').filter((r,i,a)=>!(i===a.length-1 && r==='')).map(r=>r.split('\t'));
                if (!matrix.length) return;

                const start = coordsForCell($(this));
                const rows = visibleRows();
                const months = visibleMonths();
                const updates = [];
                const touched = [];
                const affectedRows = new Set();

                matrix.forEach((values, rOffset) => {
                    values.forEach((rawValue, cOffset) => {
                        const rowIndex = start.row + rOffset;
                        const colIndex = start.col + (matrix[0].length === 1 ? 0 : cOffset);
                        if (!rows[rowIndex] || !months[colIndex]) return;
                        const cell = $(rows[rowIndex]).find(`.editable-eje[data-mes='${months[colIndex]}']`);
                        if (!cell.length) return;
                        const oldValue = Number(cell.data('val')) || 0;
                        const value = safeMathEval(rawValue);
                        undoStack.push({hash:String(cell.data('hash')),mes:String(cell.data('mes')),oldValue,newValue:value});
                        cell.data('val',value).text(numberText(value));
                        updates.push({hash_id:String(cell.data('hash')),mes:String(cell.data('mes')),valor:value});
                        touched.push(cell);
                        affectedRows.add(rows[rowIndex]);
                    });
                });
                redoStack.length=0; refreshHistoryButtons();
                affectedRows.forEach(row=>recalcularFilaLocalmente($(row), true));
                recalcularTotales();
                try {
                    await bulkSave(updates,touched);
                    $('#toast').html(`<i class="fa-solid fa-table-cells"></i> ${updates.length} celdas pegadas y guardadas`).css('background','#166534').fadeIn().delay(1800).fadeOut();
                } catch(error) { alert('Error al pegar desde Excel: '+error.message); }
            });

            window.copiarSeleccionEje = async function() {
                let selected = $('.editable-eje.selected-eje-cell:visible');
                if (!selected.length && activeCell) selected = activeCell;
                if (!selected.length) return alert('Seleccione al menos una celda Eje.');
                const coords = selected.map(function(){const c=coordsForCell($(this));return {...c,val:Number($(this).data('val'))||0};}).get();
                const rMin=Math.min(...coords.map(c=>c.row)), rMax=Math.max(...coords.map(c=>c.row));
                const cMin=Math.min(...coords.map(c=>c.col)), cMax=Math.max(...coords.map(c=>c.col));
                const map=new Map(coords.map(c=>[`${c.row}|${c.col}`,c.val]));
                const lines=[];
                for(let r=rMin;r<=rMax;r++){
                    const vals=[]; for(let c=cMin;c<=cMax;c++) vals.push(map.has(`${r}|${c}`)?map.get(`${r}|${c}`):'');
                    lines.push(vals.join('\t'));
                }
                await navigator.clipboard.writeText(lines.join('\n'));
                $('#toast').html('<i class="fa-solid fa-copy"></i> Selección copiada').css('background','#166534').fadeIn().delay(1200).fadeOut();
            };

            window.toggleMatrixFullscreen = async function() {
                const workspace=document.getElementById('matrix-workspace');
                try {
                    if (!document.fullscreenElement) await workspace.requestFullscreen();
                    else await document.exitFullscreen();
                } catch(error) { alert('No se pudo activar pantalla completa: '+error.message); }
            };
            document.addEventListener('fullscreenchange', function(){
                const active=!!document.fullscreenElement;
                document.body.classList.toggle('matrix-is-fullscreen',active);
                $('#btn-matrix-fullscreen').html(active?'<i class="fa-solid fa-compress"></i> Salir de pantalla completa':'<i class="fa-solid fa-expand"></i> Pantalla completa');
            });

            window.exportarMatrizXLSX = function() {
                if (typeof XLSX === 'undefined') return alert('El lector XLSX todavía no está disponible.');
                const clone=document.getElementById('matrix-table').cloneNode(true);
                clone.querySelectorAll('[style*="display: none"], .btn-trash').forEach(el=>el.remove());
                Array.from(clone.querySelectorAll('tbody tr')).forEach((tr,index)=>{
                    const original=$('#matrix-table tbody tr').eq(index);
                    if (!original.is(':visible')) tr.remove();
                });
                clone.querySelectorAll('[contenteditable]').forEach(el=>el.removeAttribute('contenteditable'));
                const wb=XLSX.utils.table_to_book(clone,{sheet:'Matriz POA',raw:true});
                const safeName=('Matriz_'+String($('select[name="poa"]').val()||'POA')).replace(/[^a-z0-9_-]+/gi,'_');
                XLSX.writeFile(wb,safeName+'.xlsx');
            };

            if ($('#matrix-table').length) {
                aplicarFiltroMeses();
                recalcularTodasLasFilas();
                refreshHistoryButtons();
            }

            $('.btn-trash').on('click', function() {
                const btn=$(this); if(!confirm('¿Eliminar esta línea presupuestaria?')) return;
                const row=btn.closest('tr'); btn.html('<i class="fa-solid fa-spinner fa-spin"></i>');
                $.post(window.location.pathname,{action:'delete_row',hash_id:btn.data('hash')},function(res){
                    try { if(JSON.parse(res).status==='ok') row.fadeOut(250,function(){$(this).remove();recalcularTotales();}); }
                    catch(_){ btn.html('<i class="fa-solid fa-trash-can"></i>'); }
                });
            });

            // ==========================================
            // PROCESAMIENTO EXCEL MEJORADO (CON ALERTAS)
            // ==========================================

            const purchaseRefreshIntervalMs = 120000;
            setInterval(function(){
                if (!document.hidden) refreshPurchaseExecutionCells(false);
            }, purchaseRefreshIntervalMs);
            window.addEventListener('focus', function(){
                if (Date.now() - lastPurchaseRefreshAt >= purchaseRefreshIntervalMs) {
                    refreshPurchaseExecutionCells(false);
                }
            });
        });
    </script>
</body>
</html>
