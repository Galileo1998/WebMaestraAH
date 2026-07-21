<?php

if (!defined('COMPRAS_ADMIN_DIR')) {
    throw new RuntimeException('No se definió el directorio del módulo de compras.');
}

/* ========================================================
   UTILIDADES
   ======================================================== */
function h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function jsonOut(array $payload, int $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function addColSafe(PDO $db, string $table, string $column, string $definition) {
    $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
    if ($st && $st->rowCount() === 0) {
        $db->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

function csrfToken(): string {
    if (empty($_SESSION['compras_csrf'])) {
        $_SESSION['compras_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['compras_csrf'];
}

function validateCsrf() {
    $token = (string)($_POST['csrf_token'] ?? '');
    if ($token === '' || !hash_equals((string)($_SESSION['compras_csrf'] ?? ''), $token)) {
        throw new RuntimeException('La sesión del formulario venció. Recargue la página e intente nuevamente.');
    }
}

function stateRank(string $state): int {
    if (strpos($state, '6_') === 0) return 6;
    if (strpos($state, '5_') === 0) return 5;
    if (strpos($state, '4_') === 0) return 4;
    if (strpos($state, '3_') === 0) return 3;
    if (strpos($state, '2_') === 0) return 2;
    return 1;
}

function monthKeyFromDate(string $date): string {
    $ts = strtotime($date ?: 'now');
    $map = [1=>'jan',2=>'feb',3=>'mar',4=>'apr',5=>'may',6=>'jun',7=>'jul',8=>'aug',9=>'sep',10=>'oct',11=>'nov',12=>'dec'];
    return $map[(int)date('n', $ts)] ?? 'jul';
}

function normalizeMonth(string $month): string {
    $valid = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
    return in_array($month, $valid, true) ? $month : monthKeyFromDate(date('Y-m-d'));
}

function currentUserLabel(): string {
    $candidates = [
        $_SESSION['name'] ?? null,
        $_SESSION['nombre'] ?? null,
        $_SESSION['user_name'] ?? null,
        $_SESSION['email'] ?? null,
        is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['name'] ?? $_SESSION['user']['email'] ?? null) : null,
    ];
    foreach ($candidates as $value) {
        $value = trim((string)$value);
        if ($value !== '') return $value;
    }
    return 'Usuario autenticado';
}

function currentCompraUserId(): int {
    return (int)($_SESSION['user_id'] ?? 0);
}

function currentCompraUserIsAdmin(): bool {
    return (string)($_SESSION['user_role'] ?? '') === 'admin';
}

function verifyCurrentCompraPassword(PDO $db, string $password): bool {
    if ($password === '' || currentCompraUserId() <= 0) return false;
    $st = $db->prepare('SELECT password FROM users WHERE id=? LIMIT 1');
    $st->execute([currentCompraUserId()]);
    $hash = (string)$st->fetchColumn();
    return $hash !== '' && password_verify($password, $hash);
}

function purchaseEditUnlocked(int $purchaseId): bool {
    $until = (int)($_SESSION['compras_edit_unlock'][(string)$purchaseId] ?? 0);
    return $until >= time();
}

function assertPurchaseAccess(PDO $db, int $purchaseId): void {
    if (currentCompraUserIsAdmin()) return;
    try {
        $st = $db->prepare('SELECT usuario_id FROM ah_compras_propiedad WHERE compra_id=? LIMIT 1');
        $st->execute([$purchaseId]);
        if ((int)$st->fetchColumn() === currentCompraUserId()) return;
    } catch (Throwable $e) {}
    throw new RuntimeException('No tiene permiso para consultar o modificar este expediente de compra.');
}

function comprasColumnExists(PDO $db, string $table, string $column): bool {
    try {
        $st = $db->query("SHOW COLUMNS FROM `{$table}` LIKE " . $db->quote($column));
        return $st && $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function comprasFormatoFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;

    $base = dirname(COMPRAS_ADMIN_DIR);
    $dirs = [
        $base . '/storage',
        $base . '/data',
        COMPRAS_ADMIN_DIR,
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/compras_formatos_store.json';
            return $path;
        }
    }

    $path = '';
    return $path;
}

function readCompraFormatoFileStore(): array {
    $path = comprasFormatoFilePath();
    if ($path === '' || !is_file($path)) return [];

    $handle = @fopen($path, 'rb');
    if (!$handle) return [];

    try {
        @flock($handle, LOCK_SH);
        $json = stream_get_contents($handle);
        @flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function writeCompraFormatoFileStore(int $compraId, string $format): bool {
    $path = comprasFormatoFilePath();
    if ($path === '') return false;

    $handle = @fopen($path, 'c+');
    if (!$handle) return false;

    try {
        if (!@flock($handle, LOCK_EX)) return false;
        rewind($handle);
        $json = stream_get_contents($handle);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) $data = [];

        $data[(string)$compraId] = [
            'formato' => normalizeCompraFormato($format),
            'updated_at' => date('c'),
        ];

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

function normalizeCompraFormato($format): string {
    $format = strtoupper(trim((string)$format));
    return in_array($format, ['A','B','C'], true) ? $format : 'A';
}


/**
 * Almacenamiento compatible del mes de ejecución.
 * Usa la columna mes_ejecucion cuando existe; si el hosting aún no permitió
 * crearla, conserva el valor en un archivo local y en la sesión.
 */
function comprasMesFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;

    $base = dirname(COMPRAS_ADMIN_DIR);
    $dirs = [
        $base . '/storage',
        $base . '/data',
        COMPRAS_ADMIN_DIR,
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/compras_meses_ejecucion_store.json';
            return $path;
        }
    }

    $path = '';
    return $path;
}

function readCompraMesFileStore(): array {
    $path = comprasMesFilePath();
    if ($path === '' || !is_file($path)) return [];

    $handle = @fopen($path, 'rb');
    if (!$handle) return [];
    try {
        @flock($handle, LOCK_SH);
        $json = stream_get_contents($handle);
        @flock($handle, LOCK_UN);
    } finally {
        fclose($handle);
    }

    $data = json_decode((string)$json, true);
    return is_array($data) ? $data : [];
}

function writeCompraMesFileStore(int $compraId, string $month): bool {
    $path = comprasMesFilePath();
    if ($path === '') return false;

    $handle = @fopen($path, 'c+');
    if (!$handle) return false;
    try {
        if (!@flock($handle, LOCK_EX)) return false;
        rewind($handle);
        $json = stream_get_contents($handle);
        $data = json_decode((string)$json, true);
        if (!is_array($data)) $data = [];

        $data[(string)$compraId] = [
            'mes' => normalizeMonth($month),
            'updated_at' => date('c'),
        ];

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

function getCompraExecutionMonth(PDO $db, int $compraId, array $purchase = []): string {
    if (array_key_exists('mes_ejecucion', $purchase)) {
        $value = trim((string)$purchase['mes_ejecucion']);
        if ($value !== '') return normalizeMonth($value);
    }

    if (isset($_SESSION['compras_meses_ejecucion'][(string)$compraId])) {
        return normalizeMonth((string)$_SESSION['compras_meses_ejecucion'][(string)$compraId]);
    }

    $data = readCompraMesFileStore();
    if (isset($data[(string)$compraId])) {
        $entry = $data[(string)$compraId];
        $value = is_array($entry) ? ($entry['mes'] ?? '') : $entry;
        if (trim((string)$value) !== '') return normalizeMonth((string)$value);
    }

    return monthKeyFromDate((string)($purchase['fecha'] ?? date('Y-m-d')));
}

function saveCompraExecutionMonth(PDO $db, int $compraId, string $month): string {
    $month = normalizeMonth($month);
    if (!isset($_SESSION['compras_meses_ejecucion']) || !is_array($_SESSION['compras_meses_ejecucion'])) {
        $_SESSION['compras_meses_ejecucion'] = [];
    }
    $_SESSION['compras_meses_ejecucion'][(string)$compraId] = $month;

    if (comprasColumnExists($db, 'ah_compras', 'mes_ejecucion')) {
        $st = $db->prepare('UPDATE ah_compras SET mes_ejecucion=? WHERE id=?');
        $st->execute([$month, $compraId]);
        return 'column';
    }

    if (writeCompraMesFileStore($compraId, $month)) return 'file';
    return 'session';
}

function getCompraFormato(PDO $db, int $compraId, array $purchase = []): string {
    if (array_key_exists('formato_compra', $purchase)) {
        $value = trim((string)$purchase['formato_compra']);
        if ($value !== '') return normalizeCompraFormato($value);
    }

    if (isset($_SESSION['compras_formatos'][(string)$compraId])) {
        return normalizeCompraFormato($_SESSION['compras_formatos'][(string)$compraId]);
    }

    if (tableExists($db, 'ah_compras_formatos')) {
        try {
            $st = $db->prepare('SELECT formato FROM ah_compras_formatos WHERE compra_id=? LIMIT 1');
            $st->execute([$compraId]);
            $value = $st->fetchColumn();
            if ($value !== false) return normalizeCompraFormato($value);
        } catch (Throwable $e) {}
    }

    $fileData = readCompraFormatoFileStore();
    if (isset($fileData[(string)$compraId])) {
        $entry = $fileData[(string)$compraId];
        $value = is_array($entry) ? ($entry['formato'] ?? 'A') : $entry;
        return normalizeCompraFormato($value);
    }

    return 'A';
}

function saveCompraFormato(PDO $db, int $compraId, string $format): string {
    $format = normalizeCompraFormato($format);

    // Siempre conserva el valor en la sesión para no bloquear el flujo,
    // aun cuando el servidor no permita crear tablas auxiliares.
    if (!isset($_SESSION['compras_formatos']) || !is_array($_SESSION['compras_formatos'])) {
        $_SESSION['compras_formatos'] = [];
    }
    $_SESSION['compras_formatos'][(string)$compraId] = $format;

    if (comprasColumnExists($db, 'ah_compras', 'formato_compra')) {
        $st = $db->prepare('UPDATE ah_compras SET formato_compra=? WHERE id=?');
        $st->execute([$format, $compraId]);
        return 'column';
    }

    if (tableExists($db, 'ah_compras_formatos')) {
        try {
            $st = $db->prepare(
                'INSERT INTO ah_compras_formatos (compra_id, formato) VALUES (?,?) '
                . 'ON DUPLICATE KEY UPDATE formato=VALUES(formato)'
            );
            $st->execute([$compraId, $format]);
            return 'table';
        } catch (Throwable $e) {
            // Continúa con almacenamiento en archivo.
        }
    }

    if (writeCompraFormatoFileStore($compraId, $format)) {
        return 'file';
    }

    // Último recurso: queda en sesión. Esto permite continuar y evita
    // que la indisponibilidad de /tmp paralice el expediente.
    return 'session';
}

function lockPurchase(PDO $db, int $id): array {
    assertPurchaseAccess($db, $id);
    $st = $db->prepare('SELECT * FROM ah_compras WHERE id=? FOR UPDATE');
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('El expediente de compra no existe.');
    $row['formato_compra'] = getCompraFormato($db, $id, $row);
    return $row;
}

function ensureEditable(array $purchase, int $maximumRank = 4) {
    if (purchaseEditUnlocked((int)($purchase['id'] ?? 0))) return;
    if (stateRank((string)($purchase['estado'] ?? '')) >= 5) {
        throw new RuntimeException('El expediente ya fue autorizado y no puede modificarse.');
    }
    if (stateRank((string)($purchase['estado'] ?? '')) > $maximumRank) {
        throw new RuntimeException('La etapa actual no permite esta modificación.');
    }
}

function money($value): float {
    $n = (float)$value;
    return round(max(0, $n), 2);
}

function quantity($value): float {
    $n = (float)$value;
    return round(max(0, $n), 2);
}

function compactPoaLabel($marcoLogico, $extension = '') {
    $marcoLogico = trim((string)$marcoLogico);
    $extension = trim((string)$extension);
    if ($marcoLogico === '') return $extension !== '' ? $extension : 'Línea POA';
    $parts = preg_split('/\s+/', $marcoLogico);
    $code = trim((string)($parts[0] ?? $marcoLogico), " \t\n\r\0\x0B:;,-");
    return trim($code . ($extension !== '' ? ' ' . $extension : ''));
}

function comprasNormalizeLineCode(string $value): string {
    $value = trim($value);
    if ($value === '') return '';
    $parts = preg_split('/\s+/', $value);
    $code = strtoupper(trim((string)($parts[0] ?? $value)));
    return preg_replace('/[^A-Z0-9._-]+/', '', $code);
}

function comprasNormalizeExt(string $value): string {
    return preg_replace('/[^A-Z0-9._-]+/', '', strtoupper(trim($value)));
}

function comprasNormalizeAccount(string $value): string {
    $value = strtoupper(trim($value));
    if ($value === '') return '';
    $parts = preg_split('/\s+-\s+/', $value, 2);
    return preg_replace('/[^A-Z0-9._-]+/', '', trim((string)($parts[0] ?? $value)));
}

/**
 * Resuelve la línea vigente del POA. Prioriza el hash guardado y, cuando el
 * POA fue sincronizado nuevamente, busca por código del marco lógico,
 * extensión y cuenta contable para mantener la afectación en la fila correcta.
 */
function resolveCurrentPoaHash(PDO $db, array $purchaseLine): string {
    $storedHash = trim((string)($purchaseLine['poa_hash'] ?? ''));
    if ($storedHash !== '') {
        $st = $db->prepare('SELECT hash_id FROM ah_poa WHERE hash_id=? LIMIT 1');
        $st->execute([$storedHash]);
        $found = $st->fetchColumn();
        if ($found) return (string)$found;
    }

    $code = comprasNormalizeLineCode((string)($purchaseLine['marco_logico'] ?? ''));
    $ext = comprasNormalizeExt((string)($purchaseLine['ext'] ?? ''));
    $accountSource = function_exists('purchasePoaSourceAccount') ? purchasePoaSourceAccount($db, $purchaseLine) : (string)($purchaseLine['cuenta_contable'] ?? '');
    $account = comprasNormalizeAccount($accountSource);
    if ($code === '') throw new RuntimeException('La línea imputada no tiene código de marco lógico.');

    $sql = "SELECT hash_id, marco_logico, ext, cuenta_contable
            FROM ah_poa
            WHERE is_active=1 AND marco_logico LIKE ?";
    $params = [$code . '%'];
    if ($ext !== '') {
        $sql .= ' AND UPPER(TRIM(ext))=?';
        $params[] = $ext;
    }
    $sql .= ' ORDER BY id ASC';
    $st = $db->prepare($sql);
    $st->execute($params);
    $candidates = $st->fetchAll(PDO::FETCH_ASSOC);

    if (count($candidates) === 1) return (string)$candidates[0]['hash_id'];
    if ($account !== '') {
        $accountMatches = [];
        foreach ($candidates as $candidate) {
            if (comprasNormalizeAccount((string)($candidate['cuenta_contable'] ?? '')) === $account) {
                $accountMatches[] = $candidate;
            }
        }
        if (count($accountMatches) === 1) return (string)$accountMatches[0]['hash_id'];
    }

    if (!$candidates) {
        throw new RuntimeException('No se encontró en el POA activo la línea ' . $code . ($ext !== '' ? ' ' . $ext : '') . '.');
    }
    throw new RuntimeException('La línea ' . $code . ($ext !== '' ? ' ' . $ext : '') . ' aparece más de una vez. Verifique la cuenta contable seleccionada.');
}

function poaMovementsAvailable(PDO $db) {
    static $available = null;
    if ($available === null) $available = tableExists($db, 'ah_poa_movimientos');
    return $available;
}

function taxRate(string $type): float {
    return strtoupper($type) === 'G' ? 0.15 : 0.0;
}

/**
 * Ejecución mensual compatible sin exigir nuevas tablas ni ALTER TABLE.
 *
 * Prioridad:
 * 1) columnas eje_mes, cuando existen;
 * 2) tabla ah_poa_ejecucion_mensual, cuando ya existe;
 * 3) cálculo directo desde compras autorizadas + ajustes manuales del POA.
 *
 * La tercera vía usa ah_compras/ah_compras_poa como libro mayor existente,
 * por lo que no requiere importar SQL adicional.
 */
function ensurePoaExecutionFallback(PDO $db) {
    // No se modifica la estructura durante la carga. La ausencia de la tabla
    // auxiliar no es un error: el sistema puede calcular la ejecución desde
    // las compras autorizadas.
    return true;
}

function poaExecutionColumnExists(PDO $db, string $month) {
    return comprasColumnExists($db, 'ah_poa', 'eje_' . normalizeMonth($month));
}

function poaManualExecutionFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;

    $base = dirname(COMPRAS_ADMIN_DIR);
    foreach ([$base . '/storage', $base . '/data', COMPRAS_ADMIN_DIR] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/poa_ejecucion_manual_store.json';
            return $path;
        }
    }
    $path = '';
    return $path;
}

function readPoaManualExecutionStore(): array {
    $path = poaManualExecutionFilePath();
    if ($path === '' || !is_file($path)) return [];
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function authorizedPurchaseExecutionValue(PDO $db, string $hash, string $month): float {
    $month = normalizeMonth($month);
    $hasMonthColumn = comprasColumnExists($db, 'ah_compras', 'mes_ejecucion');
    $selectMonth = $hasMonthColumn ? ', c.mes_ejecucion' : '';
    $sql = "SELECT cp.compra_id, cp.monto, c.fecha{$selectMonth}
            FROM ah_compras_poa cp
            INNER JOIN ah_compras c ON c.id=cp.compra_id
            WHERE cp.poa_hash=?
              AND c.estado IN ('4_Autorizada','5_Almacen','5_Autorizada','6_Almacen')";
    $st = $db->prepare($sql);
    $st->execute([$hash]);
    $sum = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $purchase = ['fecha' => $row['fecha'] ?? date('Y-m-d')];
        if ($hasMonthColumn) $purchase['mes_ejecucion'] = $row['mes_ejecucion'] ?? '';
        $rowMonth = getCompraExecutionMonth($db, (int)$row['compra_id'], $purchase);
        if ($rowMonth === $month) $sum += (float)$row['monto'];
    }
    return round($sum, 2);
}

function getPoaExecutionMonthValue(PDO $db, string $hash, string $month) {
    $month = normalizeMonth($month);
    if (poaExecutionColumnExists($db, $month)) {
        $st = $db->prepare("SELECT COALESCE(`eje_{$month}`,0) FROM ah_poa WHERE hash_id=? LIMIT 1");
        $st->execute([$hash]);
        return (float)$st->fetchColumn();
    }
    if (tableExists($db, 'ah_poa_ejecucion_mensual')) {
        $st = $db->prepare('SELECT COALESCE(monto,0) FROM ah_poa_ejecucion_mensual WHERE poa_hash=? AND mes=? LIMIT 1');
        $st->execute([$hash, $month]);
        return (float)$st->fetchColumn();
    }

    $base = authorizedPurchaseExecutionValue($db, $hash, $month);
    $manual = readPoaManualExecutionStore();
    $delta = (float)($manual[$hash][$month]['delta'] ?? $manual[$hash][$month] ?? 0);
    return round($base + $delta, 2);
}

function addPoaExecutionAmount(PDO $db, string $hash, string $month, float $amount) {
    $month = normalizeMonth($month);
    if (poaExecutionColumnExists($db, $month)) {
        $column = 'eje_' . $month;
        $st = $db->prepare("UPDATE ah_poa SET `{$column}`=COALESCE(`{$column}`,0)+? WHERE hash_id=?");
        $st->execute([$amount, $hash]);
        if ($st->rowCount() < 1) throw new RuntimeException('No se encontró la línea POA seleccionada.');
        return;
    }
    if (tableExists($db, 'ah_poa_ejecucion_mensual')) {
        $exists = $db->prepare('SELECT hash_id FROM ah_poa WHERE hash_id=? LIMIT 1');
        $exists->execute([$hash]);
        if (!$exists->fetchColumn()) throw new RuntimeException('No se encontró la línea POA seleccionada.');
        $st = $db->prepare(
            'INSERT INTO ah_poa_ejecucion_mensual (poa_hash,mes,monto) VALUES (?,?,?) '
            . 'ON DUPLICATE KEY UPDATE monto=monto+VALUES(monto), updated_at=CURRENT_TIMESTAMP'
        );
        $st->execute([$hash, $month, $amount]);
        return;
    }

    // Sin columnas ni tabla auxiliar no se duplica el monto en otro lugar:
    // ah_compras_poa + el estado autorizado son la fuente de verdad.
    $exists = $db->prepare('SELECT hash_id FROM ah_poa WHERE hash_id=? LIMIT 1');
    $exists->execute([$hash]);
    if (!$exists->fetchColumn()) throw new RuntimeException('No se encontró la línea POA seleccionada.');
}

function recalcPoaExecuted(PDO $db, string $hash) {
    $months = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
    $total = 0.0;
    foreach ($months as $month) $total += getPoaExecutionMonthValue($db, $hash, $month);
    $st = $db->prepare('UPDATE ah_poa SET ejecutado=? WHERE hash_id=?');
    $st->execute([round($total, 2), $hash]);
}

function availableForPoa(PDO $db, string $hash, int $excludePurchaseId = 0): float {
    $st = $db->prepare('SELECT presupuesto_anual, ejecutado FROM ah_poa WHERE hash_id=? LIMIT 1');
    $st->execute([$hash]);
    $line = $st->fetch(PDO::FETCH_ASSOC);
    if (!$line) return 0;

    $committed = 0.0;
    if (poaMovementsAvailable($db)) {
        $sql = "SELECT COALESCE(SUM(monto),0) FROM ah_poa_movimientos WHERE poa_hash=? AND tipo='COMPROMISO'";
        $params = [$hash];
        if ($excludePurchaseId > 0) {
            $sql .= ' AND compra_id<>?';
            $params[] = $excludePurchaseId;
        }
        $cs = $db->prepare($sql);
        $cs->execute($params);
        $committed = (float)$cs->fetchColumn();
    } else {
        // Respaldo sin libro mayor: las imputaciones aún no autorizadas
        // funcionan como compromisos presupuestarios.
        $sql = "SELECT COALESCE(SUM(cp.monto),0)
                FROM ah_compras_poa cp
                INNER JOIN ah_compras c ON c.id=cp.compra_id
                WHERE cp.poa_hash=? AND c.estado IN ('3_Transferencia','4_Imputacion')";
        $params = [$hash];
        if ($excludePurchaseId > 0) {
            $sql .= ' AND cp.compra_id<>?';
            $params[] = $excludePurchaseId;
        }
        try {
            $cs = $db->prepare($sql);
            $cs->execute($params);
            $committed = (float)$cs->fetchColumn();
        } catch (Throwable $e) {
            $committed = 0.0;
        }
    }

    return round((float)$line['presupuesto_anual'] - (float)$line['ejecutado'] - $committed, 2);
}

function saveAudit(PDO $db, int $purchaseId, string $action, array $detail = []) {
    try {
        $st = $db->prepare('INSERT INTO ah_compras_auditoria (compra_id, accion, usuario, detalle_json) VALUES (?,?,?,?)');
        $st->execute([$purchaseId, $action, currentUserLabel(), json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    } catch (Throwable $e) {
        // La auditoría nunca debe interrumpir el flujo principal.
    }
}

function reverseCompraPoaExecution(PDO $db, int $purchaseId, array $purchase): array {
    $affected = [];
    $st = $db->prepare('SELECT poa_hash,monto FROM ah_compras_poa WHERE compra_id=?');
    $st->execute([$purchaseId]);
    $month = getCompraExecutionMonth($db, $purchaseId, $purchase);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $line) {
        $hash = trim((string)($line['poa_hash'] ?? ''));
        $amount = max(0, (float)($line['monto'] ?? 0));
        if ($hash === '') continue;
        $affected[$hash] = true;
        if ($amount <= 0) continue;
        if (poaExecutionColumnExists($db, $month)) {
            $column = 'eje_' . normalizeMonth($month);
            $update = $db->prepare("UPDATE ah_poa SET `{$column}`=GREATEST(0,COALESCE(`{$column}`,0)-?) WHERE hash_id=?");
            $update->execute([$amount, $hash]);
        } elseif (tableExists($db, 'ah_poa_ejecucion_mensual')) {
            $update = $db->prepare('UPDATE ah_poa_ejecucion_mensual SET monto=GREATEST(0,COALESCE(monto,0)-?) WHERE poa_hash=? AND mes=?');
            $update->execute([$amount, $hash, normalizeMonth($month)]);
        }
    }
    return array_keys($affected);
}

function removeCompraStoreEntry(string $path, int $purchaseId): void {
    if ($path === '' || !is_file($path)) return;
    $fh = @fopen($path, 'c+');
    if (!$fh) return;
    try {
        if (!@flock($fh, LOCK_EX)) return;
        rewind($fh);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data) || !array_key_exists((string)$purchaseId, $data)) return;
        unset($data[(string)$purchaseId]);
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) return;
        rewind($fh);
        ftruncate($fh, 0);
        fwrite($fh, $encoded);
        fflush($fh);
        @flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
}

function purgeCompraFallbackStores(int $purchaseId): void {
    foreach ([comprasFormatoFilePath(), comprasMesFilePath(), comprasRecepcionFilePath(), comprasCotizacionesFilePath()] as $path) {
        removeCompraStoreEntry($path, $purchaseId);
    }
    foreach (['compras_formatos','compras_meses_ejecucion','compras_recepcion_store','compras_cotizaciones_fallback','compras_edit_unlock'] as $key) {
        if (isset($_SESSION[$key]) && is_array($_SESSION[$key])) unset($_SESSION[$key][(string)$purchaseId]);
    }
}

function deleteCompraDatabaseTrail(PDO $db, int $purchaseId): void {
    if (tableExists($db,'ah_compras_cotizacion_precios') && tableExists($db,'ah_compras_cotizaciones')) {
        $db->prepare('DELETE p FROM ah_compras_cotizacion_precios p INNER JOIN ah_compras_cotizaciones q ON q.id=p.cotizacion_id WHERE q.compra_id=?')->execute([$purchaseId]);
    }
    if (tableExists($db,'ah_compras_cotizacion_precios') && tableExists($db,'ah_compras_resumen_filas')) {
        $db->prepare('DELETE p FROM ah_compras_cotizacion_precios p INNER JOIN ah_compras_resumen_filas r ON r.id=p.resumen_fila_id WHERE r.compra_id=?')->execute([$purchaseId]);
    }
    if (tableExists($db,'ah_compras_planilla_detalles') && tableExists($db,'ah_compras_planillas')) {
        $db->prepare('DELETE d FROM ah_compras_planilla_detalles d INNER JOIN ah_compras_planillas p ON p.id=d.planilla_id WHERE p.compra_id=?')->execute([$purchaseId]);
    }

    $orderedTables = [
        'ah_compras_recepcion_detalles','ah_compras_ejecuciones','ah_poa_movimientos',
        'ah_compras_planillas','ah_compras_cotizaciones','ah_compras_resumen_filas',
        'ah_compras_poa','ah_compras_detalles','ah_compras_auditoria',
        'ah_compras_propiedad','ah_compras_formatos'
    ];
    foreach ($orderedTables as $table) {
        if (!tableExists($db,$table) || !comprasColumnExists($db,$table,'compra_id')) continue;
        $db->prepare("DELETE FROM `{$table}` WHERE compra_id=?")->execute([$purchaseId]);
    }

    // Limpia cualquier tabla auxiliar futura que almacene directamente compra_id.
    $known = array_fill_keys($orderedTables, true);
    $tables = $db->prepare("SELECT table_name FROM information_schema.columns WHERE table_schema=DATABASE() AND column_name='compra_id' AND table_name LIKE 'ah\\_%'");
    $tables->execute();
    foreach ($tables->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table === 'ah_compras' || isset($known[$table])) continue;
        if (!preg_match('/^ah_[a-z0-9_]+$/i',(string)$table)) continue;
        $db->prepare("DELETE FROM `{$table}` WHERE compra_id=?")->execute([$purchaseId]);
    }
    $db->prepare('DELETE FROM ah_compras WHERE id=?')->execute([$purchaseId]);
}

/* ========================================================
   CONTROL DE ESTRUCTURA
   La pantalla nunca ejecuta ALTER TABLE automáticamente. En hosting
   compartido, un ALTER puede requerir /tmp y bloquear todo el módulo.
   La migración completa solo se ejecuta mediante ?actualizar_estructura=1.
   ======================================================== */

/**
 * Respaldo compatible para los datos generales de recepción.
 * Evita depender de columnas nuevas en ah_compras mientras el servidor
 * no permita ejecutar ALTER TABLE por el problema de /tmp.
 */
function comprasRecepcionFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;

    $base = dirname(COMPRAS_ADMIN_DIR);
    $dirs = [
        $base . '/storage',
        $base . '/data',
    ];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/compras_recepcion_store.json';
            return $path;
        }
    }

    $path = '';
    return $path;
}

