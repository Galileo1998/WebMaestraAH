<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FormService.php';
form_assert_installed($db);
$service = new FormService($db);
$slug = trim((string)($_GET['f'] ?? $_POST['form_slug'] ?? ''));
$form = $service->getFormBySlug($slug);
if (!$form) { http_response_code(404); exit('Formulario no encontrado.'); }
$schema = $service->getSchema((int)$form['id']);
$config = $schema['form']['configuracion'];

function form_public_asset_url(string $path): string {
    $path = trim($path);
    if ($path === '') return '';
    if (preg_match('#^(https?://|data:image/|/)#i', $path)) return $path;
    return '../' . ltrim(preg_replace('#^\.\./#', '', $path), '/');
}
$now = time();
$closedReason = null;
if ($form['estado'] !== 'publicado') $closedReason = $form['estado']==='cerrado'?'Este formulario está cerrado.':'Este formulario aún no ha sido publicado.';
if ($form['fecha_apertura'] && strtotime($form['fecha_apertura']) > $now) $closedReason='El formulario estará disponible a partir del '.date('d/m/Y H:i',strtotime($form['fecha_apertura'])).'.';
if ($form['fecha_cierre'] && strtotime($form['fecha_cierre']) < $now) $closedReason='El período para responder este formulario finalizó.';
if ($form['limite_respuestas'] && $service->countResponses((int)$form['id']) >= (int)$form['limite_respuestas']) $closedReason='Este formulario alcanzó el límite de respuestas.';
$userId = form_current_user_id();
if ((int)$form['requiere_login']===1 && !$userId) $closedReason='Debes iniciar sesión para responder este formulario.';
if ((int)$form['una_respuesta']===1 && $userId) {
    $st=$db->prepare("SELECT COUNT(*) FROM ah_form_responses WHERE form_id=? AND usuario_id=? AND estado='enviada'");$st->execute([$form['id'],$userId]);
    if((int)$st->fetchColumn()>0)$closedReason='Ya registraste una respuesta en este formulario.';
}

function geo_options_public(PDO $db,string $level,string $municipio,string $base,string $caserio,array $types): array {
    if($level==='municipio') return $db->query("SELECT DISTINCT municipio AS value, municipio AS label FROM ah_bases_geograficas WHERE municipio IS NOT NULL AND TRIM(municipio)<>'' ORDER BY municipio")->fetchAll();
    if($level==='base'){$st=$db->prepare("SELECT DISTINCT nombre_base AS value,nombre_base AS label FROM ah_bases_geograficas WHERE municipio=? AND TRIM(nombre_base)<>'' ORDER BY nombre_base");$st->execute([$municipio]);return $st->fetchAll();}
    if($level==='caserio'){
        $sql="SELECT DISTINCT c.caserio AS value,c.caserio AS label FROM ah_centros c INNER JOIN ah_bases_geograficas b ON LOWER(TRIM(b.nombre_base))=LOWER(TRIM(c.comunidad_base)) WHERE b.municipio=? AND c.comunidad_base=? AND TRIM(c.caserio)<>''";$params=[$municipio,$base];
        if($types){$sql.=' AND c.tipo IN('.implode(',',array_fill(0,count($types),'?')).')';$params=array_merge($params,$types);} $sql.=' ORDER BY c.caserio';$st=$db->prepare($sql);$st->execute($params);return $st->fetchAll();
    }
    if($level==='centro'){
        $sql="SELECT c.id AS value,CONCAT(c.nombre,' · ',c.tipo) AS label,c.tipo,c.nombre,c.comunidad_base,c.caserio,c.pob_total,c.pob_fem,c.pob_masc,c.pob_0_5,c.pob_6_17,c.pob_18_24 FROM ah_centros c INNER JOIN ah_bases_geograficas b ON LOWER(TRIM(b.nombre_base))=LOWER(TRIM(c.comunidad_base)) WHERE b.municipio=? AND c.comunidad_base=?";$params=[$municipio,$base];
        if($caserio!==''){$sql.=' AND c.caserio=?';$params[]=$caserio;} if($types){$sql.=' AND c.tipo IN('.implode(',',array_fill(0,count($types),'?')).')';$params=array_merge($params,$types);} $sql.=' ORDER BY c.tipo,c.nombre';$st=$db->prepare($sql);$st->execute($params);return $st->fetchAll();
    }
    return [];
}

function form_upload_error_text(int $code): string {
    return match ($code) {
        UPLOAD_ERR_OK => '',
        UPLOAD_ERR_INI_SIZE => 'La imagen supera el límite upload_max_filesize configurado en PHP.',
        UPLOAD_ERR_FORM_SIZE => 'La imagen supera el tamaño máximo permitido por el formulario.',
        UPLOAD_ERR_PARTIAL => 'La imagen se cargó parcialmente. Inténtelo nuevamente.',
        UPLOAD_ERR_NO_FILE => 'No se recibió ningún archivo.',
        UPLOAD_ERR_NO_TMP_DIR => 'El servidor no tiene configurada una carpeta temporal para cargas.',
        UPLOAD_ERR_CANT_WRITE => 'El servidor no pudo escribir el archivo temporal en el disco.',
        UPLOAD_ERR_EXTENSION => 'Una extensión de PHP detuvo la carga del archivo.',
        default => 'Error desconocido durante la carga del archivo.'
    };
}

