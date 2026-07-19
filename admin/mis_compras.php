<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$db=(new Database())->getConnection();
$auth=new Auth($db);
$auth->requireLogin();

function h($value): string { return htmlspecialchars((string)$value,ENT_QUOTES,'UTF-8'); }
function csrfToken(): string {
    if (empty($_SESSION['compras_csrf'])) $_SESSION['compras_csrf']=bin2hex(random_bytes(32));
    return (string)$_SESSION['compras_csrf'];
}
function normalizedOwnerTokens(string $value): array {
    $value=mb_strtolower(trim($value),'UTF-8');
    $ascii=@iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$value);
    if ($ascii!==false) $value=$ascii;
    $parts=preg_split('/[^a-z0-9]+/',strtolower($value),-1,PREG_SPLIT_NO_EMPTY)?:[];
    $ignored=['de','del','la','las','los','en','y','por','para','oficial','administrador','admin'];
    return array_values(array_unique(array_filter($parts,static fn($token)=>strlen($token)>=4&&!in_array($token,$ignored,true))));
}
function historicalPurchaseMatchesUser(string $solicitante,string $userName): bool {
    $userTokens=normalizedOwnerTokens($userName);
    $requestTokens=normalizedOwnerTokens($solicitante);
    if (!$userTokens || !$requestTokens) return false;
    $matches=count(array_intersect($userTokens,$requestTokens));
    return $matches>=2 && $matches>=min(3,(int)ceil(count($userTokens)*0.5));
}
function statusBadge(string $raw): string {
    $label=str_replace('_',' · ',$raw);
    $class='default';
    if (strpos($raw,'1_')===0) $class='draft';
    elseif (strpos($raw,'2_')===0 || strpos($raw,'3_')===0 || strpos($raw,'4_')===0) $class='process';
    elseif (strpos($raw,'5_')===0 || strpos($raw,'6_')===0) $class='done';
    elseif ($raw==='9_Archivada') $class='archived';
    return '<span class="status '.$class.'">'.h($label).'</span>';
}

$isAdmin=(string)($_SESSION['user_role']??'')==='admin';
$userId=(int)($_SESSION['user_id']??0);
$showAll=$isAdmin && (string)($_GET['todas']??'')==='1';
$month=trim((string)($_GET['mes']??''));
if ($month!=='' && !preg_match('/^\d{4}-\d{2}$/',$month)) $month='';

