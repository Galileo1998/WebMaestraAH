<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FormService.php';
form_require_admin_auth($db);
form_assert_installed($db);
$service = new FormService($db);
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        form_validate_csrf($_POST['csrf'] ?? null);
        $action = $_POST['action'] ?? '';
        if ($action === 'create') {
            $title = trim((string)($_POST['titulo'] ?? ''));
            if ($title === '') throw new RuntimeException('Escriba el título del formulario.');
            $id = $service->createForm($title, form_current_user_id());
            header('Location: editor_formulario.php?id=' . $id);
            exit;
        }
        if ($action === 'duplicate') {
            $id = $service->duplicateForm((int)($_POST['id'] ?? 0), form_current_user_id());
            header('Location: editor_formulario.php?id=' . $id);
            exit;
        }
        if ($action === 'delete') {
            $service->deleteForm((int)($_POST['id'] ?? 0), form_current_user_id());
            $msg = '<div class="alert alert-success">Formulario eliminado.</div>';
        }
    } catch (Throwable $e) {
        $msg = '<div class="alert alert-error">'.form_h($e->getMessage()).'</div>';
    }
}
$forms = $service->listForms();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Formularios | Acción Honduras</title><link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="../assets/formularios.css?v=20260714-3"></head>
<body><div class="forms-shell"><?php $sidebar=__DIR__.'/sidebar.php'; if(is_file($sidebar)) include $sidebar; ?>
<main class="forms-main"><div class="forms-topbar"><div class="forms-title"><h1><i class="fa-solid fa-rectangle-list" style="color:#34859B"></i> Formularios</h1><p>Crea formularios, encuestas, evaluaciones, registros territoriales y tableros de respuestas.</p></div><button class="btn btn-primary" onclick="document.getElementById('createModal').classList.add('open')"><i class="fa-solid fa-plus"></i> Nuevo formulario</button></div>
<?=$msg?>
<?php if(!$forms): ?><div class="card empty"><i class="fa-solid fa-file-circle-plus"></i><h2>No hay formularios</h2><p>Crea el primero para comenzar.</p></div><?php else: ?>
<div class="forms-grid"><?php foreach($forms as $f): $status=$f['estado']; ?>
<article class="card form-card"><div><div class="form-card-meta"><span class="badge badge-<?=$status==='publicado'?'published':($status==='cerrado'?'closed':'draft')?>"><i class="fa-solid fa-circle"></i><?=form_h(ucfirst($status))?></span><span class="badge">Actualizado <?=form_h(date('d/m/Y H:i',strtotime($f['updated_at'])))?></span></div><h3><?=form_h($f['titulo'])?></h3></div><div class="form-card-stats"><div class="mini-stat"><span>Preguntas</span><strong><?=number_format((int)$f['preguntas'])?></strong></div><div class="mini-stat"><span>Respuestas</span><strong><?=number_format((int)$f['respuestas'])?></strong></div></div><div class="form-card-actions"><a class="btn btn-sm btn-primary" href="editor_formulario.php?id=<?=$f['id']?>"><i class="fa-solid fa-pen"></i> Editar</a><a class="btn btn-sm" href="respuestas_formulario.php?id=<?=$f['id']?>"><i class="fa-solid fa-chart-column"></i> Respuestas</a><a class="btn btn-sm" target="_blank" href="../formularios/responder.php?f=<?=urlencode($f['slug'])?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir</a><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=form_csrf_token()?>"><input type="hidden" name="action" value="duplicate"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn btn-sm" title="Duplicar"><i class="fa-solid fa-copy"></i></button></form><form method="post" style="display:inline" onsubmit="return confirm('¿Eliminar el formulario y todas sus respuestas?')"><input type="hidden" name="csrf" value="<?=form_csrf_token()?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?=$f['id']?>"><button class="btn btn-sm btn-danger" title="Eliminar"><i class="fa-solid fa-trash"></i></button></form></div></article>
<?php endforeach; ?></div><?php endif; ?></main></div>
<div id="createModal" class="modal"><div class="modal-box"><div class="modal-head"><h3>Nuevo formulario</h3><button class="btn btn-icon" onclick="document.getElementById('createModal').classList.remove('open')"><i class="fa-solid fa-xmark"></i></button></div><form method="post" style="margin-top:18px"><input type="hidden" name="csrf" value="<?=form_csrf_token()?>"><input type="hidden" name="action" value="create"><div class="field"><label>Título</label><input class="input" name="titulo" autofocus required placeholder="Ej. Seguimiento mensual de Centros ADN"></div><button class="btn btn-primary" style="width:100%;justify-content:center">Crear y diseñar</button></form></div></div></body></html>