function form_prepare_upload_storage(int $formId): array {
    $relative = 'uploads/formularios/' . $formId . '/' . date('Y/m');
    $packageRoot = dirname(__DIR__);
    $candidates = [
        $packageRoot . '/' . $relative,
    ];
    $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($docRoot !== '') $candidates[] = $docRoot . '/' . $relative;
    $candidates[] = dirname($packageRoot) . '/' . $relative;
    $seen = [];
    $errors = [];
    foreach ($candidates as $dir) {
        $dir = str_replace('\\', '/', $dir);
        if (isset($seen[$dir])) continue;
        $seen[$dir] = true;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (is_dir($dir)) {
            @chmod($dir, 0775);
            if (is_writable($dir)) return ['dir'=>$dir, 'relative'=>$relative];
            $errors[] = $dir . ' (sin permiso de escritura)';
        } else {
            $errors[] = $dir . ' (no se pudo crear)';
        }
    }
    throw new RuntimeException('No hay una carpeta disponible para guardar adjuntos. Revise permisos 775 en uploads/formularios. Rutas verificadas: ' . implode(' | ', $errors));
}

function form_image_extensions(): array {
    return ['jpg','jpeg','jfif','jpe','png','gif','webp','avif','heic','heif','bmp','dib','tif','tiff','ico'];
}

function form_save_inline_upload(int $formId, int $qid, array $question, string $dataUrl, string $originalName): string {
    if (!preg_match('#^data:([a-zA-Z0-9.+-]+/[a-zA-Z0-9.+-]+);base64,(.*)$#s', $dataUrl, $match)) {
        throw new RuntimeException('El archivo de “'.$question['titulo'].'” no tiene un formato válido.');
    }

    $mime = strtolower(trim($match[1]));
    $binary = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
    if ($binary === false) {
        throw new RuntimeException('No fue posible leer el archivo de “'.$question['titulo'].'”.');
    }

    $maxMb = max(1, (int)($question['config']['max_mb'] ?? 20));
    if (strlen($binary) > $maxMb * 1024 * 1024) {
        throw new RuntimeException('El archivo de “'.$question['titulo'].'” supera '.$maxMb.' MB.');
    }

    $mimeToExt = [
        'image/jpeg'=>'jpg','image/jpg'=>'jpg','image/pjpeg'=>'jpg','image/png'=>'png',
        'image/gif'=>'gif','image/webp'=>'webp','image/avif'=>'avif',
        'image/heic'=>'heic','image/heif'=>'heif','image/bmp'=>'bmp',
        'image/x-ms-bmp'=>'bmp','image/tiff'=>'tiff','image/x-icon'=>'ico',
        'application/pdf'=>'pdf','application/msword'=>'doc',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
        'application/vnd.ms-excel'=>'xls',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>'xlsx'
    ];

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';
    if ($ext === '') {
        $ext = $mimeToExt[$mime] ?? '';
        if ($ext === '' && str_starts_with($mime, 'image/')) {
            $subtype = explode('/', $mime, 2)[1] ?? '';
            $subtype = explode('+', $subtype, 2)[0];
            $ext = preg_replace('/[^a-z0-9]+/', '', strtolower($subtype)) ?: 'img';
        }
    }

    $allowed = array_values(array_unique(array_map(
        static fn($v) => strtolower(ltrim(trim((string)$v), '.')),
        (array)($question['config']['allowed_types'] ?? ['image/*'])
    )));
    $imageExts = form_image_extensions();
    $allowsAnyImage = in_array('image/*', $allowed, true)
        || in_array('image', $allowed, true)
        || count(array_intersect($allowed, $imageExts)) > 0;
    $isImage = str_starts_with($mime, 'image/') || in_array($ext, $imageExts, true);
    if ($mime === 'image/svg+xml' || $ext === 'svg') {
        throw new RuntimeException('El formato SVG no se admite por seguridad. Utilice una fotografía o imagen rasterizada.');
    }

    $normalizedExt = $ext === 'jpeg' ? 'jpg' : $ext;
    $allowedNormalized = array_map(static fn($v) => $v === 'jpeg' ? 'jpg' : $v, $allowed);

    if ($isImage && $allowsAnyImage) {
        // Se conserva el archivo original. No se usa getimagesize(), porque PHP no
        // reconoce todos los formatos válidos como HEIC, HEIF, AVIF o TIFF.
        if ($normalizedExt === '' || $normalizedExt === 'img') {
            $normalizedExt = $mimeToExt[$mime] ?? 'img';
        }
    } elseif ($normalizedExt === '' || !in_array($normalizedExt, $allowedNormalized, true)) {
        throw new RuntimeException(
            'Tipo de archivo no permitido en “'.$question['titulo'].'”. Extensiones admitidas: '.implode(', ', $allowed).'.'
        );
    }

    $storage = form_prepare_upload_storage($formId);
    $name = bin2hex(random_bytes(16)).'.'.$normalizedExt;
    $dest = $storage['dir'].'/'.$name;
    $written = @file_put_contents($dest, $binary, LOCK_EX);
    if ($written === false || $written !== strlen($binary)) {
        @unlink($dest);
        throw new RuntimeException('No fue posible guardar el archivo adjunto. Verifique permisos de escritura en uploads/formularios.');
    }
    @chmod($dest, 0644);
    return $storage['relative'].'/'.$name;
}

function form_upload_accept(array $allowed): string {
    $map = [
        'jpg'=>'image/jpeg','jpeg'=>'image/jpeg','jfif'=>'image/jpeg','jpe'=>'image/jpeg',
        'png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp','avif'=>'image/avif',
        'heic'=>'image/heic','heif'=>'image/heif','bmp'=>'image/bmp','dib'=>'image/bmp',
        'tif'=>'image/tiff','tiff'=>'image/tiff','ico'=>'image/x-icon',
        'pdf'=>'application/pdf','doc'=>'application/msword',
        'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls'=>'application/vnd.ms-excel',
        'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
    ];

    $normalized = array_values(array_filter(array_unique(array_map(
        static fn($v) => strtolower(ltrim(trim((string)$v), '.')),
        $allowed
    ))));
    $out = [];
    $imageExts = form_image_extensions();
    if (in_array('image/*', $normalized, true)
        || in_array('image', $normalized, true)
        || count(array_intersect($normalized, $imageExts)) > 0) {
        $out[] = 'image/*';
    }
    foreach ($normalized as $ext) {
        if ($ext === 'image/*' || $ext === 'image' || in_array($ext, $imageExts, true)) continue;
        $out[] = $map[$ext] ?? ('.'.$ext);
    }
    return implode(',', array_values(array_unique($out)));
}

