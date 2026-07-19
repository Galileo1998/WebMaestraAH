<?php
// =================================================================
// ARCHIVO: admin/mensajes.php
// MÓDULO DE GESTIÓN DE MENSAJES DE CONTACTO
// =================================================================
session_start();

// Validar que el administrador esté logueado
// if (!isset($_SESSION['admin_logged_in'])) { header("Location: login.php"); exit; }

require_once __DIR__ . '/../config/Database.php';
require_once __DIR__ . '/../classes/Auth.php';
$database = new Database();
$db = $database->getConnection();
$auth = new Auth($db);
$auth->requireLogin();
$csrf_token = Auth::generateCSRF();

// =======================================================
// LÓGICA DE ACCIONES (Marcar leído, eliminar)
// =======================================================
$mensaje_sistema = '';

if (isset($_GET['action']) && isset($_GET['id'])) {
    Auth::checkCSRF($_GET['token'] ?? '');
    $id_accion = (int)$_GET['id'];
    
    if ($_GET['action'] === 'marcar_leido') {
        $stmt = $db->prepare("UPDATE contact_messages SET status = 'leido' WHERE id = :id");
        if ($stmt->execute([':id' => $id_accion])) {
            $mensaje_sistema = '<div class="alert alert-success"><i class="fa-solid fa-check-circle"></i> Mensaje marcado como leído.</div>';
        }
    } elseif ($_GET['action'] === 'eliminar') {
        $stmt = $db->prepare("DELETE FROM contact_messages WHERE id = :id");
        if ($stmt->execute([':id' => $id_accion])) {
            $mensaje_sistema = '<div class="alert alert-warning"><i class="fa-solid fa-trash-can"></i> Mensaje eliminado correctamente.</div>';
        }
    }
    // Redirigir para limpiar la URL y evitar re-envíos al actualizar la página
    header("Location: mensajes.php");
    exit;
}

// =======================================================
// EXTRAER LOS MENSAJES (Ordenados: Más recientes primero)
// =======================================================
$query = "SELECT * FROM contact_messages ORDER BY created_at DESC";
$stmt = $db->query($query);
$mensajes = $stmt->fetchAll(PDO::FETCH_ASSOC);

