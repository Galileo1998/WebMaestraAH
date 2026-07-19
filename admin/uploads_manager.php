<?php
session_start();
require_once '../config/Database.php';
require_once '../classes/Auth.php';

$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$csrf_token = Auth::generateCSRF();

$msg = "";

// ========================================================
// 1. CONFIGURACIÓN DE RUTAS Y SEGURIDAD ANTI-TRAVERSAL
// ========================================================
$root_uploads = realpath(__DIR__ . '/../uploads/'); 
if (!$root_uploads) {
    die("Error: El directorio /uploads no existe en la raíz.");
}

$sub_folder = isset($_GET['folder']) ? trim($_GET['folder']) : '';
$current_path = realpath($root_uploads . '/' . $sub_folder);

if ($current_path === false || strpos($current_path, $root_uploads) !== 0) {
    $current_path = $root_uploads; 
    $sub_folder = '';
}

// CORRECCIÓN VITAL: URL Absoluta garantizada para descargas
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$domain = $_SERVER['HTTP_HOST'];
// Eliminamos dependencias extrañas y forzamos la ruta base suponiendo que estamos en /admin
$base_dir = rtrim(dirname($_SERVER['PHP_SELF'], 2), '/');
$url_folder_base = $protocol . "://" . $domain . $base_dir . "/uploads/" . ($sub_folder ? str_replace('\\', '/', $sub_folder) . '/' : '');

