<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/FormService.php';

// BUILD: RESPUESTAS_ESTADISTICAS_V6_2026-07-14
form_require_admin_auth($db);
form_assert_installed($db);

function rf_h(mixed $value): string {
    if ($value === null) return '';
    if (is_bool($value)) return $value ? 'Sí' : 'No';
    if (is_scalar($value)) return form_h((string)$value);
    return form_h(form_json_encode($value));
}
function rf_json(mixed $value): string {
    return json_encode($value, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?: 'null';
}
function rf_date(?string $value, string $format='d/m/Y H:i'): string {
    if (!$value) return '—';
    $ts = strtotime($value);
    return $ts === false ? rf_h($value) : date($format, $ts);
}
function rf_lower(string $value): string { return mb_strtolower(trim($value), 'UTF-8'); }
function rf_median(array $values): float {
    if (!$values) return 0.0;
    sort($values, SORT_NUMERIC);
    $count=count($values); $middle=intdiv($count,2);
    return $count%2 ? (float)$values[$middle] : ((float)$values[$middle-1]+(float)$values[$middle])/2;
}
function rf_answer_text(?array $answer, array $centerMap=[]): string {
    if (!$answer) return '';
    if (!empty($answer['file'])) return (string)$answer['file'];
    $json=$answer['json'] ?? null;
    if (is_array($json) && $json) {
        $parts=[];
        foreach($json as $key=>$value){
            if ($key==='centro' && isset($centerMap[(string)$value])) $value=$centerMap[(string)$value];
            if (is_array($value)) $value=implode(', ', array_map(static fn($v)=>is_scalar($v)?(string)$v:form_json_encode($v),$value));
            elseif (is_bool($value)) $value=$value?'Sí':'No';
            else $value=(string)$value;
            if ($value==='') continue;
            $parts[]=(is_string($key)&&!is_numeric($key)?ucfirst(str_replace('_',' ',$key)).': ':'').$value;
        }
        return implode(' · ',$parts);
    }
    return trim((string)($answer['text'] ?? ''));
}
function rf_count_add(array &$counter, string $label, int $amount=1): void {
    $label=trim($label); if($label==='') return;
    $counter[$label]=($counter[$label]??0)+$amount;
}
function rf_top(array $counter, int $limit=12): array {
    arsort($counter,SORT_NUMERIC); return array_slice($counter,0,$limit,true);
}
function rf_keywords(array $texts, int $limit=15): array {
    $stop=['para','como','pero','porque','desde','hasta','sobre','entre','este','esta','estos','estas','del','las','los','una','uno','unos','unas','que','con','sin','por','y','o','de','la','el','en','al','se','su','sus','es','son','fue','han','muy','más','mas','también','tambien','actividad','respuesta'];
    $stop=array_flip($stop);$counts=[];
    foreach($texts as $text){
        $clean=rf_lower((string)$text);
        $clean=preg_replace('/[^\p{L}\p{N}]+/u',' ',$clean)??'';
        foreach(preg_split('/\s+/u',$clean,-1,PREG_SPLIT_NO_EMPTY)?:[] as $word){
            if(mb_strlen($word,'UTF-8')<4||isset($stop[$word])||is_numeric($word))continue;
            $counts[$word]=($counts[$word]??0)+1;
        }
    }
    return rf_top($counts,$limit);
}
function rf_is_image_path(string $path): bool {
    return (bool)preg_match('/\.(jpe?g|jfif|png|gif|webp|avif|heic|heif|bmp|tiff?|ico)$/i',$path);
}

$service=new FormService($db);
$id=(int)($_GET['id']??0);
if($id<=0){http_response_code(400);exit('Formulario inválido.');}
$errorMessage='';
try{
    $schema=$service->getSchema($id);
    $form=$schema['form'];
    $responsesAll=$service->responseRows($id,20000);
}catch(Throwable $e){
    http_response_code(500);$errorMessage=$e->getMessage();
    $schema=['form'=>['titulo'=>'Respuestas del formulario','estado'=>'borrador','limite_respuestas'=>null],'sections'=>[]];
    $form=$schema['form'];$responsesAll=[];
}

$questions=[];
foreach(($schema['sections']??[]) as $section){
    foreach(($section['questions']??[]) as $question){
        if(!in_array(($question['tipo']??''),['title_description','image','video'],true))$questions[(int)$question['id']]=$question;
    }
}

$centerMap=[];$centerMeta=[];
try{
    $centerRows=$db->query("SELECT id,nombre,tipo,comunidad_base,caserio FROM ah_centros")->fetchAll(PDO::FETCH_ASSOC);
    foreach($centerRows as $c){$centerMap[(string)$c['id']]=(string)$c['nombre'];$centerMeta[(string)$c['id']]=$c;}
}catch(Throwable $e){}

$geoByResponse=[];$municipalityOptions=[];$centerOptions=[];
foreach($responsesAll as $r){
    $geo=['municipio'=>'','base'=>'','caserio'=>'','centro'=>'','centro_nombre'=>''];
    foreach($questions as $qid=>$q){
        if(!in_array($q['tipo']??'',['geo_cascade','center_selector'],true))continue;
        $a=$r['answers'][$qid]??null;$j=$a['json']??null;
        if(!is_array($j))continue;
        foreach(['municipio','base','caserio','centro'] as $level)if($geo[$level]===''&&!empty($j[$level]))$geo[$level]=(string)$j[$level];
    }
    if($geo['centro']!=='' && isset($centerMap[$geo['centro']]))$geo['centro_nombre']=$centerMap[$geo['centro']];
    elseif($geo['centro']!=='')$geo['centro_nombre']=$geo['centro'];
    $geoByResponse[(int)$r['id']]=$geo;
    if($geo['municipio']!=='')$municipalityOptions[$geo['municipio']]=$geo['municipio'];
    if($geo['centro']!=='')$centerOptions[$geo['centro']]=$geo['centro_nombre']?:$geo['centro'];
}
ksort($municipalityOptions,SORT_NATURAL|SORT_FLAG_CASE);asort($centerOptions,SORT_NATURAL|SORT_FLAG_CASE);

$dateFrom=trim((string)($_GET['desde']??''));
$dateTo=trim((string)($_GET['hasta']??''));
$municipioFilter=trim((string)($_GET['municipio']??''));
$centerFilter=trim((string)($_GET['centro']??''));
$search=trim((string)($_GET['buscar']??''));
$searchLower=rf_lower($search);

$responses=array_values(array_filter($responsesAll,function(array $r)use($dateFrom,$dateTo,$municipioFilter,$centerFilter,$searchLower,$geoByResponse,$questions,$centerMap):bool{
    $date=substr((string)($r['submitted_at']??''),0,10);
    if($dateFrom!==''&&$date<$dateFrom)return false;
    if($dateTo!==''&&$date>$dateTo)return false;
    $geo=$geoByResponse[(int)$r['id']]??[];
    if($municipioFilter!==''&&($geo['municipio']??'')!==$municipioFilter)return false;
    if($centerFilter!==''&&($geo['centro']??'')!==$centerFilter)return false;
    if($searchLower!==''){
        $haystack=rf_lower((string)($r['correo']??'').' '.(string)($r['nombre_respondiente']??''));
        foreach($questions as $qid=>$q)$haystack.=' '.rf_lower(rf_answer_text($r['answers'][$qid]??null,$centerMap));
        if(!str_contains($haystack,$searchLower))return false;
    }
    return true;
}));

$total=count($responses);$totalAll=count($responsesAll);$today=date('Y-m-d');$monthPrefix=date('Y-m');
$todayCount=0;$monthCount=0;$unique=[];$answeredCells=0;$fileCount=0;$timeline=[];$hours=array_fill(0,24,0);
$weekdayLabels=['Dom','Lun','Mar','Mié','Jue','Vie','Sáb'];$weekdays=array_fill_keys($weekdayLabels,0);$dates=[];
$geoCounters=['municipio'=>[],'base'=>[],'caserio'=>[],'centro'=>[]];
foreach($responses as $r){
    $submitted=(string)($r['submitted_at']??'');$date=substr($submitted,0,10);$dates[]=$date;
    if($date===$today)$todayCount++;if(str_starts_with($date,$monthPrefix))$monthCount++;
    $timeline[$date]=($timeline[$date]??0)+1;
    $ts=strtotime($submitted);if($ts!==false){$hours[(int)date('G',$ts)]++;$weekdays[$weekdayLabels[(int)date('w',$ts)]]++;}
    $identity=rf_lower((string)($r['correo']?:($r['nombre_respondiente']?:$r['token'])));if($identity!=='')$unique[$identity]=true;
    foreach($r['answers'] as $a){if(($a['text']??'')!==''||!empty($a['json'])||!empty($a['file']))$answeredCells++;if(!empty($a['file']))$fileCount++;}
    $geo=$geoByResponse[(int)$r['id']]??[];
    foreach(['municipio','base','caserio'] as $level)rf_count_add($geoCounters[$level],(string)($geo[$level]??''));
    rf_count_add($geoCounters['centro'],(string)($geo['centro_nombre']??''));
}
ksort($timeline);
$possibleCells=$total*max(1,count($questions));$completionPct=$possibleCells?round($answeredCells/$possibleCells*100,1):0;
$activeDays=count(array_filter($timeline));$avgDaily=$activeDays?round($total/$activeDays,1):0;
$lastResponse=$responses[0]['submitted_at']??null;$firstResponse=$responses?end($responses)['submitted_at']:null;
$limit=(int)($form['limite_respuestas']??0);$limitPct=$limit?min(100,round($totalAll/$limit*100,1)):null;
$last7=0;$prev7=0;$todayTs=strtotime($today);
foreach($timeline as $date=>$count){$diff=(int)floor(($todayTs-strtotime($date))/86400);if($diff>=0&&$diff<=6)$last7+=$count;elseif($diff>=7&&$diff<=13)$prev7+=$count;}
$trendPct=$prev7>0?round(($last7-$prev7)/$prev7*100,1):($last7>0?100:0);

$questionStats=[];$geoQuestionIds=[];
$categoricalTypes=['multiple_choice','checkboxes','dropdown','linear_scale','rating','consent','multiple_choice_grid','checkbox_grid'];
foreach($questions as $qid=>$q){
    $stat=['id'=>$qid,'titulo'=>$q['titulo'],'tipo'=>$q['tipo'],'answered'=>0,'missing'=>0,'values'=>[],'numeric'=>[],'texts'=>[],'files'=>[],'geo'=>['municipio'=>[],'base'=>[],'caserio'=>[],'centro'=>[]]];
    foreach($responses as $r){
        $a=$r['answers'][$qid]??null;
        if(!$a||((string)($a['text']??'')===''&&empty($a['json'])&&empty($a['file']))){$stat['missing']++;continue;}
        $stat['answered']++;
        if(!empty($a['file'])){$stat['files'][]=['path'=>$a['file'],'response_id'=>$r['id'],'date'=>$r['submitted_at']];continue;}
        $json=$a['json']??null;
        if(in_array($q['tipo'],['geo_cascade','center_selector'],true)&&is_array($json)){
            $geoQuestionIds[]=$qid;
            foreach(['municipio','base','caserio','centro'] as $level){
                $v=(string)($json[$level]??'');if($level==='centro'&&isset($centerMap[$v]))$v=$centerMap[$v];rf_count_add($stat['geo'][$level],$v);
            }
            continue;
        }
        $values=[];
        if(is_array($json)&&$json){
            foreach($json as $key=>$value){
                if(is_array($value)){foreach($value as $subKey=>$subValue)$values[]=(is_string($subKey)?$subKey.': ':'').(string)$subValue;}
                else $values[]=(is_string($key)&&!is_numeric($key)?$key.': ':'').(string)$value;
            }
        }elseif(($a['text']??'')!=='')$values[]=(string)$a['text'];
        foreach($values as $value){$value=trim($value);if($value==='')continue;if($q['tipo']==='number'||is_numeric($value))$stat['numeric'][]=(float)$value;if(in_array($q['tipo'],$categoricalTypes,true))rf_count_add($stat['values'],$value);else $stat['texts'][]=$value;}
    }
    if($stat['numeric']){$stat['sum']=array_sum($stat['numeric']);$stat['average']=$stat['sum']/count($stat['numeric']);$stat['median']=rf_median($stat['numeric']);$stat['min']=min($stat['numeric']);$stat['max']=max($stat['numeric']);}
    $stat['values']=rf_top($stat['values'],30);$stat['keywords']=rf_keywords($stat['texts']);
    foreach($stat['geo'] as $level=>$counter)$stat['geo'][$level]=rf_top($counter,30);
    $questionStats[$qid]=$stat;
}
$geoQuestionIds=array_values(array_unique($geoQuestionIds));

$peakDate='';$peakCount=0;foreach($timeline as $date=>$count)if($count>$peakCount){$peakDate=$date;$peakCount=$count;}
$topMunicipio=key(rf_top($geoCounters['municipio'],1))?:'Sin datos';
$topCentro=key(rf_top($geoCounters['centro'],1))?:'Sin datos';
$mostMissing=null;foreach($questionStats as $s)if($mostMissing===null||$s['missing']>$mostMissing['missing'])$mostMissing=$s;

$exportRows=[];
foreach($responses as $r){
    $geo=$geoByResponse[(int)$r['id']]??[];
    $row=['ID'=>$r['id'],'Fecha'=>$r['submitted_at']??'','Correo'=>$r['correo']??'','Respondiente'=>$r['nombre_respondiente']??'','Municipio'=>$geo['municipio']??'','Comunidad base'=>$geo['base']??'','Caserío'=>$geo['caserio']??'','Centro'=>$geo['centro_nombre']??''];
    foreach($questions as $qid=>$q)$row[(string)$q['titulo']]=rf_answer_text($r['answers'][$qid]??null,$centerMap);
    $exportRows[]=$row;
}

$filtersActive=$dateFrom!==''||$dateTo!==''||$municipioFilter!==''||$centerFilter!==''||$search!=='';
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Analítica de respuestas | <?=rf_h($form['titulo']??'')?></title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<link rel="stylesheet" href="../assets/formularios.css?v=20260714-6">
<script src="https://unpkg.com/chart.js@4.4.2/dist/chart.umd.js"></script>
<script src="https://unpkg.com/xlsx@0.18.5/dist/xlsx.full.min.js"></script>
<style>
.chart-load-warning{min-height:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;gap:8px;padding:24px;color:#991b1b;background:#fff7f7;border:1px dashed #fca5a5;border-radius:12px}.chart-load-warning i{font-size:1.6rem}.chart-load-warning strong{font-size:.95rem}.chart-load-warning span{font-size:.82rem;color:#64748b;max-width:460px}
</style></head>
<body><div class="forms-shell"><?php $sidebar=__DIR__.'/sidebar.php';if(is_file($sidebar))include $sidebar; ?>
<main class="forms-main response-dashboard">
<?php if($errorMessage!==''):?><div class="alert alert-error"><strong>No fue posible cargar las respuestas.</strong><div><?=rf_h($errorMessage)?></div></div><?php endif;?>
<header class="response-hero">
  <div><a class="response-back" href="formularios.php"><i class="fa-solid fa-arrow-left"></i> Formularios</a><div class="response-kicker">Centro de análisis</div><h1><?=rf_h($form['titulo']??'')?></h1><p>Indicadores, tendencias, distribución geográfica, análisis por pregunta y base completa de respuestas.</p></div>
  <div class="response-hero-actions"><a class="btn" target="_blank" href="../formularios/responder.php?f=<?=urlencode((string)($form['slug']??''))?>"><i class="fa-solid fa-arrow-up-right-from-square"></i> Abrir formulario</a><a class="btn" href="editor_formulario.php?id=<?=$id?>"><i class="fa-solid fa-pen"></i> Editar</a><button id="exportXlsx" class="btn btn-green"><i class="fa-solid fa-file-excel"></i> Exportar XLSX</button></div>
</header>

<form class="card response-filters" method="get"><input type="hidden" name="id" value="<?=$id?>"><div class="response-filter-grid">
  <div class="field"><label>Desde</label><input class="input" type="date" name="desde" value="<?=rf_h($dateFrom)?>"></div>
  <div class="field"><label>Hasta</label><input class="input" type="date" name="hasta" value="<?=rf_h($dateTo)?>"></div>
  <div class="field"><label>Municipio</label><select class="select" name="municipio"><option value="">Todos</option><?php foreach($municipalityOptions as $value):?><option value="<?=rf_h($value)?>" <?=$municipioFilter===$value?'selected':''?>><?=rf_h($value)?></option><?php endforeach;?></select></div>
  <div class="field"><label>Centro</label><select class="select" name="centro"><option value="">Todos</option><?php foreach($centerOptions as $value=>$label):?><option value="<?=rf_h($value)?>" <?=$centerFilter===(string)$value?'selected':''?>><?=rf_h($label)?></option><?php endforeach;?></select></div>
  <div class="field response-search"><label>Buscar en respuestas</label><input class="input" name="buscar" value="<?=rf_h($search)?>" placeholder="Docente, correo, centro o respuesta..."></div>
  <div class="response-filter-actions"><button class="btn btn-primary" type="submit"><i class="fa-solid fa-filter"></i> Aplicar</button><?php if($filtersActive):?><a class="btn" href="?id=<?=$id?>"><i class="fa-solid fa-xmark"></i> Limpiar</a><?php endif;?></div>
</div></form>

<section class="response-metrics-grid">
  <article class="response-metric primary"><div class="response-metric-icon"><i class="fa-solid fa-inbox"></i></div><div><span>Respuestas analizadas</span><strong><?=number_format($total)?></strong><small><?=$filtersActive?'de '.number_format($totalAll).' totales':'Total acumulado'?></small></div></article>
  <article class="response-metric"><div class="response-metric-icon"><i class="fa-solid fa-calendar-day"></i></div><div><span>Este mes</span><strong><?=number_format($monthCount)?></strong><small><?=number_format($todayCount)?> recibidas hoy</small></div></article>
  <article class="response-metric"><div class="response-metric-icon"><i class="fa-solid fa-users"></i></div><div><span>Respondientes únicos</span><strong><?=number_format(count($unique))?></strong><small>Por correo, nombre o token</small></div></article>
  <article class="response-metric"><div class="response-metric-icon"><i class="fa-solid fa-list-check"></i></div><div><span>Completitud</span><strong><?=number_format($completionPct,1)?>%</strong><small>Campos respondidos</small></div></article>
  <article class="response-metric"><div class="response-metric-icon"><i class="fa-solid fa-camera"></i></div><div><span>Evidencias adjuntas</span><strong><?=number_format($fileCount)?></strong><small>Archivos y fotografías</small></div></article>
  <article class="response-metric"><div class="response-metric-icon"><i class="fa-solid fa-chart-line"></i></div><div><span>Ritmo diario</span><strong><?=number_format($avgDaily,1)?></strong><small><?=$trendPct>=0?'+':''?><?=number_format($trendPct,1)?>% vs. semana anterior</small></div></article>
</section>
<?php if($limit):?><div class="card response-limit"><div><b>Uso del límite de respuestas</b><span><?=number_format($totalAll)?> de <?=number_format($limit)?></span></div><div class="response-limit-bar"><span style="width:<?=$limitPct?>%"></span></div><strong><?=number_format((float)$limitPct,1)?>%</strong></div><?php endif;?>

<nav class="response-tabs" aria-label="Vistas de respuestas">
  <button class="response-tab active" data-tab="general"><i class="fa-solid fa-chart-pie"></i> Resumen ejecutivo</button>
  <button class="response-tab" data-tab="preguntas"><i class="fa-solid fa-square-poll-vertical"></i> Por pregunta</button>
  <button class="response-tab" data-tab="geografia"><i class="fa-solid fa-map-location-dot"></i> Geografía</button>
  <button class="response-tab" data-tab="evidencias"><i class="fa-solid fa-images"></i> Evidencias</button>
  <button class="response-tab" data-tab="individuales"><i class="fa-solid fa-address-card"></i> Individuales</button>
  <button class="response-tab" data-tab="datos"><i class="fa-solid fa-table"></i> Base de datos</button>
</nav>

<section class="response-tab-panel active" data-panel="general">
  <div class="response-grid response-grid-2">
    <article class="card response-chart-card wide"><div class="response-card-head"><div><span class="eyebrow">Tendencia</span><h2>Respuestas en el tiempo</h2></div><div class="response-card-meta"><?=rf_date($firstResponse,'d/m/Y')?> — <?=rf_date($lastResponse,'d/m/Y')?></div></div><div class="chart-height-lg"><canvas id="timelineChart"></canvas></div></article>
    <article class="card response-insights"><div class="response-card-head"><div><span class="eyebrow">Lectura rápida</span><h2>Hallazgos automáticos</h2></div></div><div class="insight-list">
      <div class="insight"><i class="fa-solid fa-calendar-check"></i><div><b>Día de mayor recepción</b><span><?=$peakDate?rf_date($peakDate,'d/m/Y').' · '.number_format($peakCount).' respuestas':'Aún no hay datos'?></span></div></div>
      <div class="insight"><i class="fa-solid fa-location-dot"></i><div><b>Mayor participación territorial</b><span><?=rf_h($topMunicipio)?></span></div></div>
      <div class="insight"><i class="fa-solid fa-school"></i><div><b>Centro con más registros</b><span><?=rf_h($topCentro)?></span></div></div>
      <div class="insight"><i class="fa-solid fa-triangle-exclamation"></i><div><b>Pregunta con más omisiones</b><span><?=rf_h((string)($mostMissing['titulo']??'Sin datos'))?> · <?=number_format((int)($mostMissing['missing']??0))?></span></div></div>
      <div class="insight"><i class="fa-solid fa-clock"></i><div><b>Última respuesta</b><span><?=rf_date($lastResponse)?></span></div></div>
    </div></article>
  </div>
  <div class="response-grid response-grid-3">
    <article class="card response-chart-card"><div class="response-card-head"><div><span class="eyebrow">Comportamiento</span><h2>Por día de la semana</h2></div></div><div class="chart-height"><canvas id="weekdayChart"></canvas></div></article>
    <article class="card response-chart-card"><div class="response-card-head"><div><span class="eyebrow">Horario</span><h2>Hora de envío</h2></div></div><div class="chart-height"><canvas id="hourChart"></canvas></div></article>
    <article class="card response-chart-card"><div class="response-card-head"><div><span class="eyebrow">Cobertura</span><h2>Municipios</h2></div></div><div class="chart-height"><canvas id="municipalitySummaryChart"></canvas></div></article>
  </div>
  <div class="card response-summary-table"><div class="response-card-head"><div><span class="eyebrow">Calidad de datos</span><h2>Resumen de preguntas</h2></div></div><div class="table-wrap"><table class="responses-table compact"><thead><tr><th>Pregunta</th><th>Tipo</th><th>Respondidas</th><th>Omitidas</th><th>Cobertura</th><th>Lectura principal</th></tr></thead><tbody><?php foreach($questionStats as $stat):$coverage=$total?round($stat['answered']/$total*100,1):0;$main='—';if(isset($stat['average']))$main='Promedio '.number_format((float)$stat['average'],2);elseif($stat['values'])$main=(string)array_key_first($stat['values']).' ('.number_format((int)reset($stat['values'])).')';elseif($stat['geo']['municipio'])$main=(string)array_key_first($stat['geo']['municipio']);elseif($stat['texts'])$main=mb_strimwidth((string)$stat['texts'][0],0,70,'…','UTF-8');elseif($stat['files'])$main=number_format(count($stat['files'])).' archivos';?><tr><td><b><?=rf_h($stat['titulo'])?></b></td><td><?=rf_h($stat['tipo'])?></td><td><?=number_format($stat['answered'])?></td><td><?=number_format($stat['missing'])?></td><td><div class="mini-progress"><span style="width:<?=$coverage?>%"></span></div><?=number_format($coverage,1)?>%</td><td><?=rf_h($main)?></td></tr><?php endforeach;?></tbody></table></div></div>
</section>

<section class="response-tab-panel" data-panel="preguntas"><div class="question-analysis-grid">
<?php foreach($questionStats as $qid=>$stat):?><article class="card question-analysis-card" id="question-<?=$qid?>"><div class="response-card-head"><div><span class="eyebrow"><?=rf_h($stat['tipo'])?></span><h2><?=rf_h($stat['titulo'])?></h2></div><span class="response-count-chip"><?=number_format($stat['answered'])?> / <?=number_format($total)?></span></div>
<?php if(isset($stat['average'])):?><div class="numeric-stat-grid"><div><span>Suma</span><strong><?=number_format((float)$stat['sum'],2)?></strong></div><div><span>Promedio</span><strong><?=number_format((float)$stat['average'],2)?></strong></div><div><span>Mediana</span><strong><?=number_format((float)$stat['median'],2)?></strong></div><div><span>Mínimo</span><strong><?=number_format((float)$stat['min'],2)?></strong></div><div><span>Máximo</span><strong><?=number_format((float)$stat['max'],2)?></strong></div></div><div class="chart-height"><canvas class="numeric-histogram" data-values='<?=rf_h(rf_json($stat['numeric']))?>'></canvas></div>
<?php elseif($stat['values']):?><div class="chart-height"><canvas class="distribution-chart" data-labels='<?=rf_h(rf_json(array_keys($stat['values'])))?>' data-values='<?=rf_h(rf_json(array_values($stat['values'])))?>'></canvas></div><div class="ranking-list"><?php $rank=0;foreach(array_slice($stat['values'],0,5,true) as $label=>$count):$rank++;$pct=$stat['answered']?round($count/$stat['answered']*100,1):0;?><div><span class="rank-number"><?=$rank?></span><span class="rank-label"><?=rf_h($label)?></span><b><?=number_format($count)?> · <?=$pct?>%</b></div><?php endforeach;?></div>
<?php elseif(in_array($stat['tipo'],['geo_cascade','center_selector'],true)):?><div class="geo-mini-grid"><?php foreach(['municipio'=>'Municipios','base'=>'Comunidades base','caserio'=>'Caseríos','centro'=>'Centros'] as $level=>$label):?><div><h4><?=$label?></h4><div class="ranking-list"><?php $rank=0;foreach(array_slice($stat['geo'][$level],0,6,true) as $value=>$count):$rank++;?><div><span class="rank-number"><?=$rank?></span><span class="rank-label"><?=rf_h($value)?></span><b><?=number_format($count)?></b></div><?php endforeach;?><?php if(!$stat['geo'][$level]):?><div class="empty-inline">Sin datos</div><?php endif;?></div></div><?php endforeach;?></div>
<?php elseif($stat['files']):?><div class="file-question-summary"><i class="fa-solid fa-paperclip"></i><strong><?=number_format(count($stat['files']))?></strong><span>archivos recibidos</span></div>
<?php else:?><div class="text-analysis"><div class="keyword-cloud"><?php foreach($stat['keywords'] as $word=>$count):?><span style="--weight:<?=min(8,2+$count)?>"><?=rf_h($word)?> <small><?=$count?></small></span><?php endforeach;?><?php if(!$stat['keywords']):?><span class="empty-inline">Sin palabras suficientes para analizar.</span><?php endif;?></div><h4>Respuestas recientes</h4><div class="text-answer-list"><?php foreach(array_slice(array_reverse($stat['texts']),0,8) as $text):?><blockquote><?=nl2br(rf_h(mb_strimwidth($text,0,420,'…','UTF-8')))?></blockquote><?php endforeach;?><?php if(!$stat['texts']):?><div class="empty-inline">Sin respuestas de texto.</div><?php endif;?></div></div><?php endif;?>
<div class="question-footer"><span><?=number_format($stat['missing'])?> omitidas</span><span><?=number_format($total?($stat['answered']/$total*100):0,1)?>% de cobertura</span></div></article><?php endforeach;?>
<?php if(!$questionStats):?><div class="card empty">El formulario no contiene preguntas analizables.</div><?php endif;?></div></section>

<section class="response-tab-panel" data-panel="geografia"><div class="response-grid response-grid-2">
<?php foreach(['municipio'=>'Municipios','base'=>'Comunidades base','caserio'=>'Caseríos','centro'=>'Centros'] as $level=>$label):$data=rf_top($geoCounters[$level],25);?><article class="card response-chart-card"><div class="response-card-head"><div><span class="eyebrow">Distribución territorial</span><h2><?=$label?></h2></div><span class="response-count-chip"><?=number_format(count($geoCounters[$level]))?> distintos</span></div><div class="chart-height-lg"><canvas class="geo-chart" data-level="<?=$level?>" data-labels='<?=rf_h(rf_json(array_keys($data)))?>' data-values='<?=rf_h(rf_json(array_values($data)))?>'></canvas></div></article><?php endforeach;?></div></section>

<section class="response-tab-panel" data-panel="evidencias"><div class="evidence-toolbar"><div><h2>Evidencias y archivos</h2><p><?=number_format($fileCount)?> adjuntos encontrados en las respuestas filtradas.</p></div><input id="evidenceSearch" class="input" placeholder="Buscar por pregunta o fecha..."></div><div class="evidence-grid" id="evidenceGrid">
<?php foreach($responses as $r):foreach($questions as $qid=>$q):$a=$r['answers'][$qid]??null;if(empty($a['file']))continue;$path=(string)$a['file'];?><article class="card evidence-card" data-search="<?=rf_h(rf_lower($q['titulo'].' '.($r['submitted_at']??'').' '.$path))?>"><?php if(rf_is_image_path($path)):?><a target="_blank" href="../<?=rf_h($path)?>"><img src="../<?=rf_h($path)?>" loading="lazy" alt="<?=rf_h($q['titulo'])?>"></a><?php else:?><a class="file-placeholder" target="_blank" href="../<?=rf_h($path)?>"><i class="fa-solid fa-file"></i></a><?php endif;?><div><b><?=rf_h($q['titulo'])?></b><span>Respuesta #<?=number_format((int)$r['id'])?> · <?=rf_date($r['submitted_at']??null)?></span><a target="_blank" href="../<?=rf_h($path)?>">Abrir archivo <i class="fa-solid fa-arrow-up-right-from-square"></i></a></div></article><?php endforeach;endforeach;?><?php if(!$fileCount):?><div class="card empty">No hay archivos en las respuestas filtradas.</div><?php endif;?></div></section>

<section class="response-tab-panel" data-panel="individuales"><div class="individual-toolbar"><div><h2>Respuestas individuales</h2><p>Detalle completo de cada envío.</p></div><input id="individualSearch" class="input" placeholder="Buscar respondiente o contenido..."></div><div class="individual-response-list" id="individualList">
<?php foreach(array_slice($responses,0,500) as $index=>$r):$geo=$geoByResponse[(int)$r['id']]??[];$searchBlob=rf_lower(($r['correo']??'').' '.($r['nombre_respondiente']??'').' '.implode(' ',array_map(fn($qid)=>rf_answer_text($r['answers'][$qid]??null,$centerMap),array_keys($questions))));?><details class="card individual-response" data-search="<?=rf_h($searchBlob)?>" <?=$index===0?'open':''?>><summary><div><span class="response-id">#<?=number_format((int)$r['id'])?></span><b><?=rf_h($r['nombre_respondiente']?:($r['correo']?:'Respuesta anónima'))?></b><small><?=rf_date($r['submitted_at']??null)?></small></div><div class="individual-location"><i class="fa-solid fa-location-dot"></i> <?=rf_h(($geo['municipio']??'')?:'Sin ubicación')?><?=!empty($geo['centro_nombre'])?' · '.rf_h($geo['centro_nombre']):''?></div><i class="fa-solid fa-chevron-down"></i></summary><div class="individual-answer-grid"><?php foreach($questions as $qid=>$q):$a=$r['answers'][$qid]??null;?><div class="individual-answer"><span><?=rf_h($q['titulo'])?></span><?php if(!$a):?><em>Sin respuesta</em><?php elseif(!empty($a['file'])):?><a target="_blank" href="../../<?=rf_h($a['file'])?>"><i class="fa-solid fa-paperclip"></i> Abrir archivo</a><?php else:?><b><?=nl2br(rf_h(rf_answer_text($a,$centerMap)))?></b><?php endif;?></div><?php endforeach;?></div></details><?php endforeach;?><?php if(!$responses):?><div class="card empty">Aún no hay respuestas.</div><?php endif;?></div><?php if(count($responses)>500):?><div class="response-note">Se muestran las 500 respuestas más recientes. La exportación XLSX incluye las <?=number_format($total)?> respuestas filtradas.</div><?php endif;?></section>

<section class="response-tab-panel" data-panel="datos"><div class="data-toolbar"><div><h2>Base consolidada</h2><p>Vista paginada. La exportación incluye todas las filas filtradas.</p></div><div><input id="tableSearch" class="input" placeholder="Buscar en la tabla..."><select id="pageSize" class="select"><option value="25">25 filas</option><option value="50" selected>50 filas</option><option value="100">100 filas</option><option value="250">250 filas</option></select></div></div><div class="card table-wrap response-data-table"><table class="responses-table" id="responsesTable"><thead></thead><tbody></tbody></table></div><div class="table-pagination"><span id="tableInfo"></span><div><button id="prevPage" class="btn btn-sm"><i class="fa-solid fa-chevron-left"></i></button><span id="pageLabel"></span><button id="nextPage" class="btn btn-sm"><i class="fa-solid fa-chevron-right"></i></button></div></div></section>
</main></div>
<script>
if (typeof Chart === 'undefined') {
    document.querySelectorAll('canvas').forEach((canvas) => {
        const box = canvas.parentElement;
        if (!box) return;
        canvas.style.display = 'none';
        const warning = document.createElement('div');
        warning.className = 'chart-load-warning';
        warning.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i><strong>No se pudo iniciar el componente local de gráficos.</strong><span>No se encontró el archivo local assets/chart-lite.js. Verifique que haya sido copiado junto con este archivo.</span>';
        box.appendChild(warning);
    });
}
const DATA={timeline:<?=rf_json($timeline)?>,weekdays:<?=rf_json($weekdays)?>,hours:<?=rf_json(array_values($hours))?>,municipalities:<?=rf_json(rf_top($geoCounters['municipio'],12))?>,exportRows:<?=rf_json($exportRows)?>};
const palette=['#34859B','#46b094','#7aaec0','#a8d8d4','#f3bd5d','#ec8f6a','#8b9dc3','#9b8fc2','#7db48f','#d98373','#6da5d1','#b5a46c'];
function chartSafe(canvas,config){if(!canvas||typeof Chart==='undefined')return null;return new Chart(canvas,config)}
const timelineLabels=Object.keys(DATA.timeline),timelineValues=Object.values(DATA.timeline);let running=0;const cumulative=timelineValues.map(v=>running+=Number(v));
chartSafe(document.getElementById('timelineChart'),{type:'line',data:{labels:timelineLabels,datasets:[{label:'Respuestas por día',data:timelineValues,borderColor:'#34859B',backgroundColor:'rgba(52,133,155,.14)',fill:true,tension:.3,yAxisID:'y'},{label:'Acumulado',data:cumulative,borderColor:'#46b094',backgroundColor:'#46b094',tension:.25,yAxisID:'y1'}]},options:{responsive:true,maintainAspectRatio:false,interaction:{mode:'index',intersect:false},plugins:{legend:{position:'bottom'}},scales:{y:{beginAtZero:true,ticks:{precision:0}},y1:{beginAtZero:true,position:'right',grid:{drawOnChartArea:false},ticks:{precision:0}}}}});
chartSafe(document.getElementById('weekdayChart'),{type:'bar',data:{labels:Object.keys(DATA.weekdays),datasets:[{data:Object.values(DATA.weekdays),backgroundColor:'#34859B',borderRadius:8}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});
chartSafe(document.getElementById('hourChart'),{type:'line',data:{labels:Array.from({length:24},(_,i)=>String(i).padStart(2,'0')+':00'),datasets:[{data:DATA.hours,borderColor:'#46b094',backgroundColor:'rgba(70,176,148,.14)',fill:true,tension:.35}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false},ticks:{maxRotation:0,autoSkip:true,maxTicksLimit:8}}}}});
chartSafe(document.getElementById('municipalitySummaryChart'),{type:'doughnut',data:{labels:Object.keys(DATA.municipalities),datasets:[{data:Object.values(DATA.municipalities),backgroundColor:palette}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
document.querySelectorAll('.distribution-chart').forEach((el,i)=>{const labels=JSON.parse(el.dataset.labels||'[]'),values=JSON.parse(el.dataset.values||'[]');chartSafe(el,{type:labels.length>6?'bar':'doughnut',data:{labels,datasets:[{data:values,backgroundColor:palette}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:labels.length>10?'y':'x',plugins:{legend:{display:labels.length<=6,position:'bottom'}},scales:labels.length>6?{x:{beginAtZero:true,ticks:{precision:0}},y:{grid:{display:false}}}:{}}});});
document.querySelectorAll('.numeric-histogram').forEach(el=>{const values=JSON.parse(el.dataset.values||'[]').map(Number).filter(Number.isFinite);if(!values.length)return;const min=Math.min(...values),max=Math.max(...values),bins=Math.min(10,Math.max(4,Math.ceil(Math.sqrt(values.length)))),size=(max-min||1)/bins,counts=Array(bins).fill(0),labels=[];for(let i=0;i<bins;i++)labels.push(`${(min+i*size).toFixed(1)}–${(min+(i+1)*size).toFixed(1)}`);values.forEach(v=>counts[Math.min(bins-1,Math.floor((v-min)/size))]++);chartSafe(el,{type:'bar',data:{labels,datasets:[{data:counts,backgroundColor:'#34859B',borderRadius:7}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{precision:0}},x:{grid:{display:false}}}}});});
document.querySelectorAll('.geo-chart').forEach(el=>{const labels=JSON.parse(el.dataset.labels||'[]'),values=JSON.parse(el.dataset.values||'[]');chartSafe(el,{type:'bar',data:{labels,datasets:[{data:values,backgroundColor:palette,borderRadius:7}]},options:{responsive:true,maintainAspectRatio:false,indexAxis:'y',plugins:{legend:{display:false}},scales:{x:{beginAtZero:true,ticks:{precision:0}},y:{grid:{display:false}}}}});});

document.querySelectorAll('.response-tab').forEach(btn=>btn.addEventListener('click',()=>{document.querySelectorAll('.response-tab').forEach(x=>x.classList.remove('active'));document.querySelectorAll('.response-tab-panel').forEach(x=>x.classList.remove('active'));btn.classList.add('active');document.querySelector(`[data-panel="${btn.dataset.tab}"]`)?.classList.add('active');setTimeout(()=>window.dispatchEvent(new Event('resize')),50);}));
const individualSearch=document.getElementById('individualSearch');individualSearch?.addEventListener('input',()=>{const q=individualSearch.value.trim().toLocaleLowerCase('es');document.querySelectorAll('.individual-response').forEach(el=>el.hidden=q&&!el.dataset.search.includes(q));});
const evidenceSearch=document.getElementById('evidenceSearch');evidenceSearch?.addEventListener('input',()=>{const q=evidenceSearch.value.trim().toLocaleLowerCase('es');document.querySelectorAll('.evidence-card').forEach(el=>el.hidden=q&&!el.dataset.search.includes(q));});

let tablePage=1,tableQuery='';function filteredTableRows(){if(!tableQuery)return DATA.exportRows;return DATA.exportRows.filter(row=>Object.values(row).join(' ').toLocaleLowerCase('es').includes(tableQuery));}
function renderDataTable(){const rows=filteredTableRows(),size=Number(document.getElementById('pageSize').value||50),pages=Math.max(1,Math.ceil(rows.length/size));tablePage=Math.min(tablePage,pages);const start=(tablePage-1)*size,slice=rows.slice(start,start+size),table=document.getElementById('responsesTable'),headers=DATA.exportRows[0]?Object.keys(DATA.exportRows[0]):[];table.querySelector('thead').innerHTML='<tr>'+headers.map(h=>`<th>${escapeHtml(h)}</th>`).join('')+'</tr>';table.querySelector('tbody').innerHTML=slice.map(row=>'<tr>'+headers.map(h=>`<td>${escapeHtml(row[h]??'')}</td>`).join('')+'</tr>').join('');document.getElementById('tableInfo').textContent=`Mostrando ${rows.length?start+1:0}–${Math.min(start+size,rows.length)} de ${rows.length}`;document.getElementById('pageLabel').textContent=`Página ${tablePage} de ${pages}`;document.getElementById('prevPage').disabled=tablePage<=1;document.getElementById('nextPage').disabled=tablePage>=pages;}
function escapeHtml(v){return String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));}
document.getElementById('tableSearch').addEventListener('input',e=>{tableQuery=e.target.value.trim().toLocaleLowerCase('es');tablePage=1;renderDataTable();});document.getElementById('pageSize').addEventListener('change',()=>{tablePage=1;renderDataTable();});document.getElementById('prevPage').addEventListener('click',()=>{tablePage--;renderDataTable();});document.getElementById('nextPage').addEventListener('click',()=>{tablePage++;renderDataTable();});renderDataTable();
document.getElementById('exportXlsx').addEventListener('click',()=>{const wb=XLSX.utils.book_new();const ws=XLSX.utils.json_to_sheet(DATA.exportRows);XLSX.utils.book_append_sheet(wb,ws,'Respuestas');XLSX.writeFile(wb,<?=rf_json(form_slugify((string)($form['titulo']??'formulario')).'-respuestas.xlsx')?>);});
</script></body></html>
