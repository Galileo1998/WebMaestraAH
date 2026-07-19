<?php
session_start();

// VERIFICACIÓN DE SEGURIDAD
if (!isset($_SESSION['usuario_id'])) {
    // Si no hay sesión de admin, mandar al login principal
    header("Location: ../index.php");
    exit;
}

require '../db_config.php';


// Obtener lista de técnicos
$stmt = $pdo->query("SELECT * FROM technicians ORDER BY id DESC");
$tecnicos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Gestión de Técnicos</title>
    
    <script src="https://code.jquery.com/jquery-3.7.0.min.js"></script>
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.6/css/jquery.dataTables.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.1/css/buttons.dataTables.min.css">
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* --- NUEVA PALETA DE COLORES --- */
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-accent: #B4D6E0;
            --color-bg: #f4f7f6;
        }

        body { font-family: 'Segoe UI', sans-serif; background: var(--color-bg); padding: 20px; color: #444; }
        
        .container { max-width: 1100px; margin: auto; }

        .card { 
            background: white; padding: 30px; border-radius: 12px; 
            box-shadow: 0 4px 20px rgba(0,0,0,0.08); 
            border-top: 5px solid var(--color-primary); 
        }

        .header { 
            display: flex; justify-content: space-between; align-items: center; 
            margin-bottom: 25px; border-bottom: 2px solid #eee; padding-bottom: 15px;
        }
        h1 { margin: 0; color: var(--color-primary); font-size: 24px; font-weight: 800; }
        p { color: #888; margin: 5px 0 0 0; font-size: 14px; }

        /* Botones */
        .btn { padding: 10px 18px; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s; font-size: 13px; }
        
        .btn-add { background: var(--color-primary); color: white; box-shadow: 0 2px 5px rgba(70, 176, 148, 0.3); }
        .btn-add:hover { background: var(--color-support); transform: translateY(-1px); }
        
        .btn-back { background: #e2e6ea; color: #555; }
        .btn-back:hover { background: #dbe0e5; color: #333; }

        /* Acciones en Tabla */
        .btn-icon { width: 32px; height: 32px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center; border: none; cursor: pointer; transition: transform 0.2s; color: white; font-size: 14px; text-decoration: none; margin-right: 5px; }
        .btn-icon:hover { transform: scale(1.15); }
        
        .btn-edit { background: var(--color-support); }
        .btn-delete { background: #dc3545; }

        /* DataTables Styles */
        table.dataTable thead th {
            background-color: var(--color-primary);
            color: white;
            padding: 12px;
            font-weight: 600;
            border-bottom: none;
        }
        
        tfoot input {
            width: 100%; padding: 6px; box-sizing: border-box;
            border: 1px solid #ddd; border-radius: 4px; font-size: 12px;
        }

        /* Badges */
        .badge { padding: 5px 12px; border-radius: 20px; font-size: 11px; font-weight: 800; letter-spacing: 0.5px; }
        .badge-activo { background: #d4edda; color: #155724; }
        .badge-inactivo { background: #f8d7da; color: #721c24; }

        /* Modal */
        .modal { display: none; position: fixed; z-index: 2000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(52, 133, 155, 0.6); backdrop-filter: blur(2px); }
        .modal-content { 
            background-color: white; margin: 8% auto; padding: 30px; 
            border-radius: 12px; width: 450px; position: relative; 
            box-shadow: 0 15px 40px rgba(0,0,0,0.2);
            animation: slideDown 0.3s ease;
        }
        
        .close { position: absolute; right: 20px; top: 15px; font-size: 24px; cursor: pointer; color: #aaa; }
        .close:hover { color: var(--color-primary); }

        /* Formulario Modal */
        label { display: block; margin-top: 15px; font-weight: 700; color: var(--color-support); font-size: 13px; }
        input, select { 
            width: 100%; padding: 10px; margin-top: 5px; 
            border: 1px solid #ddd; border-radius: 6px; 
            box-sizing: border-box; font-size: 14px;
        }
        input:focus, select:focus { outline: none; border-color: var(--color-primary); box-shadow: 0 0 0 3px rgba(70, 176, 148, 0.1); }
        
        .btn-save { 
            background: var(--color-primary); color: white; width: 100%; margin-top: 25px; 
            padding: 12px; font-size: 15px; border:none; border-radius:6px; font-weight:bold; cursor:pointer; 
            transition: background 0.3s;
        }
        .btn-save:hover { background: var(--color-support); }

        @keyframes slideDown { from { transform: translateY(-50px); opacity: 0; } to { transform: translateY(0); opacity: 1; } }
    </style>
</head>
<body>

<?php include 'navbar.php'; ?>

<div class="container">
    <div class="card">
        <div class="header">
            <div>
                <h1>👷 Catálogo de Técnicos</h1>
                <p>Administra el acceso y zonas del personal de campo.</p>
            </div>
            <div>
                <button onclick="abrirModal()" class="btn btn-add">
                    <i class="fa-solid fa-plus"></i> Nuevo Técnico
                </button>
            </div>
        </div>

        <table id="tablaTecnicos" class="display" style="width:100%">
            <thead>
                <tr>
                    <th>Nombre Completo</th>
                    <th>Teléfono</th>
                    <th>Zona Asignada</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach($tecnicos as $t): ?>
                <tr>
                    <td style="font-weight:600; color:#333;"><?= htmlspecialchars($t['full_name']) ?></td>
                    <td><?= htmlspecialchars($t['phone']) ?></td>
                    <td><?= htmlspecialchars($t['community_assigned']) ?></td>
                    <td>
                        <span class="badge <?= $t['status'] == 'ACTIVO' ? 'badge-activo' : 'badge-inactivo' ?>">
                            <?= $t['status'] ?>
                        </span>
                    </td>
                    <td>
                        <button onclick='editar(<?= json_encode($t) ?>)' class="btn-icon btn-edit" title="Editar">
                            <i class="fa-solid fa-pencil"></i>
                        </button>
                        <a href="eliminar_tecnico.php?id=<?= $t['id'] ?>" onclick="return confirm('¿Seguro que deseas eliminar este técnico?')" class="btn-icon btn-delete" title="Eliminar">
                            <i class="fa-solid fa-trash"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr>
                    <th>Nombre</th>
                    <th>Teléfono</th>
                    <th>Zona</th>
                    <th>Estado</th>
                    <th></th> </tr>
            </tfoot>
        </table>
    </div>
</div>

<div id="modalTecnico" class="modal">
    <div class="modal-content">
        <span class="close" onclick="cerrarModal()">&times;</span>
        <h2 id="modalTitle" style="color:var(--color-primary); margin-top:0;">Nuevo Técnico</h2>
        
        <form action="guardar_tecnico.php" method="POST">
            <input type="hidden" name="id" id="tec_id">
            
            <label>Nombre Completo:</label>
            <input type="text" name="full_name" id="tec_name" required placeholder="Ej. Juan Pérez">
            
            <label>Teléfono (Login):</label>
            <input type="text" name="phone" id="tec_phone" placeholder="Solo números">
            
            <label>Comunidad / Zona Principal:</label>
            <input type="text" name="community_assigned" id="tec_community" placeholder="Ej. La Esperanza">
            
            <label>Estado:</label>
            <select name="status" id="tec_status">
                <option value="ACTIVO">Activo</option>
                <option value="INACTIVO">Inactivo</option>
            </select>
            
            <button type="submit" class="btn-save">Guardar Datos</button>
        </form>
    </div>
</div>

<script src="https://cdn.datatables.net/1.13.6/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.1/js/buttons.print.min.js"></script>

<script>
    $(document).ready(function() {
        // Configurar inputs de búsqueda en el pie de página
        $('#tablaTecnicos tfoot th').each(function() {
            var title = $(this).text();
            if(title !== '') {
                $(this).html('<input type="text" placeholder="Buscar '+title+'" />');
            }
        });

        // Inicializar DataTable
        var table = $('#tablaTecnicos').DataTable({
            dom: 'Bfrtip',
            buttons: [
                { extend: 'excelHtml5', text: '<i class="fa fa-file-excel"></i> Excel', className: 'btn-dt' },
                { extend: 'csvHtml5', text: '<i class="fa fa-file-csv"></i> CSV', className: 'btn-dt' },
                { extend: 'print', text: '<i class="fa fa-print"></i> Imprimir', className: 'btn-dt' }
            ],
            language: { url: '//cdn.datatables.net/plug-ins/1.13.6/i18n/es-ES.json' },
            initComplete: function () {
                // Lógica de búsqueda por columna
                this.api().columns().every(function () {
                    var that = this;
                    $('input', this.footer()).on('keyup change clear', function () {
                        if (that.search() !== this.value) {
                            that.search(this.value).draw();
                        }
                    });
                });
            }
        });

        // Estilos para botones DT
        $('.dt-button').css({
            'background': '#34859B', 'color': 'white', 'border': 'none', 
            'border-radius': '5px', 'margin-right': '5px', 'font-size':'13px', 'padding':'6px 12px'
        });
    });

    // Lógica del Modal (Mantenida)
    var modal = document.getElementById("modalTecnico");

    function abrirModal() {
        document.getElementById('modalTitle').innerText = "Nuevo Técnico";
        document.getElementById('tec_id').value = "";
        document.getElementById('tec_name').value = "";
        document.getElementById('tec_phone').value = "";
        document.getElementById('tec_community').value = "";
        document.getElementById('tec_status').value = "ACTIVO";
        modal.style.display = "block";
    }

    function editar(tecnico) {
        document.getElementById('modalTitle').innerText = "Editar Técnico";
        document.getElementById('tec_id').value = tecnico.id;
        document.getElementById('tec_name').value = tecnico.full_name;
        document.getElementById('tec_phone').value = tecnico.phone;
        document.getElementById('tec_community').value = tecnico.community_assigned;
        document.getElementById('tec_status').value = tecnico.status;
        modal.style.display = "block";
    }

    function cerrarModal() {
        modal.style.display = "none";
    }

    window.onclick = function(event) {
        if (event.target == modal) { cerrarModal(); }
    }
</script>

</body>
</html>