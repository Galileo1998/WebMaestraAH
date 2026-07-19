<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$record = [
    'id' => '', 'country' => 'Honduras', 'local_partner' => 'Acción Honduras', 'community' => '',
    'capture_date' => date('Y-m-d'), 'send_date' => '', 'captured_by' => '', 'capturer_contact' => '',
    'full_name' => '', 'age' => '', 'participant_number' => '', 'school_grade' => '', 'relationship_type' => '',
    'program_model' => '', 'intermediate_result' => '', 'magic_moment_type' => '',
    'media1_type' => 'Fotografía', 'media1_path' => '', 'media1_original_name' => '', 'media1_description' => '',
    'media2_type' => 'Fotografía', 'media2_path' => '', 'media2_original_name' => '', 'media2_description' => '',
    'media3_type' => 'Fotografía', 'media3_path' => '', 'media3_original_name' => '', 'media3_description' => '',
    'testimony_feeling' => '', 'testimony_learning' => '', 'testimony_application' => '', 'testimony_change' => '',
    'consent_confirmed' => 0, 'status' => 'Borrador'
];
if ($id) $record = record_or_404($pdo, $id);
$message = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $id ? 'Editar' : 'Nuevo' ?> momento mágico</title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar no-print">
  <div><strong>Acción Honduras</strong><span> · Captura de momento mágico</span></div>
  <nav><a href="index.php">Nuevo</a><a href="records.php">Registros</a><?php if ($id): ?><a href="print.php?id=<?= $id ?>" target="_blank">Imprimir</a><?php endif; ?></nav>
</header>
<main class="page-shell">
<?php if ($message): ?><div class="alert no-print"><?= e($message) ?></div><?php endif; ?>
<form id="momentForm" action="save.php" method="post" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
<input type="hidden" name="id" value="<?= e((string)$record['id']) ?>">

<section class="paper">
<h1>FORMULARIO DE CAPTURA – MOMENTO MÁGICO</h1>

<div class="two-col">
<table class="form-table">
<tr><th>PAÍS:</th><td><select name="country"><option>Honduras</option><option>Otro</option></select></td></tr>
<tr><th>SOCIO LOCAL:</th><td><input name="local_partner" required value="<?= e($record['local_partner']) ?>"></td></tr>
<tr><th>COMUNIDAD:</th><td><input name="community" list="communities" required value="<?= e($record['community']) ?>"></td></tr>
<tr><th>FECHA CAPTURA:</th><td><input type="date" name="capture_date" required value="<?= e($record['capture_date']) ?>"></td></tr>
<tr><th>FECHA ENVÍO:</th><td><input type="date" name="send_date" value="<?= e($record['send_date']) ?>"></td></tr>
<tr><th>PERSONA QUE CAPTURÓ:</th><td><input name="captured_by" required value="<?= e($record['captured_by']) ?>"></td></tr>
<tr><th>TELÉFONO O CORREO:</th><td><input name="capturer_contact" value="<?= e($record['capturer_contact']) ?>"></td></tr>
</table>

<table class="form-table">
<tr><th>NOMBRE COMPLETO:</th><td><input name="full_name" required value="<?= e($record['full_name']) ?>"></td></tr>
<tr><th>EDAD:</th><td><input type="number" name="age" min="0" max="120" value="<?= e((string)$record['age']) ?>"></td></tr>
<tr><th>NÚMERO DE PARTICIPANTE:</th><td><input name="participant_number" value="<?= e($record['participant_number']) ?>"></td></tr>
<tr><th>GRADO ESCOLAR/OCUPACIÓN:</th><td><select name="school_grade"><option value="">Seleccione</option><?php foreach (['Prebásica','1.º grado','2.º grado','3.º grado','4.º grado','5.º grado','6.º grado','7.º grado','8.º grado','9.º grado','Bachillerato','Universidad','Trabaja','No aplica','Otro'] as $opt): ?><option <?= $record['school_grade']===$opt?'selected':'' ?>><?= e($opt) ?></option><?php endforeach; ?></select></td></tr>
<tr><th>RELACIÓN CON CHILDFUND:</th><td><select name="relationship_type"><option value="">Seleccione</option><?php foreach (['Niña inscrita','Niño inscrito','Joven inscrito/a','Madre, padre o cuidador','Docente','Voluntario/a','Miembro comunitario','Otro'] as $opt): ?><option <?= $record['relationship_type']===$opt?'selected':'' ?>><?= e($opt) ?></option><?php endforeach; ?></select></td></tr>
<tr><th>ESTADO:</th><td><select name="status"><option <?= $record['status']==='Borrador'?'selected':'' ?>>Borrador</option><option <?= $record['status']==='Finalizado'?'selected':'' ?>>Finalizado</option></select></td></tr>
<tr><th>CONSENTIMIENTO:</th><td class="check-cell"><label><input type="checkbox" name="consent_confirmed" value="1" <?= $record['consent_confirmed']?'checked':'' ?>> Verificado</label></td></tr>
</table>
</div>