// ========================================================
// 2. PROCESAMIENTO DE ACCIONES (SUBIR, MOVER, CREAR, BORRAR)
// ========================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    Auth::checkCSRF($_POST['csrf_token'] ?? '');
    
    // ACCIÓN: SUBIR ARCHIVO
    if ($_POST['action'] == 'upload_file') {
        if (isset($_FILES['file']) && $_FILES['file']['error'] == 0) {
            $file = $_FILES['file'];
            $original_name = basename($file['name']);
            $clean_name = preg_replace("/[^a-zA-Z0-9\._-]/", "_", $original_name);
            $new_filename = time() . "_" . $clean_name;
            $target_file = $current_path . '/' . $new_filename;

            $allowed_exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'mp4', 'webm', 'zip', 'rar', '7z'];
            $ext = strtolower(pathinfo($target_file, PATHINFO_EXTENSION));

            $allowed_mimes = [
                'jpg' => ['image/jpeg'], 'jpeg' => ['image/jpeg'], 'png' => ['image/png'],
                'gif' => ['image/gif'], 'webp' => ['image/webp'], 'pdf' => ['application/pdf'],
                'doc' => ['application/msword', 'application/octet-stream'],
                'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
                'xls' => ['application/vnd.ms-excel', 'application/octet-stream'],
                'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
                'csv' => ['text/plain', 'text/csv', 'application/csv'],
                'mp4' => ['video/mp4'], 'webm' => ['video/webm'],
                'zip' => ['application/zip', 'application/x-zip-compressed'],
                'rar' => ['application/vnd.rar', 'application/x-rar-compressed', 'application/octet-stream'],
                '7z' => ['application/x-7z-compressed', 'application/octet-stream']
            ];
            $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
            $valid_size = (int)$file['size'] > 0 && (int)$file['size'] <= 26214400;
            $valid_mime = isset($allowed_mimes[$ext]) && in_array($mime, $allowed_mimes[$ext], true);

            if (in_array($ext, $allowed_exts, true) && $valid_size && $valid_mime) {
                if (move_uploaded_file($file['tmp_name'], $target_file)) {
                    $msg = "<div class='alert success'><i class='fa-solid fa-check-circle'></i> Archivo subido con éxito a esta carpeta.</div>";
                } else {
                    $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al mover el archivo al servidor. Verifica permisos.</div>";
                }
            } else {
                $msg = "<div class='alert error'><i class='fa-solid fa-ban'></i> Archivo no permitido, vacío, con contenido incompatible o mayor de 25 MB.</div>";
            }
        }
    }

    // ACCIÓN: CREAR CARPETA
    if ($_POST['action'] == 'create_folder') {
        $folder_name = trim($_POST['folder_name']);
        $clean_folder_name = preg_replace("/[^a-zA-Z0-9_-]/", "_", $folder_name); 
        
        if (!empty($clean_folder_name)) {
            $new_dir_path = $current_path . '/' . $clean_folder_name;
            if (!file_exists($new_dir_path)) {
                if (mkdir($new_dir_path, 0755, true)) {
                    $msg = "<div class='alert success'><i class='fa-solid fa-folder-plus'></i> Carpeta creada con éxito.</div>";
                } else {
                    $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al crear la carpeta. Verifica permisos del servidor.</div>";
                }
            } else {
                $msg = "<div class='alert error'><i class='fa-solid fa-copy'></i> Ya existe un elemento con ese nombre.</div>";
            }
        } else {
            $msg = "<div class='alert error'><i class='fa-solid fa-ban'></i> El nombre de la carpeta no es válido.</div>";
        }
    }

    // ACCIÓN: ELIMINAR ARCHIVO O CARPETA
    if ($_POST['action'] == 'delete_item') {
        $item_name = $_POST['item_name'];
        $target_path = realpath($current_path . '/' . $item_name);
        
        if ($target_path && strpos($target_path, $root_uploads) === 0) {
            if (is_dir($target_path)) {
                if (@rmdir($target_path)) {
                    $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Carpeta eliminada.</div>";
                } else {
                    $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> No se puede eliminar. Asegúrate de que la carpeta esté vacía.</div>";
                }
            } else {
                if (@unlink($target_path)) {
                    $msg = "<div class='alert success'><i class='fa-solid fa-trash'></i> Archivo eliminado.</div>";
                } else {
                    $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al eliminar el archivo.</div>";
                }
            }
        }
    }

    // ACCIÓN: MOVER ARCHIVO/CARPETA
    if ($_POST['action'] == 'move_item') {
        $item_name = $_POST['item_name'];
        $dest_folder = $_POST['dest_folder']; 

        $source_path = realpath($current_path . '/' . $item_name);
        
        if ($dest_folder === '') {
            $dest_path = $root_uploads;
        } else {
            $dest_path = realpath($root_uploads . '/' . $dest_folder);
        }

        if ($source_path && $dest_path && strpos($source_path, $root_uploads) === 0 && strpos($dest_path, $root_uploads) === 0) {
            $final_dest = $dest_path . '/' . $item_name;
            
            if (!file_exists($final_dest)) {
                if (rename($source_path, $final_dest)) {
                    $msg = "<div class='alert success'><i class='fa-solid fa-truck-fast'></i> Elemento movido exitosamente.</div>";
                } else {
                    $msg = "<div class='alert error'><i class='fa-solid fa-triangle-exclamation'></i> Error al mover el elemento en el servidor.</div>";
                }
            } else {
                $msg = "<div class='alert error'><i class='fa-solid fa-copy'></i> Ya existe un archivo con ese nombre en la carpeta de destino.</div>";
            }
        } else {
            $msg = "<div class='alert error'><i class='fa-solid fa-shield-halved'></i> Movimiento bloqueado por reglas de seguridad.</div>";
        }
    }
}

// ========================================================
// 3. MAPEO DINÁMICO DE DIRECTORIOS Y ARCHIVOS
// ========================================================
$directories = [];
$files = [];

if (is_dir($current_path)) {
    $iterator = new DirectoryIterator($current_path);
    foreach ($iterator as $item) {
        if ($iterator->isDot()) continue; 
        
        $name = $item->getFilename();
        
        if ($item->isDir()) {
            $directories[] = [
                'name' => $name,
                'rel_path' => ($sub_folder ? $sub_folder . '/' : '') . $name,
                'mtime' => $item->getMTime()
            ];
        } else {
            if ($name === '.htaccess') continue;
            
            $files[] = [
                'name' => $name,
                'size' => $item->getSize(),
                'ext' => strtolower($item->getExtension()),
                'url' => $url_folder_base . rawurlencode($name), // URL Segura con espacios encodificados
                'mtime' => $item->getMTime()
            ];
        }
    }
}

usort($directories, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });

