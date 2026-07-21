<?php

function ensureAccountingCatalog(PDO $db): void {
    static $ready = false;
    if ($ready) return;

    // La carga normal solo comprueba disponibilidad; el DDL se ejecuta una vez,
    // cuando el catálogo todavía no existe.
    try {
        $db->query('SELECT 1 FROM ah_catalogo_cuentas LIMIT 1');
        $db->query('SELECT 1 FROM ah_compras_cuentas LIMIT 1');
        $ready = true;
        return;
    } catch (Throwable $missingCatalog) {}

    $db->exec("CREATE TABLE IF NOT EXISTS ah_catalogo_cuentas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codigo VARCHAR(30) NOT NULL,
        nombre VARCHAR(255) NOT NULL,
        sinopsis TEXT NULL,
        tipo VARCHAR(30) NOT NULL DEFAULT 'Gasto',
        permite_compras TINYINT(1) NOT NULL DEFAULT 1,
        activo TINYINT(1) NOT NULL DEFAULT 1,
        orden INT NOT NULL DEFAULT 0,
        fuente VARCHAR(120) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_catalogo_codigo_nombre (codigo, nombre(180)),
        INDEX idx_catalogo_activo_tipo (activo, permite_compras, tipo),
        INDEX idx_catalogo_codigo (codigo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_cuentas (
        compra_poa_id INT NOT NULL PRIMARY KEY,
        catalogo_cuenta_id INT NOT NULL,
        poa_cuenta_origen TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_compra_catalogo_cuenta (catalogo_cuenta_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $count = (int)$db->query('SELECT COUNT(*) FROM ah_catalogo_cuentas')->fetchColumn();
    if ($count === 0) {
        $seedPath = dirname(__DIR__) . '/data/catalogo_cuentas_2026.json';
        $seed = is_file($seedPath) ? json_decode((string)file_get_contents($seedPath), true) : null;
        if (!is_array($seed)) throw new RuntimeException('No se pudo leer la base del catálogo contable.');
        $insert = $db->prepare('INSERT IGNORE INTO ah_catalogo_cuentas(codigo,nombre,sinopsis,tipo,orden,fuente) VALUES(?,?,?,?,?,?)');
        foreach ($seed as $row) {
            if (!is_array($row) || count($row) < 5) continue;
            $insert->execute([
                trim((string)$row[0]), trim((string)$row[1]), trim((string)$row[2]),
                trim((string)$row[3]) ?: 'Gasto', (int)$row[4], 'Catálogo AF-2026 30-06-2026'
            ]);
        }
    }
    $ready = true;
}

function accountingCatalogOptions(PDO $db, bool $onlyPurchases = true): array {
    ensureAccountingCatalog($db);
    $sql = 'SELECT id,codigo,nombre,sinopsis,tipo,permite_compras,activo FROM ah_catalogo_cuentas WHERE activo=1';
    if ($onlyPurchases) $sql .= ' AND permite_compras=1';
    $sql .= " ORDER BY FIELD(tipo,'Gasto','Balance'), orden, codigo, nombre";
    return $db->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function accountingCatalogAccount(PDO $db, int $id, bool $requirePurchases = true): array {
    ensureAccountingCatalog($db);
    $sql = 'SELECT * FROM ah_catalogo_cuentas WHERE id=? AND activo=1';
    if ($requirePurchases) $sql .= ' AND permite_compras=1';
    $st = $db->prepare($sql . ' LIMIT 1');
    $st->execute([$id]);
    $account = $st->fetch(PDO::FETCH_ASSOC);
    if (!$account) throw new RuntimeException('Seleccione una cuenta contable activa del catálogo financiero.');
    return $account;
}

function accountingCatalogLabel(array $account): string {
    return trim((string)($account['codigo'] ?? '')) . ' - ' . trim((string)($account['nombre'] ?? ''));
}

function savePurchaseAccountingLink(PDO $db, int $purchasePoaId, int $catalogId, string $poaSourceAccount): void {
    ensureAccountingCatalog($db);
    $st = $db->prepare('INSERT INTO ah_compras_cuentas(compra_poa_id,catalogo_cuenta_id,poa_cuenta_origen) VALUES(?,?,?) ON DUPLICATE KEY UPDATE catalogo_cuenta_id=VALUES(catalogo_cuenta_id),poa_cuenta_origen=VALUES(poa_cuenta_origen)');
    $st->execute([$purchasePoaId, $catalogId, $poaSourceAccount]);
}

function purchasePoaSourceAccount(PDO $db, array $purchaseLine): string {
    $lineId = (int)($purchaseLine['id'] ?? 0);
    if ($lineId > 0) {
        try {
            ensureAccountingCatalog($db);
            $st = $db->prepare('SELECT poa_cuenta_origen FROM ah_compras_cuentas WHERE compra_poa_id=? LIMIT 1');
            $st->execute([$lineId]);
            $source = trim((string)$st->fetchColumn());
            if ($source !== '') return $source;
        } catch (Throwable $ignored) {}
    }
    return trim((string)($purchaseLine['cuenta_contable'] ?? ''));
}
