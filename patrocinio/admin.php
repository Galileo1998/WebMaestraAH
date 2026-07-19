<?php
include 'db_config.php';

// Obtener todos los técnicos
$tech_query = $pdo->query("SELECT id, nombre FROM usuarios WHERE rol = 'TECNICO'");
$tecnicos = $tech_query->fetchAll(PDO::FETCH_ASSOC);

// Obtener todas las cartas
$letters_query = $pdo->query("SELECT l.*, u.nombre as tecnico_nombre 
                             FROM letters l 
                             LEFT JOIN usuarios u ON l.assigned_to_user_id = u.id 
                             ORDER BY l.created_at DESC");
$cartas = $letters_query->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Panel Admin - Magic Letters</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <div class="container">
        <h1>Gestión de Cartas del Padrino</h1>
        
        <table>
            <thead>
                <tr>
                    <th>ID Local</th>
                    <th>Código Niño</th>
                    <th>Estado</th>
                    <th>Técnico Asignado</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($cartas as $carta): ?>
                <tr>
                    <td><?php echo $carta['local_id']; ?></td>
                    <td><?php echo $carta['child_code']; ?></td>
                    <td><span class="badge <?php echo strtolower($carta['status']); ?>">
                        <?php echo $carta['status']; ?>
                    </span></td>
                    <td><?php echo $tecnico_nombre ?? 'Sin asignar'; ?></td>
                    <td>
                        <form action="assign.php" method="POST">
                            <input type="hidden" name="local_id" value="<?php echo $carta['local_id']; ?>">
                            <select name="user_id">
                                <option value="">Seleccionar Técnico</option>
                                <?php foreach ($tecnicos as $t): ?>
                                    <option value="<?php echo $t['id']; ?>"><?php echo $t['nombre']; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button type="submit">Asignar</button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</body>
</html>