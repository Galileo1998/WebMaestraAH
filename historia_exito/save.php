<?php
session_start();
require __DIR__ . '/db.php';
require __DIR__ . '/functions.php';
verify_csrf();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT) ?: 0;
$existing = $id ? record_or_404($pdo, $id) : [];

try {
    $media = [];
    for ($i=1; $i<=3; $i++) {
        $uploaded = upload_media("media{$i}", $existing["media{$i}_path"] ?? null);
        $media[$i] = [
            'path' => $uploaded['path'] ?? null,
            'name' => $uploaded['name'] ?? ($existing["media{$i}_original_name"] ?? null),
        ];
    }

    $data = [
        trim($_POST['country'] ?? 'Honduras'), trim($_POST['local_partner'] ?? ''), trim($_POST['community'] ?? ''),
        normalize_date($_POST['capture_date'] ?? '') ?: date('Y-m-d'), normalize_date($_POST['send_date'] ?? ''),
        trim($_POST['captured_by'] ?? ''), trim($_POST['capturer_contact'] ?? ''),
        trim($_POST['full_name'] ?? ''), ($_POST['age'] ?? '') !== '' ? (int)$_POST['age'] : null,
        trim($_POST['participant_number'] ?? ''), trim($_POST['school_grade'] ?? ''), trim($_POST['relationship_type'] ?? ''),
        trim($_POST['program_model'] ?? ''), trim($_POST['intermediate_result'] ?? ''), trim($_POST['magic_moment_type'] ?? ''),
        trim($_POST['media1_type'] ?? ''), $media[1]['path'], $media[1]['name'], trim($_POST['media1_description'] ?? ''),
        trim($_POST['media2_type'] ?? ''), $media[2]['path'], $media[2]['name'], trim($_POST['media2_description'] ?? ''),
        trim($_POST['media3_type'] ?? ''), $media[3]['path'], $media[3]['name'], trim($_POST['media3_description'] ?? ''),
        trim($_POST['testimony_feeling'] ?? ''), trim($_POST['testimony_learning'] ?? ''),
        trim($_POST['testimony_application'] ?? ''), trim($_POST['testimony_change'] ?? ''),
        isset($_POST['consent_confirmed']) ? 1 : 0, ($_POST['status'] ?? '') === 'Finalizado' ? 'Finalizado' : 'Borrador'
    ];

    if (!$data[1] || !$data[2] || !$data[5] || !$data[7]) {
        throw new RuntimeException('Complete socio local, comunidad, persona que capturó y nombre de participante.');
    }

    $columns = 'country=?, local_partner=?, community=?, capture_date=?, send_date=?, captured_by=?, capturer_contact=?, full_name=?, age=?, participant_number=?, school_grade=?, relationship_type=?, program_model=?, intermediate_result=?, magic_moment_type=?, media1_type=?, media1_path=?, media1_original_name=?, media1_description=?, media2_type=?, media2_path=?, media2_original_name=?, media2_description=?, media3_type=?, media3_path=?, media3_original_name=?, media3_description=?, testimony_feeling=?, testimony_learning=?, testimony_application=?, testimony_change=?, consent_confirmed=?, status=?';

    if ($id) {
        $stmt = $pdo->prepare("UPDATE magic_moments SET $columns WHERE id=?");
        $data[] = $id;
        $stmt->execute($data);
    } else {
        $placeholders = implode(',', array_fill(0, count($data), '?'));
        $stmt = $pdo->prepare('INSERT INTO magic_moments (country,local_partner,community,capture_date,send_date,captured_by,capturer_contact,full_name,age,participant_number,school_grade,relationship_type,program_model,intermediate_result,magic_moment_type,media1_type,media1_path,media1_original_name,media1_description,media2_type,media2_path,media2_original_name,media2_description,media3_type,media3_path,media3_original_name,media3_description,testimony_feeling,testimony_learning,testimony_application,testimony_change,consent_confirmed,status) VALUES (' . $placeholders . ')');
        $stmt->execute($data);
        $id = (int)$pdo->lastInsertId();
    }

    $_SESSION['flash'] = 'Registro guardado correctamente.';
    header('Location: index.php?id=' . $id);
} catch (Throwable $e) {
    $_SESSION['flash'] = 'No se guardó: ' . $e->getMessage();
    header('Location: index.php' . ($id ? '?id=' . $id : ''));
}