function form_logic_scalar($value): string {
    if (is_array($value)) return implode('|', array_map(static fn($v)=>is_scalar($v)?trim((string)$v):form_json_encode($v), $value));
    return trim((string)($value ?? ''));
}

function form_logic_matches(array $logic, $sourceValue): bool {
    if (empty($logic['enabled'])) return true;
    $operator = (string)($logic['operator'] ?? 'equals');
    $expected = trim((string)($logic['value'] ?? ''));
    $values = is_array($sourceValue) ? array_map(static fn($v)=>mb_strtolower(trim((string)$v),'UTF-8'), $sourceValue) : [mb_strtolower(trim((string)($sourceValue ?? '')),'UTF-8')];
    $actual = implode('|',$values);
    $expectedLower = mb_strtolower($expected,'UTF-8');
    return match($operator){
        'not_equals' => $actual !== $expectedLower,
        'contains' => in_array($expectedLower,$values,true) || str_contains($actual,$expectedLower),
        'not_contains' => !(in_array($expectedLower,$values,true) || str_contains($actual,$expectedLower)),
        'is_empty' => $actual === '',
        'not_empty' => $actual !== '',
        'greater_than' => is_numeric($actual) && is_numeric($expected) && (float)$actual > (float)$expected,
        'less_than' => is_numeric($actual) && is_numeric($expected) && (float)$actual < (float)$expected,
        default => $actual === $expectedLower,
    };
}

$logicQuestionMap = [];
foreach ($schema['sections'] as $logicSection) {
    foreach ($logicSection['questions'] as $logicQuestion) {
        $logicKey = trim((string)($logicQuestion['config']['logic_key'] ?? ''));
        if ($logicKey !== '') $logicQuestionMap[$logicKey] = $logicQuestion;
    }
}

function form_question_visible(array $question, array $answersInput, array $logicQuestionMap): bool {
    $logic = is_array($question['logica'] ?? null) ? $question['logica'] : [];
    if (empty($logic['enabled'])) return true;
    $sourceKey = trim((string)($logic['source_key'] ?? ''));
    if ($sourceKey === '' || !isset($logicQuestionMap[$sourceKey])) return false;
    $source = $logicQuestionMap[$sourceKey];
    $sourceId = (int)($source['id'] ?? 0);
    $sourceValue = $sourceId > 0 ? ($answersInput[$sourceId] ?? null) : null;
    return form_logic_matches($logic,$sourceValue);
}

if(isset($_GET['ajax']) && $_GET['ajax']==='geo'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        $types=array_values(array_filter(array_map('trim',explode(',',(string)($_GET['types']??'')))));
        echo form_json_encode(['status'=>'ok','data'=>geo_options_public($db,(string)($_GET['level']??'municipio'),trim((string)($_GET['municipio']??'')),trim((string)($_GET['base']??'')),trim((string)($_GET['caserio']??'')),$types)]);
    }catch(Throwable $e){http_response_code(400);echo form_json_encode(['status'=>'error','msg'=>$e->getMessage()]);}
    exit;
}

