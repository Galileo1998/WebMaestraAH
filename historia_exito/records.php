<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$q = trim($_GET['q'] ?? '');
if ($q !== '') {
    $stmt = $pdo->prepare('SELECT * FROM magic_moments WHERE full_name LIKE ? OR participant_number LIKE ? OR community LIKE ? ORDER BY capture_date DESC, id DESC');
    $like = "%$q%";
    $stmt->execute([$like,$like,$like]);
} else {
    $stmt = $pdo->query('SELECT * FROM magic_moments ORDER BY capture_date DESC, id DESC');
}
$rows = $stmt->fetchAll();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Registros</title><link rel="stylesheet" href="assets/style.css"></head><body>
<header class="topbar"><div><strong>Momentos mágicos</strong></div><nav><a href="index.php">Nuevo registro</a></nav></header>
<main class="list-shell"><h1>Registros guardados</h1><form class="search" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre, número o comunidad"><button>Buscar</button></form>
<div class="table-wrap"><table class="records"><thead><tr><th>Fecha</th><th>Participante</th><th>Comunidad</th><th>Programa</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><td><?= e($r['capture_date']) ?></td><td><?= e($r['full_name']) ?><small><?= e($r['participant_number']) ?></small></td><td><?= e($r['community']) ?></td><td><?= e($r['program_model']) ?></td><td><span class="status <?= strtolower($r['status']) ?>"><?= e($r['status']) ?></span></td><td><a href="index.php?id=<?= $r['id'] ?>">Editar</a> · <a target="_blank" href="print.php?id=<?= $r['id'] ?>">Imprimir</a></td></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="6">No hay registros.</td></tr><?php endif; ?>
</tbody></table></div></main></body></html>