// =======================================================
// VISTA (HTML)
// =======================================================
// include 'includes/header.php'; // Tu header del panel de administración
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Bandeja de Entrada | Admin Acción Honduras</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* Estilos Base */
        body { font-family: 'Inter', sans-serif; background-color: #f1f5f9; color: #1e293b; margin: 0; padding: 30px 20px; }
        .admin-container { max-width: 1100px; margin: 0 auto; background: white; padding: 30px; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05); }
        
        /* Cabecera y Botón Volver */
        .header-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; border-bottom: 1px solid #e2e8f0; padding-bottom: 15px; }
        .header-top h1 { margin: 0; font-size: 1.6rem; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        
        .btn-back { display: inline-flex; align-items: center; gap: 8px; background: #e2e8f0; color: #475569; padding: 10px 18px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.95rem; transition: all 0.2s; }
        .btn-back:hover { background: #cbd5e1; color: #0f172a; }

        /* Alertas */
        .alert { padding: 14px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; display: flex; align-items: center; gap: 10px; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .alert-warning { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }

        /* Tabla */
        .table-responsive { overflow-x: auto; }
        .data-table { width: 100%; border-collapse: collapse; min-width: 800px; }
        .data-table th { background: #f8fafc; padding: 14px 16px; text-align: left; font-size: 0.85rem; text-transform: uppercase; letter-spacing: 0.5px; color: #64748b; border-bottom: 2px solid #e2e8f0; }
        .data-table td { padding: 16px; border-bottom: 1px solid #e2e8f0; vertical-align: middle; font-size: 0.95rem; }
        .data-table tr:hover { background: #f8fafc; }
        
        /* Fila de Mensaje Nuevo (Pendiente) */
        .row-unread { background-color: #f0fdfa !important; font-weight: 600; }
        .row-unread td { border-bottom: 1px solid #ccfbf1; }

        /* Badges */
        .badge { padding: 6px 12px; border-radius: 20px; font-size: 0.75rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .badge-pendiente { background: #fee2e2; color: #b91c1c; } /* Rojo para llamar la atención */
        .badge-leido { background: #f1f5f9; color: #64748b; }

        /* Botones de Acción en Tabla */
        .action-group { display: flex; gap: 8px; justify-content: flex-end; }
        .btn-icon { width: 36px; height: 36px; border-radius: 8px; border: none; display: flex; align-items: center; justify-content: center; cursor: pointer; transition: all 0.2s; text-decoration: none; color: white; font-size: 0.9rem; }
        .btn-view { background: #34859b; } .btn-view:hover { background: #2c7285; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(52, 133, 155, 0.2); }
        .btn-check { background: #10b981; } .btn-check:hover { background: #059669; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(16, 185, 129, 0.2); }
        .btn-delete { background: #ef4444; } .btn-delete:hover { background: #dc2626; transform: translateY(-2px); box-shadow: 0 4px 6px rgba(239, 68, 68, 0.2); }

        /* Modal */
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(15, 23, 42, 0.75); z-index: 1000; align-items: center; justify-content: center; backdrop-filter: blur(4px); }
        .modal-box { background: white; width: 95%; max-width: 650px; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25); transform: translateY(20px); opacity: 0; transition: all 0.3s ease; }
        .modal-overlay.active .modal-box { transform: translateY(0); opacity: 1; }
        
        .modal-header { background: #f8fafc; padding: 24px; border-bottom: 1px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; }
        .modal-header h2 { margin: 0; font-size: 1.25rem; color: #0f172a; display: flex; align-items: center; gap: 10px; }
        .modal-close { background: none; border: none; font-size: 1.5rem; color: #94a3b8; cursor: pointer; transition: color 0.2s; }
        .modal-close:hover { color: #0f172a; }
        
        .modal-body { padding: 24px; max-height: 60vh; overflow-y: auto; }
        .msg-meta { background: #f8fafc; padding: 16px; border-radius: 8px; margin-bottom: 20px; border: 1px solid #e2e8f0; }
        .msg-meta p { margin: 0 0 8px 0; font-size: 0.95rem; color: #475569; }
        .msg-meta p:last-child { margin: 0; }
        .msg-content { font-size: 1.05rem; line-height: 1.7; color: #1e293b; white-space: pre-wrap; }
        
        .modal-footer { padding: 20px 24px; border-top: 1px solid #e2e8f0; display: flex; justify-content: flex-end; gap: 12px; }
        .btn-text { padding: 10px 20px; border-radius: 8px; text-decoration: none; font-weight: 600; font-size: 0.95rem; cursor: pointer; border: none; transition: background 0.2s; }
        .btn-reply { background: #34859b; color: white; } .btn-reply:hover { background: #2c7285; }
        .btn-cancel { background: #f1f5f9; color: #475569; } .btn-cancel:hover { background: #e2e8f0; }
    </style>
</head>
<body>

<div class="admin-container">
    
    <div class="header-top">
        <h1><i class="fa-solid fa-envelope-open-text" style="color: #34859b;"></i> Bandeja de Entrada</h1>
        <a href="index.php" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Volver al Dashboard
        </a>
    </div>

    <?php if (isset($_SESSION['mensaje_sistema'])) { 
        echo $_SESSION['mensaje_sistema']; 
        unset($_SESSION['mensaje_sistema']); 
    } ?>

    <div class="table-responsive">
        <table class="data-table">
            <thead>
                <tr>
                    <th width="15%">Fecha Recibido</th>
                    <th width="25%">Remitente</th>
                    <th width="35%">Asunto</th>
                    <th width="10%">Estado</th>
                    <th width="15%" style="text-align: right;">Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mensajes)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: #64748b;">
                            <i class="fa-regular fa-folder-open" style="font-size: 2rem; margin-bottom: 10px; display: block; color: #cbd5e1;"></i>
                            Bandeja vacía. No hay mensajes registrados.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($mensajes as $msg): ?>
                        <tr class="<?php echo ($msg['status'] === 'pendiente') ? 'row-unread' : ''; ?>">
                            <td style="color: #64748b; font-size: 0.9rem;">
                                <?php echo date('d/m/Y', strtotime($msg['created_at'])); ?><br>
                                <small><?php echo date('h:i A', strtotime($msg['created_at'])); ?></small>
                            </td>
                            <td>
                                <strong style="color: #0f172a;"><?php echo htmlspecialchars($msg['name']); ?></strong><br>
                                <span style="color: #64748b; font-size: 0.85rem;"><?php echo htmlspecialchars($msg['email']); ?></span>
                            </td>
                            <td>
                                <?php echo htmlspecialchars($msg['subject'] ?: 'Sin Asunto especificado'); ?>
                            </td>
                            <td>
                                <span class="badge badge-<?php echo strtolower($msg['status']); ?>">
                                    <?php echo $msg['status']; ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-group">
                                    <button class="btn-icon btn-view" onclick='abrirModal(<?php echo json_encode($msg); ?>)' title="Leer Mensaje">
                                        <i class="fa-solid fa-eye"></i>
                                    </button>
                                    
                                    <?php if ($msg['status'] === 'pendiente'): ?>
                                        <a href="mensajes.php?action=marcar_leido&amp;id=<?php echo $msg['id']; ?>&amp;token=<?php echo urlencode($csrf_token); ?>" class="btn-icon btn-check" title="Marcar como Leído">
                                            <i class="fa-solid fa-check"></i>
                                        </a>
                                    <?php endif; ?>

                                    <a href="mensajes.php?action=eliminar&amp;id=<?php echo $msg['id']; ?>&amp;token=<?php echo urlencode($csrf_token); ?>" class="btn-icon btn-delete" onclick="return confirm('¿Confirma que desea eliminar este mensaje permanentemente?');" title="Eliminar">
                                        <i class="fa-solid fa-trash-can"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="modal-overlay" id="mensajeModal">
    <div class="modal-box">
        <div class="modal-header">
            <h2><i class="fa-regular fa-envelope"></i> Lectura de Mensaje</h2>
            <button class="modal-close" onclick="cerrarModal()"><i class="fa-solid fa-xmark"></i></button>
        </div>
        
        <div class="modal-body">
            <div class="msg-meta">
                <p><strong>De:</strong> <span id="modalNombre" style="color: #0f172a;"></span> &lt;<span id="modalEmail"></span>&gt;</p>
                <p><strong>Asunto:</strong> <span id="modalAsunto" style="color: #0f172a;"></span></p>
                <p><strong>Recibido:</strong> <span id="modalFecha"></span> (IP: <span id="modalIp"></span>)</p>
            </div>
            
            <div class="msg-content" id="modalCuerpo"></div>
        </div>
        
        <div class="modal-footer">
            <button class="btn-text btn-cancel" onclick="cerrarModal()">Cerrar Visor</button>
            <a href="#" id="modalResponder" class="btn-text btn-reply"><i class="fa-solid fa-reply"></i> Responder al Usuario</a>
        </div>
    </div>
</div>

<script>
    const modalOverlay = document.getElementById('mensajeModal');

    function abrirModal(mensaje) {
        document.getElementById('modalNombre').textContent = mensaje.name;
        document.getElementById('modalEmail').textContent = mensaje.email;
        document.getElementById('modalAsunto').textContent = mensaje.subject || 'Sin Asunto';
        document.getElementById('modalCuerpo').textContent = mensaje.message;
        document.getElementById('modalIp').textContent = mensaje.ip_address || 'N/A';
        
        const fecha = new Date(mensaje.created_at);
        document.getElementById('modalFecha').textContent = fecha.toLocaleString('es-HN');

        document.getElementById('modalResponder').href = `mailto:${mensaje.email}?subject=RE: ${mensaje.subject || 'Contacto Portal Web'}`;

        modalOverlay.style.display = 'flex';
        // Pequeño timeout para permitir que el display:flex se aplique antes de la animación de opacidad
        setTimeout(() => { modalOverlay.classList.add('active'); }, 10);
    }

    function cerrarModal() {
        modalOverlay.classList.remove('active');
        setTimeout(() => { modalOverlay.style.display = 'none'; }, 300); // Esperar que termine la animación
    }

    modalOverlay.addEventListener('click', function(e) {
        if (e.target === this) { cerrarModal(); }
    });
</script>

<?php 
// include 'includes/footer.php'; 
?>
</body>
</html>