$success=false;$error='';$editToken=trim((string)($_GET['edit']??$_POST['edit_token']??''));$existingResponse=null;$existingAnswers=[];
if($editToken!=='' && (int)$form['permitir_edicion']===1){
    $st=$db->prepare("SELECT * FROM ah_form_responses WHERE token=? AND form_id=? AND estado='enviada' LIMIT 1");$st->execute([$editToken,$form['id']]);$existingResponse=$st->fetch()?:null;
    if($existingResponse){$st=$db->prepare('SELECT * FROM ah_form_answers WHERE response_id=?');$st->execute([$existingResponse['id']]);foreach($st->fetchAll() as $a)$existingAnswers[(int)$a['question_id']]=$a;}
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='submit_form'){
    try{
        if($closedReason && !$existingResponse) throw new RuntimeException($closedReason);
        form_validate_csrf($_POST['csrf']??null);
        $answersInput=$_POST['q']??[];
        $email=trim((string)($_POST['respondent_email']??''));
        if((int)$form['recopilar_correo']===1 && !filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Ingresa un correo electrónico válido.');
        $prepared=[];
        foreach($schema['sections'] as $section){foreach($section['questions'] as $q){
            if(in_array($q['tipo'],['title_description','image','video'],true))continue;
            $qid=(int)$q['id'];$value=$answersInput[$qid]??null;
            $isVisible=form_question_visible($q,$answersInput,$logicQuestionMap);
            if(!$isVisible){$prepared[$qid]=['question'=>$q,'value'=>null,'file'=>null];continue;}
            $inlineData = trim((string)($_POST['file_b64'][$qid] ?? ''));
            $inlineName = trim((string)($_POST['file_name'][$qid] ?? ''));
            $hasInlineFile = $inlineData !== '';
            $hasMultipartFile = isset($_FILES['file_'.$qid]) && ($_FILES['file_'.$qid]['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE;
            $hasFile = $hasInlineFile || $hasMultipartFile;
            $hasExistingFile = !empty($existingAnswers[$qid]['archivo_path']);
            if((int)$q['requerido']===1 && !$hasFile && !$hasExistingFile){$empty=$value===null||$value===''||(is_array($value)&&count(array_filter($value,fn($v)=>$v!==''&&$v!==null))===0);if($empty)throw new RuntimeException('Completa la pregunta: '.$q['titulo']);}
            $validation=$q['validacion'];
            if(!is_array($value) && $value!==null && $value!==''){
                $str=trim((string)$value);
                if(!empty($validation['regex']) && @preg_match('/'.$validation['regex'].'/u',$str)!==1) throw new RuntimeException($validation['message']??('Respuesta no válida en: '.$q['titulo']));
                if($q['tipo']==='email' && !filter_var($str,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Correo no válido en: '.$q['titulo']);
                if(isset($validation['min']) && $validation['min']!=='' && is_numeric($str) && (float)$str<(float)$validation['min']) throw new RuntimeException($validation['message']??('Valor inferior al mínimo en: '.$q['titulo']));
                if(isset($validation['max']) && $validation['max']!=='' && is_numeric($str) && (float)$str>(float)$validation['max']) throw new RuntimeException($validation['message']??('Valor superior al máximo en: '.$q['titulo']));
            }
            $prepared[$qid]=['question'=>$q,'value'=>$value,'file'=>null];
            if($hasInlineFile){
                $prepared[$qid]['file']=form_save_inline_upload((int)$form['id'],$qid,$q,$inlineData,$inlineName ?: ('archivo_'.$qid));
            } elseif($hasMultipartFile){
                $file=$_FILES['file_'.$qid];
                $uploadError=(int)($file['error']??UPLOAD_ERR_NO_FILE);
                if($uploadError!==UPLOAD_ERR_OK)throw new RuntimeException(form_upload_error_text($uploadError).' Pregunta: “'.$q['titulo'].'”.');
                $maxMb=max(1,(int)($q['config']['max_mb']??20));
                if((int)($file['size']??0)>$maxMb*1024*1024)throw new RuntimeException('El archivo de “'.$q['titulo'].'” supera '.$maxMb.' MB.');
                $ext=strtolower(pathinfo((string)$file['name'],PATHINFO_EXTENSION));
                $allowed=array_values(array_unique(array_map(static fn($v)=>strtolower(ltrim(trim((string)$v),'.')),(array)($q['config']['allowed_types']??['pdf','jpg','jpeg','png','doc','docx','xlsx']))));
                if(!in_array($ext,$allowed,true))throw new RuntimeException('Tipo de archivo no permitido en “'.$q['titulo'].'”. Extensiones admitidas: '.implode(', ',$allowed).'.');
                if(!is_uploaded_file((string)$file['tmp_name']) && !is_readable((string)$file['tmp_name']))throw new RuntimeException('El archivo temporal no está disponible. Intente seleccionar la imagen nuevamente.');
                $storage=form_prepare_upload_storage((int)$form['id']);
                $name=bin2hex(random_bytes(16)).'.'.$ext;
                $dest=$storage['dir'].'/'.$name;
                $moved=move_uploaded_file((string)$file['tmp_name'],$dest);
                if(!$moved && is_readable((string)$file['tmp_name'])){$moved=@copy((string)$file['tmp_name'],$dest);if($moved)@unlink((string)$file['tmp_name']);}
                if(!$moved)throw new RuntimeException('No fue posible guardar el archivo adjunto en '.$storage['dir'].'. Verifique permisos 775 y espacio disponible.');
                @chmod($dest,0644);
                $prepared[$qid]['file']=$storage['relative'].'/'.$name;
            }
        }}
        $db->beginTransaction();
        if($existingResponse){$responseId=(int)$existingResponse['id'];$db->prepare('UPDATE ah_form_responses SET correo=?,nombre_respondiente=?,metadata_json=?,submitted_at=NOW() WHERE id=?')->execute([$email?:null,trim((string)($_POST['respondent_name']??''))?:null,form_json_encode(['user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),'edited'=>true]),$responseId]);}
        else{$token=hash('sha256',random_bytes(32).microtime(true));$ipHash=hash('sha256',(string)($_SERVER['REMOTE_ADDR']??'').'|'.$form['id']);$st=$db->prepare("INSERT INTO ah_form_responses(form_id,token,usuario_id,correo,nombre_respondiente,ip_hash,estado,metadata_json,started_at,submitted_at) VALUES(?,?,?,?,?,?,'enviada',?,NOW(),NOW())");$st->execute([$form['id'],$token,$userId,$email?:null,trim((string)($_POST['respondent_name']??''))?:null,$ipHash,form_json_encode(['user_agent'=>substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)])]);$responseId=(int)$db->lastInsertId();$editToken=$token;}
        $upsert=$db->prepare("INSERT INTO ah_form_answers(response_id,question_id,valor_texto,valor_json,archivo_path) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE valor_texto=VALUES(valor_texto),valor_json=VALUES(valor_json),archivo_path=COALESCE(VALUES(archivo_path),archivo_path),updated_at=CURRENT_TIMESTAMP");
        foreach($prepared as $qid=>$item){$value=$item['value'];$text=is_array($value)?null:trim((string)$value);$json=is_array($value)?form_json_encode($value):null;$upsert->execute([$responseId,$qid,$text,$json,$item['file']]);}
        $db->commit();$success=true;
        if($form['notificar_correo'] && filter_var($form['notificar_correo'],FILTER_VALIDATE_EMAIL)){@mail($form['notificar_correo'],'Nueva respuesta: '.$form['titulo'],'Se recibió una nueva respuesta el '.date('d/m/Y H:i').'.');}
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=$e->getMessage();}
}

function existing_value(array $existingAnswers,int $qid){$a=$existingAnswers[$qid]??null;if(!$a)return null;return $a['valor_json']?form_json_decode($a['valor_json']):$a['valor_texto'];}
function input_name(int $qid,string $suffix=''): string{return 'q['.$qid.']'.$suffix;}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=form_h($form['titulo'])?></title><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet"><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"><link rel="stylesheet" href="../assets/formularios.css?v=20260714-4"><style>:root{--form-color:<?=form_h($form['tema_color'])?>}</style></head><body class="public-body"><main class="public-wrap">
<?php if($success):
$confirmationImage = trim((string)($config['confirmation_image'] ?? ''));
$confirmationTitle = trim((string)($config['confirmation_title'] ?? 'Respuesta registrada')) ?: 'Respuesta registrada';
$confirmationAlt = trim((string)($config['confirmation_image_alt'] ?? 'Gracias por completar el formulario')) ?: 'Gracias por completar el formulario';
$confirmationWidth = max(160, min(1000, (int)($config['confirmation_image_max_width'] ?? 520)));
?><section class="confirmation">
<?php if($confirmationImage !== ''): ?><div class="confirmation-image-wrap"><img class="confirmation-image" src="<?=form_h(form_public_asset_url($confirmationImage))?>" alt="<?=form_h($confirmationAlt)?>" style="max-width:<?=$confirmationWidth?>px"></div><?php endif; ?>
<div class="confirmation-check"><i class="fa-solid fa-circle-check"></i></div><h1><?=form_h($confirmationTitle)?></h1><p><?=nl2br(form_h($form['mensaje_confirmacion']?:'¡Gracias! Tu respuesta fue registrada correctamente.'))?></p><div class="confirmation-actions"><?php if((int)$form['permitir_edicion']===1): ?><a class="btn" href="?f=<?=urlencode($slug)?>&edit=<?=urlencode($editToken)?>"><i class="fa-solid fa-pen"></i> Editar mi respuesta</a><?php endif; ?><?php if(!empty($config['mostrar_enlace_otro_envio'])): ?><a class="btn btn-primary" href="?f=<?=urlencode($slug)?>"><i class="fa-solid fa-rotate-right"></i> Enviar otra respuesta</a><?php endif; ?></div></section>
<?php elseif($closedReason): ?><section class="confirmation"><i class="fa-solid fa-lock" style="font-size:3rem;color:#64748b"></i><h1><?=form_h($form['titulo'])?></h1><p><?=form_h($closedReason)?></p></section>
<?php else: ?><section class="public-cover"><h1><?=form_h($form['titulo'])?></h1><?php if($form['descripcion']): ?><p><?=nl2br(form_h($form['descripcion']))?></p><?php endif; ?><?php if((int)$form['mostrar_progreso']===1): ?><div class="public-progress"><span id="progressBar"></span></div><?php endif; ?><p style="margin-top:10px;font-size:.8rem"><span class="required-star">*</span> Indica una pregunta obligatoria.</p></section>
<?php if($error): ?><div class="alert alert-error"><?=form_h($error)?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" id="publicForm"><input type="hidden" name="action" value="submit_form"><input type="hidden" name="form_slug" value="<?=form_h($slug)?>"><input type="hidden" name="csrf" value="<?=form_csrf_token()?>"><input type="hidden" name="edit_token" value="<?=form_h($editToken)?>">
<?php if((int)$form['recopilar_correo']===1): ?><div class="public-question"><h3>Correo electrónico <span class="required-star">*</span></h3><input class="input" type="email" name="respondent_email" required value="<?=form_h((string)($existingResponse['correo']??''))?>"></div><?php endif; ?>
<?php foreach($schema['sections'] as $si=>$section): ?><section class="form-section" data-index="<?=$si?>" data-section-id="<?=$section['id']?>" style="<?=$si===0?'':'display:none'?>"><div class="public-section-title"><h2><?=form_h($section['titulo']?:('Sección '.($si+1)))?></h2><?php if($section['descripcion']): ?><p><?=nl2br(form_h($section['descripcion']))?></p><?php endif; ?></div>
<?php foreach($section['questions'] as $q): $qid=(int)$q['id'];$value=existing_value($existingAnswers,$qid);$required=(int)$q['requerido']===1;$req=$required?'required':''; ?>
<?php if($q['tipo']==='title_description'): ?><div class="public-section-title"><h2><?=form_h($q['titulo'])?></h2><p><?=nl2br(form_h($q['descripcion']))?></p></div><?php continue; elseif($q['tipo']==='image'): ?><div class="public-question" style="text-align:center"><?php if(!empty($q['config']['url'])): ?><img src="<?=form_h($q['config']['url'])?>" alt="<?=form_h($q['config']['caption']??$q['titulo'])?>" style="max-width:100%;border-radius:10px"><?php endif; ?><div class="question-help"><?=form_h($q['config']['caption']??$q['titulo'])?></div></div><?php continue; elseif($q['tipo']==='video'): ?><div class="public-question"><h3><?=form_h($q['titulo'])?></h3><?php if(!empty($q['config']['url'])): ?><div style="position:relative;padding-top:56.25%"><iframe src="<?=form_h($q['config']['url'])?>" style="position:absolute;inset:0;width:100%;height:100%;border:0" allowfullscreen></iframe></div><?php endif; ?><div class="question-help"><?=form_h($q['config']['caption']??'')?></div></div><?php continue; endif; ?>
<div class="public-question" data-question-id="<?=$qid?>" data-type="<?=form_h($q['tipo'])?>" data-logic-key="<?=form_h((string)($q['config']['logic_key']??''))?>" data-logic="<?=form_h(form_json_encode($q['logica']??[]))?>"><h3><?=form_h($q['titulo'])?><?=$required?' <span class="required-star">*</span>':''?></h3><?php if($q['descripcion']): ?><div class="question-help"><?=nl2br(form_h($q['descripcion']))?></div><?php endif; ?>
<?php switch($q['tipo']): case 'paragraph': ?><textarea class="textarea" rows="5" name="<?=input_name($qid)?>" <?=$req?>><?=form_h((string)$value)?></textarea><?php break; case 'email': ?><input class="input" type="email" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php break; case 'number': ?><input class="input" type="number" step="any" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php break; case 'phone': ?><input class="input" type="tel" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php break; case 'date': ?><input class="input" type="date" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php break; case 'time': ?><input class="input" type="time" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php break; case 'datetime': ?><input class="input" type="datetime-local" name="<?=input_name($qid)?>" value="<?=form_h(str_replace(' ','T',(string)$value))?>" <?=$req?>><?php break;
case 'multiple_choice': foreach($q['opciones'] as $oi=>$opt): ?><label class="choice"><input type="radio" name="<?=input_name($qid)?>" value="<?=form_h((string)$opt)?>" <?=((string)$value===(string)$opt)?'checked':''?> <?=$required&&$oi===0?'required':''?>><span><?=form_h((string)$opt)?></span></label><?php endforeach; break;
case 'checkboxes': $vals=is_array($value)?$value:[];foreach($q['opciones'] as $opt): ?><label class="choice"><input type="checkbox" name="<?=input_name($qid,'[]')?>" value="<?=form_h((string)$opt)?>" <?=in_array($opt,$vals,true)?'checked':''?>><span><?=form_h((string)$opt)?></span></label><?php endforeach; break;
case 'dropdown': ?><select class="select" name="<?=input_name($qid)?>" <?=$req?>><option value="">Seleccione...</option><?php foreach($q['opciones'] as $opt): ?><option value="<?=form_h((string)$opt)?>" <?=((string)$value===(string)$opt)?'selected':''?>><?=form_h((string)$opt)?></option><?php endforeach; ?></select><?php break;
case 'linear_scale': $min=(int)($q['opciones']['min']??1);$max=(int)($q['opciones']['max']??5); ?><div class="scale-wrap"><?php for($n=$min;$n<=$max;$n++): ?><label class="scale-item"><span><?=$n?></span><input type="radio" name="<?=input_name($qid)?>" value="<?=$n?>" <?=((string)$value===(string)$n)?'checked':''?> <?=$required&&$n===$min?'required':''?>></label><?php endfor; ?></div><div style="display:flex;justify-content:space-between;color:#64748b;font-size:.78rem"><span><?=form_h((string)($q['opciones']['min_label']??''))?></span><span><?=form_h((string)($q['opciones']['max_label']??''))?></span></div><?php break;
case 'rating': $max=(int)($q['opciones']['max']??5); ?><div class="scale-wrap"><?php for($n=1;$n<=$max;$n++): ?><label class="scale-item"><span>★</span><input type="radio" name="<?=input_name($qid)?>" value="<?=$n?>" <?=((string)$value===(string)$n)?'checked':''?> <?=$required&&$n===1?'required':''?>></label><?php endfor; ?></div><?php break;
case 'file_upload': $allowedFileTypes=(array)($q['config']['allowed_types']??['pdf','jpg','jpeg','png','docx','xlsx']); ?><input class="input js-inline-upload" type="file" name="file_<?=$qid?>" data-qid="<?=$qid?>" data-max-mb="<?=form_h((string)($q['config']['max_mb']??20))?>" accept="<?=form_h(form_upload_accept($allowedFileTypes))?>" <?=$existingAnswers[$qid]['archivo_path']??false?'':$req?>><div class="file-note">Tipos: <?=form_h(implode(', ',$q['config']['allowed_types']??['pdf','jpg','png','docx','xlsx']))?> · Máximo <?=form_h((string)($q['config']['max_mb']??20))?> MB · La imagen se prepara directamente en el navegador.</div><?php if(!empty($existingAnswers[$qid]['archivo_path'])):?><div class="file-note">Ya existe un archivo guardado. Adjunta otro únicamente para reemplazarlo.</div><?php endif; ?><?php break;
case 'multiple_choice_grid': case 'checkbox_grid': $gridVal=is_array($value)?$value:[]; ?><div style="overflow:auto"><table class="grid-table"><thead><tr><th></th><?php foreach($q['opciones']['columns']??[] as $col): ?><th><?=form_h((string)$col)?></th><?php endforeach; ?></tr></thead><tbody><?php foreach($q['opciones']['rows']??[] as $ri=>$row): ?><tr><td><?=form_h((string)$row)?></td><?php foreach($q['opciones']['columns']??[] as $col): $inputType=$q['tipo']==='checkbox_grid'?'checkbox':'radio';$name=$q['tipo']==='checkbox_grid'?input_name($qid,'['.$ri.'][]'):input_name($qid,'['.$ri.']');$checked=$q['tipo']==='checkbox_grid'?in_array($col,(array)($gridVal[$ri]??[]),true):((string)($gridVal[$ri]??'')===(string)$col); ?><td><input type="<?=$inputType?>" name="<?=$name?>" value="<?=form_h((string)$col)?>" <?=$checked?'checked':''?>></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php break;
case 'geo_cascade': case 'center_selector': $geo=is_array($value)?$value:[];$types=implode(',',$q['opciones']['center_types']??[]);$levels=$q['tipo']==='geo_cascade'?($q['opciones']['levels']??['municipio','base','caserio','centro']):['municipio','base','caserio','centro']; ?><div class="geo-grid geo-cascade" data-types="<?=form_h($types)?>" data-qid="<?=$qid?>"><?php foreach(['municipio'=>'Municipio','base'=>'Comunidad base','caserio'=>'Caserío','centro'=>'Centro'] as $level=>$label): if(!in_array($level,$levels,true))continue; ?><div class="field"><label><?=$label?></label><select class="select geo-select" data-level="<?=$level?>" name="<?=input_name($qid,'['.$level.']')?>" data-current="<?=form_h((string)($geo[$level]??''))?>" <?=$required&&$level==='municipio'?'required':''?>><option value="">Seleccione...</option></select></div><?php endforeach; ?></div><?php break;
case 'consent': ?><label class="choice"><input type="checkbox" name="<?=input_name($qid)?>" value="Aceptado" <?=((string)$value==='Aceptado')?'checked':''?> <?=$req?>><span><?=form_h((string)($q['config']['consent_text']??'Declaro que la información proporcionada es correcta.'))?></span></label><?php break;
default: ?><input class="input" type="text" name="<?=input_name($qid)?>" value="<?=form_h((string)$value)?>" <?=$req?>><?php endswitch; ?>
</div><?php endforeach; ?><div class="form-nav"><button type="button" class="btn prev-section" <?=$si===0?'style="visibility:hidden"':''?>><i class="fa-solid fa-arrow-left"></i> Anterior</button><?php if($si<count($schema['sections'])-1): ?><button type="button" class="btn btn-primary next-section">Siguiente <i class="fa-solid fa-arrow-right"></i></button><?php else: ?><button type="submit" class="btn btn-green"><i class="fa-solid fa-paper-plane"></i> Enviar respuesta</button><?php endif; ?></div></section><?php endforeach; ?></form><?php endif; ?></main>
<script>
const sections=[...document.querySelectorAll('.form-section')];let current=0;const progress=document.getElementById('progressBar');
function showSection(i){sections.forEach((s,n)=>s.style.display=n===i?'block':'none');current=i;if(progress)progress.style.width=((i+1)/sections.length*100)+'%';window.scrollTo({top:0,behavior:'smooth'});}document.querySelectorAll('.next-section').forEach(b=>b.onclick=()=>{const sec=sections[current];const invalid=[...sec.querySelectorAll(':invalid')][0];if(invalid){invalid.reportValidity();invalid.closest('.public-question')?.classList.add('invalid');return;}showSection(Math.min(sections.length-1,current+1));});document.querySelectorAll('.prev-section').forEach(b=>b.onclick=()=>showSection(Math.max(0,current-1)));showSection(0);
async function geoFetch(level,wrap){const types=wrap.dataset.types||'';const get=l=>wrap.querySelector(`[data-level="${l}"]`)?.value||'';const u=new URL(location.href);u.searchParams.set('ajax','geo');u.searchParams.set('level',level);u.searchParams.set('municipio',get('municipio'));u.searchParams.set('base',get('base'));u.searchParams.set('caserio',get('caserio'));u.searchParams.set('types',types);const r=await fetch(u);const j=await r.json();return j.data||[];}
async function loadGeo(wrap,level,reset=true){const select=wrap.querySelector(`[data-level="${level}"]`);if(!select)return;if(reset)select.innerHTML='<option value="">Seleccione...</option>';const data=await geoFetch(level,wrap);data.forEach(x=>{const o=document.createElement('option');o.value=x.value;o.textContent=x.label;o.dataset.full=JSON.stringify(x);select.appendChild(o);});const cur=select.dataset.current;if(cur){select.value=cur;select.dataset.current='';}}
document.querySelectorAll('.geo-cascade').forEach(async wrap=>{for(const level of ['municipio','base','caserio','centro']){if(!wrap.querySelector(`[data-level="${level}"]`))continue;await loadGeo(wrap,level);const s=wrap.querySelector(`[data-level="${level}"]`);if(!s.value && level!=='municipio')break;}wrap.querySelectorAll('.geo-select').forEach(s=>s.addEventListener('change',async()=>{const order=['municipio','base','caserio','centro'];const idx=order.indexOf(s.dataset.level);for(let i=idx+1;i<order.length;i++){const next=wrap.querySelector(`[data-level="${order[i]}"]`);if(next){next.value='';next.innerHTML='<option value="">Seleccione...</option>';}}const nextLevel=order[idx+1];if(nextLevel)await loadGeo(wrap,nextLevel);}));});
document.querySelectorAll('.public-question input,.public-question select,.public-question textarea').forEach(input=>{input.dataset.originalRequired=input.required?'1':'0';});
function questionValue(wrap){
  if(!wrap)return '';
  const checks=[...wrap.querySelectorAll('input[type="checkbox"]:checked,input[type="radio"]:checked')].map(x=>x.value);
  if(checks.length)return checks;
  const fields=[...wrap.querySelectorAll('select,textarea,input:not([type="hidden"]):not([type="file"]):not([type="checkbox"]):not([type="radio"])')];
  if(fields.length===1)return fields[0].value||'';
  if(fields.length>1)return fields.map(x=>x.value||'').filter(Boolean);
  return '';
}
function logicMatches(logic,value){
  if(!logic?.enabled)return true;
  const vals=Array.isArray(value)?value.map(v=>String(v??'').trim().toLocaleLowerCase('es')):[String(value??'').trim().toLocaleLowerCase('es')];
  const actual=vals.join('|');const expected=String(logic.value??'').trim().toLocaleLowerCase('es');
  switch(logic.operator||'equals'){
    case 'not_equals':return actual!==expected;
    case 'contains':return vals.includes(expected)||actual.includes(expected);
    case 'not_contains':return !(vals.includes(expected)||actual.includes(expected));
    case 'is_empty':return actual==='';
    case 'not_empty':return actual!=='';
    case 'greater_than':return Number(actual)>Number(expected);
    case 'less_than':return Number(actual)<Number(expected);
    default:return actual===expected;
  }
}
function refreshConditionalQuestions(){
  const byKey=new Map([...document.querySelectorAll('.public-question[data-logic-key]')].map(w=>[w.dataset.logicKey,w]));
  document.querySelectorAll('.public-question[data-logic]').forEach(wrap=>{
    let logic={};try{logic=JSON.parse(wrap.dataset.logic||'{}');}catch(e){}
    const source=logic?.source_key?byKey.get(logic.source_key):null;
    const visible=!logic?.enabled||(!!source&&logicMatches(logic,questionValue(source)));
    wrap.classList.toggle('logic-hidden',!visible);
    wrap.querySelectorAll('input,select,textarea').forEach(input=>{
      if(!input.dataset.originalRequired)input.dataset.originalRequired=input.required?'1':'0';
      input.disabled=!visible;
      input.required=visible&&input.dataset.originalRequired==='1';
    });
  });
}
document.getElementById('publicForm')?.addEventListener('input',refreshConditionalQuestions);
document.getElementById('publicForm')?.addEventListener('change',refreshConditionalQuestions);
refreshConditionalQuestions();

function readAsDataURL(file){return new Promise((resolve,reject)=>{const r=new FileReader();r.onload=()=>resolve(String(r.result||''));r.onerror=()=>reject(new Error('No fue posible leer el archivo seleccionado.'));r.readAsDataURL(file);});}
function imageFromFile(file){
  return new Promise((resolve,reject)=>{
    const img=new Image();
    const url=URL.createObjectURL(file);
    img.onload=()=>{URL.revokeObjectURL(url);resolve(img);};
    img.onerror=()=>{URL.revokeObjectURL(url);reject(new Error('Formato no decodificable por este navegador.'));};
    img.src=url;
  });
}
function looksLikeImage(file){
  return String(file.type||'').toLowerCase().startsWith('image/')
    || /\.(jpe?g|jfif|png|gif|webp|avif|heic|heif|bmp|dib|tiff?|ico)$/i.test(file.name||'');
}
async function prepareBrowserFile(file,maxMb){
  let output=file;
  let outputName=file.name||'imagen';

  // Solo se optimizan los formatos que el navegador puede abrir. Formatos como
  // HEIC, HEIF, TIFF o ciertos AVIF se conservan intactos y se envían directamente.
  if(looksLikeImage(file)){
    try{
      const img=await imageFromFile(file);
      const maxSide=1920;
      const scale=Math.min(1,maxSide/Math.max(img.naturalWidth||1,img.naturalHeight||1));
      if(scale<1 || file.size>1.5*1024*1024){
        const canvas=document.createElement('canvas');
        canvas.width=Math.max(1,Math.round(img.naturalWidth*scale));
        canvas.height=Math.max(1,Math.round(img.naturalHeight*scale));
        const ctx=canvas.getContext('2d');
        if(ctx){
          ctx.drawImage(img,0,0,canvas.width,canvas.height);
          const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',0.84));
          if(blob){
            output=new File([blob],(file.name.replace(/\.[^.]+$/,'')||'fotografia')+'.jpg',{type:'image/jpeg'});
            outputName=output.name;
          }
        }
      }
    }catch(error){
      // No se rechaza la imagen. Se conserva y envía en su formato original.
      output=file;
      outputName=file.name||'imagen';
    }
  }

  if(output.size>maxMb*1024*1024){
    throw new Error(`El archivo “${file.name}” supera ${maxMb} MB.`);
  }
  return {data:await readAsDataURL(output),name:outputName,type:output.type||'application/octet-stream',size:output.size};
}
const publicForm=document.getElementById('publicForm');
publicForm?.addEventListener('submit',async event=>{
  if(publicForm.dataset.inlineReady==='1')return;
  const inputs=[...publicForm.querySelectorAll('.js-inline-upload:not(:disabled)')].filter(i=>i.files&&i.files[0]);
  if(!inputs.length)return;
  event.preventDefault();
  const submitButton=publicForm.querySelector('button[type="submit"]');
  const oldHtml=submitButton?.innerHTML;
  if(submitButton){submitButton.disabled=true;submitButton.innerHTML='<i class="fa-solid fa-spinner fa-spin"></i> Preparando archivos...';}
  try{
    publicForm.querySelectorAll('.inline-upload-generated').forEach(n=>n.remove());
    for(const input of inputs){
      const qid=input.dataset.qid;const maxMb=Math.max(1,Number(input.dataset.maxMb||10));
      const prepared=await prepareBrowserFile(input.files[0],maxMb);
      const values={file_b64:prepared.data,file_name:prepared.name,file_type:prepared.type,file_size:String(prepared.size)};
      Object.entries(values).forEach(([key,value])=>{const area=document.createElement('textarea');area.hidden=true;area.className='inline-upload-generated';area.name=`${key}[${qid}]`;area.value=value;publicForm.appendChild(area);});
      input.dataset.originalName=input.name;input.removeAttribute('name');input.required=false;
    }
    publicForm.dataset.inlineReady='1';
    HTMLFormElement.prototype.submit.call(publicForm);
  }catch(error){
    inputs.forEach(input=>{if(input.dataset.originalName)input.name=input.dataset.originalName;});
    if(submitButton){submitButton.disabled=false;submitButton.innerHTML=oldHtml||'Enviar respuesta';}
    alert(error.message||'No fue posible preparar el archivo.');
  }
});
</script></body></html>
