<?php

function poaEnsureAuditTable(PDO $db): void
{
    $db->exec(
        "CREATE TABLE IF NOT EXISTS ah_poa_ejecucion_bitacora (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            poa_hash VARCHAR(190) NOT NULL,
            poa_nombre VARCHAR(255) NOT NULL DEFAULT '',
            mes CHAR(3) NOT NULL,
            valor_anterior DECIMAL(15,2) NOT NULL DEFAULT 0,
            valor_nuevo DECIMAL(15,2) NOT NULL DEFAULT 0,
            manual_anterior DECIMAL(15,2) NOT NULL DEFAULT 0,
            manual_nuevo DECIMAL(15,2) NOT NULL DEFAULT 0,
            compras_autorizadas DECIMAL(15,2) NOT NULL DEFAULT 0,
            compras_pendientes DECIMAL(15,2) NOT NULL DEFAULT 0,
            motivo VARCHAR(500) NOT NULL DEFAULT '',
            accion VARCHAR(30) NOT NULL DEFAULT 'edicion',
            usuario_id INT NULL,
            usuario_nombre VARCHAR(190) NOT NULL DEFAULT '',
            ip_hash CHAR(64) NOT NULL DEFAULT '',
            creado_en TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            INDEX idx_poa_bitacora_linea (poa_hash, mes, creado_en),
            INDEX idx_poa_bitacora_usuario (usuario_id, creado_en)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function poaAuditUser(): array
{
    return [
        'id' => !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null,
        'name' => trim((string) ($_SESSION['user_name'] ?? 'Usuario del sistema')),
    ];
}

function poaAuditIpHash(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    return $ip === '' ? '' : hash('sha256', $ip);
}

function poaLogExecutionChange(PDO $db, array $change): void
{
    $user = poaAuditUser();
    $st = $db->prepare(
        'INSERT INTO ah_poa_ejecucion_bitacora
         (poa_hash,poa_nombre,mes,valor_anterior,valor_nuevo,manual_anterior,manual_nuevo,
          compras_autorizadas,compras_pendientes,motivo,accion,usuario_id,usuario_nombre,ip_hash)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $st->execute([
        (string) ($change['poa_hash'] ?? ''),
        (string) ($change['poa_nombre'] ?? ''),
        (string) ($change['mes'] ?? ''),
        round((float) ($change['valor_anterior'] ?? 0), 2),
        round((float) ($change['valor_nuevo'] ?? 0), 2),
        round((float) ($change['manual_anterior'] ?? 0), 2),
        round((float) ($change['manual_nuevo'] ?? 0), 2),
        round((float) ($change['compras_autorizadas'] ?? 0), 2),
        round((float) ($change['compras_pendientes'] ?? 0), 2),
        mb_substr(trim((string) ($change['motivo'] ?? 'Edición manual desde la matriz POA')), 0, 500),
        mb_substr(trim((string) ($change['accion'] ?? 'edicion')), 0, 30),
        $user['id'],
        mb_substr($user['name'], 0, 190),
        poaAuditIpHash(),
    ]);
}

function poaExecutionHistory(PDO $db, string $hash, string $month, int $limit = 50): array
{
    $limit = max(1, min(100, $limit));
    $st = $db->prepare(
        "SELECT id,mes,valor_anterior,valor_nuevo,manual_anterior,manual_nuevo,
                compras_autorizadas,compras_pendientes,motivo,accion,
                usuario_id,usuario_nombre,creado_en
         FROM ah_poa_ejecucion_bitacora
         WHERE poa_hash=? AND mes=?
         ORDER BY id DESC LIMIT {$limit}"
    );
    $st->execute([$hash, $month]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
