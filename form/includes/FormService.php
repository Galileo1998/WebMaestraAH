<?php
declare(strict_types=1);

final class FormService {
    public function __construct(private PDO $db) {}

    public function listForms(): array {
        $sql = "SELECT f.*,
                       (SELECT COUNT(*) FROM ah_form_questions q WHERE q.form_id=f.id) AS preguntas,
                       (SELECT COUNT(*) FROM ah_form_responses r WHERE r.form_id=f.id AND r.estado='enviada') AS respuestas
                FROM ah_forms f
                ORDER BY f.updated_at DESC, f.id DESC";
        return $this->db->query($sql)->fetchAll();
    }

    public function createForm(string $title, ?int $userId): int {
        $slugBase = form_slugify($title);
        $slug = $slugBase;
        $i = 2;
        $check = $this->db->prepare('SELECT COUNT(*) FROM ah_forms WHERE slug=?');
        while (true) {
            $check->execute([$slug]);
            if ((int)$check->fetchColumn() === 0) break;
            $slug = $slugBase . '-' . $i++;
        }

        $config = [
            'tipo' => 'encuesta',
            'barajar_preguntas' => false,
            'mostrar_enlace_otro_envio' => true,
            'guardar_borrador_respuesta' => true,
            'publicar_resultados' => false,
            'confirmation_title' => 'Respuesta registrada',
            'confirmation_image' => '',
            'confirmation_image_alt' => 'Gracias por completar el formulario',
            'confirmation_image_max_width' => 520,
        ];
        $stmt = $this->db->prepare("INSERT INTO ah_forms
            (titulo, slug, creado_por, mensaje_confirmacion, configuracion_json)
            VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$title, $slug, $userId, '¡Gracias! Tu respuesta fue registrada correctamente.', form_json_encode($config)]);
        $id = (int)$this->db->lastInsertId();
        $this->db->prepare('INSERT INTO ah_form_sections(form_id,titulo,descripcion,orden) VALUES(?,?,?,1)')
            ->execute([$id, 'Sección 1', '']);
        $this->audit($id, $userId, 'crear_formulario', ['titulo'=>$title]);
        return $id;
    }

    public function duplicateForm(int $formId, ?int $userId): int {
        $source = $this->getForm($formId);
        if (!$source) throw new RuntimeException('Formulario no encontrado.');
        $this->db->beginTransaction();
        try {
            $newId = $this->createForm($source['titulo'] . ' - Copia', $userId);
            $newSlug = form_slugify($source['titulo'] . '-copia-' . $newId);
            $this->db->prepare("UPDATE ah_forms SET descripcion=?, slug=?, tema_color=?, imagen_cabecera=?, mensaje_confirmacion=?, configuracion_json=?, fecha_apertura=?, fecha_cierre=?, limite_respuestas=?, requiere_login=?, una_respuesta=?, permitir_edicion=?, recopilar_correo=?, mostrar_progreso=?, notificar_correo=? WHERE id=?")
                ->execute([$source['descripcion'],$newSlug,$source['tema_color'],$source['imagen_cabecera'],$source['mensaje_confirmacion'],$source['configuracion_json'],$source['fecha_apertura'],$source['fecha_cierre'],$source['limite_respuestas'],$source['requiere_login'],$source['una_respuesta'],$source['permitir_edicion'],$source['recopilar_correo'],$source['mostrar_progreso'],$source['notificar_correo'],$newId]);
            $this->db->prepare('DELETE FROM ah_form_sections WHERE form_id=?')->execute([$newId]);
            $sections = $this->getSections($formId);
            $sectionMap = [];
            foreach ($sections as $section) {
                $st = $this->db->prepare('INSERT INTO ah_form_sections(form_id,titulo,descripcion,orden,config_json) VALUES(?,?,?,?,?)');
                $st->execute([$newId,$section['titulo'],$section['descripcion'],$section['orden'],$section['config_json']]);
                $sectionMap[(int)$section['id']] = (int)$this->db->lastInsertId();
            }
            foreach ($this->getQuestions($formId) as $q) {
                $st = $this->db->prepare("INSERT INTO ah_form_questions
                    (form_id,section_id,tipo,titulo,descripcion,requerido,orden,opciones_json,validacion_json,logica_json,config_json,puntos)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$newId,$q['section_id'] ? ($sectionMap[(int)$q['section_id']] ?? null) : null,$q['tipo'],$q['titulo'],$q['descripcion'],$q['requerido'],$q['orden'],$q['opciones_json'],$q['validacion_json'],$q['logica_json'],$q['config_json'],$q['puntos']]);
            }
            $this->db->commit();
            return $newId;
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getForm(int $id): ?array {
        $st = $this->db->prepare('SELECT * FROM ah_forms WHERE id=? LIMIT 1');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    public function getFormBySlug(string $slug): ?array {
        $st = $this->db->prepare('SELECT * FROM ah_forms WHERE slug=? LIMIT 1');
        $st->execute([$slug]);
        return $st->fetch() ?: null;
    }

    public function getSections(int $formId): array {
        $st = $this->db->prepare('SELECT * FROM ah_form_sections WHERE form_id=? ORDER BY orden,id');
        $st->execute([$formId]);
        return $st->fetchAll();
    }

    public function getQuestions(int $formId): array {
        $st = $this->db->prepare('SELECT * FROM ah_form_questions WHERE form_id=? ORDER BY orden,id');
        $st->execute([$formId]);
        return $st->fetchAll();
    }

    public function getSchema(int $formId): array {
        $form = $this->getForm($formId);
        if (!$form) throw new RuntimeException('Formulario no encontrado.');
        $sections = $this->getSections($formId);
        $questions = $this->getQuestions($formId);
        foreach ($sections as &$section) {
            $section['config'] = form_json_decode($section['config_json'] ?? null);
            $section['questions'] = [];
        }
        unset($section);
        $sectionIndex = [];
        foreach ($sections as $index => $section) $sectionIndex[(int)$section['id']] = $index;
        foreach ($questions as $q) {
            $q['opciones'] = form_json_decode($q['opciones_json'] ?? null);
            $q['validacion'] = form_json_decode($q['validacion_json'] ?? null);
            $q['logica'] = form_json_decode($q['logica_json'] ?? null);
            $q['config'] = form_json_decode($q['config_json'] ?? null);
            $sid = (int)($q['section_id'] ?? 0);
            if ($sid && isset($sectionIndex[$sid])) $sections[$sectionIndex[$sid]]['questions'][] = $q;
        }
        $form['configuracion'] = form_json_decode($form['configuracion_json'] ?? null);
        return ['form'=>$form,'sections'=>$sections];
    }

    public function saveSchema(int $formId, array $payload, ?int $userId): array {
        $form = $payload['form'] ?? [];
        $sections = $payload['sections'] ?? [];
        if (!is_array($sections) || !$sections) throw new RuntimeException('El formulario debe tener al menos una sección.');
        $title = trim((string)($form['titulo'] ?? ''));
        if ($title === '') throw new RuntimeException('Ingrese un título para el formulario.');

        // Normaliza y valida identificadores estables para la lógica condicional.
        $logicKeys = [];
        foreach ($sections as $sIndex => &$sectionRef) {
            if (!isset($sectionRef['questions']) || !is_array($sectionRef['questions'])) $sectionRef['questions'] = [];
            foreach ($sectionRef['questions'] as $qIndex => &$questionRef) {
                $questionRef['config'] = is_array($questionRef['config'] ?? null) ? $questionRef['config'] : [];
                $logicKey = trim((string)($questionRef['config']['logic_key'] ?? ''));
                if ($logicKey === '' || isset($logicKeys[$logicKey])) {
                    $logicKey = 'logic_' . bin2hex(random_bytes(10));
                    $questionRef['config']['logic_key'] = $logicKey;
                }
                $logicKeys[$logicKey] = true;
                $questionRef['logica'] = is_array($questionRef['logica'] ?? null) ? $questionRef['logica'] : [];
                $questionRef['logica'] = array_merge([
                    'enabled'=>false,'source_key'=>'','operator'=>'equals','value'=>''
                ], $questionRef['logica']);
                $questionRef['logica']['enabled'] = !empty($questionRef['logica']['enabled']);
                $questionRef['logica']['source_key'] = trim((string)$questionRef['logica']['source_key']);
                $questionRef['logica']['operator'] = in_array((string)$questionRef['logica']['operator'], ['equals','not_equals','contains','not_contains','is_empty','not_empty','greater_than','less_than'], true)
                    ? (string)$questionRef['logica']['operator'] : 'equals';
                $questionRef['logica']['value'] = (string)($questionRef['logica']['value'] ?? '');
            }
            unset($questionRef);
        }
        unset($sectionRef);
        foreach ($sections as $sectionRef) {
            foreach (($sectionRef['questions'] ?? []) as $questionRef) {
                $logic = $questionRef['logica'] ?? [];
                if (!empty($logic['enabled'])) {
                    $sourceKey = trim((string)($logic['source_key'] ?? ''));
                    if ($sourceKey === '' || !isset($logicKeys[$sourceKey])) {
                        throw new RuntimeException('La lógica condicional de “'.trim((string)($questionRef['titulo'] ?? 'Pregunta')).'” no tiene una pregunta de origen válida.');
                    }
                }
            }
        }

        $this->db->beginTransaction();
        try {
            $update = $this->db->prepare("UPDATE ah_forms SET
                titulo=?, descripcion=?, estado=?, tema_color=?, mensaje_confirmacion=?, configuracion_json=?,
                fecha_apertura=?, fecha_cierre=?, limite_respuestas=?, requiere_login=?, una_respuesta=?,
                permitir_edicion=?, recopilar_correo=?, mostrar_progreso=?, notificar_correo=? WHERE id=?");
            $update->execute([
                $title,
                trim((string)($form['descripcion'] ?? '')),
                in_array(($form['estado'] ?? 'borrador'), ['borrador','publicado','cerrado'], true) ? $form['estado'] : 'borrador',
                preg_match('/^#[0-9a-fA-F]{6}$/', (string)($form['tema_color'] ?? '')) ? $form['tema_color'] : '#34859B',
                trim((string)($form['mensaje_confirmacion'] ?? '')),
                form_json_encode($form['configuracion'] ?? []),
                $this->nullableDateTime($form['fecha_apertura'] ?? null),
                $this->nullableDateTime($form['fecha_cierre'] ?? null),
                ($form['limite_respuestas'] ?? '') !== '' ? max(1, (int)$form['limite_respuestas']) : null,
                !empty($form['requiere_login']) ? 1 : 0,
                !empty($form['una_respuesta']) ? 1 : 0,
                !empty($form['permitir_edicion']) ? 1 : 0,
                !empty($form['recopilar_correo']) ? 1 : 0,
                !empty($form['mostrar_progreso']) ? 1 : 0,
                trim((string)($form['notificar_correo'] ?? '')) ?: null,
                $formId
            ]);

            $existingSectionIds = array_map('intval', $this->db->query('SELECT id FROM ah_form_sections WHERE form_id='.(int)$formId)->fetchAll(PDO::FETCH_COLUMN));
            $existingQuestionIds = array_map('intval', $this->db->query('SELECT id FROM ah_form_questions WHERE form_id='.(int)$formId)->fetchAll(PDO::FETCH_COLUMN));
            $keptSections = [];
            $keptQuestions = [];

            foreach (array_values($sections) as $sIndex => $section) {
                $sectionId = isset($section['id']) && is_numeric($section['id']) ? (int)$section['id'] : 0;
                if ($sectionId && in_array($sectionId, $existingSectionIds, true)) {
                    $st = $this->db->prepare('UPDATE ah_form_sections SET titulo=?,descripcion=?,orden=?,config_json=? WHERE id=? AND form_id=?');
                    $st->execute([trim((string)($section['titulo'] ?? '')),trim((string)($section['descripcion'] ?? '')),$sIndex+1,form_json_encode($section['config'] ?? []),$sectionId,$formId]);
                } else {
                    $st = $this->db->prepare('INSERT INTO ah_form_sections(form_id,titulo,descripcion,orden,config_json) VALUES(?,?,?,?,?)');
                    $st->execute([$formId,trim((string)($section['titulo'] ?? '')),trim((string)($section['descripcion'] ?? '')),$sIndex+1,form_json_encode($section['config'] ?? [])]);
                    $sectionId = (int)$this->db->lastInsertId();
                }
                $keptSections[] = $sectionId;

                foreach (array_values($section['questions'] ?? []) as $qIndex => $q) {
                    $questionId = isset($q['id']) && is_numeric($q['id']) ? (int)$q['id'] : 0;
                    $params = [
                        $sectionId,
                        $this->sanitizeQuestionType((string)($q['tipo'] ?? 'short_text')),
                        trim((string)($q['titulo'] ?? 'Pregunta sin título')),
                        trim((string)($q['descripcion'] ?? '')),
                        !empty($q['requerido']) ? 1 : 0,
                        (($sIndex + 1) * 1000) + $qIndex + 1,
                        form_json_encode($q['opciones'] ?? []),
                        form_json_encode($q['validacion'] ?? []),
                        form_json_encode($q['logica'] ?? []),
                        form_json_encode($q['config'] ?? []),
                        max(0, (float)($q['puntos'] ?? 0)),
                    ];
                    if ($questionId && in_array($questionId, $existingQuestionIds, true)) {
                        $sql = 'UPDATE ah_form_questions SET section_id=?,tipo=?,titulo=?,descripcion=?,requerido=?,orden=?,opciones_json=?,validacion_json=?,logica_json=?,config_json=?,puntos=? WHERE id=? AND form_id=?';
                        $this->db->prepare($sql)->execute(array_merge($params,[$questionId,$formId]));
                    } else {
                        $sql = 'INSERT INTO ah_form_questions(section_id,tipo,titulo,descripcion,requerido,orden,opciones_json,validacion_json,logica_json,config_json,puntos,form_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)';
                        $this->db->prepare($sql)->execute(array_merge($params,[$formId]));
                        $questionId = (int)$this->db->lastInsertId();
                    }
                    $keptQuestions[] = $questionId;
                }
            }

            if ($existingQuestionIds) {
                $delete = array_values(array_diff($existingQuestionIds,$keptQuestions));
                if ($delete) $this->db->exec('DELETE FROM ah_form_questions WHERE form_id='.(int)$formId.' AND id IN('.implode(',',$delete).')');
            }
            if ($existingSectionIds) {
                $delete = array_values(array_diff($existingSectionIds,$keptSections));
                if ($delete) $this->db->exec('DELETE FROM ah_form_sections WHERE form_id='.(int)$formId.' AND id IN('.implode(',',$delete).')');
            }
            $this->audit($formId,$userId,'guardar_diseno',['secciones'=>count($sections),'preguntas'=>count($keptQuestions)]);
            $this->db->commit();
            return $this->getSchema($formId);
        } catch (Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function deleteForm(int $formId, ?int $userId): void {
        $this->audit($formId,$userId,'eliminar_formulario',[]);
        $this->db->prepare('DELETE FROM ah_forms WHERE id=?')->execute([$formId]);
    }

    public function countResponses(int $formId): int {
        $st = $this->db->prepare("SELECT COUNT(*) FROM ah_form_responses WHERE form_id=? AND estado='enviada'");
        $st->execute([$formId]);
        return (int)$st->fetchColumn();
    }

    /**
     * Obtiene respuestas sin JOIN ni ORDER BY sobre columnas LONGTEXT.
     * Se hacen dos consultas simples para evitar que MariaDB/MySQL cree
     * tablas temporales en /tmp al combinar respuestas y respuestas detalladas.
     */
    public function responseRows(int $formId, int $limit=5000): array {
        $limit = max(1, min(20000, $limit));

        // El id autoincremental conserva el orden de envío y permite usar el PRIMARY KEY.
        $sql = "SELECT id, token, correo, nombre_respondiente, submitted_at
                FROM ah_form_responses FORCE INDEX (PRIMARY)
                WHERE form_id=? AND estado='enviada'
                ORDER BY id DESC
                LIMIT {$limit}";
        $st = $this->db->prepare($sql);
        $st->execute([$formId]);
        $responseRows = $st->fetchAll(PDO::FETCH_ASSOC);

        if (!$responseRows) return [];

        $grouped = [];
        $ids = [];
        foreach ($responseRows as $row) {
            $rid = (int)$row['id'];
            $ids[] = $rid;
            $grouped[$rid] = [
                'id'=>$rid,
                'token'=>$row['token'] ?? '',
                'correo'=>$row['correo'] ?? '',
                'nombre_respondiente'=>$row['nombre_respondiente'] ?? '',
                'submitted_at'=>$row['submitted_at'] ?? null,
                'answers'=>[]
            ];
        }

        // Lotes pequeños: evita sentencias enormes y no requiere ordenar en SQL.
        foreach (array_chunk($ids, 400) as $chunk) {
            $marks = implode(',', array_fill(0, count($chunk), '?'));
            $ans = $this->db->prepare(
                "SELECT response_id, question_id, valor_texto, valor_json, archivo_path
                 FROM ah_form_answers
                 WHERE response_id IN ({$marks})"
            );
            $ans->execute($chunk);
            while ($row = $ans->fetch(PDO::FETCH_ASSOC)) {
                $rid = (int)$row['response_id'];
                if (!isset($grouped[$rid])) continue;
                $grouped[$rid]['answers'][(int)$row['question_id']] = [
                    'text'=>$row['valor_texto'],
                    'json'=>form_json_decode($row['valor_json'] ?? null),
                    'file'=>$row['archivo_path']
                ];
            }
        }

        // Mantiene el mismo orden DESC de la primera consulta.
        return array_values($grouped);
    }

    /** Calcula estadísticas usando respuestas ya cargadas, sin repetir consultas. */
    public function analyticsFromRows(array $schema, array $responses): array {
        $result = ['total'=>count($responses),'questions'=>[],'timeline'=>[]];
        foreach ($responses as $r) {
            $date = substr((string)$r['submitted_at'],0,10);
            $result['timeline'][$date] = ($result['timeline'][$date] ?? 0) + 1;
        }
        foreach ($schema['sections'] as $section) {
            foreach ($section['questions'] as $q) {
                $qid = (int)$q['id'];
                $stat = ['id'=>$qid,'titulo'=>$q['titulo'],'tipo'=>$q['tipo'],'count'=>0,'values'=>[],'numeric'=>[]];
                foreach ($responses as $r) {
                    $answer = $r['answers'][$qid] ?? null;
                    if (!$answer) continue;
                    $values = $answer['json'] ?: (($answer['text'] ?? '') !== '' ? [$answer['text']] : []);
                    if (!is_array($values)) $values = [$values];
                    foreach ($values as $value) {
                        if (is_array($value)) {
                            foreach ($value as $subKey=>$subValue) {
                                $label = is_string($subKey) ? $subKey . ': ' . (is_scalar($subValue)?$subValue:form_json_encode($subValue)) : (is_scalar($subValue)?(string)$subValue:form_json_encode($subValue));
                                $stat['values'][$label] = ($stat['values'][$label] ?? 0) + 1;
                            }
                        } else {
                            $label = trim((string)$value);
                            if ($label === '') continue;
                            $stat['values'][$label] = ($stat['values'][$label] ?? 0) + 1;
                            if (is_numeric($value)) $stat['numeric'][] = (float)$value;
                        }
                    }
                    $stat['count']++;
                }
                if ($stat['numeric']) {
                    $stat['average'] = array_sum($stat['numeric']) / count($stat['numeric']);
                    $stat['min'] = min($stat['numeric']);
                    $stat['max'] = max($stat['numeric']);
                }
                unset($stat['numeric']);
                $result['questions'][] = $stat;
            }
        }
        ksort($result['timeline']);
        return $result;
    }

    public function analytics(int $formId): array {
        $schema = $this->getSchema($formId);
        $responses = $this->responseRows($formId, 20000);
        return $this->analyticsFromRows($schema, $responses);
    }

    public function audit(int $formId, ?int $userId, string $event, array $detail): void {
        try {
            $this->db->prepare('INSERT INTO ah_form_audit(form_id,usuario_id,evento,detalle_json) VALUES(?,?,?,?)')
                ->execute([$formId,$userId,$event,form_json_encode($detail)]);
        } catch (Throwable $e) {}
    }

    private function sanitizeQuestionType(string $type): string {
        $allowed = ['short_text','paragraph','email','number','phone','multiple_choice','checkboxes','dropdown','linear_scale','rating','date','time','datetime','file_upload','multiple_choice_grid','checkbox_grid','geo_cascade','center_selector','consent','title_description','image','video'];
        return in_array($type,$allowed,true) ? $type : 'short_text';
    }

    private function nullableDateTime($value): ?string {
        $value = trim((string)$value);
        if ($value === '') return null;
        $ts = strtotime($value);
        return $ts ? date('Y-m-d H:i:s',$ts) : null;
    }
}
