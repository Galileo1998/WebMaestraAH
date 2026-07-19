<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FormService.php';
form_require_admin_auth($db);
form_assert_installed($db);
$service = new FormService($db);
$id = (int)($_GET['id'] ?? 0);
$schema = $service->getSchema($id);
$form = $schema['form'];
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diseñar formulario | <?=form_h($form['titulo'])?></title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="../assets/formularios.css?v=20260714-6"><script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script></head>
<body class="editor-page">
<header class="editor-top"><div class="editor-top-left"><a class="btn btn-icon" href="formularios.php" title="Volver"><i class="fa-solid fa-arrow-left"></i></a><input id="topTitle" class="editor-title-input" value="<?=form_h($form['titulo'])?>"><span id="saveState" class="save-state"><i class="fa-solid fa-cloud"></i> Guardado</span></div><div class="editor-top-actions"><button id="importForm" type="button" class="btn" title="Importar formulario desde JSON"><i class="fa-solid fa-file-import"></i> Importar</button><button id="exportForm" type="button" class="btn" title="Exportar formulario a JSON"><i class="fa-solid fa-file-export"></i> Exportar</button><input id="importFormFile" type="file" accept="application/json,.json" hidden><a class="btn" target="_blank" href="../formularios/responder.php?f=<?=urlencode($form['slug'])?>"><i class="fa-solid fa-eye"></i> Vista previa</a><a class="btn" href="respuestas_formulario.php?id=<?=$id?>"><i class="fa-solid fa-chart-column"></i> Respuestas</a><select id="formStatus" class="select" style="width:145px"><option value="borrador">Borrador</option><option value="publicado">Publicado</option><option value="cerrado">Cerrado</option></select><button id="saveNow" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Guardar</button></div></header>
<div class="editor-layout">
<aside class="editor-panel toolbox"><h4>Campos</h4>
<?php
$tools=[
['short_text','fa-minus','Respuesta corta'],['paragraph','fa-align-left','Párrafo'],['email','fa-envelope','Correo'],['number','fa-hashtag','Número'],['phone','fa-phone','Teléfono'],
['multiple_choice','fa-circle-dot','Opción múltiple'],['checkboxes','fa-square-check','Casillas'],['dropdown','fa-caret-down','Lista desplegable'],['linear_scale','fa-sliders','Escala lineal'],['rating','fa-star','Calificación'],
['date','fa-calendar','Fecha'],['time','fa-clock','Hora'],['datetime','fa-calendar-days','Fecha y hora'],['file_upload','fa-cloud-arrow-up','Subir archivo'],
['multiple_choice_grid','fa-table-cells','Cuadrícula opción'],['checkbox_grid','fa-table-cells-large','Cuadrícula casillas'],['geo_cascade','fa-map-location-dot','Cascada geográfica'],['center_selector','fa-building','Selector de centro'],['consent','fa-signature','Consentimiento'],['title_description','fa-heading','Título y descripción'],['image','fa-image','Imagen'],['video','fa-video','Video']];
foreach($tools as [$type,$icon,$label]): ?><div class="question-tool" data-type="<?=$type?>"><i class="fa-solid <?=$icon?>"></i><?=$label?></div><?php endforeach; ?>
<button id="addSection" class="btn" style="width:100%;justify-content:center;margin-top:10px"><i class="fa-solid fa-layer-group"></i> Nueva sección</button></aside>
<main id="canvas" class="canvas"><div class="form-cover" id="formCover" style="border-top-color:<?=form_h($form['tema_color'])?>"><input id="coverTitle" value="<?=form_h($form['titulo'])?>"><textarea id="coverDescription" rows="2" placeholder="Descripción del formulario"><?=form_h((string)$form['descripcion'])?></textarea></div><div id="sectionsContainer"></div></main>
<aside class="editor-panel properties"><h4>Propiedades</h4><div id="propertiesContent" class="prop-empty"><i class="fa-solid fa-arrow-pointer" style="font-size:2rem"></i><p>Selecciona una pregunta para configurarla.</p></div></aside>
</div>
<script>
window.FORM_BUILDER_BOOT = <?=form_json_encode([
'id'=>$id,'csrf'=>form_csrf_token(),'schema'=>$schema,'api'=>'api_formularios.php'
])?>;
</script><script src="../assets/form_builder.js?v=20260714-6"></script></body></html>