function readComprasRecepcionStore(): array {
    $path = comprasRecepcionFilePath();
    if ($path === '' || !is_file($path)) return [];

    $fh = @fopen($path, 'rb');
    if (!$fh) return [];

    try {
        @flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }

    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function writeCompraRecepcionStore(int $compraId, string $fecha, string $recibidoPor, string $notas): bool {
    $path = comprasRecepcionFilePath();
    if ($path === '') return false;

    $fh = @fopen($path, 'c+');
    if (!$fh) return false;

    try {
        if (!@flock($fh, LOCK_EX)) return false;
        rewind($fh);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) $data = [];

        $data[(string)$compraId] = [
            'fecha_recepcion' => $fecha,
            'recibido_por' => $recibidoPor,
            'notas_recepcion' => $notas,
            'updated_at' => date('c'),
        ];

        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) return false;

        rewind($fh);
        ftruncate($fh, 0);
        $ok = fwrite($fh, $encoded) !== false;
        fflush($fh);
        @flock($fh, LOCK_UN);
        return $ok;
    } finally {
        fclose($fh);
    }
}

function saveCompraRecepcionFallback(int $compraId, string $fecha, string $recibidoPor, string $notas) {
    if (!isset($_SESSION['compras_recepcion_store']) || !is_array($_SESSION['compras_recepcion_store'])) {
        $_SESSION['compras_recepcion_store'] = [];
    }

    $_SESSION['compras_recepcion_store'][(string)$compraId] = [
        'fecha_recepcion' => $fecha,
        'recibido_por' => $recibidoPor,
        'notas_recepcion' => $notas,
        'updated_at' => date('c'),
    ];

    // El archivo es el respaldo persistente; la sesión evita perder el dato
    // si el hosting no permite escribir en storage/data.
    writeCompraRecepcionStore($compraId, $fecha, $recibidoPor, $notas);
}