function getFolderTree($dir, $root_path, $prefix = '') {
    $tree = [];
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            $rel_path = str_replace($root_path . '/', '', $path);
            if ($rel_path === $root_path) $rel_path = ''; 
            $tree[$rel_path] = $prefix . $item;
            $tree = array_merge($tree, getFolderTree($path, $root_path, $prefix . '-- '));
        }
    }
    return $tree;
}
$all_folders = getFolderTree($root_uploads, $root_uploads);

function formatBytes($size, $precision = 2) {
    if ($size <= 0) return '0 B';
    $base = log($size, 1024);
    $suffixes = array('B', 'KB', 'MB', 'GB');   
    return round(pow(1024, $base - floor($base)), $precision) .' '. $suffixes[floor($base)];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nube | Acción Honduras</title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <script src="https://unpkg.com/jquery@3.7.0/dist/jquery.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root { --ah-primary: #34859B; --bg-canvas: #f8fafc; --border: #cbd5e1; --text-main: #1e293b; --text-muted: #64748b; }
        body { font-family: 'Inter', sans-serif; display: flex; min-height: 100vh; background: var(--bg-canvas); margin: 0; }
        .main-wrapper { flex-grow: 1; padding: 25px 40px; overflow-y: auto; width: 100%; box-sizing: border-box; }
        
        .alert { padding: 15px; border-radius: 8px; margin-bottom: 20px; font-weight: 600; }
        .success { background: #dcfce7; color: #166534; border: 1px solid #bbf7d0;} 
        .error { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca;}

        .cloud-toolbar { display: flex; justify-content: space-between; align-items: center; background: white; padding: 15px 20px; border-radius: 12px; border: 1px solid var(--border); box-shadow: 0 2px 4px rgba(0,0,0,0.02); margin-bottom: 20px; gap: 20px; flex-wrap: wrap;}
        .cloud-search { flex-grow: 1; max-width: 500px; position: relative; }
        .cloud-search i { position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--text-muted); }
        .cloud-search input { width: 100%; padding: 10px 10px 10px 40px; border: 1px solid var(--border); border-radius: 8px; background: #f1f5f9; font-family: 'Inter', sans-serif; font-size: 0.95rem; box-sizing: border-box; transition: 0.2s;}
        .cloud-search input:focus { outline: none; border-color: var(--ah-primary); background: white; box-shadow: 0 0 0 3px rgba(52,133,155,0.1);}
        
        .view-controls { display: flex; gap: 8px; align-items: center;}
        .btn-upload { background: var(--ah-primary); color: white; border: none; padding: 10px 20px; font-weight: bold; border-radius: 6px; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:8px;}
        .btn-upload:hover { opacity:0.9; transform: translateY(-1px);}
        .btn-outline { background: white; color: #334155; border: 1px solid var(--border); padding: 10px 15px; font-weight: bold; border-radius: 6px; cursor: pointer; transition:0.2s; display:inline-flex; align-items:center; gap:8px;}
        .btn-outline:hover { background: #f1f5f9; color: var(--ah-primary); border-color: var(--ah-primary);}
        
        .btn-view { background: transparent; border: 1px solid transparent; color: var(--text-muted); padding: 8px; border-radius: 6px; cursor: pointer; transition: 0.2s; font-size: 1.2rem; }
        .btn-view:hover { background: #f1f5f9; color: var(--text-main); }
        .btn-view.active { background: #e0f2fe; color: var(--ah-primary); border-color: #bae6fd; }

        .breadcrumbs { display: flex; align-items: center; gap: 8px; font-size: 1rem; margin-bottom: 20px; font-weight: 600; color: var(--text-main); }
        .breadcrumbs a { color: var(--text-muted); text-decoration: none; transition: 0.2s;}
        .breadcrumbs a:hover { color: var(--ah-primary); }
        .breadcrumbs .current { color: var(--text-main); }

        .cloud-container { transition: 0.3s; }
        
        .view-list { border: 1px solid var(--border); border-radius: 8px; background: white; overflow: hidden; }
        .view-list .cloud-item { display: grid; grid-template-columns: 3fr 1fr 1fr 1.5fr; padding: 12px 20px; border-bottom: 1px solid #e2e8f0; align-items: center; font-size: 0.9rem; color: var(--text-main); transition: 0.2s;}
        .view-list .cloud-item.header { background: #f8fafc; color: var(--text-muted); font-weight: 600; border-bottom: 2px solid #e2e8f0; font-size: 0.8rem; text-transform: uppercase;}
        .view-list .cloud-item:last-child { border: none; }
        .view-list .cloud-item:not(.header):hover { background: #f1f5f9; }
        
        .view-list .item-icon { font-size: 1.4rem; width: 30px; text-align: center; }
        .view-list .item-name-block { display: flex; align-items: center; gap: 15px; font-weight: 500; }
        .view-list .item-name-block a { color: var(--text-main); text-decoration: none; font-weight: 600; }
        .view-list .item-name-block a:hover { color: var(--ah-primary); text-decoration: underline;}
        .view-list .item-actions { display: flex; justify-content: flex-end; gap: 8px; opacity: 0; transition: 0.2s;}
        .view-list .cloud-item:hover .item-actions { opacity: 1; }

        .view-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 20px; }
        .view-grid .cloud-item.header { display: none; } 
        .view-grid .cloud-item { background: white; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; display: flex; flex-direction: column; transition: 0.2s; box-shadow: 0 2px 4px rgba(0,0,0,0.02);}
        .view-grid .cloud-item:hover { transform: translateY(-3px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border-color: var(--ah-primary); }
        
        .view-grid .item-preview-box { height: 140px; background: #f1f5f9; display: flex; align-items: center; justify-content: center; overflow: hidden; position: relative; border-bottom: 1px solid #e2e8f0;}
        .view-grid .item-preview-box img { width: 100%; height: 100%; object-fit: cover; }
        .view-grid .item-preview-box .item-icon { font-size: 4rem; }
        
        .view-grid .item-info-box { padding: 15px; display: flex; flex-direction: column; gap: 5px; flex-grow: 1;}
        .view-grid .item-name-block { display: flex; align-items: center; gap: 10px; font-weight: 600; font-size: 0.9rem; }
        .view-grid .item-name-block i { display: none; } 
        .view-grid .item-name-block a, .view-grid .item-name-block span { color: var(--text-main); text-decoration: none; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; width: 100%; display: block;}
        .view-grid .item-meta { font-size: 0.75rem; color: var(--text-muted); display: flex; justify-content: space-between;}
        
        .view-grid .item-actions { display: flex; justify-content: center; padding: 10px; background: #f8fafc; border-top: 1px solid #e2e8f0; gap: 5px;}

        .btn-action { background: transparent; border: 1px solid #cbd5e1; color: var(--text-main); width: 32px; height: 32px; border-radius: 6px; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: 0.2s; text-decoration: none;}
        .btn-action:hover { background: #e2e8f0; color: var(--ah-primary); border-color: var(--ah-primary);}
        .btn-action.copied { background: #16a34a; color: white; border-color: #15803d; }
        .btn-action.preview:hover { background: #e0e7ff; color: #4f46e5; border-color: #818cf8; }
        .btn-action.move:hover { background: #fef3c7; color: #b45309; border-color: #f59e0b; }
        .btn-action.delete { color: #dc2626; border-color: #fecaca; background: #fee2e2; }
        .btn-action.delete:hover { background: #ef4444; color: white; border-color: #dc2626; }

        .modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.9); z-index: 9999; display: none; align-items: center; justify-content: center; backdrop-filter: blur(5px); }
        .modal-content { background: transparent; width: 90%; max-width: 1000px; height: 85vh; border-radius: 8px; display: flex; flex-direction: column; position: relative; animation: zoomIn 0.3s ease;}
        .modal-header { display: flex; justify-content: space-between; align-items: center; color: white; padding: 15px 0; }
        .modal-title { font-size: 1.2rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;}
        .btn-close-modal { background: rgba(255,255,255,0.1); border: none; color: white; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; transition: 0.2s; display: flex; align-items: center; justify-content: center;}
        .btn-close-modal:hover { background: #ef4444; }
        .modal-body { flex-grow: 1; background: #000; border-radius: 8px; overflow: hidden; display: flex; align-items: center; justify-content: center; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);}
        .modal-body img, .modal-body iframe, .modal-body video { width: 100%; height: 100%; object-fit: contain; border: none;}
        .modal-body .no-preview { color: white; text-align: center; font-size: 1.2rem; color: #94a3b8;}
        
        .modal-small { background: white; max-width: 500px; height: auto; padding: 25px; border-radius: 12px; }
        .modal-small .modal-header { color: var(--text-main); padding: 0 0 15px 0; border-bottom: 1px solid var(--border); margin-bottom: 20px;}
        .modal-small .btn-close-modal { color: var(--text-muted); background: transparent; }
        .modal-small .btn-close-modal:hover { color: #ef4444; background: #fee2e2; }
        
        .form-select { width: 100%; padding: 10px; border: 1px solid var(--border); border-radius: 6px; font-family: inherit; font-size: 1rem; margin-bottom: 20px; box-sizing:border-box;}
        .form-select:focus { outline: none; border-color: var(--ah-primary); }

        @keyframes zoomIn { from { transform: scale(0.95); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .toast-msg { position:fixed; bottom:20px; right:20px; background:#166534; color:white; padding:10px 20px; border-radius:6px; box-shadow:0 4px 6px rgba(0,0,0,0.1); display:none; z-index:10000; font-weight:bold;}
    </style>
</head>
<body>
    <?php include 'sidebar.php'; ?>
    <div id="toast" class="toast-msg"><i class="fa-solid fa-check"></i> Copiado al portapapeles</div>

    <main class="main-wrapper">
        
        <?php echo $msg; ?>

        <div class="cloud-toolbar">
            <div class="cloud-search">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="searchInput" placeholder="Buscar archivos o carpetas en esta ubicación...">
            </div>
            <div class="view-controls">
                <button type="button" class="btn-outline" onclick="openCreateFolderModal()">
                    <i class="fa-solid fa-folder-plus"></i> Crear Carpeta
                </button>

                <form id="uploadForm" method="POST" enctype="multipart/form-data" style="display: none;">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="upload_file">
                    <input type="file" id="fileUploadInput" name="file" onchange="document.getElementById('uploadForm').submit();">
                </form>
                <button type="button" class="btn-upload" onclick="document.getElementById('fileUploadInput').click();">
                    <i class="fa-solid fa-cloud-arrow-up"></i> Subir Archivo
                </button>

                <div style="border-left: 1px solid var(--border); height: 30px; margin: 0 10px;"></div>

                <button type="button" class="btn-view" id="btn-list" title="Vista de Lista" onclick="setView('list')"><i class="fa-solid fa-list-ul"></i></button>
                <button type="button" class="btn-view" id="btn-grid" title="Vista de Cuadrícula" onclick="setView('grid')"><i class="fa-solid fa-border-all"></i></button>
            </div>
        </div>

        <div class="breadcrumbs">
            <a href="uploads_manager.php"><i class="fa-solid fa-cloud"></i> Nube AH</a>
            <?php 
            if (!empty($sub_folder)) {
                $parts = explode('/', $sub_folder);
                $accum_path = '';
                foreach ($parts as $idx => $p) {
                    $accum_path .= ($accum_path ? '/' : '') . $p;
                    echo ' <i class="fa-solid fa-chevron-right" style="font-size:0.8rem; color:#cbd5e1;"></i> ';
                    if($idx === count($parts) - 1) {
                        echo '<span class="current">'.htmlspecialchars($p).'</span>';
                    } else {
                        echo '<a href="uploads_manager.php?folder='.urlencode($accum_path).'">'.htmlspecialchars($p).'</a>';
                    }
                }
            }
            ?>
        </div>

        <div id="cloud-container" class="cloud-container view-list">
            <div class="cloud-item header">
                <div>Nombre</div>
                <div>Modificado</div>
                <div>Tamaño</div>
                <div style="text-align: right;">Acciones</div>
            </div>

            <?php if (!empty($sub_folder)): 
                $parent = dirname($sub_folder);
                if ($parent === '.' || $parent === '/') $parent = '';
            ?>
                <div class="cloud-item search-item">
                    <div class="item-preview-box" style="display:none;">
                        <i class="fa-solid fa-arrow-turn-up item-icon" style="color:#94a3b8; transform: scaleX(-1);"></i>
                    </div>
                    <div class="item-name-block item-info-box">
                        <i class="fa-solid fa-arrow-turn-up item-icon" style="color:#94a3b8; transform: scaleX(-1);"></i>
                        <a href="uploads_manager.php?folder=<?php echo urlencode($parent); ?>">.. Volver a carpeta anterior</a>
                    </div>
                    <div class="item-meta list-only">-</div>
                    <div class="item-meta list-only">-</div>
                    <div class="item-actions"></div>
                </div>
            <?php endif; ?>

            <?php foreach($directories as $dir): ?>
                <div class="cloud-item search-item" data-name="<?php echo strtolower($dir['name']); ?>">
                    <div class="item-preview-box" style="display:none;">
                        <i class="fa-solid fa-folder item-icon" style="color:#eab308;"></i>
                    </div>
                    <div class="item-name-block item-info-box">
                        <i class="fa-solid fa-folder item-icon" style="color:#eab308;"></i>
                        <a href="uploads_manager.php?folder=<?php echo urlencode($dir['rel_path']); ?>" title="<?php echo htmlspecialchars($dir['name']); ?>"><?php echo htmlspecialchars($dir['name']); ?></a>
                    </div>
                    <div class="item-meta list-only"><?php echo date('d M Y, H:i', $dir['mtime']); ?></div>
                    <div class="item-meta list-only">-</div>
                    <div class="item-actions">
                        <button type="button" class="btn-action move" title="Mover Carpeta" onclick="openMoveModal('<?php echo htmlspecialchars($dir['name']); ?>')">
                            <i class="fa-solid fa-arrows-up-down-left-right"></i>
                        </button>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar carpeta? Debe estar vacía para poder borrarse.');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($dir['name']); ?>">
                            <button type="submit" class="btn-action delete" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php foreach($files as $file): 
                $icon = 'fa-file-lines'; $color = '#94a3b8'; $type = 'file';
                $is_image = false; $is_pdf = false; $is_video = false;
                
                if (in_array($file['ext'], ['jpg', 'jpeg', 'png', 'webp', 'gif'])) { $icon = 'fa-image'; $color = '#3b82f6'; $is_image = true; $type = 'image'; }
                elseif ($file['ext'] === 'pdf') { $icon = 'fa-file-pdf'; $color = '#ef4444'; $is_pdf = true; $type = 'pdf'; }
                elseif (in_array($file['ext'], ['xls', 'xlsx', 'csv'])) { $icon = 'fa-file-excel'; $color = '#22c55e'; }
                elseif (in_array($file['ext'], ['doc', 'docx'])) { $icon = 'fa-file-word'; $color = '#2563eb'; }
                elseif (in_array($file['ext'], ['mp4', 'webm'])) { $icon = 'fa-video'; $color = '#a855f7'; $is_video = true; $type = 'video';}
                elseif (in_array($file['ext'], ['zip', 'rar', '7z'])) { $icon = 'fa-file-zipper'; $color = '#f59e0b'; }
            ?>
                <div class="cloud-item search-item" data-name="<?php echo strtolower($file['name']); ?>">
                    
                    <div class="item-preview-box" style="display:none;" onclick="openPreview('<?php echo $file['url']; ?>', '<?php echo $type; ?>', '<?php echo htmlspecialchars($file['name']); ?>')">
                        <?php if($is_image): ?>
                            <img src="<?php echo $file['url']; ?>" alt="preview" loading="lazy">
                        <?php else: ?>
                            <i class="fa-solid <?php echo $icon; ?> item-icon" style="color:<?php echo $color; ?>;"></i>
                        <?php endif; ?>
                    </div>
                    
                    <div class="item-name-block item-info-box">
                        <i class="fa-solid <?php echo $icon; ?> item-icon list-only-icon" style="color:<?php echo $color; ?>;"></i>
                        <span title="<?php echo htmlspecialchars($file['name']); ?>"><?php echo htmlspecialchars($file['name']); ?></span>
                        
                        <div class="item-meta grid-only" style="display:none;">
                            <span><?php echo strtoupper($file['ext']); ?></span>
                            <span><?php echo formatBytes($file['size'], 0); ?></span>
                        </div>
                    </div>
                    
                    <div class="item-meta list-only"><?php echo date('d M Y, H:i', $file['mtime']); ?></div>
                    <div class="item-meta list-only"><?php echo formatBytes($file['size'], 1); ?></div>
                    
                    <div class="item-actions">
                        <?php if($is_image || $is_pdf || $is_video): ?>
                            <button type="button" class="btn-action preview" title="Previsualizar" onclick="openPreview('<?php echo $file['url']; ?>', '<?php echo $type; ?>', '<?php echo htmlspecialchars($file['name']); ?>')">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                        <?php endif; ?>
                        
                        <button type="button" class="btn-action move" title="Mover Archivo" onclick="openMoveModal('<?php echo htmlspecialchars($file['name']); ?>')">
                            <i class="fa-solid fa-arrows-up-down-left-right"></i>
                        </button>

                        <button type="button" class="btn-action" title="Copiar Enlace" onclick="copyUrl('<?php echo $file['url']; ?>', this)">
                            <i class="fa-solid fa-link"></i>
                        </button>
                        
                        <a href="<?php echo $file['url']; ?>" download class="btn-action" title="Descargar" target="_blank">
                            <i class="fa-solid fa-download"></i>
                        </a>

                        <form method="POST" style="display:inline;" onsubmit="return confirm('¿Eliminar este archivo permanentemente?');">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="action" value="delete_item">
                            <input type="hidden" name="item_name" value="<?php echo htmlspecialchars($file['name']); ?>">
                            <button type="submit" class="btn-action delete" title="Eliminar"><i class="fa-solid fa-trash-can"></i></button>
                        </form>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (count($directories) === 0 && count($files) === 0): ?>
                <div style="text-align:center; padding: 60px; color:#94a3b8; grid-column: 1 / -1;">
                    <img src="https://cdn-icons-png.flaticon.com/512/7486/7486747.png" style="width:120px; opacity:0.5; margin-bottom:15px; filter: grayscale(100%);">
                    <p style="margin:0; font-size:1.1rem; color:#475569; font-weight:600;">Esta carpeta está vacía</p>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <div id="previewModal" class="modal-overlay" onclick="closePreview(event)">
        <div class="modal-content" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-title" id="previewTitle">Archivo</div>
                <button class="btn-close-modal" onclick="closePreview(true)"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="modal-body" id="previewBody"></div>
        </div>
    </div>

    <div id="moveModal" class="modal-overlay" onclick="closeMoveModal(event)">
        <div class="modal-content modal-small" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-truck-fast"></i> Mover Elemento</div>
                <button class="btn-close-modal" onclick="closeMoveModal(true)"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="color:var(--text-main);">
                <p style="margin-top:0; font-size:0.9rem;">Mover: <strong id="moveItemDisplay" style="color:var(--ah-primary);"></strong></p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="move_item">
                    <input type="hidden" name="item_name" id="moveItemNameInput">
                    <select name="dest_folder" class="form-select" required>
                        <option value="">📁 Raíz Principal (/uploads)</option>
                        <?php foreach($all_folders as $path => $formatted_name): ?>
                            <?php if ($path !== $sub_folder): ?>
                                <option value="<?php echo htmlspecialchars($path); ?>">📁 <?php echo htmlspecialchars($formatted_name); ?></option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                    <div style="text-align:right;">
                        <button type="button" class="btn-action" style="display:inline-flex; width:auto; padding:0 15px;" onclick="closeMoveModal(true)">Cancelar</button>
                        <button type="submit" class="btn-upload" style="margin-left:10px;"><i class="fa-solid fa-check"></i> Mover</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="createFolderModal" class="modal-overlay" onclick="closeCreateFolderModal(event)">
        <div class="modal-content modal-small" onclick="event.stopPropagation()">
            <div class="modal-header">
                <div class="modal-title"><i class="fa-solid fa-folder-plus"></i> Nueva Carpeta</div>
                <button class="btn-close-modal" onclick="closeCreateFolderModal(true)"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div style="color:var(--text-main);">
                <p style="margin-top:0; font-size:0.9rem;">Ingresa el nombre de la carpeta a crear en la ubicación actual.</p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="action" value="create_folder">
                    <input type="text" name="folder_name" class="form-select" placeholder="Ej: Materiales_Logistica" required pattern="[a-zA-Z0-9_-]+" title="Solo letras, números y guiones. Sin espacios.">
                    <div style="text-align:right;">
                        <button type="button" class="btn-action" style="display:inline-flex; width:auto; padding:0 15px;" onclick="closeCreateFolderModal(true)">Cancelar</button>
                        <button type="submit" class="btn-upload" style="margin-left:10px;"><i class="fa-solid fa-plus"></i> Crear</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function setView(viewType) {
            const container = $('#cloud-container');
            const btnList = $('#btn-list');
            const btnGrid = $('#btn-grid');
            
            if(viewType === 'grid') {
                container.removeClass('view-list').addClass('view-grid');
                btnGrid.addClass('active');
                btnList.removeClass('active');
                $('.item-preview-box').show();
                $('.grid-only').show();
                $('.list-only, .list-only-icon').hide();
                localStorage.setItem('ah_cloud_view', 'grid');
            } else {
                container.removeClass('view-grid').addClass('view-list');
                btnList.addClass('active');
                btnGrid.removeClass('active');
                $('.item-preview-box').hide();
                $('.grid-only').hide();
                $('.list-only, .list-only-icon').show();
                localStorage.setItem('ah_cloud_view', 'list');
            }
        }

        $(document).ready(function() {
            let savedView = localStorage.getItem('ah_cloud_view') || 'list';
            setView(savedView);
        });

        $('#searchInput').on('keyup', function() {
            let filter = $(this).val().toLowerCase();
            $('.search-item').each(function() {
                let name = $(this).data('name');
                if (name && name.indexOf(filter) > -1) {
                    $(this).show();
                } else {
                    if(!$(this).find('.fa-arrow-turn-up').length) { $(this).hide(); }
                }
            });
        });

        function copyUrl(url, btn) {
            navigator.clipboard.writeText(url).then(function() {
                let orig = btn.innerHTML;
                $(btn).addClass('copied').html('<i class="fa-solid fa-check"></i>');
                $('#toast').fadeIn().delay(1500).fadeOut();
                setTimeout(() => { $(btn).removeClass('copied').html(orig); }, 1500);
            });
        }

        function openPreview(url, type, name) {
            $('#previewTitle').text(name);
            let body = $('#previewBody');
            body.empty(); 
            if(type === 'image') { body.html(`<img src="${url}" alt="${name}">`); } 
            else if (type === 'pdf') { body.html(`<iframe src="${url}#toolbar=0"></iframe>`); } 
            else if (type === 'video') { body.html(`<video controls autoplay><source src="${url}" type="video/mp4">Tu navegador no soporta video.</video>`); } 
            else { body.html(`<div class="no-preview"><i class="fa-solid fa-eye-slash"></i><br>Formato no previsualizable.</div>`); }
            $('#previewModal').css('display', 'flex');
        }

        function closePreview(force = false) {
            if(force || event.target.id === 'previewModal') {
                $('#previewModal').hide();
                $('#previewBody').empty(); 
            }
        }

        function openMoveModal(itemName) {
            $('#moveItemDisplay').text(itemName);
            $('#moveItemNameInput').val(itemName);
            $('#moveModal').css('display', 'flex');
        }

        function closeMoveModal(force = false) {
            if(force || event.target.id === 'moveModal') { $('#moveModal').hide(); }
        }

        function openCreateFolderModal() {
            $('#createFolderModal').css('display', 'flex');
            setTimeout(() => { $('input[name="folder_name"]').focus(); }, 100);
        }

        function closeCreateFolderModal(force = false) {
            if(force || event.target.id === 'createFolderModal') { $('#createFolderModal').hide(); }
        }

        $(document).keydown(function(e) {
            if (e.key === "Escape") { 
                closePreview(true); 
                closeMoveModal(true);
                closeCreateFolderModal(true);
            }
        });
    </script>
</body>
</html>
