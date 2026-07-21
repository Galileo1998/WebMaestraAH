<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$auth = new Auth($db);
$auth->requireLogin();

$msg = '';
$self = basename($_SERVER['PHP_SELF']);

register_shutdown_function(function () {
    $error = error_get_last();
    if (!$error) return;
    $fatalTypes = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
    if (!in_array($error['type'], $fatalTypes, true)) return;
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<div style="font-family:Arial,sans-serif;margin:30px;padding:20px;border:1px solid #fecaca;background:#fef2f2;color:#991b1b;border-radius:10px">';
    echo '<h2 style="margin-top:0">Error al cargar el módulo de compras</h2>';
    echo '<p><strong>Detalle:</strong> '.htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8').'</p>';
    echo '<p><strong>Archivo:</strong> '.htmlspecialchars($error['file'], ENT_QUOTES, 'UTF-8').' · línea '.(int)$error['line'].'</p>';
    echo '</div>';
});

define('COMPRAS_ADMIN_DIR', __DIR__);
require_once __DIR__ . '/includes/compras_helpers.php';
require_once __DIR__ . '/includes/catalogo_cuentas_helpers.php';
ensureAccountingCatalog($db);

// El almacenamiento A/B/C se resuelve en cascada: columna existente, tabla auxiliar,
// archivo local y finalmente sesión. La carga normal no ejecuta DDL ni depende de /tmp.
$compras_formato_store_mode = tableExists($db, 'ah_compras_formatos') ? 'table' : (comprasFormatoFilePath() !== '' ? 'file' : 'session');
$provider_profile_ready = tableExists($db, 'ah_proveedores_perfil');

// Inicialización liviana y aislada de componentes auxiliares. Solo crea tablas faltantes; no altera tablas existentes.
$compras_components = [
    'quotes' => tableExists($db, 'ah_compras_cotizaciones') && tableExists($db, 'ah_compras_resumen_filas') && tableExists($db, 'ah_compras_cotizacion_precios'),
    'plans' => tableExists($db, 'ah_compras_planillas') && tableExists($db, 'ah_compras_planilla_detalles'),
    'reception' => tableExists($db, 'ah_compras_recepcion_detalles'),
    'errors' => []
];
$quotes_component_db_ready = !empty($compras_components['quotes']);
$quotes_component_fallback_ready = comprasCotizacionesFilePath() !== '' || session_status() === PHP_SESSION_ACTIVE;
$quotes_component_ready = $quotes_component_db_ready || $quotes_component_fallback_ready;
$plans_component_ready = !empty($compras_components['plans']);
$reception_component_ready = !empty($compras_components['reception']);
$poa_movements_ready = poaMovementsAvailable($db);
$poa_execution_fallback_ready = ensurePoaExecutionFallback($db);
$poa_execution_ledger_ready = tableExists($db, 'ah_compras_ejecuciones');

/* ========================================================
   MIGRACIÓN VERSIONADA MANUAL
   ======================================================== */