function getCompraRecepcionFallback(int $compraId, array $purchase): array {
    $result = [
        'fecha_recepcion' => trim((string)($purchase['fecha_recepcion'] ?? '')),
        'recibido_por' => trim((string)($purchase['recibido_por'] ?? '')),
        'notas_recepcion' => trim((string)($purchase['notas_recepcion'] ?? '')),
    ];

    $fileStore = readComprasRecepcionStore();
    $fileData = isset($fileStore[(string)$compraId]) && is_array($fileStore[(string)$compraId])
        ? $fileStore[(string)$compraId]
        : [];
    $sessionData = isset($_SESSION['compras_recepcion_store'][(string)$compraId])
        && is_array($_SESSION['compras_recepcion_store'][(string)$compraId])
        ? $_SESSION['compras_recepcion_store'][(string)$compraId]
        : [];

    foreach (['fecha_recepcion', 'recibido_por', 'notas_recepcion'] as $field) {
        if ($result[$field] !== '') continue;
        if (trim((string)($fileData[$field] ?? '')) !== '') {
            $result[$field] = trim((string)$fileData[$field]);
        } elseif (trim((string)($sessionData[$field] ?? '')) !== '') {
            $result[$field] = trim((string)$sessionData[$field]);
        }
    }

    return $result;
}

