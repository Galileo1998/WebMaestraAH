<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FormService.php';
form_require_admin_auth($db);
header('Content-Type: application/json; charset=utf-8');

try {
    form_assert_installed($db);
    $service = new FormService($db);
    $action = $_GET['action'] ?? $_POST['action'] ?? '';

    if ($action === 'schema') {
        echo form_json_encode(['status'=>'ok','data'=>$service->getSchema((int)($_GET['id'] ?? 0))]);
        exit;
    }

    if ($action === 'save_schema') {
        form_validate_csrf($_POST['csrf'] ?? null);
        $payload = json_decode((string)($_POST['payload'] ?? ''), true);
        if (!is_array($payload)) throw new RuntimeException('El contenido del formulario no es válido.');
        $schema = $service->saveSchema((int)($_POST['id'] ?? 0), $payload, form_current_user_id());
        echo form_json_encode(['status'=>'ok','data'=>$schema,'saved_at'=>date('H:i:s')]);
        exit;
    }

    if ($action === 'analytics') {
        echo form_json_encode(['status'=>'ok','data'=>$service->analytics((int)($_GET['id'] ?? 0))]);
        exit;
    }

    if ($action === 'responses') {
        echo form_json_encode(['status'=>'ok','data'=>$service->responseRows((int)($_GET['id'] ?? 0), 20000)]);
        exit;
    }

    if ($action === 'upload_confirmation_image') {
        form_validate_csrf($_POST['csrf'] ?? null);
        $formId = (int)($_POST['id'] ?? 0);
        if ($formId <= 0 || !$service->getForm($formId)) {
            throw new RuntimeException('Formulario inválido.');
        }

        $dataUrl = trim((string)($_POST['data_url'] ?? ''));
        $originalName = trim((string)($_POST['file_name'] ?? 'imagen'));
        if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.*)$#s', $dataUrl, $match)) {
            throw new RuntimeException('La imagen seleccionada no tiene un formato válido.');
        }

        $mime = strtolower($match[1]);
        if ($mime === 'image/svg+xml') {
            throw new RuntimeException('El formato SVG no se admite por seguridad.');
        }
        $binary = base64_decode(preg_replace('/\s+/', '', $match[2]), true);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('No fue posible leer la imagen.');
        }
        if (strlen($binary) > 12 * 1024 * 1024) {
            throw new RuntimeException('La imagen supera el máximo de 12 MB.');
        }

        $mimeMap = [
            'image/jpeg'=>'jpg','image/jpg'=>'jpg','image/pjpeg'=>'jpg',
            'image/png'=>'png','image/gif'=>'gif','image/webp'=>'webp',
            'image/avif'=>'avif','image/heic'=>'heic','image/heif'=>'heif',
            'image/bmp'=>'bmp','image/x-ms-bmp'=>'bmp','image/tiff'=>'tiff',
            'image/x-icon'=>'ico','image/vnd.microsoft.icon'=>'ico'
        ];
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]+/', '', $ext) ?: '';
        if ($ext === 'jpeg') $ext = 'jpg';
        if ($ext === '' || !preg_match('/^[a-z0-9]{2,8}$/', $ext)) {
            $ext = $mimeMap[$mime] ?? 'img';
        }

        $relativeDir = 'uploads/formularios/confirmaciones/' . $formId;
        $packageRoot = dirname(__DIR__);
        $candidates = [$packageRoot . '/' . $relativeDir];
        $docRoot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
        if ($docRoot !== '') $candidates[] = $docRoot . '/' . $relativeDir;
        $candidates[] = dirname($packageRoot) . '/' . $relativeDir;

        $targetDir = null;
        foreach (array_unique($candidates) as $candidate) {
            if (!is_dir($candidate)) @mkdir($candidate, 0775, true);
            if (is_dir($candidate)) @chmod($candidate, 0775);
            if (is_dir($candidate) && is_writable($candidate)) {
                $targetDir = $candidate;
                break;
            }
        }
        if (!$targetDir) {
            throw new RuntimeException('No hay una carpeta con permisos de escritura para guardar la imagen. Revise uploads/formularios.');
        }

        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destination = rtrim($targetDir, '/\\') . DIRECTORY_SEPARATOR . $filename;
        $written = @file_put_contents($destination, $binary, LOCK_EX);
        if ($written === false || $written !== strlen($binary)) {
            @unlink($destination);
            throw new RuntimeException('No fue posible guardar la imagen de agradecimiento.');
        }
        @chmod($destination, 0644);

        $oldPath = trim((string)($_POST['old_path'] ?? ''));
        if ($oldPath !== '' && preg_match('#^uploads/formularios/confirmaciones/' . $formId . '/[a-zA-Z0-9._-]+$#', $oldPath)) {
            foreach ($candidates as $baseCandidate) {
                $oldFile = rtrim($baseCandidate, '/\\') . '/' . basename($oldPath);
                if (is_file($oldFile) && realpath(dirname($oldFile)) === realpath($targetDir)) @unlink($oldFile);
            }
        }

        $relativePath = $relativeDir . '/' . $filename;
        $service->audit($formId, form_current_user_id(), 'subir_imagen_confirmacion', ['path'=>$relativePath]);
        echo form_json_encode(['status'=>'ok','path'=>$relativePath,'mime'=>$mime]);
        exit;
    }

    if ($action === 'delete_confirmation_image') {
        form_validate_csrf($_POST['csrf'] ?? null);
        $formId = (int)($_POST['id'] ?? 0);
        $path = trim((string)($_POST['path'] ?? ''));
        if ($formId <= 0) throw new RuntimeException('Formulario inválido.');
        if ($path !== '' && preg_match('#^uploads/formularios/confirmaciones/' . $formId . '/[a-zA-Z0-9._-]+$#', $path)) {
            $packageRoot = dirname(__DIR__);
            $files = [
                $packageRoot . '/' . $path,
                rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\') . '/' . $path,
                dirname($packageRoot) . '/' . $path,
            ];
            foreach (array_unique($files) as $file) if (is_file($file)) @unlink($file);
        }
        $service->audit($formId, form_current_user_id(), 'eliminar_imagen_confirmacion', []);
        echo form_json_encode(['status'=>'ok']);
        exit;
    }

    if ($action === 'geo') {
        $level = (string)($_GET['level'] ?? 'municipio');
        $municipio = trim((string)($_GET['municipio'] ?? ''));
        $base = trim((string)($_GET['base'] ?? ''));
        $caserio = trim((string)($_GET['caserio'] ?? ''));
        $types = array_filter(array_map('trim', explode(',', (string)($_GET['types'] ?? ''))));
        $data = [];
        if ($level === 'municipio') {
            $data = $db->query("SELECT DISTINCT municipio AS value, municipio AS label FROM ah_bases_geograficas WHERE municipio IS NOT NULL AND TRIM(municipio)<>'' ORDER BY municipio")->fetchAll();
        } elseif ($level === 'base') {
            $st = $db->prepare("SELECT DISTINCT nombre_base AS value, nombre_base AS label FROM ah_bases_geograficas WHERE municipio=? AND nombre_base IS NOT NULL AND TRIM(nombre_base)<>'' ORDER BY nombre_base");
            $st->execute([$municipio]);
            $data = $st->fetchAll();
        } elseif ($level === 'caserio') {
            $sql = "SELECT DISTINCT c.caserio AS value,c.caserio AS label
                    FROM ah_centros c
                    INNER JOIN ah_bases_geograficas b ON LOWER(TRIM(b.nombre_base))=LOWER(TRIM(c.comunidad_base))
                    WHERE b.municipio=? AND c.comunidad_base=? AND c.caserio IS NOT NULL AND TRIM(c.caserio)<>''";
            $params = [$municipio,$base];
            if ($types) {
                $sql .= ' AND c.tipo IN('.implode(',',array_fill(0,count($types),'?')).')';
                $params = array_merge($params,$types);
            }
            $sql .= ' ORDER BY c.caserio';
            $st = $db->prepare($sql);$st->execute($params);$data=$st->fetchAll();
        } elseif ($level === 'centro') {
            $sql = "SELECT c.id AS value, CONCAT(c.nombre,' · ',c.tipo) AS label, c.tipo, c.nombre, c.comunidad_base, c.caserio,
                           c.pob_total,c.pob_fem,c.pob_masc,c.pob_0_5,c.pob_6_17,c.pob_18_24
                    FROM ah_centros c
                    INNER JOIN ah_bases_geograficas b ON LOWER(TRIM(b.nombre_base))=LOWER(TRIM(c.comunidad_base))
                    WHERE b.municipio=? AND c.comunidad_base=?";
            $params = [$municipio,$base];
            if ($caserio !== '') {$sql .= ' AND c.caserio=?';$params[]=$caserio;}
            if ($types) {$sql .= ' AND c.tipo IN('.implode(',',array_fill(0,count($types),'?')).')';$params=array_merge($params,$types);}
            $sql .= ' ORDER BY c.tipo,c.nombre';
            $st=$db->prepare($sql);$st->execute($params);$data=$st->fetchAll();
        } else {
            throw new RuntimeException('Nivel geográfico no válido.');
        }
        echo form_json_encode(['status'=>'ok','data'=>$data]);
        exit;
    }

    throw new RuntimeException('Acción no reconocida.');
} catch (Throwable $e) {
    http_response_code(400);
    echo form_json_encode(['status'=>'error','msg'=>$e->getMessage()]);
}