function ensureComprasSchema(PDO $db) {
    $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_schema (id TINYINT PRIMARY KEY, version INT NOT NULL DEFAULT 0, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
    $db->exec("INSERT IGNORE INTO ah_compras_schema (id,version) VALUES (1,0)");
    $version = (int)$db->query('SELECT version FROM ah_compras_schema WHERE id=1')->fetchColumn();
    if ($version >= 5) return;

    // MySQL confirma implícitamente las sentencias DDL (CREATE/ALTER).
    // No se usa una transacción aquí porque commit() después de un DDL puede
    // lanzar "There is no active transaction" en algunas versiones de PDO.
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fecha DATE NOT NULL,
            solicitante VARCHAR(255) NOT NULL,
            descripcion_actividad TEXT,
            estado VARCHAR(50) DEFAULT '1_Solicitud',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            cantidad DECIMAL(12,2) NOT NULL DEFAULT 0,
            presentacion VARCHAR(100),
            articulo VARCHAR(255) NOT NULL,
            caracteristicas VARCHAR(500),
            tipo_impuesto VARCHAR(1) DEFAULT 'G',
            precio_unitario DECIMAL(15,2) DEFAULT 0,
            precio_total DECIMAL(15,2) DEFAULT 0,
            INDEX(compra_id),
            CONSTRAINT fk_compra_detalle FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_proveedores (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nombre VARCHAR(255) NOT NULL UNIQUE,
            rtn VARCHAR(50), banco VARCHAR(100), tipo_cuenta VARCHAR(50), cuenta_bancaria VARCHAR(100)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_poa (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            poa_hash VARCHAR(64) NOT NULL,
            sector TEXT, sub_sector TEXT, marco_logico TEXT, ext VARCHAR(50),
            fuente_financiamiento TEXT, cuenta_contable TEXT,
            monto DECIMAL(15,2) DEFAULT 0,
            meta_alcanzada DECIMAL(12,2) DEFAULT 0,
            participantes_alcanzados INT DEFAULT 0,
            mes_ejecucion VARCHAR(3) NULL,
            INDEX(compra_id), INDEX(poa_hash),
            CONSTRAINT fk_compra_poa FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        addColSafe($db,'ah_compras','formato_compra',"CHAR(1) NOT NULL DEFAULT 'A'");
        addColSafe($db,'ah_compras','proveedor','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','rtn','VARCHAR(50) NULL');
        addColSafe($db,'ah_compras','banco','VARCHAR(100) NULL');
        addColSafe($db,'ah_compras','tipo_cuenta','VARCHAR(50) NULL');
        addColSafe($db,'ah_compras','cuenta_bancaria','VARCHAR(100) NULL');
        addColSafe($db,'ah_compras','tipo_transferencia','VARCHAR(100) NULL');
        addColSafe($db,'ah_compras','subtotal','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras','descuento_total','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras','isv_total','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras','monto_total','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras','autorizado_por','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','fecha_autorizacion','DATETIME NULL');
        addColSafe($db,'ah_compras','visto_bueno','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','firma_autoriza','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','revisado_por','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','titular_1','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','titular_2','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','observacion_cotizacion','LONGTEXT NULL');
        addColSafe($db,'ah_compras','fecha_analisis_cotizacion','DATE NULL');
        addColSafe($db,'ah_compras','mes_ejecucion','VARCHAR(3) NULL');
        addColSafe($db,'ah_compras','updated_at','TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP');
        addColSafe($db,'ah_compras','fecha_recepcion','DATE NULL');
        addColSafe($db,'ah_compras','recibido_por','VARCHAR(255) NULL');
        addColSafe($db,'ah_compras','notas_recepcion','TEXT NULL');

        try { $db->exec("ALTER TABLE ah_compras_detalles MODIFY cantidad DECIMAL(12,2) NOT NULL DEFAULT 0"); } catch (Throwable $e) {}
        addColSafe($db,'ah_compras_detalles','tipo_impuesto',"VARCHAR(1) DEFAULT 'G'");
        addColSafe($db,'ah_compras_detalles','precio_unitario','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras_detalles','precio_total','DECIMAL(15,2) DEFAULT 0');
        addColSafe($db,'ah_compras_poa','meta_alcanzada','DECIMAL(12,2) DEFAULT 0');
        addColSafe($db,'ah_compras_poa','participantes_alcanzados','INT DEFAULT 0');
        addColSafe($db,'ah_compras_poa','mes_ejecucion','VARCHAR(3) NULL');
        addColSafe($db,'ah_poa','meta_ejecutada','DECIMAL(12,2) DEFAULT 0');
        addColSafe($db,'ah_poa','participantes_ejecutados','INT DEFAULT 0');
        addColSafe($db,'ah_poa','comprometido','DECIMAL(15,2) DEFAULT 0');
        foreach (['jul','aug','sep','oct','nov','dec','jan','feb','mar','apr','may','jun'] as $m) {
            addColSafe($db,'ah_poa','eje_'.$m,'DECIMAL(15,2) DEFAULT 0');
        }

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_cotizaciones (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            posicion INT NOT NULL,
            proveedor VARCHAR(255) NULL,
            rtn VARCHAR(50) NULL,
            estado_cotizacion VARCHAR(30) DEFAULT 'Cotizó',
            es_ganador TINYINT(1) DEFAULT 0,
            descuento DECIMAL(15,2) DEFAULT 0,
            subtotal DECIMAL(15,2) DEFAULT 0,
            isv DECIMAL(15,2) DEFAULT 0,
            total DECIMAL(15,2) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_compra_posicion(compra_id,posicion),
            INDEX(compra_id),
            CONSTRAINT fk_cot_compra FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_resumen_filas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            compra_detalle_id INT NULL,
            orden INT DEFAULT 0,
            cantidad DECIMAL(12,2) DEFAULT 0,
            presentacion VARCHAR(100), articulo VARCHAR(255), caracteristicas VARCHAR(500),
            es_extra TINYINT(1) DEFAULT 0,
            INDEX(compra_id), INDEX(compra_detalle_id),
            CONSTRAINT fk_resumen_compra FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_cotizacion_precios (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cotizacion_id INT NOT NULL,
            resumen_fila_id INT NOT NULL,
            precio_unitario DECIMAL(15,2) DEFAULT 0,
            tipo_impuesto VARCHAR(1) DEFAULT 'E',
            precio_total DECIMAL(15,2) DEFAULT 0,
            UNIQUE KEY uq_cot_fila(cotizacion_id,resumen_fila_id),
            CONSTRAINT fk_precio_cot FOREIGN KEY(cotizacion_id) REFERENCES ah_compras_cotizaciones(id) ON DELETE CASCADE,
            CONSTRAINT fk_precio_fila FOREIGN KEY(resumen_fila_id) REFERENCES ah_compras_resumen_filas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_planillas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            orden INT DEFAULT 0,
            plantilla VARCHAR(30) DEFAULT 'GENERAL',
            nombre_cooperativa VARCHAR(255), titulo VARCHAR(255),
            programa VARCHAR(255), marco_logico TEXT,
            fecha_preparado DATE NULL, fecha_pago DATE NULL,
            comision_default DECIMAL(7,2) DEFAULT 0,
            preparado_por VARCHAR(255), lugar VARCHAR(255), observaciones TEXT,
            INDEX(compra_id),
            CONSTRAINT fk_planilla_compra FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_planilla_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            planilla_id INT NOT NULL,
            orden INT DEFAULT 0,
            nombre VARCHAR(255), comunidad VARCHAR(255), identidad VARCHAR(50),
            monto_base DECIMAL(15,2) DEFAULT 0,
            comision_pct DECIMAL(7,2) DEFAULT 0,
            comision DECIMAL(15,2) DEFAULT 0,
            total_transferencia DECIMAL(15,2) DEFAULT 0,
            instruccion_pago VARCHAR(255), firma VARCHAR(255),
            INDEX(planilla_id),
            CONSTRAINT fk_planilla_detalle FOREIGN KEY(planilla_id) REFERENCES ah_compras_planillas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_poa_movimientos (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            compra_poa_id INT NULL,
            poa_hash VARCHAR(64) NOT NULL,
            mes VARCHAR(3) NOT NULL,
            tipo VARCHAR(20) NOT NULL,
            monto DECIMAL(15,2) DEFAULT 0,
            meta DECIMAL(12,2) DEFAULT 0,
            participantes INT DEFAULT 0,
            usuario VARCHAR(255), created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_mov_compra_linea_tipo(compra_id,compra_poa_id,tipo),
            INDEX(poa_hash), INDEX(compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_recepcion_detalles (
            id INT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            detalle_id INT NOT NULL,
            cantidad_recibida DECIMAL(12,2) DEFAULT 0,
            cantidad_danada DECIMAL(12,2) DEFAULT 0,
            observacion VARCHAR(500),
            UNIQUE KEY uq_recepcion_detalle(compra_id,detalle_id),
            CONSTRAINT fk_rec_compra FOREIGN KEY(compra_id) REFERENCES ah_compras(id) ON DELETE CASCADE,
            CONSTRAINT fk_rec_detalle FOREIGN KEY(detalle_id) REFERENCES ah_compras_detalles(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec("CREATE TABLE IF NOT EXISTS ah_compras_auditoria (
            id BIGINT AUTO_INCREMENT PRIMARY KEY,
            compra_id INT NOT NULL,
            accion VARCHAR(80) NOT NULL,
            usuario VARCHAR(255), detalle_json LONGTEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(compra_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $db->exec('UPDATE ah_compras_schema SET version=5 WHERE id=1');
    } catch (Throwable $e) {
        throw $e;
    }
}

$schemaVersion = comprasSchemaVersion($db);
$schemaPending = $schemaVersion < 5;
$schemaUpgradeRequested = isset($_GET['actualizar_estructura']) && $_GET['actualizar_estructura'] === '1';

if ($schemaUpgradeRequested) {
    try {
        ensureComprasSchema($db);
        $schemaVersion = comprasSchemaVersion($db);
        $schemaPending = $schemaVersion < 5;
        if (!$schemaPending) {
            $msg = '<div class="alert success"><i class="fa-solid fa-circle-check"></i> La estructura del módulo de compras quedó actualizada.</div>';
        }
    } catch (Throwable $e) {
        $schemaPending = true;
        $isTmpError = stripos($e->getMessage(), 'No space left on device') !== false
            || stripos($e->getMessage(), "Can't create/write to file '/tmp") !== false;
        if ($isTmpError) {
            $msg = '<div class="alert warning schema-alert"><i class="fa-solid fa-hard-drive"></i><div><strong>Actualización de estructura aplazada.</strong><span> El módulo continuará usando la estructura disponible. El servidor no tiene espacio temporal en <code>/tmp</code>; la página ya no intentará alterar tablas durante cada carga.</span></div></div>';
        } else {
            $msg = '<div class="alert warning schema-alert"><i class="fa-solid fa-screwdriver-wrench"></i><div><strong>Estructura pendiente.</strong><span> No se pudo completar la actualización: '.h($e->getMessage()).'</span></div></div>';
        }
    }
}

/* ========================================================
   CATÁLOGOS
   ======================================================== */
$presentaciones = ['Unidad','Libra','Libras','Bolsa','Bolsas','Bolsita','Galón','Metro','Metros','Caja','Paquete','Quintal','Varilla','Camionada','Litro','Global','Servicio','Persona','Día','Viaje'];
$bancos = ['Banco de Occidente S.A.','Atlántida S.A.','BAC Honduras','FICOHSA S.A.','DAVIVIENDA Honduras','BANRURAL','Banco de Honduras S.A.','Banco Cuscatlan','Banco del País','FICENSA S.A.','Azteca Honduras S.A.','Banco Hondureño del Café','LAFISE de Honduras','Banco Popular','PROMERICA S.A.','Banadesa'];
$tipos_cuenta = ['Ahorro','Cheques','Otras'];
$tipos_transferencia = ['ACH','Transferencia a terceros','Cheque','Efectivo','Otros Servicios'];
$empleados = [
    'José Orlando Osorto Pérez - Director Ejecutivo','Edwing Armando Lopez Esteves - Coordinador de Programas',
    'Patricia Carolina Vásquez Martínez - Contador General','Eduardo Hernandez Servellon - Oficial Administrativo y RRHH',
    'William Misael Martínez Chévez - Auxiliar Administrativo','Carlos Eduardo Martínez - Oficial de MEAL',
    'Beyquer Odan Maldonado - Coordinador de Área','Carmen Suyapa Pavón Almendarez - Oficial de Patrocinio',
    'David Alonzo Ramos - Técnico de Desarrollo Comunitario','Goel Garcia Alvarado - Técnico de Desarrollo Comunitario',
    'Nubia Lisseth Medina Munguía - Técnico de Desarrollo Comunitario','Roger Neptaly Silva Sánchez - Técnico de Desarrollo Comunitario',
    'Jeniffer Abigail Canales Flores - Técnico de Desarrollo Comunitario','Francisca Baiza García - Técnico de Desarrollo Comunitario',
    'Alex Omar Alvarado A - Técnico de Desarrollo Comunitario','Alex Francisco Castillo - Técnico de Desarrollo Comunitario',
    'Jhony Alfredo Amador - Técnico de Desarrollo Comunitario','Norma Waleska Hernandez - Técnico de Desarrollo Comunitario',
    'Eliberth Fabricio Galileo García Martínez - Oficial en Comunicaciones e Incidencia',
    'Jennifer Sabrina Romero Martínez - Especialista en Protección e Incidencia','Osterly Samir Vásquez - Técnico de Desarrollo Comunitario'
];
$revisado_opts = ['Patricia Carolina Vásquez Martínez - Contador General','William Misael Martínez Chévez - Auxiliar Administrativo','Eduardo Hernandez Servellon - Oficial Administrativo y RRHH'];
$titulares_opts = ['José Orlando Osorto Pérez - Director Ejecutivo','Edwing Armando Lopez Esteves - Coordinador de Programas','Patricia Carolina Vásquez Martínez - Contador General','Eduardo Hernandez Servellon - Oficial Administrativo y RRHH'];

$proveedores_db=[];
try{
    $proveedores_db=$db->query("SELECT id,nombre,rtn,banco,tipo_cuenta,cuenta_bancaria FROM ah_proveedores ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($proveedores_db as &$provRow) {
        $profileRow = providerProfile($db, (string)($provRow['nombre'] ?? ''));
        $provRow = array_merge($provRow, [
            'direccion' => (string)($profileRow['direccion'] ?? ''),
            'tipo_transferencia' => (string)($profileRow['tipo_transferencia'] ?? '')
        ]);
    }
    unset($provRow);
}catch(Throwable $e){}

$poa_vigente_lines = [];
$nombre_poa_vigente = 'Ninguno';
$catalogo_cuentas = accountingCatalogOptions($db);
$cuentasOptions = '';
foreach ($catalogo_cuentas as $account) {
    $label = accountingCatalogLabel($account);
    $cuentasOptions .= '<option value="'.(int)$account['id'].'" data-type="'.h($account['tipo']).'">'.h($label).'</option>';
}
try {
    $st = $db->query("SELECT hash_id,nombre_poa,sector,sub_sector,marco_logico,ext,fuente_financiamiento,cuenta_contable,rubro_contable,presupuesto_anual,ejecutado FROM ah_poa WHERE is_active=1 ORDER BY id");
    $poa_vigente_lines = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    if ($poa_vigente_lines) $nombre_poa_vigente = (string)$poa_vigente_lines[0]['nombre_poa'];
} catch (Throwable $e) {}

/* ========================================================
   PROCESAMIENTO DE ACCIONES
   ======================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = (string)$_POST['action'];
    try {
        validateCsrf();

        if ($action === 'desbloquear_edicion') {
            $id=(int)($_POST['compra_id']??0);
            assertPurchaseAccess($db,$id);
            if (!verifyCurrentCompraPassword($db,(string)($_POST['password']??''))) {
                throw new RuntimeException('La contraseña ingresada no es correcta.');
            }
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id);
            $affectedHashes=[];
            if (stateRank((string)($purchase['estado']??''))>=5) {
                $st=$db->prepare('SELECT DISTINCT poa_hash FROM ah_compras_poa WHERE compra_id=?');
                $st->execute([$id]);
                $affectedHashes=$st->fetchAll(PDO::FETCH_COLUMN);
                if (tableExists($db,'ah_compras_ejecuciones')) $db->prepare('DELETE FROM ah_compras_ejecuciones WHERE compra_id=?')->execute([$id]);
                if (tableExists($db,'ah_poa_movimientos')) $db->prepare('DELETE FROM ah_poa_movimientos WHERE compra_id=?')->execute([$id]);
                $reopenSets=["estado='1_Solicitud'",'autorizado_por=NULL','fecha_autorizacion=NULL'];
                foreach (['fecha_recepcion','recibido_por','notas_recepcion'] as $optionalColumn) {
                    if (comprasColumnExists($db,'ah_compras',$optionalColumn)) {
                        $reopenSets[]="`{$optionalColumn}`=NULL";
                    }
                }
                $db->prepare('UPDATE ah_compras SET '.implode(',',$reopenSets).' WHERE id=?')->execute([$id]);
                saveAudit($db,$id,'EXPEDIENTE_REABIERTO',['estado_anterior'=>$purchase['estado'],'motivo'=>'Edición histórica confirmada con contraseña']);
            } else {
                saveAudit($db,$id,'EDICION_HISTORICA_DESBLOQUEADA',['estado'=>$purchase['estado']]);
            }
            $db->commit();
            foreach ($affectedHashes as $hash) if (trim((string)$hash)!=='') recalcPoaExecuted($db,(string)$hash);
            $_SESSION['compras_edit_unlock'][(string)$id]=time()+900;
            header("Location: {$self}?id={$id}&tab=solicitud&msg=success_unlock"); exit;
        }

        if ($action === 'archivar_compra') {
            $id=(int)($_POST['compra_id']??0);
            assertPurchaseAccess($db,$id);
            if (!verifyCurrentCompraPassword($db,(string)($_POST['password']??''))) {
                throw new RuntimeException('La contraseña ingresada no es correcta.');
            }
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id);
            $st=$db->prepare('SELECT DISTINCT poa_hash FROM ah_compras_poa WHERE compra_id=?');
            $st->execute([$id]);
            $affectedHashes=$st->fetchAll(PDO::FETCH_COLUMN);
            if (tableExists($db,'ah_compras_ejecuciones')) $db->prepare('DELETE FROM ah_compras_ejecuciones WHERE compra_id=?')->execute([$id]);
            if (tableExists($db,'ah_poa_movimientos')) $db->prepare('DELETE FROM ah_poa_movimientos WHERE compra_id=?')->execute([$id]);
            $db->prepare("UPDATE ah_compras SET estado='9_Archivada' WHERE id=?")->execute([$id]);
            if ($compras_ownership_ready) {
                $db->prepare('UPDATE ah_compras_propiedad SET archived_at=NOW(),archived_by=? WHERE compra_id=?')->execute([currentUserLabel(),$id]);
            }
            saveAudit($db,$id,'EXPEDIENTE_ARCHIVADO',['estado_anterior'=>$purchase['estado']]);
            $db->commit();
            foreach ($affectedHashes as $hash) if (trim((string)$hash)!=='') recalcPoaExecuted($db,(string)$hash);
            unset($_SESSION['compras_edit_unlock'][(string)$id]);
            header('Location: mis_compras.php?msg=archived'); exit;
        }

        if ($action === 'eliminar_compra') {
            $id=(int)($_POST['compra_id']??0);
            assertPurchaseAccess($db,$id);
            if (!verifyCurrentCompraPassword($db,(string)($_POST['password']??''))) {
                throw new RuntimeException('La contraseña ingresada no es correcta.');
            }
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id);
            $affectedHashes=reverseCompraPoaExecution($db,$id,$purchase);
            $db->prepare('DELETE FROM ah_compras_cuentas WHERE compra_poa_id IN (SELECT id FROM ah_compras_poa WHERE compra_id=?)')->execute([$id]);
            deleteCompraDatabaseTrail($db,$id);
            $db->commit();
            purgeCompraFallbackStores($id);
            foreach ($affectedHashes as $hash) recalcPoaExecuted($db,(string)$hash);
            header('Location: mis_compras.php?msg=deleted'); exit;
        }

        if ($action === 'guardar_nuevo_proveedor') {
            $nombre=trim((string)($_POST['nombre']??'')); $rtn=trim((string)($_POST['rtn']??''));
            $direccion=trim((string)($_POST['direccion']??'')); $banco=trim((string)($_POST['banco']??''));
            $tipoTransferencia=trim((string)($_POST['tipo_transferencia']??'')); $tipoCuenta=trim((string)($_POST['tipo_cuenta']??''));
            $cuenta=trim((string)($_POST['cuenta_bancaria']??''));
            if($nombre==='') throw new RuntimeException('Ingrese el nombre del proveedor.');
            $st=$db->prepare("INSERT INTO ah_proveedores(nombre,rtn,banco,tipo_cuenta,cuenta_bancaria) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rtn=VALUES(rtn),banco=VALUES(banco),tipo_cuenta=VALUES(tipo_cuenta),cuenta_bancaria=VALUES(cuenta_bancaria)");
            $st->execute([$nombre,$rtn,$banco,$tipoCuenta,$cuenta]); saveProviderProfile($db,$nombre,$direccion,$tipoTransferencia);
            jsonOut(['status'=>'success','msg'=>'Proveedor guardado correctamente.','proveedor'=>['nombre'=>$nombre,'rtn'=>$rtn,'direccion'=>$direccion,'banco'=>$banco,'tipo_transferencia'=>$tipoTransferencia,'tipo_cuenta'=>$tipoCuenta,'cuenta_bancaria'=>$cuenta]]);
        }

        if ($action === 'guardar_solicitud') {
            $db->beginTransaction();
            $id = (int)($_POST['compra_id'] ?? 0);
            $format = strtoupper(trim((string)($_POST['formato_compra'] ?? 'A')));
            if (!in_array($format,['A','B','C'],true)) $format='A';
            $fecha = (string)($_POST['fecha'] ?? date('Y-m-d'));
            $solicitante = trim((string)($_POST['solicitante'] ?? ''));
            $descripcion = trim((string)($_POST['descripcion_actividad'] ?? ''));
            if ($solicitante === '' || $descripcion === '') throw new RuntimeException('Complete solicitante y justificación de la compra.');

            if ($id > 0) {
                $purchase = lockPurchase($db,$id);
                ensureEditable($purchase,1);
                $st = $db->prepare("UPDATE ah_compras SET fecha=?,solicitante=?,descripcion_actividad=?,visto_bueno=?,firma_autoriza=?,revisado_por=?,titular_1=?,titular_2=? WHERE id=?");
                $st->execute([$fecha,$solicitante,$descripcion,$_POST['visto_bueno']??'',$_POST['firma_autoriza']??'',$_POST['revisado_por']??'',$_POST['titular_1']??'',$_POST['titular_2']??'',$id]);
                saveCompraFormato($db, $id, $format);
            } else {
                $st = $db->prepare("INSERT INTO ah_compras(fecha,solicitante,descripcion_actividad,visto_bueno,firma_autoriza,revisado_por,titular_1,titular_2,estado) VALUES(?,?,?,?,?,?,?,?,'1_Solicitud')");
                $st->execute([$fecha,$solicitante,$descripcion,$_POST['visto_bueno']??'',$_POST['firma_autoriza']??'',$_POST['revisado_por']??'',$_POST['titular_1']??'',$_POST['titular_2']??'']);
                $id = (int)$db->lastInsertId();
                if ($compras_ownership_ready) {
                    $owner=$db->prepare('INSERT INTO ah_compras_propiedad(compra_id,usuario_id,usuario_nombre) VALUES(?,?,?)');
                    $owner->execute([$id,currentCompraUserId(),currentUserLabel()]);
                }
                saveCompraFormato($db, $id, $format);
            }

            $kept=[];
            foreach ((array)($_POST['items'] ?? []) as $item) {
                $art = trim((string)($item['articulo'] ?? ''));
                $qty = quantity($item['cantidad'] ?? 0);
                if ($art==='' || $qty<=0) continue;
                $itemId=(int)($item['id']??0);
                if ($itemId>0) {
                    $st=$db->prepare('UPDATE ah_compras_detalles SET cantidad=?,presentacion=?,articulo=?,caracteristicas=? WHERE id=? AND compra_id=?');
                    $st->execute([$qty,trim((string)($item['presentacion']??'')),$art,trim((string)($item['caracteristicas']??'')),$itemId,$id]);
                    $kept[]=$itemId;
                } else {
                    $st=$db->prepare('INSERT INTO ah_compras_detalles(compra_id,cantidad,presentacion,articulo,caracteristicas) VALUES(?,?,?,?,?)');
                    $st->execute([$id,$qty,trim((string)($item['presentacion']??'')),$art,trim((string)($item['caracteristicas']??''))]);
                    $kept[]=(int)$db->lastInsertId();
                }
            }
            if (!$kept) throw new RuntimeException('Agregue al menos un artículo o servicio.');
            $marks=implode(',',array_fill(0,count($kept),'?'));
            $params=$kept; $params[]=$id;
            $db->prepare("DELETE FROM ah_compras_detalles WHERE id NOT IN ({$marks}) AND compra_id=?")->execute($params);

            // En Formato B, las líneas originales alimentan el resumen. Las filas extras se conservan.
            if ($format==='B' && !empty($quotes_component_db_ready)) {
                $existing=$db->prepare('SELECT id FROM ah_compras_resumen_filas WHERE compra_id=? AND compra_detalle_id=? LIMIT 1');
                $insert=$db->prepare('INSERT INTO ah_compras_resumen_filas(compra_id,compra_detalle_id,orden,cantidad,presentacion,articulo,caracteristicas,es_extra) SELECT ?,id,?,cantidad,presentacion,articulo,caracteristicas,0 FROM ah_compras_detalles WHERE id=?');
                $update=$db->prepare('UPDATE ah_compras_resumen_filas r JOIN ah_compras_detalles d ON d.id=r.compra_detalle_id SET r.cantidad=d.cantidad,r.presentacion=d.presentacion,r.articulo=d.articulo,r.caracteristicas=d.caracteristicas WHERE r.compra_id=? AND r.compra_detalle_id=?');
                foreach ($kept as $i=>$detailId) {
                    $existing->execute([$id,$detailId]);
                    if ($existing->fetchColumn()) $update->execute([$id,$detailId]);
                    else $insert->execute([$id,$i+1,$detailId]);
                }
                $db->prepare("DELETE r FROM ah_compras_resumen_filas r LEFT JOIN ah_compras_detalles d ON d.id=r.compra_detalle_id WHERE r.compra_id=? AND r.es_extra=0 AND d.id IS NULL")->execute([$id]);
            }

            saveAudit($db,$id,'SOLICITUD_GUARDADA',['formato'=>$format,'items'=>count($kept)]);
            $db->commit();
            $next=$format==='B'?'cotizaciones':'orden';
            header("Location: {$self}?id={$id}&tab={$next}&msg=success_solicitud"); exit;
        }

        if ($action === 'guardar_cotizaciones') {
            $id=(int)($_POST['compra_id']??0);
            $purchase = null;

            if (!empty($quotes_component_db_ready)) {
                $db->beginTransaction();
                $purchase=lockPurchase($db,$id); ensureEditable($purchase,2);
                if (($purchase['formato_compra']??'A')!=='B') throw new RuntimeException('La comparación de cotizaciones solo aplica al Formato B.');

                $quotes=(array)($_POST['cotizaciones']??[]);
                while (count($quotes)<4) $quotes[]=[];
                $winnerPos=(int)($_POST['ganador_posicion']??1);
                if ($winnerPos<1 || $winnerPos>4) $winnerPos=1;
                $savedQuotes=[];
                $qUpsert=$db->prepare("INSERT INTO ah_compras_cotizaciones(compra_id,posicion,proveedor,rtn,estado_cotizacion,es_ganador,descuento) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE proveedor=VALUES(proveedor),rtn=VALUES(rtn),estado_cotizacion=VALUES(estado_cotizacion),es_ganador=VALUES(es_ganador),descuento=VALUES(descuento)");
                foreach ($quotes as $idx=>$quote) {
                    $pos=$idx+1;
                    $provider=trim((string)($quote['proveedor']??''));
                    $status=trim((string)($quote['estado']??'Cotizó')) ?: 'Cotizó';
                    if ($pos===1 && $provider==='') throw new RuntimeException('El primer proveedor debe registrarse y será el ganador inicial.');
                    $qUpsert->execute([$id,$pos,$provider,trim((string)($quote['rtn']??'')),$status,$pos===$winnerPos?1:0,money($quote['descuento']??0)]);
                    $find=$db->prepare('SELECT id FROM ah_compras_cotizaciones WHERE compra_id=? AND posicion=?');
                    $find->execute([$id,$pos]); $savedQuotes[$pos]=(int)$find->fetchColumn();
                }
                if (!isset($savedQuotes[$winnerPos])) $winnerPos=1;
                $db->prepare('UPDATE ah_compras_cotizaciones SET es_ganador=(posicion=?) WHERE compra_id=?')->execute([$winnerPos,$id]);

                $postedRows=(array)($_POST['resumen']??[]);
                $keptRows=[];
                foreach ($postedRows as $order=>$row) {
                    $article=trim((string)($row['articulo']??''));
                    if ($article==='') continue;
                    $rowId=(int)($row['id']??0);
                    $qty=quantity($row['cantidad']??0);
                    $extra=!empty($row['es_extra'])?1:0;
                    if ($rowId>0) {
                        $st=$db->prepare('UPDATE ah_compras_resumen_filas SET orden=?,cantidad=?,presentacion=?,articulo=?,caracteristicas=?,es_extra=? WHERE id=? AND compra_id=?');
                        $st->execute([$order+1,$qty,trim((string)($row['presentacion']??'')),$article,trim((string)($row['caracteristicas']??'')),$extra,$rowId,$id]);
                    } else {
                        $st=$db->prepare('INSERT INTO ah_compras_resumen_filas(compra_id,orden,cantidad,presentacion,articulo,caracteristicas,es_extra) VALUES(?,?,?,?,?,?,?)');
                        $st->execute([$id,$order+1,$qty,trim((string)($row['presentacion']??'')),$article,trim((string)($row['caracteristicas']??'')),$extra]);
                        $rowId=(int)$db->lastInsertId();
                    }
                    $keptRows[]=$rowId;
                    foreach ($savedQuotes as $pos=>$quoteId) {
                        $priceData=(array)($row['precios'][$pos]??[]);
                        $unit=money($priceData['precio']??0);
                        $tax=strtoupper((string)($priceData['impuesto']??'E'))==='G'?'G':'E';
                        $total=round($qty*$unit,2);
                        $st=$db->prepare("INSERT INTO ah_compras_cotizacion_precios(cotizacion_id,resumen_fila_id,precio_unitario,tipo_impuesto,precio_total) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE precio_unitario=VALUES(precio_unitario),tipo_impuesto=VALUES(tipo_impuesto),precio_total=VALUES(precio_total)");
                        $st->execute([$quoteId,$rowId,$unit,$tax,$total]);
                    }
                }
                if (!$keptRows) throw new RuntimeException('El resumen de cotización debe contener al menos una fila.');
                $marks=implode(',',array_fill(0,count($keptRows),'?')); $params=$keptRows; $params[]=$id;
                $db->prepare("DELETE FROM ah_compras_resumen_filas WHERE id NOT IN ({$marks}) AND compra_id=?")->execute($params);

                foreach ($savedQuotes as $quoteId) {
                    $st=$db->prepare("SELECT COALESCE(SUM(p.precio_total),0) subtotal, COALESCE(SUM(CASE WHEN p.tipo_impuesto='G' THEN p.precio_total ELSE 0 END),0) gravado FROM ah_compras_cotizacion_precios p WHERE p.cotizacion_id=?");
                    $st->execute([$quoteId]); $tot=$st->fetch(PDO::FETCH_ASSOC);
                    $qd=$db->prepare('SELECT descuento FROM ah_compras_cotizaciones WHERE id=?'); $qd->execute([$quoteId]); $discount=money($qd->fetchColumn());
                    $subtotal=(float)$tot['subtotal']; $grav=(float)$tot['gravado'];
                    $discGrav=$subtotal>0?$discount*($grav/$subtotal):0;
                    $isv=max(0,($grav-$discGrav)*0.15); $grand=$subtotal-$discount+$isv;
                    $db->prepare('UPDATE ah_compras_cotizaciones SET subtotal=?,isv=?,total=? WHERE id=?')->execute([$subtotal,$isv,$grand,$quoteId]);
                }

                $sets=["estado='2_Cotizaciones'"]; $params=[];
                if (comprasColumnExists($db,'ah_compras','observacion_cotizacion')) { $sets[]='observacion_cotizacion=?'; $params[]=trim((string)($_POST['observacion_cotizacion']??'')); }
                if (comprasColumnExists($db,'ah_compras','fecha_analisis_cotizacion')) { $sets[]='fecha_analisis_cotizacion=?'; $params[]=(string)($_POST['fecha_analisis_cotizacion']??date('Y-m-d')); }
                $params[]=$id;
                $db->prepare('UPDATE ah_compras SET '.implode(',',$sets).' WHERE id=?')->execute($params);
                saveAudit($db,$id,'COTIZACIONES_GUARDADAS',['ganador_posicion'=>$winnerPos,'filas'=>count($keptRows),'storage'=>'database']);
                $db->commit();

                // Algunos hostings no permiten agregar las columnas de observación y fecha
                // a ah_compras. Conservamos siempre un respaldo persistente cuando faltan,
                // aun cuando proveedores, filas y precios sí se guarden en sus tablas.
                if (!comprasColumnExists($db,'ah_compras','observacion_cotizacion')
                    || !comprasColumnExists($db,'ah_compras','fecha_analisis_cotizacion')) {
                    $payload=buildCompraCotizacionesPayload(
                        (array)($_POST['cotizaciones']??[]),
                        (array)($_POST['resumen']??[]),
                        $winnerPos,
                        (string)($_POST['observacion_cotizacion']??''),
                        (string)($_POST['fecha_analisis_cotizacion']??date('Y-m-d'))
                    );
                    if (!writeCompraCotizacionesStore($id,$payload)) {
                        throw new RuntimeException('El resumen se guardó, pero no fue posible conservar la observación y la fecha de análisis.');
                    }
                }
            } else {
                $st=$db->prepare('SELECT * FROM ah_compras WHERE id=? LIMIT 1'); $st->execute([$id]); $purchase=$st->fetch(PDO::FETCH_ASSOC);
                if (!$purchase) throw new RuntimeException('Expediente no encontrado.');
                $purchase['formato_compra']=getCompraFormato($db,$id,$purchase);
                ensureEditable($purchase,2);
                if (($purchase['formato_compra']??'A')!=='B') throw new RuntimeException('La comparación de cotizaciones solo aplica al Formato B.');

                $payload=buildCompraCotizacionesPayload(
                    (array)($_POST['cotizaciones']??[]),
                    (array)($_POST['resumen']??[]),
                    (int)($_POST['ganador_posicion']??1),
                    (string)($_POST['observacion_cotizacion']??''),
                    (string)($_POST['fecha_analisis_cotizacion']??date('Y-m-d'))
                );
                if (!writeCompraCotizacionesStore($id,$payload)) throw new RuntimeException('No fue posible guardar el resumen de cotizaciones en el almacenamiento alternativo.');

                $db->beginTransaction();
                $sets=["estado='2_Cotizaciones'"]; $params=[];
                if (comprasColumnExists($db,'ah_compras','observacion_cotizacion')) { $sets[]='observacion_cotizacion=?'; $params[]=$payload['observacion_cotizacion']; }
                if (comprasColumnExists($db,'ah_compras','fecha_analisis_cotizacion')) { $sets[]='fecha_analisis_cotizacion=?'; $params[]=$payload['fecha_analisis_cotizacion']; }
                $params[]=$id;
                $db->prepare('UPDATE ah_compras SET '.implode(',',$sets).' WHERE id=?')->execute($params);
                saveAudit($db,$id,'COTIZACIONES_GUARDADAS',['ganador_posicion'=>(int)($_POST['ganador_posicion']??1),'filas'=>count($payload['rows']),'storage'=>'json']);
                $db->commit();
            }

            header("Location: {$self}?id={$id}&tab=orden&msg=success_cotizaciones"); exit;
        }

        if ($action === 'guardar_orden') {
            $id=(int)($_POST['compra_id']??0);
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id); ensureEditable($purchase,3);
            $format=(string)$purchase['formato_compra'];
            $provider=''; $rtn=''; $discount=money($_POST['descuento_total']??0);
            $subtotal=0; $gravado=0;

            if ($format==='B') {
                if (!empty($quotes_component_db_ready)) {
                    $q=$db->prepare('SELECT * FROM ah_compras_cotizaciones WHERE compra_id=? AND es_ganador=1 LIMIT 1');
                    $q->execute([$id]); $winner=$q->fetch(PDO::FETCH_ASSOC);
                    if (!$winner) throw new RuntimeException('Primero seleccione y guarde el proveedor ganador.');
                    $provider=(string)$winner['proveedor']; $rtn=(string)$winner['rtn']; $discount=(float)$winner['descuento'];
                    $rows=$db->prepare("SELECT r.*,p.precio_unitario,p.precio_total,p.tipo_impuesto FROM ah_compras_resumen_filas r JOIN ah_compras_cotizacion_precios p ON p.resumen_fila_id=r.id WHERE r.compra_id=? AND p.cotizacion_id=? AND p.precio_unitario>0 ORDER BY r.orden,r.id");
                    $rows->execute([$id,$winner['id']]); $winnerRows=$rows->fetchAll(PDO::FETCH_ASSOC);
                } else {
                    $payload=getCompraCotizacionesFallback($id);
                    $winner=null;
                    foreach ((array)$payload['quotes'] as $quote) if (!empty($quote['es_ganador'])) { $winner=$quote; break; }
                    if (!$winner) throw new RuntimeException('Primero seleccione y guarde el proveedor ganador.');
                    $provider=(string)$winner['proveedor']; $rtn=(string)$winner['rtn']; $discount=(float)$winner['descuento'];
                    $winnerRows=[];
                    foreach ((array)$payload['rows'] as $row) {
                        $pr=$payload['prices'][(string)$row['id']][(string)$winner['id']] ?? null;
                        if (!$pr || (float)($pr['precio_unitario']??0)<=0) continue;
                        $winnerRows[]=array_merge($row,$pr);
                    }
                }
                if (!$winnerRows) throw new RuntimeException('El proveedor ganador no tiene artículos cotizados.');
                $db->prepare('DELETE FROM ah_compras_detalles WHERE compra_id=?')->execute([$id]);
                $ins=$db->prepare('INSERT INTO ah_compras_detalles(compra_id,cantidad,presentacion,articulo,caracteristicas,tipo_impuesto,precio_unitario,precio_total) VALUES(?,?,?,?,?,?,?,?)');
                foreach ($winnerRows as $row) {
                    $ins->execute([$id,$row['cantidad'],$row['presentacion'],$row['articulo'],$row['caracteristicas'],$row['tipo_impuesto'],$row['precio_unitario'],$row['precio_total']]);
                    $subtotal += (float)$row['precio_total'];
                    if (($row['tipo_impuesto']??'E')==='G') $gravado += (float)$row['precio_total'];
                }
            } else {
                $provider=trim((string)($_POST['proveedor']??''));
                $rtn=trim((string)($_POST['rtn']??''));
                if ($provider==='') throw new RuntimeException('Seleccione el proveedor.');
                $details=(array)($_POST['detalles']??[]);
                $st=$db->prepare('SELECT id,cantidad FROM ah_compras_detalles WHERE compra_id=?'); $st->execute([$id]);
                $dbDetails=[]; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $dbDetails[(int)$d['id']]=$d;
                $upd=$db->prepare('UPDATE ah_compras_detalles SET precio_unitario=?,precio_total=?,tipo_impuesto=? WHERE id=? AND compra_id=?');
                foreach ($details as $detailId=>$data) {
                    $detailId=(int)$detailId; if (!isset($dbDetails[$detailId])) continue;
                    $unit=money($data['precio']??0); $tax=strtoupper((string)($data['tipo_impuesto']??'E'))==='G'?'G':'E';
                    $total=round((float)$dbDetails[$detailId]['cantidad']*$unit,2);
                    $upd->execute([$unit,$total,$tax,$detailId,$id]);
                    $subtotal+=$total; if ($tax==='G') $gravado+=$total;
                }
            }
            if ($subtotal<=0) throw new RuntimeException('La orden debe tener valores mayores a cero.');
            if ($discount>$subtotal) $discount=$subtotal;
            $discGrav=$subtotal>0?$discount*($gravado/$subtotal):0;
            $isv=max(0,($gravado-$discGrav)*0.15); $grand=round($subtotal-$discount+$isv,2);
            $providerData=providerProfile($db,$provider); $providerRtn=$rtn!==''?$rtn:trim((string)($providerData['rtn']??''));
            $providerBank=trim((string)($providerData['banco']??'')); $providerAccountType=trim((string)($providerData['tipo_cuenta']??''));
            $providerAccount=trim((string)($providerData['cuenta_bancaria']??'')); $providerTransfer=trim((string)($providerData['tipo_transferencia']??''));
            $db->prepare("UPDATE ah_compras SET proveedor=?,rtn=?,banco=?,tipo_cuenta=?,cuenta_bancaria=?,tipo_transferencia=?,subtotal=?,descuento_total=?,isv_total=?,monto_total=?,estado='3_Orden' WHERE id=?")->execute([$provider,$providerRtn,$providerBank,$providerAccountType,$providerAccount,$providerTransfer,$subtotal,$discount,$isv,$grand,$id]);
            $db->prepare("INSERT INTO ah_proveedores(nombre,rtn) VALUES(?,?) ON DUPLICATE KEY UPDATE rtn=VALUES(rtn)")->execute([$provider,$providerRtn]);
            saveAudit($db,$id,'ORDEN_GUARDADA',['subtotal'=>$subtotal,'isv'=>$isv,'total'=>$grand]);
            $db->commit();
            $next=$format==='C'?'planillas':'transferencia';
            header("Location: {$self}?id={$id}&tab={$next}&msg=success_orden"); exit;
        }

        if ($action === 'guardar_planillas') {
            $id=(int)($_POST['compra_id']??0);
            if (empty($plans_component_ready)) {
                throw new RuntimeException('El componente de planillas no pudo inicializarse. Revise el espacio temporal del servidor o cree las tablas auxiliares desde phpMyAdmin.');
            }
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id); ensureEditable($purchase,3);
            if (($purchase['formato_compra']??'A')!=='C') throw new RuntimeException('Las planillas corresponden al Formato C.');
            $db->prepare('DELETE FROM ah_compras_planillas WHERE compra_id=?')->execute([$id]);
            $planillas=(array)($_POST['planillas']??[]); $count=0; $totalAll=0;
            $poaPlanilla=$db->prepare("SELECT COALESCE(NULLIF(p.programa,''),cp.sector) AS programa, COALESCE(NULLIF(p.marco_logico,''),cp.marco_logico) AS marco_logico FROM ah_compras_poa cp LEFT JOIN ah_poa p ON p.hash_id=cp.poa_hash WHERE cp.compra_id=? ORDER BY cp.id LIMIT 1");
            $poaPlanilla->execute([$id]);
            $poaPlanillaContext=$poaPlanilla->fetch(PDO::FETCH_ASSOC) ?: ['programa'=>'','marco_logico'=>''];
            $insP=$db->prepare('INSERT INTO ah_compras_planillas(compra_id,orden,plantilla,nombre_cooperativa,titulo,programa,marco_logico,fecha_preparado,fecha_pago,comision_default,preparado_por,lugar,observaciones) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $insD=$db->prepare('INSERT INTO ah_compras_planilla_detalles(planilla_id,orden,nombre,comunidad,identidad,monto_base,comision_pct,comision,total_transferencia,instruccion_pago) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($planillas as $pIndex=>$p) {
                $rows=(array)($p['detalles']??[]); $validRows=[];
                foreach ($rows as $r) if (trim((string)($r['nombre']??''))!=='') $validRows[]=$r;
                if (!$validRows) continue;
                $template=trim((string)($p['plantilla']??'GENERAL')) ?: 'GENERAL';
                $defaultPct=(float)($p['comision_default']??($template==='COMISAL'?4:0));
                if (count($validRows)===1) {
                    $rowPct=max(0,min(100,(float)($validRows[0]['comision_pct']??$defaultPct)));
                    $validRows[0]['comision_pct']=$rowPct;
                    $validRows[0]['monto_base']=round((float)$purchase['monto_total']/(1+($rowPct/100)),2);
                } else {
                    $validRows=array_values(array_filter($validRows,static fn(array $row): bool => money($row['monto_base']??0)>0));
                    if (!$validRows) continue;
                }
                $programaPlanilla=trim((string)($poaPlanillaContext['programa']??''));
                $marcoPlanilla=trim((string)($poaPlanillaContext['marco_logico']??''));
                $insP->execute([$id,$pIndex+1,$template,trim((string)($p['nombre_cooperativa']??'')),trim((string)($p['titulo']??'')),$programaPlanilla,$marcoPlanilla,($p['fecha_preparado']??date('Y-m-d')),($p['fecha_pago']??null)?:null,$defaultPct,trim((string)($p['preparado_por']??'')),trim((string)($p['lugar']??'')),trim((string)($p['observaciones']??''))]);
                $planillaId=(int)$db->lastInsertId();
                foreach ($validRows as $rIndex=>$r) {
                    $base=money($r['monto_base']??0); $pct=(float)($r['comision_pct']??$defaultPct); $pct=max(0,min(100,$pct));
                    $commission=round($base*$pct/100,2); $total=round($base+$commission,2); $totalAll+=$total;
                    $insD->execute([$planillaId,$rIndex+1,trim((string)$r['nombre']),trim((string)($r['comunidad']??'')),trim((string)($r['identidad']??'')),$base,$pct,$commission,$total,trim((string)($r['instruccion_pago']??''))]);
                }
                $count++;
            }
            if ($count===0) throw new RuntimeException('Agregue al menos una planilla con beneficiarios.');
            if (abs($totalAll-(float)$purchase['monto_total'])>0.05) {
                throw new RuntimeException('El total de las planillas (L. '.number_format($totalAll,2).') debe coincidir con el total de la orden (L. '.number_format((float)$purchase['monto_total'],2).').');
            }
            $db->prepare("UPDATE ah_compras SET estado='3_Planillas' WHERE id=?")->execute([$id]);
            saveAudit($db,$id,'PLANILLAS_GUARDADAS',['planillas'=>$count,'total'=>$totalAll]);
            $db->commit();
            header("Location: {$self}?id={$id}&tab=transferencia&msg=success_planillas"); exit;
        }

        if ($action === 'guardar_transferencia') {
            $id=(int)($_POST['compra_id']??0);
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id); ensureEditable($purchase,4);
            if (stateRank((string)$purchase['estado'])<3) throw new RuntimeException('Primero complete la orden de compra.');
            if (($purchase['formato_compra']??'A')==='C') {
                $c=$db->prepare('SELECT COUNT(*) FROM ah_compras_planillas WHERE compra_id=?'); $c->execute([$id]);
                if ((int)$c->fetchColumn()===0) throw new RuntimeException('El Formato C requiere al menos una planilla.');
            }
            $month=normalizeMonth((string)($_POST['mes_ejecucion']??monthKeyFromDate((string)$purchase['fecha'])));
            $lines=(array)($_POST['poa_lineas']??[]); $valid=[]; $sum=0;
            foreach ($lines as $line) {
                $hash=trim((string)($line['hash']??'')); $amount=money($line['monto']??0);
                if ($hash==='' || $amount<=0) continue;
                $st=$db->prepare('SELECT * FROM ah_poa WHERE hash_id=? AND is_active=1 LIMIT 1'); $st->execute([$hash]); $poa=$st->fetch(PDO::FETCH_ASSOC);
                if (!$poa) throw new RuntimeException('Una línea presupuestaria ya no pertenece al POA activo.');
                $available=availableForPoa($db,$hash,$id);
                if ($amount>$available+0.05) throw new RuntimeException('Fondos insuficientes en '.$poa['marco_logico'].'. Disponible: L. '.number_format($available,2));
                $catalogId=(int)($line['cuenta_id']??0);
                $account=accountingCatalogAccount($db,$catalogId);
                $valid[]=['hash'=>$hash,'amount'=>$amount,'catalog_id'=>$catalogId,'account'=>accountingCatalogLabel($account),'poa'=>$poa];
                $sum+=$amount;
            }
            if (!$valid) throw new RuntimeException('Asigne al menos una línea del POA.');
            if (abs($sum-(float)$purchase['monto_total'])>0.05) throw new RuntimeException('La distribución del POA debe coincidir exactamente con el total de la orden.');

            if ($poa_movements_ready) {
                $db->prepare("DELETE FROM ah_poa_movimientos WHERE compra_id=? AND tipo='COMPROMISO'")->execute([$id]);
            }
            $db->prepare('DELETE FROM ah_compras_cuentas WHERE compra_poa_id IN (SELECT id FROM ah_compras_poa WHERE compra_id=?)')->execute([$id]);
            $db->prepare('DELETE FROM ah_compras_poa WHERE compra_id=?')->execute([$id]);
            $poaHasExecutionMonth = comprasColumnExists($db, 'ah_compras_poa', 'mes_ejecucion');
            if ($poaHasExecutionMonth) {
                $ins=$db->prepare('INSERT INTO ah_compras_poa(compra_id,poa_hash,sector,sub_sector,marco_logico,ext,fuente_financiamiento,cuenta_contable,monto,meta_alcanzada,participantes_alcanzados,mes_ejecucion) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            } else {
                $ins=$db->prepare('INSERT INTO ah_compras_poa(compra_id,poa_hash,sector,sub_sector,marco_logico,ext,fuente_financiamiento,cuenta_contable,monto,meta_alcanzada,participantes_alcanzados) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
            }
            $mov = $poa_movements_ready
                ? $db->prepare("INSERT INTO ah_poa_movimientos(compra_id,compra_poa_id,poa_hash,mes,tipo,monto,meta,participantes,usuario) VALUES(?,?,?,?, 'COMPROMISO',?,?,?,?)")
                : null;
            foreach ($valid as $line) {
                $p=$line['poa'];
                $insertParams=[$id,$line['hash'],$p['sector'],$p['sub_sector'],$p['marco_logico'],$p['ext'],$p['fuente_financiamiento'],$line['account']?:$p['cuenta_contable'],$line['amount'],0,0];
                if ($poaHasExecutionMonth) $insertParams[]=$month;
                $ins->execute($insertParams);
                $cpId=(int)$db->lastInsertId();
                savePurchaseAccountingLink($db,$cpId,$line['catalog_id'],(string)($p['cuenta_contable']??''));
                if ($mov) $mov->execute([$id,$cpId,$line['hash'],$month,$line['amount'],0,0,currentUserLabel()]);
            }
            $db->prepare("UPDATE ah_compras SET banco=?,tipo_cuenta=?,cuenta_bancaria=?,tipo_transferencia=?,estado='4_Imputacion' WHERE id=?")->execute([trim((string)($_POST['banco']??'')),trim((string)($_POST['tipo_cuenta']??'')),trim((string)($_POST['cuenta_bancaria']??'')),trim((string)($_POST['tipo_transferencia']??'')),$id]);
            saveCompraExecutionMonth($db, $id, $month);
            if (!empty($purchase['proveedor'])) {
                $providerName=trim((string)$purchase['proveedor']); $providerBank=trim((string)($_POST['banco']??''));
                $providerAccountType=trim((string)($_POST['tipo_cuenta']??'')); $providerAccount=trim((string)($_POST['cuenta_bancaria']??''));
                $providerTransfer=trim((string)($_POST['tipo_transferencia']??'')); $providerAddress=trim((string)($_POST['direccion_proveedor']??''));
                $db->prepare('UPDATE ah_proveedores SET banco=?,tipo_cuenta=?,cuenta_bancaria=? WHERE nombre=?')->execute([$providerBank,$providerAccountType,$providerAccount,$providerName]);
                saveProviderProfile($db,$providerName,$providerAddress,$providerTransfer);
            }
            saveAudit($db,$id,'IMPUTACION_GUARDADA',['mes'=>$month,'lineas'=>count($valid),'total'=>$sum]);
            $db->commit();
            header("Location: {$self}?id={$id}&tab=autorizacion&msg=success_transferencia"); exit;
        }

        if ($action === 'autorizar_pago') {
            $id=(int)($_POST['compra_id']??0);
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id);
            if (stateRank((string)$purchase['estado'])>=5) {
                $db->commit();
                header("Location: {$self}?id={$id}&tab=almacen&msg=already_authorized"); exit;
            }
            if (($purchase['estado']??'')!=='4_Imputacion') throw new RuntimeException('La compra debe estar imputada al POA antes de autorizarse.');
            $st=$db->prepare('SELECT * FROM ah_compras_poa WHERE compra_id=? FOR UPDATE'); $st->execute([$id]); $lines=$st->fetchAll(PDO::FETCH_ASSOC);
            if (!$lines) throw new RuntimeException('No existen líneas presupuestarias para ejecutar.');
            $purchaseExecutionMonth = getCompraExecutionMonth($db, $id, $purchase);
            $affectedPoaHashes = [];
            foreach ($lines as $line) {
                $lineMonth = array_key_exists('mes_ejecucion', $line) ? trim((string)$line['mes_ejecucion']) : '';
                $month=normalizeMonth($lineMonth !== '' ? $lineMonth : $purchaseExecutionMonth);
                $resolvedPoaHash = resolveCurrentPoaHash($db, $line);
                if ($resolvedPoaHash !== (string)($line['poa_hash'] ?? '')) {
                    $db->prepare('UPDATE ah_compras_poa SET poa_hash=? WHERE id=?')
                       ->execute([$resolvedPoaHash, (int)$line['id']]);
                    $line['poa_hash'] = $resolvedPoaHash;
                }
                $available=availableForPoa($db,$resolvedPoaHash,$id);
                if ((float)$line['monto']>$available+0.05) throw new RuntimeException('Los fondos disponibles cambiaron y ya no cubren la línea '.$line['marco_logico'].'.');
                $mustExecute = true;
                if ($poa_execution_ledger_ready) {
                    $existing = $db->prepare('SELECT id FROM ah_compras_ejecuciones WHERE compra_poa_id=? LIMIT 1');
                    $existing->execute([(int)$line['id']]);
                    $mustExecute = !$existing->fetchColumn();
                } elseif ($poa_movements_ready) {
                    $existing=$db->prepare("SELECT id FROM ah_poa_movimientos WHERE compra_id=? AND compra_poa_id=? AND tipo='EJECUCION' LIMIT 1");
                    $existing->execute([$id,$line['id']]);
                    $mustExecute = !$existing->fetchColumn();
                }
                if ($mustExecute) {
                    if ($poa_execution_ledger_ready) {
                        $db->prepare('INSERT INTO ah_compras_ejecuciones(compra_id,compra_poa_id,poa_hash,mes,monto) VALUES(?,?,?,?,?)')
                           ->execute([$id,(int)$line['id'],$resolvedPoaHash,$month,(float)$line['monto']]);
                    }
                    if ($poa_movements_ready) {
                        $db->prepare("INSERT INTO ah_poa_movimientos(compra_id,compra_poa_id,poa_hash,mes,tipo,monto,meta,participantes,usuario) VALUES(?,?,?,?, 'EJECUCION',?,?,?,?)")
                           ->execute([$id,$line['id'],$resolvedPoaHash,$month,$line['monto'],0,0,currentUserLabel()]);
                    }
                    addPoaExecutionAmount($db,$resolvedPoaHash,$month,(float)$line['monto']);
                }
                $affectedPoaHashes[$resolvedPoaHash] = true;
            }
            if ($poa_movements_ready) {
                $db->prepare("DELETE FROM ah_poa_movimientos WHERE compra_id=? AND tipo='COMPROMISO'")->execute([$id]);
            }
            $updated=$db->prepare("UPDATE ah_compras SET estado='5_Autorizada',autorizado_por=?,fecha_autorizacion=NOW() WHERE id=? AND estado='4_Imputacion'");
            $updated->execute([currentUserLabel(),$id]);
            if ($updated->rowCount()!==1) throw new RuntimeException('La compra cambió de estado durante la autorización.');
            // El cálculo directo desde compras autorizadas ya incluye esta compra
            // después de cambiar el estado, por eso el recálculo se realiza aquí.
            foreach (array_keys($affectedPoaHashes) as $poaHash) {
                recalcPoaExecuted($db, $poaHash);
            }
            saveAudit($db,$id,'PAGO_AUTORIZADO',['total'=>$purchase['monto_total']]);
            $db->commit();
            header("Location: {$self}?id={$id}&tab=almacen&msg=success_autorizacion"); exit;
        }

        if ($action === 'recibir_almacen') {
            $id=(int)($_POST['compra_id']??0);
            $db->beginTransaction();
            $purchase=lockPurchase($db,$id);
            if (stateRank((string)$purchase['estado'])<5) throw new RuntimeException('La compra debe estar autorizada antes de registrarse la recepción.');
            $db->prepare('DELETE FROM ah_compras_recepcion_detalles WHERE compra_id=?')->execute([$id]);
            $ins=$db->prepare('INSERT INTO ah_compras_recepcion_detalles(compra_id,detalle_id,cantidad_recibida,cantidad_danada,observacion) VALUES(?,?,?,?,?)');
            foreach ((array)($_POST['recepcion']??[]) as $detailId=>$r) {
                $ins->execute([$id,(int)$detailId,quantity($r['recibida']??0),quantity($r['danada']??0),trim((string)($r['observacion']??''))]);
            }
            $fechaRecepcion = trim((string)($_POST['fecha_recepcion'] ?? date('Y-m-d')));
            if ($fechaRecepcion === '') $fechaRecepcion = date('Y-m-d');
            $recibidoPor = trim((string)($_POST['recibido_por'] ?? ''));
            $notasRecepcion = trim((string)($_POST['notas_recepcion'] ?? ''));

            // Actualiza únicamente las columnas que realmente existen.
            // Así el cierre de almacén funciona aun cuando el hosting no ha
            // permitido crear fecha_recepcion, recibido_por o notas_recepcion.
            $setParts = ["estado='6_Almacen'"];
            $updateParams = [];
            foreach ([
                'fecha_recepcion' => $fechaRecepcion,
                'recibido_por' => $recibidoPor,
                'notas_recepcion' => $notasRecepcion,
            ] as $column => $value) {
                if (comprasColumnExists($db, 'ah_compras', $column)) {
                    $setParts[] = "`{$column}`=?";
                    $updateParams[] = $value;
                }
            }
            $updateParams[] = $id;
            $db->prepare('UPDATE ah_compras SET '.implode(',', $setParts).' WHERE id=?')->execute($updateParams);

            saveAudit($db,$id,'RECEPCION_REGISTRADA',[
                'fecha_recepcion' => $fechaRecepcion,
                'recibido_por' => $recibidoPor,
                'notas_recepcion' => $notasRecepcion,
            ]);
            $db->commit();

            // Guarda siempre un respaldo compatible para que los datos se
            // vuelvan a mostrar aunque las columnas no existan en ah_compras.
            saveCompraRecepcionFallback($id, $fechaRecepcion, $recibidoPor, $notasRecepcion);

            header("Location: {$self}?id={$id}&tab=almacen&msg=success_almacen"); exit;
        }

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            try { $db->rollBack(); } catch (Throwable $rollbackError) { /* no interrumpir el mensaje original */ }
        }
        if ($action==='guardar_nuevo_proveedor') jsonOut(['status'=>'error','msg'=>$e->getMessage()],400);

        $errorMessage = $e->getMessage();
        if (stripos($errorMessage, 'There is no active transaction') !== false) {
            $errorMessage = 'La operación perdió su transacción de base de datos. Recargue la página e intente nuevamente.';
        }
        $msg='<div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> '.h($errorMessage).'</div>';
    }
}

/* ========================================================
   CARGA DEL EXPEDIENTE
   ======================================================== */
$id=(int)($_GET['id']??0);
$activeTab=(string)($_GET['tab']??'solicitud');
$purchase=null; $details=[]; $poaDistribution=[]; $quotes=[]; $summaryRows=[]; $prices=[]; $planillas=[]; $receipts=[];
if ($id>0) {
    $st=$db->prepare('SELECT * FROM ah_compras WHERE id=?'); $st->execute([$id]); $purchase=$st->fetch(PDO::FETCH_ASSOC);
    if ($purchase) {
        try {
            assertPurchaseAccess($db,$id);
        } catch (RuntimeException $e) {
            http_response_code(403);
            die('<div style="font-family:Arial,sans-serif;padding:35px"><h2>Acceso restringido</h2><p>'.h($e->getMessage()).'</p><a href="mis_compras.php">Volver a mis compras</a></div>');
        }
        $purchase['formato_compra'] = getCompraFormato($db, $id, $purchase);
        $purchase['mes_ejecucion'] = getCompraExecutionMonth($db, $id, $purchase);
        $recepcionMeta = getCompraRecepcionFallback($id, $purchase);
        $purchase['fecha_recepcion'] = $recepcionMeta['fecha_recepcion'];
        $purchase['recibido_por'] = $recepcionMeta['recibido_por'];
        $purchase['notas_recepcion'] = $recepcionMeta['notas_recepcion'];
        $st=$db->prepare('SELECT * FROM ah_compras_detalles WHERE compra_id=? ORDER BY id'); $st->execute([$id]); $details=$st->fetchAll(PDO::FETCH_ASSOC);
        $st=$db->prepare('SELECT cp.*, p.programa AS programa_poa, p.marco_logico AS marco_logico_poa, cc.catalogo_cuenta_id FROM ah_compras_poa cp LEFT JOIN ah_poa p ON p.hash_id=cp.poa_hash LEFT JOIN ah_compras_cuentas cc ON cc.compra_poa_id=cp.id WHERE cp.compra_id=? ORDER BY cp.id'); $st->execute([$id]); $poaDistribution=$st->fetchAll(PDO::FETCH_ASSOC);
        $purchaseFormat = (string)($purchase['formato_compra'] ?? 'A');
        if ($purchaseFormat === 'B') {
            if (!empty($quotes_component_db_ready)) {
                try {
                    $st=$db->prepare('SELECT * FROM ah_compras_cotizaciones WHERE compra_id=? ORDER BY posicion'); $st->execute([$id]); $quotes=$st->fetchAll(PDO::FETCH_ASSOC);
                    $st=$db->prepare('SELECT * FROM ah_compras_resumen_filas WHERE compra_id=? ORDER BY orden,id'); $st->execute([$id]); $summaryRows=$st->fetchAll(PDO::FETCH_ASSOC);
                    if ($quotes && $summaryRows) {
                        $st=$db->prepare('SELECT p.* FROM ah_compras_cotizacion_precios p JOIN ah_compras_cotizaciones q ON q.id=p.cotizacion_id WHERE q.compra_id=?'); $st->execute([$id]);
                        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $prices[(int)$p['resumen_fila_id']][(int)$p['cotizacion_id']]=$p;
                    }
                } catch (Throwable $e) {
                    $quotes=[]; $summaryRows=[]; $prices=[];
                }
            }

            $fallback=getCompraCotizacionesFallback($id);
            if (!$quotes_component_db_ready || (!$quotes && !$summaryRows)) {
                $quotes=(array)$fallback['quotes'];
                $summaryRows=(array)$fallback['rows'];
                $prices=(array)$fallback['prices'];
                if (empty($summaryRows)) {
                    foreach ($details as $order=>$d) {
                        $summaryRows[]=[
                            'id'=>(int)$d['id'], 'compra_detalle_id'=>(int)$d['id'], 'orden'=>$order+1,
                            'cantidad'=>$d['cantidad'], 'presentacion'=>$d['presentacion'],
                            'articulo'=>$d['articulo'], 'caracteristicas'=>$d['caracteristicas'], 'es_extra'=>0
                        ];
                    }
                }
            }
            if (empty($purchase['observacion_cotizacion']) && trim((string)($fallback['observacion_cotizacion']??''))!=='') $purchase['observacion_cotizacion']=$fallback['observacion_cotizacion'];
            if (empty($purchase['fecha_analisis_cotizacion']) && trim((string)($fallback['fecha_analisis_cotizacion']??''))!=='') $purchase['fecha_analisis_cotizacion']=$fallback['fecha_analisis_cotizacion'];
        }

        if ($purchaseFormat === 'C') {
            if (!empty($plans_component_ready)) {
                try {
                    $st=$db->prepare('SELECT * FROM ah_compras_planillas WHERE compra_id=? ORDER BY orden,id'); $st->execute([$id]); $planillas=$st->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($planillas as &$p) {
                        $sd=$db->prepare('SELECT * FROM ah_compras_planilla_detalles WHERE planilla_id=? ORDER BY orden,id'); $sd->execute([$p['id']]); $p['detalles']=$sd->fetchAll(PDO::FETCH_ASSOC);
                    } unset($p);
                } catch (Throwable $e) {
                    $planillas=[];
                    if ($msg==='') $msg='<div class="alert error">No se pudo cargar el componente de planillas: '.h($e->getMessage()).'</div>';
                }
            } elseif ($msg==='') {
                $msg='<div class="alert error"><strong>Formato C no inicializado:</strong> no fue posible crear las tablas auxiliares de planillas. Los demás formatos continúan funcionando.</div>';
            }
        }
        if (!empty($reception_component_ready)) {
            try {
                $st=$db->prepare('SELECT * FROM ah_compras_recepcion_detalles WHERE compra_id=?'); $st->execute([$id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $receipts[(int)$r['detalle_id']]=$r;
            } catch (Throwable $e) {
                $receipts=[];
            }
        }
    }
}

$format=$purchase['formato_compra']??'A';
$rank=$purchase?stateRank((string)$purchase['estado']):1;
$locked=((string)($purchase['estado']??'')==='9_Archivada') || ($rank>=5 && !purchaseEditUnlocked($id));
$defVb='Edwing Armando Lopez Esteves - Coordinador de Programas';
$defFirma='José Orlando Osorto Pérez - Director Ejecutivo';
$defRev='Eduardo Hernandez Servellon - Oficial Administrativo y RRHH';
$valVb=$purchase['visto_bueno']??$defVb;
$valFirma=$purchase['firma_autoriza']??$defFirma;
$valRev=$purchase['revisado_por']??$defRev;
$valT1=$purchase['titular_1']??$defFirma;
$valT2=$purchase['titular_2']??$defVb;
$selectedProviderProfile = $purchase ? providerProfile($db, (string)($purchase['proveedor'] ?? '')) : [];
$providerAddressValue = (string)($selectedProviderProfile['direccion'] ?? '');
$providerBankValue = trim((string)($purchase['banco'] ?? '')) !== '' ? (string)$purchase['banco'] : (string)($selectedProviderProfile['banco'] ?? '');
$providerTransferValue = trim((string)($purchase['tipo_transferencia'] ?? '')) !== '' ? (string)$purchase['tipo_transferencia'] : (string)($selectedProviderProfile['tipo_transferencia'] ?? '');
$providerAccountTypeValue = trim((string)($purchase['tipo_cuenta'] ?? '')) !== '' ? (string)$purchase['tipo_cuenta'] : (string)($selectedProviderProfile['tipo_cuenta'] ?? '');
$providerAccountValue = trim((string)($purchase['cuenta_bancaria'] ?? '')) !== '' ? (string)$purchase['cuenta_bancaria'] : (string)($selectedProviderProfile['cuenta_bancaria'] ?? '');

if (!$msg && isset($_GET['msg'])) {
    $messages=[
        'success_solicitud'=>'Solicitud guardada correctamente.',
        'success_cotizaciones'=>'Resumen de cotizaciones guardado. El ganador alimentará la orden.',
        'success_orden'=>'Orden recalculada y guardada desde el servidor.',
        'success_planillas'=>'Planillas guardadas y cuadradas con la orden.',
        'success_transferencia'=>'Imputación y compromiso presupuestario registrados.',
        'success_autorizacion'=>'Pago autorizado y ejecutado una sola vez en el POA.',
        'success_almacen'=>'Recepción registrada y expediente finalizado.',
        'success_unlock'=>'Expediente desbloqueado durante 15 minutos. Si estaba autorizado, se reabrió y retiró temporalmente del POA hasta una nueva autorización.',
        'already_authorized'=>'El expediente ya estaba autorizado; no se duplicó la ejecución.'
    ];
    if (isset($messages[$_GET['msg']])) $msg='<div class="alert success"><i class="fa-solid fa-check"></i> '.h($messages[$_GET['msg']]).'</div>';
}

$orderViewDetails = $details;
$orderViewSubtotal = (float)($purchase['subtotal'] ?? 0);
$orderViewDiscount = (float)($purchase['descuento_total'] ?? 0);
$orderViewTax = (float)($purchase['isv_total'] ?? 0);
$orderViewGrand = (float)($purchase['monto_total'] ?? 0);
$firstPoaLine = $poaDistribution[0] ?? [];
$planillaPoaDefaults = [
    'programa' => trim((string)($firstPoaLine['programa_poa'] ?? '')) ?: trim((string)($firstPoaLine['sector'] ?? '')),
    'marco_logico' => trim((string)($firstPoaLine['marco_logico_poa'] ?? '')) ?: trim((string)($firstPoaLine['marco_logico'] ?? '')),
];
$winnerQuote = null;
if ($purchase && $format === 'B') {
    foreach ($quotes as $quote) {
        if (!empty($quote['es_ganador'])) { $winnerQuote = $quote; break; }
    }
    if ($winnerQuote) {
        $preview = [];
        foreach ($summaryRows as $row) {
            $pr = $prices[$row['id']][$winnerQuote['id']] ?? $prices[(string)$row['id']][(string)$winnerQuote['id']] ?? null;
            if (!$pr || (float)$pr['precio_unitario'] <= 0) continue;
            $preview[] = array_merge($row, [
                'id' => 'preview_'.$row['id'],
                'tipo_impuesto' => $pr['tipo_impuesto'],
                'precio_unitario' => $pr['precio_unitario'],
                'precio_total' => $pr['precio_total'],
            ]);
        }
        if ($preview) $orderViewDetails = $preview;
        $orderViewSubtotal = (float)$winnerQuote['subtotal'];
        $orderViewDiscount = (float)$winnerQuote['descuento'];
        $orderViewTax = (float)$winnerQuote['isv'];
        $orderViewGrand = (float)$winnerQuote['total'];
    }
}

$poaOptions='';
foreach ($poa_vigente_lines as $line) {
    $available=availableForPoa($db,(string)$line['hash_id'],$id);
    $label=compactPoaLabel($line['marco_logico'] ?? '', $line['ext'] ?? '');
    $poaOptions.='<option value="'.h($line['hash_id']).'" data-disp="'.$available.'" title="'.h($line['marco_logico']).'">'.h($label).'</option>';
}
?>

<?php require __DIR__ . '/views/compras_view.php';