function tableExists(PDO $db, string $table): bool {
    try {
        $st = $db->prepare(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1'
        );
        $st->execute([$table]);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ensureComprasOwnershipTable(PDO $db): bool {
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_propiedad (
            compra_id INT NOT NULL PRIMARY KEY,
            usuario_id INT NOT NULL,
            usuario_nombre VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            archived_at DATETIME NULL,
            archived_by VARCHAR(255) NULL,
            INDEX idx_compra_owner(usuario_id),
            INDEX idx_compra_archived(archived_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        return tableExists($db, 'ah_compras_propiedad');
    } catch (Throwable $e) {
        return false;
    }
}

$compras_ownership_ready = ensureComprasOwnershipTable($db);

/**
 * Respaldo de perfiles de proveedor cuando el hosting no permite crear
 * ah_proveedores_perfil. Conserva dirección y tipo de transferencia sin ALTER.
 */
function comprasProveedorPerfilFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;
    $base = dirname(COMPRAS_ADMIN_DIR);
    foreach ([$base . '/storage', $base . '/data', COMPRAS_ADMIN_DIR] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/compras_proveedores_perfil_store.json';
            return $path;
        }
    }
    return $path = '';
}
function readProveedorPerfilFileStore(): array {
    $path = comprasProveedorPerfilFilePath();
    if ($path === '' || !is_file($path)) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    try {
        @flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
    } finally { fclose($fh); }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}
function writeProveedorPerfilFileStore(string $nombre, string $direccion, string $tipoTransferencia): bool {
    $path = comprasProveedorPerfilFilePath();
    if ($path === '') return false;
    $fh = @fopen($path, 'c+');
    if (!$fh) return false;
    try {
        if (!@flock($fh, LOCK_EX)) return false;
        rewind($fh);
        $data = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($data)) $data = [];
        $key = mb_strtolower(trim($nombre), 'UTF-8');
        $data[$key] = [
            'nombre' => trim($nombre),
            'direccion' => trim($direccion),
            'tipo_transferencia' => trim($tipoTransferencia),
            'updated_at' => date('c')
        ];
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($json === false) return false;
        rewind($fh); ftruncate($fh, 0);
        $ok = fwrite($fh, $json) !== false;
        fflush($fh); @flock($fh, LOCK_UN);
        return $ok;
    } finally { fclose($fh); }
}