<div class="classification-grid">
<table class="form-table compact"><tr><th>MODELO PROGRAMÁTICO:</th><td><select name="program_model"><option value="">Seleccione</option><?php foreach (['CC','NSP','MQMC','PACTO','CRECER','REDES','TEJIENDO MI FUTURO','Otro'] as $opt): ?><option <?= $record['program_model']===$opt?'selected':'' ?>><?= e($opt) ?></option><?php endforeach; ?></select></td></tr><tr><th>RESULTADO INTERMEDIO:</th><td><input name="intermediate_result" list="results" value="<?= e($record['intermediate_result']) ?>"></td></tr></table>
<table class="form-table compact"><tr><th>TIPO DE MOMENTO MÁGICO:</th><td><select name="magic_moment_type"><option value="">Seleccione</option><?php foreach (['Momento cotidiano','Momento programático y/o patrocinio','Momento con hito de desarrollo','Niños siendo niños'] as $opt): ?><option <?= $record['magic_moment_type']===$opt?'selected':'' ?>><?= e($opt) ?></option><?php endforeach; ?></select></td></tr></table>
</div>

<h2>DESCRIPCIÓN DE FOTOGRAFÍAS / EVIDENCIAS <small>Incluya hasta 3 archivos.</small></h2>
<table class="form-table media-table">
<tr><th>EVIDENCIA</th><th>TIPO Y ARCHIVO</th><th>DESCRIPCIÓN DE LA ACTIVIDAD, PERSONAS PRESENTES Y EDADES</th></tr>
<?php for ($i=1; $i<=3; $i++): $path=$record["media{$i}_path"] ?? ''; ?>
<tr>
<td class="media-label">Evidencia <?= $i ?><div class="preview" id="preview<?= $i ?>"><?php if ($path && is_image_path($path)): ?><img src="<?= e($path) ?>" alt="Vista previa"><?php elseif ($path): ?><span><?= e($record["media{$i}_original_name"] ?: basename($path)) ?></span><?php endif; ?></div></td>
<td><select name="media<?= $i ?>_type"><?php foreach (['Fotografía','Video','Audio','PDF / documento','Otro'] as $opt): ?><option <?= ($record["media{$i}_type"] ?? '')===$opt?'selected':'' ?>><?= e($opt) ?></option><?php endforeach; ?></select><input class="file-input" type="file" name="media<?= $i ?>" data-preview="preview<?= $i ?>" accept="image/jpeg,image/png,image/webp,video/mp4,audio/mpeg,audio/mp4,application/pdf,.docx,.xlsx"><small><?= $path ? 'Ya existe un archivo; solo seleccione otro para reemplazarlo.' : 'Máximo 15 MB.' ?></small></td>
<td><textarea name="media<?= $i ?>_description" rows="4"><?= e($record["media{$i}_description"] ?? '') ?></textarea></td>
</tr>
<?php endfor; ?>
</table>

<h2>TESTIMONIOS <small>Deben ser cortos, puntuales y fieles a las palabras de la persona.</small></h2>
<table class="form-table testimony-table">
<tr><th>PREGUNTA</th><th>RESPUESTA / TESTIMONIO</th></tr>
<tr><td>¿Cómo te sientes al realizar lo que más te gusta?</td><td><textarea name="testimony_feeling" rows="3"><?= e($record['testimony_feeling']) ?></textarea></td></tr>
<tr><td>¿Qué aprendiste durante la actividad?</td><td><textarea name="testimony_learning" rows="3"><?= e($record['testimony_learning']) ?></textarea></td></tr>
<tr><td>¿Cómo aplicarías o aplicaste lo aprendido?</td><td><textarea name="testimony_application" rows="3"><?= e($record['testimony_application']) ?></textarea></td></tr>
<tr><td>¿Cómo ha cambiado o cambiará tu vida? ¿Harás algo diferente?</td><td><textarea name="testimony_change" rows="3"><?= e($record['testimony_change']) ?></textarea></td></tr>
</table>

<div class="actions no-print"><button type="submit">Guardar en base de datos</button><?php if ($id): ?><a class="button secondary" target="_blank" href="print.php?id=<?= $id ?>">Vista de impresión</a><?php endif; ?></div>
</section>
</form>
</main>

<datalist id="communities"><option value="Alubarén"><option value="Curarén"><option value="La Venta"><option value="Lepaterique"><option value="Reitoca"></datalist>
<datalist id="results"><option value="Resultado intermedio 1"><option value="Resultado intermedio 2"><option value="Resultado intermedio 3"><option value="No aplica"></datalist>
<script src="assets/app.js"></script>
</body>
</html>