try {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_propiedad (
        compra_id INT NOT NULL PRIMARY KEY, usuario_id INT NOT NULL, usuario_nombre VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, archived_at DATETIME NULL, archived_by VARCHAR(255) NULL,
        INDEX idx_compra_owner(usuario_id), INDEX idx_compra_archived(archived_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Recupera la propiedad de expedientes antiguos cuando el solicitante comienza con el nombre del usuario.
    $db->exec("INSERT IGNORE INTO ah_compras_propiedad(compra_id,usuario_id,usuario_nombre)
        SELECT c.id,u.id,u.name FROM ah_compras c JOIN users u ON c.solicitante LIKE CONCAT(u.name,'%')");

    // Recupera únicamente para el usuario conectado expedientes antiguos sin dueño.
    // Se exige coincidencia de varios componentes del nombre para evitar asignaciones ajenas.
    if ($userId>0) {
        $userStmt=$db->prepare('SELECT name FROM users WHERE id=? LIMIT 1');
        $userStmt->execute([$userId]);
        $userName=(string)$userStmt->fetchColumn();
        $unowned=$db->query('SELECT c.id,c.solicitante FROM ah_compras c LEFT JOIN ah_compras_propiedad o ON o.compra_id=c.id WHERE o.compra_id IS NULL')->fetchAll(PDO::FETCH_ASSOC);
        $claim=$db->prepare('INSERT IGNORE INTO ah_compras_propiedad(compra_id,usuario_id,usuario_nombre) VALUES(?,?,?)');
        foreach ($unowned as $candidate) {
            if (historicalPurchaseMatchesUser((string)$candidate['solicitante'],$userName)) {
                $claim->execute([(int)$candidate['id'],$userId,$userName]);
            }
        }
    }
} catch (Throwable $e) {}

$where=[]; $params=[];
if (!$showAll) { $where[]='o.usuario_id=?'; $params[]=$userId; }
if ($month!=='') { $where[]="DATE_FORMAT(c.fecha,'%Y-%m')=?"; $params[]=$month; }
$sql="SELECT c.id,c.fecha,c.solicitante,c.descripcion_actividad,c.monto_total,c.estado,c.created_at,
             o.usuario_id,o.usuario_nombre,o.archived_at,o.archived_by,
             (SELECT cp.marco_logico FROM ah_compras_poa cp WHERE cp.compra_id=c.id ORDER BY cp.id LIMIT 1) AS marco_logico
      FROM ah_compras c LEFT JOIN ah_compras_propiedad o ON o.compra_id=c.id";
if ($where) $sql.=' WHERE '.implode(' AND ',$where);
$sql.=' ORDER BY c.fecha DESC,c.id DESC';
$st=$db->prepare($sql); $st->execute($params); $compras=$st->fetchAll(PDO::FETCH_ASSOC);

$months=$db->query("SELECT DISTINCT DATE_FORMAT(fecha,'%Y-%m') mes FROM ah_compras ORDER BY mes DESC")->fetchAll(PDO::FETCH_COLUMN);
$msg=(string)($_GET['msg']??'')==='archived' ? 'El expediente fue archivado y retirado de los cálculos y gráficos.' : '';
if ((string)($_GET['msg']??'')==='deleted') $msg='La compra y todos sus registros relacionados fueron eliminados definitivamente. El saldo POA fue recalculado.';
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Historial de compras | Acción Honduras</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--p:#34859B;--a:#46B094;--ink:#1e293b;--line:#dbe3ee;--bg:#f6f8fb}*{box-sizing:border-box}body{margin:0;display:flex;min-height:100vh;background:var(--bg);font-family:Inter,sans-serif;color:var(--ink)}.main{flex:1;padding:30px 36px;min-width:0}.header,.filters,.actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.header{justify-content:space-between;margin-bottom:18px}.header h1{margin:0;font-size:1.7rem}.btn{border:0;border-radius:9px;padding:10px 14px;font-weight:800;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;background:#e2e8f0;color:#334155}.btn.primary{background:var(--p);color:#fff}.btn.all{background:#0f172a;color:#fff}.filters{background:#fff;border:1px solid var(--line);padding:13px;border-radius:12px;margin-bottom:16px}.filters select{padding:9px 11px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;font:inherit}.notice{padding:12px 14px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;border-radius:9px;margin-bottom:15px;font-weight:700}.table-wrap{overflow:auto;background:#fff;border:1px solid var(--line);border-radius:13px}table{border-collapse:collapse;width:100%;min-width:1050px}th{background:#f1f5f9;color:#475569;text-transform:uppercase;font-size:.72rem;letter-spacing:.04em;text-align:left;padding:12px}td{padding:12px;border-top:1px solid #e2e8f0;font-size:.86rem;vertical-align:middle}tr:hover td{background:#f8fafc}.folio{color:#075985;font-weight:900;white-space:nowrap}.creator small{display:block;color:#64748b;margin-top:3px}.status{display:inline-flex;border-radius:999px;padding:5px 9px;font-size:.7rem;font-weight:900;white-space:nowrap}.status.draft{background:#e2e8f0;color:#475569}.status.process{background:#fef3c7;color:#92400e}.status.done{background:#dcfce7;color:#166534}.status.archived{background:#fee2e2;color:#991b1b}.status.default{background:#e0e7ff;color:#3730a3}.icon-btn{width:35px;height:35px;padding:0;justify-content:center}.edit{background:#e0f2fe;color:#075985}.print{background:#e2e8f0;color:#334155}.archive{background:#fee2e2;color:#991b1b}.empty{text-align:center;padding:45px;color:#64748b}.modal{display:none;position:fixed;inset:0;background:rgba(15,23,42,.68);align-items:center;justify-content:center;z-index:9999}.modal.open{display:flex}.modal-card{width:min(460px,92vw);background:#fff;border-radius:14px;padding:22px}.modal-card h3{margin:0 0 8px}.modal-card p{color:#64748b;font-size:.88rem}.modal-card input{width:100%;padding:11px;border:1px solid #cbd5e1;border-radius:8px;margin:8px 0 15px}.modal-actions{display:flex;justify-content:flex-end;gap:9px}@media(max-width:800px){.main{padding:18px 13px}.header{align-items:flex-start}}
</style>
</head>
<body>
<?php include 'sidebar.php'; ?>
<main class="main">
  <div class="header">
    <h1><i class="fa-solid fa-clock-rotate-left"></i> <?= $showAll?'Historial general':'Mis compras' ?></h1>
    <div class="actions">
      <?php if($isAdmin): ?><a class="btn all" href="mis_compras.php<?=$showAll?'':'?todas=1'?>"><i class="fa-solid fa-users-viewfinder"></i> <?=$showAll?'Ver solo las mías':'Ver todas'?></a><?php endif; ?>
      <a class="btn primary" href="compras.php"><i class="fa-solid fa-plus"></i> Nueva compra</a>
    </div>
  </div>
  <?php if($msg): ?><div class="notice"><i class="fa-solid fa-circle-check"></i> <?=h($msg)?></div><?php endif; ?>
  <form class="filters" method="get">
    <?php if($showAll): ?><input type="hidden" name="todas" value="1"><?php endif; ?>
    <label for="mes"><strong>Mes:</strong></label>
    <select id="mes" name="mes" onchange="this.form.submit()"><option value="">Todos los meses</option><?php foreach($months as $m):?><option value="<?=h($m)?>" <?=$month===$m?'selected':''?>><?=h(date('F Y',strtotime($m.'-01'))) ?></option><?php endforeach;?></select>
    <?php if($month!==''): ?><a class="btn" href="mis_compras.php<?=$showAll?'?todas=1':''?>">Limpiar</a><?php endif; ?>
  </form>
  <div class="table-wrap"><table><thead><tr><th>Folio</th><th>Fecha</th><?php if($isAdmin):?><th>Realizada por</th><?php endif;?><th>Solicitante</th><th>Descripción</th><th>Marco lógico</th><th>Monto</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
  <?php if($compras): foreach($compras as $c): ?>
    <tr><td class="folio">#AH-C-<?=sprintf('%04d',$c['id'])?></td><td><?=h(date('d/m/Y',strtotime($c['fecha'])))?></td>
    <?php if($isAdmin):?><td class="creator"><strong><?=h($c['usuario_nombre']?:'Sin asignar')?></strong><small>ID usuario: <?=h($c['usuario_id']?:'—')?></small></td><?php endif;?>
    <td><?=h($c['solicitante'])?></td><td><?=h(mb_strimwidth((string)$c['descripcion_actividad'],0,70,'…'))?></td><td><?=h($c['marco_logico']?:'—')?></td><td><strong>L. <?=number_format((float)$c['monto_total'],2)?></strong></td><td><?=statusBadge((string)$c['estado'])?></td>
    <td><div class="actions"><a class="btn icon-btn edit" href="compras.php?id=<?=$c['id']?>" title="Ver"><i class="fa-solid fa-eye"></i></a><a class="btn icon-btn print" href="imprimir_compra.php?id=<?=$c['id']?>" target="_blank" title="Documentos"><i class="fa-solid fa-print"></i></a><?php if($c['estado']!=='9_Archivada'):?><button class="btn icon-btn edit" onclick="openSecureAction('desbloquear_edicion',<?=$c['id']?>,'Editar expediente')" title="Editar con contraseña"><i class="fa-solid fa-pen-to-square"></i></button><?php endif;?><button class="btn icon-btn archive" onclick="openSecureAction('eliminar_compra',<?=$c['id']?>,'Eliminar compra definitivamente')" title="Eliminar definitivamente"><i class="fa-solid fa-trash-can"></i></button></div></td></tr>
  <?php endforeach; else: ?><tr><td colspan="<?=$isAdmin?9:8?>"><div class="empty"><i class="fa-solid fa-folder-open fa-3x"></i><h3>No hay compras para este filtro</h3></div></td></tr><?php endif; ?>
  </tbody></table></div>
</main>
<div class="modal" id="secureModal"><form class="modal-card" method="post" action="compras.php" id="secureForm"><h3 id="secureTitle">Confirmar acción</h3><p id="secureText">Ingrese su contraseña actual.</p><input type="hidden" name="csrf_token" value="<?=h(csrfToken())?>"><input type="hidden" name="action" id="secureAction"><input type="hidden" name="compra_id" id="securePurchase"><input type="password" name="password" required autocomplete="current-password" placeholder="Contraseña actual"><div class="modal-actions"><button type="button" class="btn" onclick="closeSecureAction()">Cancelar</button><button class="btn primary">Confirmar</button></div></form></div>
<script>function openSecureAction(action,id,title){document.getElementById('secureAction').value=action;document.getElementById('securePurchase').value=id;document.getElementById('secureTitle').textContent=title;document.getElementById('secureText').textContent=action==='eliminar_compra'?'Esta acción es irreversible: eliminará la compra, almacén, planillas, cotizaciones, movimientos y ejecución POA. Ingrese su contraseña para continuar.':'Ingrese su contraseña actual. La acción quedará registrada.';document.getElementById('secureModal').classList.add('open');setTimeout(()=>document.querySelector('#secureModal input[type=password]').focus(),50)}function closeSecureAction(){document.getElementById('secureModal').classList.remove('open')}document.getElementById('secureModal').addEventListener('click',e=>{if(e.target.id==='secureModal')closeSecureAction()});document.getElementById('secureForm').addEventListener('submit',e=>{if(document.getElementById('secureAction').value==='eliminar_compra'&&!confirm('¿Confirma la eliminación definitiva de esta compra? Esta acción no se puede deshacer.'))e.preventDefault()})</script>
</body></html>
