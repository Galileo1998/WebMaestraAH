<?php
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$r = record_or_404($pdo, $id);
function val(array $r, string $k): string { return e($r[$k] ?? ''); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Momento mágico #<?= $id ?></title><link rel="stylesheet" href="assets/style.css"></head><body class="print-view">
<div class="print-toolbar no-print"><button onclick="window.print()">Imprimir / Guardar PDF</button><a href="index.php?id=<?= $id ?>">Editar</a></div>
<main class="paper output">
<h1>FORMULARIO DE CAPTURA – MOMENTO MÁGICO</h1>
<div class="two-col">
<table class="form-table"><tr><th>PAÍS:</th><td><?= val($r,'country') ?></td></tr><tr><th>SOCIO LOCAL:</th><td><?= val($r,'local_partner') ?></td></tr><tr><th>COMUNIDAD:</th><td><?= val($r,'community') ?></td></tr><tr><th>FECHA CAPTURA:</th><td><?= val($r,'capture_date') ?></td></tr><tr><th>FECHA ENVÍO:</th><td><?= val($r,'send_date') ?></td></tr><tr><th>PERSONA QUE CAPTURÓ:</th><td><?= val($r,'captured_by') ?></td></tr><tr><th>TELÉFONO O CORREO:</th><td><?= val($r,'capturer_contact') ?></td></tr></table>
<table class="form-table"><tr><th>NOMBRE COMPLETO:</th><td><?= val($r,'full_name') ?></td></tr><tr><th>EDAD:</th><td><?= val($r,'age') ?></td></tr><tr><th>NÚMERO DE PARTICIPANTE:</th><td><?= val($r,'participant_number') ?></td></tr><tr><th>GRADO ESCOLAR/OCUPACIÓN:</th><td><?= val($r,'school_grade') ?></td></tr><tr><th>RELACIÓN CON CHILDFUND:</th><td><?= val($r,'relationship_type') ?></td></tr><tr><th>CONSENTIMIENTO:</th><td><?= $r['consent_confirmed'] ? 'Verificado' : 'Pendiente' ?></td></tr><tr><th>ESTADO:</th><td><?= val($r,'status') ?></td></tr></table>
</div>
<div class="classification-grid"><table class="form-table compact"><tr><th>MODELO PROGRAMÁTICO:</th><td><?= val($r,'program_model') ?></td></tr><tr><th>RESULTADO INTERMEDIO:</th><td><?= val($r,'intermediate_result') ?></td></tr></table><table class="form-table compact"><tr><th>TIPO DE MOMENTO MÁGICO:</th><td><?= val($r,'magic_moment_type') ?></td></tr></table></div>
<h2>DESCRIPCIÓN DE FOTOGRAFÍAS / EVIDENCIAS</h2>
<table class="form-table media-table"><tr><th>EVIDENCIA</th><th>ARCHIVO</th><th>DESCRIPCIÓN</th></tr>
<?php for($i=1;$i<=3;$i++): $path=$r["media{$i}_path"] ?? ''; $desc=$r["media{$i}_description"] ?? ''; if(!$path && !$desc) continue; ?><tr><td class="media-label">Evidencia <?= $i ?><br><small><?= val($r,"media{$i}_type") ?></small></td><td class="print-media"><?php if($path && is_image_path($path)): ?><img src="<?= e($path) ?>" alt="Evidencia <?= $i ?>"><?php elseif($path): ?><span><?= val($r,"media{$i}_original_name") ?></span><?php else: ?>—<?php endif; ?></td><td><?= nl2br(e($desc)) ?></td></tr><?php endfor; ?></table>
<h2>TESTIMONIOS</h2>
<table class="form-table testimony-table"><tr><th>PREGUNTA</th><th>RESPUESTA / TESTIMONIO</th></tr><tr><td>¿Cómo te sientes al realizar lo que más te gusta?</td><td><?= nl2br(val($r,'testimony_feeling')) ?></td></tr><tr><td>¿Qué aprendiste durante la actividad?</td><td><?= nl2br(val($r,'testimony_learning')) ?></td></tr><tr><td>¿Cómo aplicarías o aplicaste lo aprendido?</td><td><?= nl2br(val($r,'testimony_application')) ?></td></tr><tr><td>¿Cómo ha cambiado o cambiará tu vida? ¿Harás algo diferente?</td><td><?= nl2br(val($r,'testimony_change')) ?></td></tr></table>
<footer>Registro #<?= $id ?> · Generado el <?= date('d/m/Y H:i') ?></footer>
</main></body></html>
