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

    $action = (string)($_GET['action'] ?? '');
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
    http_response_code(500);
    echo json_encode(['status' => 'error', 'msg' => 'No se pudo cargar la actividad.'], JSON_UNESCAPED_UNICODE);
}