/** Perfil ampliado del proveedor sin ALTER TABLE sobre ah_proveedores. */
function ensureProveedorPerfilTable(PDO $db): bool {
    if (tableExists($db, 'ah_proveedores_perfil')) return true;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ah_proveedores_perfil (
            id INT AUTO_INCREMENT PRIMARY KEY,
            proveedor_nombre VARCHAR(255) NOT NULL UNIQUE,
            direccion TEXT NULL,
            tipo_transferencia VARCHAR(100) NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) { return false; }
    return tableExists($db, 'ah_proveedores_perfil');
}
function providerProfile(PDO $db, string $nombre): array {
    $nombre = trim($nombre);
    if ($nombre === '') return [];

    $base = [];
    try {
        $st = $db->prepare("SELECT id,nombre,rtn,banco,tipo_cuenta,cuenta_bancaria FROM ah_proveedores WHERE nombre=? LIMIT 1");
        $st->execute([$nombre]);
        $base = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $base = []; }

    $extra = ['direccion' => '', 'tipo_transferencia' => ''];
    if (tableExists($db, 'ah_proveedores_perfil')) {
        try {
            $st = $db->prepare("SELECT COALESCE(direccion,'') direccion,COALESCE(tipo_transferencia,'') tipo_transferencia FROM ah_proveedores_perfil WHERE proveedor_nombre=? LIMIT 1");
            $st->execute([$nombre]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if ($row) $extra = array_merge($extra, $row);
        } catch (Throwable $e) {}
    }

    $key = mb_strtolower($nombre, 'UTF-8');
    if (($extra['direccion'] ?? '') === '' || ($extra['tipo_transferencia'] ?? '') === '') {
        $file = readProveedorPerfilFileStore();
        $stored = isset($file[$key]) && is_array($file[$key]) ? $file[$key] : [];
        if (($extra['direccion'] ?? '') === '') $extra['direccion'] = (string)($stored['direccion'] ?? '');
        if (($extra['tipo_transferencia'] ?? '') === '') $extra['tipo_transferencia'] = (string)($stored['tipo_transferencia'] ?? '');
    }
    if (isset($_SESSION['compras_proveedores_perfil'][$key]) && is_array($_SESSION['compras_proveedores_perfil'][$key])) {
        $stored = $_SESSION['compras_proveedores_perfil'][$key];
        if (($extra['direccion'] ?? '') === '') $extra['direccion'] = (string)($stored['direccion'] ?? '');
        if (($extra['tipo_transferencia'] ?? '') === '') $extra['tipo_transferencia'] = (string)($stored['tipo_transferencia'] ?? '');
    }

    return array_merge([
        'id' => null, 'nombre' => $nombre, 'rtn' => '', 'banco' => '',
        'tipo_cuenta' => '', 'cuenta_bancaria' => ''
    ], $base, $extra);
}
function saveProviderProfile(PDO $db,string $nombre,string $direccion,string $tipoTransferencia): void {
    $nombre = trim($nombre);
    if ($nombre === '') return;
    $key = mb_strtolower($nombre, 'UTF-8');
    if (!isset($_SESSION['compras_proveedores_perfil']) || !is_array($_SESSION['compras_proveedores_perfil'])) {
        $_SESSION['compras_proveedores_perfil'] = [];
    }
    $_SESSION['compras_proveedores_perfil'][$key] = [
        'direccion' => trim($direccion),
        'tipo_transferencia' => trim($tipoTransferencia)
    ];
    writeProveedorPerfilFileStore($nombre, $direccion, $tipoTransferencia);

    if (tableExists($db, 'ah_proveedores_perfil')) {
        try {
            $st=$db->prepare("INSERT INTO ah_proveedores_perfil(proveedor_nombre,direccion,tipo_transferencia) VALUES(?,?,?) ON DUPLICATE KEY UPDATE direccion=VALUES(direccion),tipo_transferencia=VALUES(tipo_transferencia)");
            $st->execute([$nombre,trim($direccion),trim($tipoTransferencia)]);
        } catch (Throwable $e) {}
    }
}



/**
 * Almacenamiento alternativo del Formato B.
 * Permite trabajar con cotizaciones aun cuando el hosting no puede crear
 * las tablas auxiliares por falta de espacio temporal en /tmp.
 */
function comprasCotizacionesFilePath(): string {
    static $path = null;
    if ($path !== null) return $path;

    $base = dirname(COMPRAS_ADMIN_DIR);
    foreach ([$base . '/storage', $base . '/data', COMPRAS_ADMIN_DIR] as $dir) {
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        if (is_dir($dir) && is_writable($dir)) {
            $path = rtrim($dir, '/\\') . '/compras_cotizaciones_store.json';
            return $path;
        }
    }
    $path = '';
    return $path;
}

function readComprasCotizacionesStore(): array {
    $path = comprasCotizacionesFilePath();
    if ($path === '' || !is_file($path)) return [];
    $fh = @fopen($path, 'rb');
    if (!$fh) return [];
    try {
        @flock($fh, LOCK_SH);
        $raw = stream_get_contents($fh);
        @flock($fh, LOCK_UN);
    } finally {
        fclose($fh);
    }
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function writeCompraCotizacionesStore(int $compraId, array $payload): bool {
    if (!isset($_SESSION['compras_cotizaciones_fallback']) || !is_array($_SESSION['compras_cotizaciones_fallback'])) {
        $_SESSION['compras_cotizaciones_fallback'] = [];
    }
    $payload['updated_at'] = date('c');
    $_SESSION['compras_cotizaciones_fallback'][(string)$compraId] = $payload;

    $path = comprasCotizacionesFilePath();
    if ($path === '') return true; // La sesión sigue siendo un respaldo válido.
    $fh = @fopen($path, 'c+');
    if (!$fh) return true;
    try {
        if (!@flock($fh, LOCK_EX)) return true;
        rewind($fh);
        $all = json_decode((string)stream_get_contents($fh), true);
        if (!is_array($all)) $all = [];
        $all[(string)$compraId] = $payload;
        $encoded = json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if ($encoded === false) return false;
        rewind($fh);
        ftruncate($fh, 0);
        $ok = fwrite($fh, $encoded) !== false;
        fflush($fh);
        @flock($fh, LOCK_UN);
        return $ok;
    } finally {
        fclose($fh);
    }
}

function getCompraCotizacionesFallback(int $compraId): array {
    $default = [
        'quotes' => [], 'rows' => [], 'prices' => [],
        'observacion_cotizacion' => '', 'fecha_analisis_cotizacion' => ''
    ];
    $all = readComprasCotizacionesStore();
    $row = isset($all[(string)$compraId]) && is_array($all[(string)$compraId]) ? $all[(string)$compraId] : [];
    if (isset($_SESSION['compras_cotizaciones_fallback'][(string)$compraId]) && is_array($_SESSION['compras_cotizaciones_fallback'][(string)$compraId])) {
        $row = array_replace_recursive($row, $_SESSION['compras_cotizaciones_fallback'][(string)$compraId]);
    }
    return array_replace($default, $row);
}

function buildCompraCotizacionesPayload(array $quotesPost, array $rowsPost, int $winnerPos, string $observation, string $analysisDate): array {
    while (count($quotesPost) < 4) $quotesPost[] = [];
    if ($winnerPos < 1 || $winnerPos > 4) $winnerPos = 1;

    $quotes = [];
    for ($idx = 0; $idx < 4; $idx++) {
        $pos = $idx + 1;
        $q = (array)($quotesPost[$idx] ?? []);
        $provider = trim((string)($q['proveedor'] ?? ''));
        if ($pos === 1 && $provider === '') {
            throw new RuntimeException('El primer proveedor debe registrarse y será el ganador inicial.');
        }
        $quotes[] = [
            'id' => $pos,
            'posicion' => $pos,
            'proveedor' => $provider,
            'rtn' => trim((string)($q['rtn'] ?? '')),
            'estado_cotizacion' => trim((string)($q['estado'] ?? 'Cotizó')) ?: 'Cotizó',
            'es_ganador' => $pos === $winnerPos ? 1 : 0,
            'descuento' => money($q['descuento'] ?? 0),
            'subtotal' => 0.0,
            'isv' => 0.0,
            'total' => 0.0,
        ];
    }

    $rows = [];
    $prices = [];
    $totals = [1 => ['subtotal'=>0.0,'gravado'=>0.0], 2 => ['subtotal'=>0.0,'gravado'=>0.0], 3 => ['subtotal'=>0.0,'gravado'=>0.0], 4 => ['subtotal'=>0.0,'gravado'=>0.0]];
    foreach ($rowsPost as $order => $rowRaw) {
        $row = (array)$rowRaw;
        $article = trim((string)($row['articulo'] ?? ''));
        if ($article === '') continue;
        $postedId = (int)($row['id'] ?? 0);
        $rowId = $postedId > 0 ? $postedId : (100000 + (int)$order + 1);
        $qty = quantity($row['cantidad'] ?? 0);
        $stored = [
            'id' => $rowId,
            'orden' => (int)$order + 1,
            'cantidad' => $qty,
            'presentacion' => trim((string)($row['presentacion'] ?? '')),
            'articulo' => $article,
            'caracteristicas' => trim((string)($row['caracteristicas'] ?? '')),
            'es_extra' => !empty($row['es_extra']) ? 1 : 0,
            'compra_detalle_id' => $postedId > 0 ? $postedId : null,
        ];
        $rows[] = $stored;
        $prices[(string)$rowId] = [];
        for ($pos = 1; $pos <= 4; $pos++) {
            $pd = (array)($row['precios'][$pos] ?? []);
            $unit = money($pd['precio'] ?? 0);
            $tax = strtoupper((string)($pd['impuesto'] ?? 'E')) === 'G' ? 'G' : 'E';
            $total = round($qty * $unit, 2);
            $prices[(string)$rowId][(string)$pos] = [
                'cotizacion_id' => $pos,
                'resumen_fila_id' => $rowId,
                'precio_unitario' => $unit,
                'tipo_impuesto' => $tax,
                'precio_total' => $total,
            ];
            $totals[$pos]['subtotal'] += $total;
            if ($tax === 'G') $totals[$pos]['gravado'] += $total;
        }
    }
    if (!$rows) throw new RuntimeException('El resumen de cotización debe contener al menos una fila.');

    foreach ($quotes as &$q) {
        $pos = (int)$q['posicion'];
        $subtotal = round($totals[$pos]['subtotal'], 2);
        $gravado = round($totals[$pos]['gravado'], 2);
        $discount = min(max(0, (float)$q['descuento']), $subtotal);
        $discGrav = $subtotal > 0 ? $discount * ($gravado / $subtotal) : 0;
        $isv = round(max(0, ($gravado - $discGrav) * 0.15), 2);
        $q['subtotal'] = $subtotal;
        $q['isv'] = $isv;
        $q['total'] = round($subtotal - $discount + $isv, 2);
    }
    unset($q);

    return [
        'quotes' => $quotes,
        'rows' => $rows,
        'prices' => $prices,
        'observacion_cotizacion' => trim($observation),
        'fecha_analisis_cotizacion' => $analysisDate !== '' ? $analysisDate : date('Y-m-d'),
    ];
}

/**
 * Inicializa únicamente las tablas auxiliares que todavía no existen.
 * No ejecuta ALTER TABLE y nunca se llama dentro de una transacción.
 * Esto permite activar Formatos B y C aun cuando la migración completa
 * esté deshabilitada por limitaciones temporales del servidor.
 */
function ensureComprasComponentTables(PDO $db): array {
    $result = ['quotes' => true, 'plans' => true, 'reception' => true, 'errors' => []];

    $definitions = [
        'ah_compras_cotizaciones' => "CREATE TABLE IF NOT EXISTS ah_compras_cotizaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            posicion INT NOT NULL,
            proveedor VARCHAR(255) NULL,
            rtn VARCHAR(50) NULL,
            estado_cotizacion VARCHAR(30) DEFAULT 'Cotizó',
            es_ganador TINYINT(1) DEFAULT 0,
            descuento DECIMAL(15,2) DEFAULT 0,
            subtotal DECIMAL(15,2) DEFAULT 0,
            isv DECIMAL(15,2) DEFAULT 0,
            total DECIMAL(15,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_compra_posicion(compra_id,posicion),
            INDEX idx_cot_compra(compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'ah_compras_resumen_filas' => "CREATE TABLE IF NOT EXISTS ah_compras_resumen_filas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            compra_detalle_id INT NULL,
            orden INT DEFAULT 0,
            cantidad DECIMAL(12,2) DEFAULT 0,
            presentacion VARCHAR(100) NULL,
            articulo VARCHAR(255) NULL,
            caracteristicas VARCHAR(500) NULL,
            es_extra TINYINT(1) DEFAULT 0,
            INDEX idx_resumen_compra(compra_id),
            INDEX idx_resumen_detalle(compra_detalle_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'ah_compras_cotizacion_precios' => "CREATE TABLE IF NOT EXISTS ah_compras_cotizacion_precios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cotizacion_id INT NOT NULL,
            resumen_fila_id INT NOT NULL,
            precio_unitario DECIMAL(15,2) DEFAULT 0,
            tipo_impuesto VARCHAR(1) DEFAULT 'E',
            precio_total DECIMAL(15,2) DEFAULT 0,
            UNIQUE KEY uq_cot_fila(cotizacion_id,resumen_fila_id),
            INDEX idx_precio_cot(cotizacion_id),
            INDEX idx_precio_fila(resumen_fila_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'ah_compras_planillas' => "CREATE TABLE IF NOT EXISTS ah_compras_planillas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            orden INT DEFAULT 0,
            plantilla VARCHAR(30) DEFAULT 'GENERAL',
            nombre_cooperativa VARCHAR(255) NULL,
            titulo VARCHAR(255) NULL,
            programa VARCHAR(255) NULL,
            marco_logico TEXT NULL,
            fecha_preparado DATE NULL,
            fecha_pago DATE NULL,
            comision_default DECIMAL(7,2) DEFAULT 0,
            preparado_por VARCHAR(255) NULL,
            lugar VARCHAR(255) NULL,
            observaciones TEXT NULL,
            INDEX idx_planilla_compra(compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'ah_compras_planilla_detalles' => "CREATE TABLE IF NOT EXISTS ah_compras_planilla_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            planilla_id INT NOT NULL,
            orden INT DEFAULT 0,
            nombre VARCHAR(255) NULL,
            comunidad VARCHAR(255) NULL,
            identidad VARCHAR(50) NULL,
            monto_base DECIMAL(15,2) DEFAULT 0,
            comision_pct DECIMAL(7,2) DEFAULT 0,
            comision DECIMAL(15,2) DEFAULT 0,
            total_transferencia DECIMAL(15,2) DEFAULT 0,
            instruccion_pago VARCHAR(255) NULL,
            firma VARCHAR(255) NULL,
            INDEX idx_planilla_detalle(planilla_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        'ah_compras_recepcion_detalles' => "CREATE TABLE IF NOT EXISTS ah_compras_recepcion_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            detalle_id INT NOT NULL,
            cantidad_recibida DECIMAL(12,2) DEFAULT 0,
            cantidad_danada DECIMAL(12,2) DEFAULT 0,
            cantidad_faltante DECIMAL(12,2) DEFAULT 0,
            observacion TEXT NULL,
            UNIQUE KEY uq_recepcion_detalle(compra_id,detalle_id),
            INDEX idx_recepcion_compra(compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    ];

    foreach ($definitions as $table => $sql) {
        if (tableExists($db, $table)) continue;
        try {
            $db->exec($sql);
        } catch (Throwable $e) {
            $result['errors'][$table] = $e->getMessage();
        }
    }

    $result['quotes'] = tableExists($db, 'ah_compras_cotizaciones')
        && tableExists($db, 'ah_compras_resumen_filas')
        && tableExists($db, 'ah_compras_cotizacion_precios');
    $result['plans'] = tableExists($db, 'ah_compras_planillas')
        && tableExists($db, 'ah_compras_planilla_detalles');
    $result['reception'] = tableExists($db, 'ah_compras_recepcion_detalles');

    return $result;
}

function comprasSchemaVersion(PDO $db): int {
    try {
        if (!tableExists($db, 'ah_compras_schema')) return 0;
        return (int)$db->query('SELECT version FROM ah_compras_schema WHERE id=1')->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
