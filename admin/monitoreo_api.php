<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../config/Database.php';
require_once dirname(__DIR__) . '/classes/Auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    $db = (new Database())->getConnection();
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $auth = new Auth($db);
    $auth->requireLogin();
    $auth->checkAccess('monitoreo.php', $db);

    $action = (string)($_REQUEST['action'] ?? '');
    $requireV2Csrf = static function (): void {
        $csrf = (string)($_POST['csrf'] ?? '');
        if (empty($_SESSION['monitoreo_v2_csrf']) || !hash_equals((string)$_SESSION['monitoreo_v2_csrf'], $csrf)) {
            throw new RuntimeException('Sesión de edición vencida.');
        }
    };
    $currentCredential = static function (PDO $connection): ?array {
        $ids = [$_SESSION['user_id'] ?? null,$_SESSION['usuario_id'] ?? null,$_SESSION['id_usuario'] ?? null,$_SESSION['id'] ?? null,is_array($_SESSION['user'] ?? null)?($_SESSION['user']['id']??null):null];
        foreach ($ids as $id) if ($id !== null && $id !== '' && is_numeric($id)) { $st=$connection->prepare('SELECT id,email,password FROM users WHERE id=? LIMIT 1');$st->execute([(int)$id]);if($row=$st->fetch(PDO::FETCH_ASSOC))return $row; }
        $emails = [$_SESSION['email']??null,$_SESSION['user_email']??null,$_SESSION['correo']??null,is_array($_SESSION['user']??null)?($_SESSION['user']['email']??null):null];
        foreach ($emails as $email) { $email=trim((string)$email);if($email!==''){$st=$connection->prepare('SELECT id,email,password FROM users WHERE email=? LIMIT 1');$st->execute([$email]);if($row=$st->fetch(PDO::FETCH_ASSOC))return $row;} }
        return null;
    };
    $saveSnapshot = static function (PDO $connection, int $taskId, string $event): void {
        try {
            $taskStmt=$connection->prepare('SELECT * FROM ah_poa WHERE id=? LIMIT 1');$taskStmt->execute([$taskId]);$task=$taskStmt->fetch(PDO::FETCH_ASSOC);if(!$task)return;
            $assignmentStmt=$connection->prepare('SELECT * FROM ah_poa_asignaciones WHERE id_poa=? ORDER BY id');$assignmentStmt->execute([$taskId]);
            $stageStmt=$connection->prepare('SELECT * FROM ah_poa_etapas WHERE id_poa=? ORDER BY orden,id');$stageStmt->execute([$taskId]);
            $snapshot=['tarea'=>$task,'asignaciones'=>$assignmentStmt->fetchAll(PDO::FETCH_ASSOC),'etapas'=>$stageStmt->fetchAll(PDO::FETCH_ASSOC)];$json=json_encode($snapshot,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)return;$hash=hash('sha256',$json);
            $last=$connection->prepare('SELECT estado_hash FROM ah_monitoreo_historial WHERE id_poa=? ORDER BY id DESC LIMIT 1');$last->execute([$taskId]);if(hash_equals((string)($last->fetchColumn()?:''),$hash))return;
            $period=(string)($task['operativo_periodo']??'');$user=(string)($_SESSION['email']??$_SESSION['user_email']??$_SESSION['nombre']??'V2');$insert=$connection->prepare('INSERT INTO ah_monitoreo_historial (id_poa,periodo,evento,estado_json,estado_hash,usuario) VALUES(?,?,?,?,?,?)');$insert->execute([$taskId,$period,$event,$json,$hash,$user]);
        } catch (Throwable $ignored) {}
    };
    $normalizeText = static function (string $value): string {
        $value=mb_strtolower(trim($value),'UTF-8');return strtr($value,['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    };
    $placeCategory = static function (string $value) use ($normalizeText): string {
        $value=$normalizeText($value);if(str_contains($value,'preescolar'))return 'preescolar';if(str_contains($value,'adn'))return 'adn';if(str_contains($value,'uaps')||str_contains($value,'cis'))return 'uaps/cis';if(str_contains($value,'centro educativo')||str_contains($value,'educativo')||str_contains($value,'basica')||str_contains($value,'media'))return 'basica';return '';
    };
    $centerCategory = static function (string $value) use ($normalizeText): string {
        $value=$normalizeText($value);if(str_contains($value,'preescolar'))return 'preescolar';if(str_contains($value,'adn'))return 'adn';if(str_contains($value,'uaps')||str_contains($value,'cis'))return 'uaps/cis';if(str_contains($value,'basica')||str_contains($value,'media')||str_contains($value,'educativo'))return 'basica';return 'otro';
    };
    $centersFor = static function (PDO $connection, array $bases, array $places) use ($normalizeText,$placeCategory,$centerCategory): array {
        $baseKeys=array_values(array_unique(array_filter(array_map($normalizeText,$bases))));$categories=array_values(array_unique(array_filter(array_map($placeCategory,$places))));if(!$baseKeys||!$categories)return [];
        static $allCenters=null;if($allCenters===null)$allCenters=$connection->query('SELECT * FROM ah_centros ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);$result=[];foreach($allCenters as $center){if(!in_array($normalizeText((string)($center['comunidad_base']??'')),$baseKeys,true)||!in_array($centerCategory((string)($center['tipo']??'')),$categories,true))continue;$result[(string)$center['id']]=$center;}return $result;
    };
    $targetPopulation = static function (array $center, string $unit) use ($normalizeText): float {
        $number=static fn(string $field):float=>(float)($center[$field]??0);$type=$normalizeText((string)($center['tipo']??''));$total=$number('pob_total');if(str_contains($type,'preescolar')||str_contains($type,'basica')||str_contains($type,'educativo')||str_contains($type,'media'))return $total;
        $age05=$number('pm_0_5_9_f')+$number('pm_0_5_9_m');if($age05===0.0)$age05=$number('pob_0_5');$age614=$number('pm_6_14_9_f')+$number('pm_6_14_9_m');$age1517=$number('pm_15_17_9_f')+$number('pm_15_17_9_m');$age617=$age614+$age1517;if($age617===0.0)$age617=$number('pob_6_17');$age1824=$number('pm_18_24_f')+$number('pm_18_24_m');if($age1824===0.0)$age1824=$number('pob_18_24');$leaders=$number('pm_lideres_f')+$number('pm_lideres_m');if($leaders===0.0)$leaders=$number('lideres_f')+$number('lideres_m');$unit=$normalizeText($unit);if(str_contains($unit,'lider'))return $leaders;if(str_contains($unit,'infante')||str_contains($unit,'0 a 5'))return $age05;if(str_contains($unit,'nino')||str_contains($unit,'nina')||str_contains($unit,'6 a 14')||str_contains($unit,'nnaj'))return $age614>0?$age614:$age617;if(str_contains($unit,'adolescente')||str_contains($unit,'15 a 17'))return $age1517;if(str_contains($unit,'joven')||str_contains($unit,'18 a 24'))return $age1824;return $total;
    };
    if ($action === 'save_activity_meta' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$taskId=(int)($_POST['id_poa']??0);$mode=(string)($_POST['mode']??'status');
        if($mode==='hidden'){$hidden=(string)($_POST['hidden']??'0')==='1'?1:0;$stmt=$db->prepare('UPDATE ah_poa SET operativo_oculto=? WHERE id=? AND is_active=1');$stmt->execute([$hidden,$taskId]);$saveSnapshot($db,$taskId,$hidden?'ocultar':'restaurar');echo json_encode(['status'=>'ok','hidden'=>$hidden]);exit;}
        if($mode==='progress'){$progress=max(0,min(100,(float)($_POST['progress']??0)));$status=(string)round($progress).'%';$stmt=$db->prepare('UPDATE ah_poa SET operativo_estado=? WHERE id=? AND is_active=1');$stmt->execute([$status,$taskId]);echo json_encode(['status'=>'ok','estado'=>$status],JSON_UNESCAPED_UNICODE);exit;}
        $allowed=['Pendiente','En Proceso','Completado','Reprogramado','Cancelado'];$status=trim((string)($_POST['estado']??''));if(!in_array($status,$allowed,true))throw new RuntimeException('Estado no válido.');$stmt=$db->prepare('UPDATE ah_poa SET operativo_estado=? WHERE id=? AND is_active=1');$stmt->execute([$status,$taskId]);$saveSnapshot($db,$taskId,'estado');echo json_encode(['status'=>'ok','estado'=>$status],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'add_catalog' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$type=(string)($_POST['catalog_type']??'');$value=trim((string)($_POST['catalog_value']??''));$program=trim((string)($_POST['programa']??'GENERAL'));$stage=trim((string)($_POST['etapa']??'TODAS'));$map=['responsable'=>'ah_cat_responsables','unidad'=>'ah_cat_unidades','verificacion'=>'ah_cat_verificaciones','lugar'=>'ah_cat_lugares'];if(!isset($map[$type])||$value==='')throw new RuntimeException('Dato de catálogo no válido.');$table=$map[$type];if(in_array($type,['unidad','verificacion'],true)){$stmt=$db->prepare("INSERT INTO `{$table}` (programa,etapa,nombre,activo) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE activo=1");$stmt->execute([$program,$stage,$value]);}else{$stmt=$db->prepare("INSERT IGNORE INTO `{$table}` (nombre) VALUES (?)");$stmt->execute([$value]);}echo json_encode(['status'=>'ok','value'=>$value],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'verify_goals_password' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();
        $password=(string)($_POST['password']??'');if($password==='')throw new RuntimeException('Ingrese su contraseña.');
        $user=$currentCredential($db);if(!$user)throw new RuntimeException('No fue posible identificar al usuario autenticado.');
        $hash=(string)($user['password']??'');if(!(password_verify($password,$hash)||hash_equals($hash,$password)))throw new RuntimeException('Contraseña incorrecta.');
        $_SESSION['metas_edit_unlocked_until']=time()+900;
        echo json_encode(['status'=>'ok','expires_in'=>900],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'save_goals' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();
        if (empty($_SESSION['metas_edit_unlocked_until']) || (int)$_SESSION['metas_edit_unlocked_until'] < time()) throw new RuntimeException('La autorización para editar metas venció.');
        $taskId=(int)($_POST['id_poa']??0);$payload=json_decode((string)($_POST['payload']??'{}'),true);if(!is_array($payload))throw new RuntimeException('Metas no válidas.');
        $months=['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
        $sets=['meta_actividades=?','meta_actividades_alc=?','operativo_meta_obj=?','operativo_meta_alc=?'];
        $values=[max(0,(float)($payload['m_act_obj']??0)),max(0,(float)($payload['m_act_alc']??0)),max(0,(float)($payload['m_part_obj']??0)),max(0,(float)($payload['m_part_alc']??0))];
        foreach($months as $month){$sets[]="op_act_{$month}=?";$values[]=max(0,(float)($payload['op_act'][$month]??0));$sets[]="op_part_{$month}=?";$values[]=max(0,(float)($payload['op_part'][$month]??0));$sets[]="op_editado_{$month}=1";}
        $values[]=$taskId;$stmt=$db->prepare('UPDATE ah_poa SET '.implode(',',$sets).' WHERE id=? AND is_active=1');$stmt->execute($values);
        $saveSnapshot($db,$taskId,'metas_v2');echo json_encode(['status'=>'ok'],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'save_notes' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();
        $taskId = (int)($_POST['id_poa'] ?? 0);
        $notes = (string)($_POST['info_adicional'] ?? '');
        $stmt = $db->prepare('UPDATE ah_poa SET operativo_info_adicional=? WHERE id=? AND is_active=1');
        $stmt->execute([$notes, $taskId]);
        $saveSnapshot($db,$taskId,'notas_v2');echo json_encode(['status'=>'ok'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'save_team_value' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();
        $taskId = (int)($_POST['id_poa'] ?? 0);
        $assignmentId = (int)($_POST['assignment_id'] ?? 0);
        $month = (string)($_POST['month'] ?? '');
        $kind = (string)($_POST['kind'] ?? '');
        $value = max(0, (float)($_POST['value'] ?? 0));
        $months = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
        if (!in_array($month, $months, true) || !in_array($kind, ['meta','logro'], true)) throw new RuntimeException('Campo de asignación no válido.');
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT * FROM ah_poa_asignaciones WHERE id=? AND id_poa=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$assignmentId, $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Asignación no encontrada.');
        $row[$kind . '_' . $month] = $value;
        $metaTotal = 0.0; $logroTotal = 0.0; $activeMonths = [];
        foreach ($months as $m) {
            $meta = (float)($row['meta_' . $m] ?? 0); $logro = (float)($row['logro_' . $m] ?? 0);
            $metaTotal += $meta; $logroTotal += $logro;
            if ($meta > 0 || $logro > 0) $activeMonths[] = $m;
        }
        $column = $kind . '_' . $month;
        $stmt = $db->prepare("UPDATE ah_poa_asignaciones SET `{$column}`=?,meta_asignada=?,logro_asignado=?,meses_asignados=? WHERE id=? AND id_poa=?");
        $stmt->execute([$value, $metaTotal, $logroTotal, implode(', ', $activeMonths), $assignmentId, $taskId]);
        $db->commit();
        $saveSnapshot($db,$taskId,'equipo_v2');echo json_encode(['status'=>'ok','meta_total'=>$metaTotal,'logro_total'=>$logroTotal], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'save_team_assignment' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$taskId=(int)($_POST['id_poa']??0);$assignmentId=(int)($_POST['assignment_id']??0);$remove=(string)($_POST['remove']??'0')==='1';
        if($remove&&$assignmentId>0){$stmt=$db->prepare('DELETE FROM ah_poa_asignaciones WHERE id=? AND id_poa=?');$stmt->execute([$assignmentId,$taskId]);$saveSnapshot($db,$taskId,'equipo_v2');echo json_encode(['status'=>'ok']);exit;}
        $tecnico=trim((string)($_POST['tecnico']??''));$base=trim((string)($_POST['base_asignada']??''));if($tecnico==='')throw new RuntimeException('Seleccione un técnico.');
        $stmt=$db->prepare("INSERT INTO ah_poa_asignaciones (id_poa,tecnico,base_asignada,meses_asignados,meta_asignada,logro_asignado,lugares_json) VALUES(?,?,?,'',0,0,'[]')");$stmt->execute([$taskId,$tecnico,$base]);
        $assignmentId=(int)$db->lastInsertId();$saveSnapshot($db,$taskId,'equipo_v2');echo json_encode(['status'=>'ok','assignment_id'=>$assignmentId],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'save_team_places' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$taskId=(int)($_POST['id_poa']??0);$places=json_decode((string)($_POST['places']??'[]'),true);if(!is_array($places))$places=[];$places=array_values(array_unique(array_filter(array_map('strval',$places),static fn($v)=>trim($v)!=='')));$json=json_encode($places,JSON_UNESCAPED_UNICODE);
        $oldStmt=$db->prepare('SELECT equipo_lugares_json FROM ah_poa WHERE id=?');$oldStmt->execute([$taskId]);$oldPlaces=json_decode((string)$oldStmt->fetchColumn(),true);if(!is_array($oldPlaces))$oldPlaces=[];$hasCenterPlaces=(bool)array_filter(array_merge($places,$oldPlaces),static fn($value)=>$placeCategory((string)$value)!=='');
        $db->beginTransaction();$stmt=$db->prepare('UPDATE ah_poa SET equipo_lugares_json=? WHERE id=? AND is_active=1');$stmt->execute([$json,$taskId]);$stmt=$db->prepare('UPDATE ah_poa_asignaciones SET lugares_json=? WHERE id_poa=?');$stmt->execute([$json,$taskId]);$updated=[];
        if($hasCenterPlaces){$monthMap=[1=>'jan',2=>'feb',3=>'mar',4=>'apr',5=>'may',6=>'jun',7=>'jul',8=>'aug',9=>'sep',10=>'oct',11=>'nov',12=>'dec'];$month=$monthMap[(int)date('n')];$assignStmt=$db->prepare('SELECT * FROM ah_poa_asignaciones WHERE id_poa=? ORDER BY id');$assignStmt->execute([$taskId]);foreach($assignStmt->fetchAll(PDO::FETCH_ASSOC) as $assignment){$centers=$centersFor($db,[(string)($assignment['base_asignada']??'')],$places);$value=array_sum(array_map(static fn($center)=>(float)($center['pob_total']??0),$centers));$assignment['meta_'.$month]=$value;$metaTotal=0.0;$logroTotal=0.0;$active=[];foreach(['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $key){$metaTotal+=(float)($assignment['meta_'.$key]??0);$logroTotal+=(float)($assignment['logro_'.$key]??0);if((float)($assignment['meta_'.$key]??0)>0||(float)($assignment['logro_'.$key]??0)>0)$active[]=$key;}$update=$db->prepare("UPDATE ah_poa_asignaciones SET meta_{$month}=?,meta_asignada=?,logro_asignado=?,meses_asignados=? WHERE id=? AND id_poa=?");$update->execute([$value,$metaTotal,$logroTotal,implode(', ',$active),(int)$assignment['id'],$taskId]);$updated[(string)$assignment['id']]=['month'=>$month,'value'=>$value,'meta_total'=>$metaTotal,'logro_total'=>$logroTotal];}}
        $db->commit();$saveSnapshot($db,$taskId,'lugares_equipo_v2');echo json_encode(['status'=>'ok','places'=>$places,'updated'=>$updated],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'save_stage_row' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();
        $taskId = (int)($_POST['id_poa'] ?? 0);
        $stageId = (int)($_POST['stage_id'] ?? 0);
        $rowKey = (string)($_POST['row_key'] ?? '');
        $db->beginTransaction();
        $stmt = $db->prepare('SELECT involucrados_json FROM ah_poa_etapas WHERE id=? AND id_poa=? LIMIT 1 FOR UPDATE');
        $stmt->execute([$stageId, $taskId]);
        $json = $stmt->fetchColumn();
        if ($json === false || $rowKey === '') {
            $db->rollBack();
            http_response_code(404);
            echo json_encode(['status'=>'error','msg'=>'Línea de agenda no encontrada.'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $rows = json_decode((string)$json, true);
        if (!is_array($rows)) $rows = [];
        if (!isset($rows[$rowKey]) || !is_array($rows[$rowKey])) {
            if (($_POST['create'] ?? '0') !== '1') {
                $db->rollBack();
                http_response_code(404);
                echo json_encode(['status'=>'error','msg'=>'La línea ya no existe.'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $rows[$rowKey] = ['persona'=>'','unidad'=>'','base'=>'','mes'=>'','verifics'=>[],'lugar'=>[],'centros'=>[],'deleted'=>false];
        }
        if (array_key_exists('persona', $_POST)) $rows[$rowKey]['persona'] = trim((string)$_POST['persona']);
        if (array_key_exists('unidad', $_POST)) $rows[$rowKey]['unidad'] = trim((string)$_POST['unidad']);
        if (array_key_exists('base', $_POST)) $rows[$rowKey]['base'] = trim((string)$_POST['base']);
        if (array_key_exists('mes', $_POST)) $rows[$rowKey]['mes'] = trim((string)$_POST['mes']);
        foreach (['verifics','lugar'] as $arrayField) {
            if (array_key_exists($arrayField, $_POST)) {
                $decoded = json_decode((string)$_POST[$arrayField], true);
                $rows[$rowKey][$arrayField] = is_array($decoded) ? array_values(array_unique(array_map('strval', $decoded))) : [];
            }
        }
        if (array_key_exists('deleted', $_POST)) $rows[$rowKey]['deleted'] = (string)$_POST['deleted'] === '1';
        $rows[$rowKey]['a_lograr'] = max(0, (float)($_POST['a_lograr'] ?? 0));
        $rows[$rowKey]['cumplido'] = max(0, (float)($_POST['cumplido'] ?? 0));
        $rows[$rowKey]['a_tiempo'] = max(0, min(100, (float)($_POST['a_tiempo'] ?? 100)));
        $rows[$rowKey]['en_forma'] = max(0, min(100, (float)($_POST['en_forma'] ?? 100)));
        $rows[$rowKey]['quality_initialized'] = true;
        $rows[$rowKey]['quality_version'] = 2;
        $stmt = $db->prepare('UPDATE ah_poa_etapas SET involucrados_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND id_poa=?');
        $stmt->execute([json_encode($rows, JSON_UNESCAPED_UNICODE), $stageId, $taskId]);
        $db->commit();
        $saveSnapshot($db,$taskId,'agenda_v2');echo json_encode(['status'=>'ok','row'=>$rows[$rowKey]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'save_center_row' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$taskId=(int)($_POST['id_poa']??0);$stageId=(int)($_POST['stage_id']??0);$rowKey=(string)($_POST['row_key']??'');$centerId=(string)($_POST['center_id']??'');
        if($rowKey===''||$centerId==='')throw new RuntimeException('Centro no válido.');$db->beginTransaction();$stmt=$db->prepare('SELECT involucrados_json FROM ah_poa_etapas WHERE id=? AND id_poa=? LIMIT 1 FOR UPDATE');$stmt->execute([$stageId,$taskId]);$rows=json_decode((string)$stmt->fetchColumn(),true);
        if(!is_array($rows)||!isset($rows[$rowKey])||!is_array($rows[$rowKey]))throw new RuntimeException('Línea de agenda no encontrada.');if(!isset($rows[$rowKey]['centros'])||!is_array($rows[$rowKey]['centros']))$rows[$rowKey]['centros']=[];$center=$rows[$rowKey]['centros'][$centerId]??['id'=>$centerId];
        foreach(['a_lograr','cumplido','pob_0_5','pob_6_17','pob_18_24'] as $field)$center[$field]=max(0,(float)($_POST[$field]??0));foreach(['a_tiempo','en_forma'] as $field)$center[$field]=max(0,min(100,(float)($_POST[$field]??100)));$center['quality_initialized']=true;$center['quality_version']=2;$rows[$rowKey]['centros'][$centerId]=$center;$active=array_values(array_filter($rows[$rowKey]['centros'],'is_array'));$rows[$rowKey]['a_lograr']=array_sum(array_map(static fn($item)=>(float)($item['a_lograr']??0),$active));$rows[$rowKey]['cumplido']=array_sum(array_map(static fn($item)=>(float)($item['cumplido']??0),$active));if($active){$rows[$rowKey]['a_tiempo']=array_sum(array_map(static fn($item)=>(float)($item['a_tiempo']??100),$active))/count($active);$rows[$rowKey]['en_forma']=array_sum(array_map(static fn($item)=>(float)($item['en_forma']??100),$active))/count($active);}
        $stmt=$db->prepare('UPDATE ah_poa_etapas SET involucrados_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND id_poa=?');$stmt->execute([json_encode($rows,JSON_UNESCAPED_UNICODE),$stageId,$taskId]);$db->commit();$saveSnapshot($db,$taskId,'centros_v2');echo json_encode(['status'=>'ok','center'=>$center,'row'=>$rows[$rowKey]],JSON_UNESCAPED_UNICODE);exit;
    }
    if ($action === 'sync_row_centers' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        $requireV2Csrf();$taskId=(int)($_POST['id_poa']??0);$stageId=(int)($_POST['stage_id']??0);$rowKey=(string)($_POST['row_key']??'');if($rowKey==='')throw new RuntimeException('Línea no válida.');$db->beginTransaction();$stmt=$db->prepare('SELECT involucrados_json FROM ah_poa_etapas WHERE id=? AND id_poa=? LIMIT 1 FOR UPDATE');$stmt->execute([$stageId,$taskId]);$rows=json_decode((string)$stmt->fetchColumn(),true);if(!is_array($rows)||!isset($rows[$rowKey]))throw new RuntimeException('Línea no encontrada.');$row=$rows[$rowKey];$bases=array_values(array_filter(array_map('trim',preg_split('/[|,]/',(string)($row['base']??''))?:[])));if(!$bases&&trim((string)($row['persona']??''))!==''){$baseStmt=$db->prepare("SELECT DISTINCT base_asignada FROM ah_poa_asignaciones WHERE id_poa=? AND tecnico=? AND COALESCE(base_asignada,'')<>''");$baseStmt->execute([$taskId,(string)$row['persona']]);$bases=$baseStmt->fetchAll(PDO::FETCH_COLUMN);}$places=is_array($row['lugar']??null)?$row['lugar']:[];$selected=$centersFor($db,$bases,$places);$existing=is_array($row['centros']??null)?$row['centros']:[];$centers=[];$programmed=0.0;foreach($selected as $id=>$source){$previous=is_array($existing[$id]??null)?$existing[$id]:[];$target=$targetPopulation($source,(string)($row['unidad']??''));$centers[$id]=array_merge($previous,['id'=>(string)$id,'nombre'=>(string)($source['nombre']??''),'tipo'=>(string)($source['tipo']??''),'comunidad_base'=>(string)($source['comunidad_base']??''),'caserio'=>(string)($source['caserio']??''),'pob_0_5'=>(float)($source['pob_0_5']??0),'pob_6_17'=>(float)($source['pob_6_17']??0),'pob_18_24'=>(float)($source['pob_18_24']??0),'a_lograr'=>$target,'cumplido'=>(float)($previous['cumplido']??0),'a_tiempo'=>(float)($previous['a_tiempo']??100),'en_forma'=>(float)($previous['en_forma']??100),'quality_initialized'=>true,'quality_version'=>2]);$programmed+=$target;}$row['base']=implode('|',$bases);$row['centros']=$centers;$row['a_lograr']=$programmed;$row['cumplido']=array_sum(array_map(static fn($center)=>(float)($center['cumplido']??0),$centers));$rows[$rowKey]=$row;$update=$db->prepare('UPDATE ah_poa_etapas SET involucrados_json=?,updated_at=CURRENT_TIMESTAMP WHERE id=? AND id_poa=?');$update->execute([json_encode($rows,JSON_UNESCAPED_UNICODE),$stageId,$taskId]);$db->commit();$saveSnapshot($db,$taskId,'centros_v2');echo json_encode(['status'=>'ok','row'=>$row,'count'=>count($centers)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if ($action === 'task_list') {
        $page = max(1, (int)($_GET['page'] ?? 1));
        $perPage = min(50, max(10, (int)($_GET['per_page'] ?? 20)));
        $offset = ($page - 1) * $perPage;
        $search = trim((string)($_GET['q'] ?? ''));
        $program = trim((string)($_GET['programa'] ?? ''));
        $sector=trim((string)($_GET['sector']??''));$technician=trim((string)($_GET['tecnico']??''));$activityStatus=trim((string)($_GET['estado']??''));$month=trim((string)($_GET['mes']??''));$execution=trim((string)($_GET['ejecucion']??''));$hidden=(string)($_GET['ocultas']??'0')==='1';
        $where = ['is_active=1', 'operativo_oculto='.($hidden?'1':'0')];
        $params = [];
        if ($search !== '') {
            $where[] = '(descripcion_actividad LIKE ? OR marco_logico LIKE ? OR codigo_maestro LIKE ?)';
            $needle = '%' . $search . '%';
            array_push($params, $needle, $needle, $needle);
        }
        if ($program !== '') {
            $where[] = 'programa=?';
            $params[] = $program;
        }
        if($sector!==''){$where[]='sector=?';$params[]=$sector;}if($technician!==''){$where[]='(operativo_tecnico=? OR EXISTS(SELECT 1 FROM ah_poa_asignaciones a WHERE a.id_poa=ah_poa.id AND a.tecnico=?))';array_push($params,$technician,$technician);}if($activityStatus!==''){$where[]='operativo_estado=?';$params[]=$activityStatus;}
        $validMonths=['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];if(in_array($month,$validMonths,true))$where[]="(op_act_{$month}>0 OR op_part_{$month}>0)";
        if($execution==='under')$where[]='operativo_meta_alc < operativo_meta_obj';elseif($execution==='over')$where[]='operativo_meta_alc > operativo_meta_obj';elseif($execution==='none')$where[]='COALESCE(operativo_meta_alc,0)=0';
        $whereSql = implode(' AND ', $where);
        $count = $db->prepare("SELECT COUNT(*) FROM ah_poa WHERE {$whereSql}");
        $count->execute($params);
        $total = (int)$count->fetchColumn();
        $sql = "SELECT id,codigo_maestro,descripcion_actividad,marco_logico,programa,sector,operativo_tecnico,operativo_comunidad,operativo_estado,meta_actividades,meta_actividades_alc,operativo_meta_obj,operativo_meta_alc FROM ah_poa WHERE {$whereSql} ORDER BY id ASC LIMIT {$perPage} OFFSET {$offset}";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status'=>'ok','rows'=>$rows,'total'=>$total,'page'=>$page,'per_page'=>$perPage], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action === 'catalogs') {
        $catalogs = [];
        foreach (['responsables'=>'ah_cat_responsables','unidades'=>'ah_cat_unidades','lugares'=>'ah_cat_lugares'] as $key=>$table) {
            try { $catalogs[$key] = $db->query("SELECT nombre FROM `{$table}` ORDER BY nombre ASC")->fetchAll(PDO::FETCH_COLUMN); }
            catch (Throwable $ignored) { $catalogs[$key] = []; }
        }
        try { $catalogs['verificaciones'] = $db->query('SELECT nombre FROM ah_cat_verificaciones WHERE activo=1 ORDER BY nombre ASC')->fetchAll(PDO::FETCH_COLUMN); }
        catch (Throwable $ignored) { try { $catalogs['verificaciones'] = $db->query('SELECT nombre FROM ah_cat_verificaciones ORDER BY nombre ASC')->fetchAll(PDO::FETCH_COLUMN); } catch (Throwable $ignoredAgain) { $catalogs['verificaciones'] = []; } }
        try { $catalogs['tecnicos'] = $db->query('SELECT nombre FROM ah_tecnicos WHERE activo=1 ORDER BY nombre ASC')->fetchAll(PDO::FETCH_COLUMN); }
        catch (Throwable $ignored) { $catalogs['tecnicos'] = []; }
        try { $catalogs['sectores']=$db->query("SELECT DISTINCT sector FROM ah_poa WHERE is_active=1 AND sector IS NOT NULL AND sector<>'' ORDER BY sector")->fetchAll(PDO::FETCH_COLUMN); } catch(Throwable $ignored){$catalogs['sectores']=[];}
        $catalogs['responsables'] = array_values(array_unique(array_merge($catalogs['responsables'], $catalogs['tecnicos'])));
        try { $catalogs['tecnicos_bases']=$db->query("SELECT DISTINCT t.nombre,COALESCE(b.nombre_base,'') AS nombre_base FROM ah_tecnicos t LEFT JOIN ah_bases_geograficas b ON t.identidad=b.identidad_tecnico WHERE t.activo=1 ORDER BY t.nombre,b.nombre_base")->fetchAll(PDO::FETCH_ASSOC); }
        catch(Throwable $ignored){$catalogs['tecnicos_bases']=array_map(static fn($name)=>['nombre'=>$name,'nombre_base'=>''],$catalogs['tecnicos']);}
        echo json_encode(['status'=>'ok','catalogs'=>$catalogs], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
    if ($action !== 'task_detail') {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'msg' => 'Acción no válida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $taskId = (int)($_GET['id'] ?? 0);
    if ($taskId < 1) {
        http_response_code(422);
        echo json_encode(['status' => 'error', 'msg' => 'Actividad no válida.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM ah_poa WHERE id=? AND is_active=1 LIMIT 1');
    $stmt->execute([$taskId]);
    $task = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$task) {
        http_response_code(404);
        echo json_encode(['status' => 'error', 'msg' => 'Actividad no encontrada.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $stmt = $db->prepare('SELECT * FROM ah_poa_asignaciones WHERE id_poa=? ORDER BY id ASC');
    $stmt->execute([$taskId]);
    $assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $db->prepare('SELECT * FROM ah_poa_etapas WHERE id_poa=? ORDER BY orden ASC, id ASC');
    $stmt->execute([$taskId]);
    $stages = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $months = ['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'];
    $opAct = [];
    $opPart = [];
    foreach ($months as $month) {
        $opAct[$month] = (float)($task['op_act_' . $month] ?? 0);
        $opPart[$month] = (float)($task['op_part_' . $month] ?? 0);
    }

    $description = trim((string)($task['descripcion_actividad'] ?? ''));
    if ($description === '') $description = trim((string)($task['marco_logico'] ?? 'Actividad'));

    echo json_encode(['status' => 'ok', 'task' => [
        'id' => (int)$task['id'],
        'actividad' => $description,
        'codigo' => trim((string)($task['codigo_maestro'] ?? '')),
        'extension' => trim((string)($task['ext'] ?? '')),
        'marco_logico' => (string)($task['marco_logico'] ?? ''),
        'programa' => (string)($task['programa'] ?? ''),
        'sector' => (string)($task['sector'] ?? ''),
        'tecnico' => (string)($task['operativo_tecnico'] ?? 'Trabajo en Equipo'),
        'comunidad' => (string)($task['operativo_comunidad'] ?? ''),
        'periodo' => (string)($task['operativo_periodo'] ?? ''),
        'estado' => (string)($task['operativo_estado'] ?? 'Pendiente'),
        't_part' => (string)($task['tipo_participante'] ?? ''),
        'm_act_obj' => (float)($task['meta_actividades'] ?? 0),
        'm_act_alc' => (float)($task['meta_actividades_alc'] ?? 0),
        'm_part_obj' => (float)($task['operativo_meta_obj'] ?? 0),
        'm_part_alc' => (float)($task['operativo_meta_alc'] ?? 0),
        'info_adicional' => (string)($task['operativo_info_adicional'] ?? ''),
        'team_lugares' => (string)($task['equipo_lugares_json'] ?? '[]'),
        'etapas' => $stages,
        'op_act' => $opAct,
        'op_part' => $opPart,
        'asignaciones' => $assignments,
    ]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    http_response_code($e instanceof RuntimeException ? 422 : 500);
    echo json_encode(['status' => 'error', 'msg' => $e instanceof RuntimeException ? $e->getMessage() : 'No se pudo completar la operación.'], JSON_UNESCAPED_UNICODE);
}
