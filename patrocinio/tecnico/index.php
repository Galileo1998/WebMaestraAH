<?php
session_start();
require '../db_config.php';

// Si ya está logueado, mandar al panel
if (isset($_SESSION['tech_id'])) {
    header("Location: panel.php");
    exit;
}

// Obtener lista de técnicos para el select
$stmt = $pdo->query("SELECT id, full_name FROM technicians WHERE status = 'ACTIVO' ORDER BY full_name ASC");
$tecnicos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$error = "";

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $tech_id = $_POST['tech_id'];
    $phone = trim($_POST['phone']);

    // Verificar si el teléfono coincide con el ID seleccionado
    $sql = "SELECT * FROM technicians WHERE id = ? AND phone = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$tech_id, $phone]);
    $user = $stmt->fetch();

    if ($user) {
        $_SESSION['tech_id'] = $user['id'];
        $_SESSION['tech_name'] = $user['full_name'];
        header("Location: panel.php");
        exit;
    } else {
        $error = "❌ Teléfono incorrecto o usuario no válido.";
    }
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0"> 
    <title>Acceso Técnico - MagicLetter</title>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
        /* --- PALETA DE COLORES --- */
        :root {
            --color-primary: #46B094;
            --color-support: #34859B;
            --color-accent: #B4D6E0;
        }

        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            /* Fondo degradado idéntico al admin */
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-support) 100%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
            padding: 20px; /* Para evitar bordes en móviles */
        }

        .login-card { 
            background: white; 
            padding: 40px 30px; 
            border-radius: 16px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.2); 
            width: 100%; 
            max-width: 360px; 
            text-align: center;
        }

        /* --- ZONA LOGO --- */
        .logo-container {
            margin-bottom: 20px;
            height: 90px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo-img {
            max-width: 100%;
            max-height: 90px;
            object-fit: contain;
        }

        h2 { 
            color: var(--color-support); 
            margin: 0 0 5px 0; 
            font-weight: 800;
        }
        
        .subtitle {
            color: #888;
            font-size: 14px;
            margin-bottom: 25px;
        }

        /* Inputs y Selects */
        select, input { 
            width: 100%; 
            padding: 14px; 
            margin: 10px 0; 
            border: 1px solid #ddd; 
            border-radius: 8px; 
            font-size: 16px; /* Tamaño 16px evita zoom en iPhone */
            box-sizing: border-box; 
            background-color: #f9f9f9;
            transition: all 0.3s;
            outline: none;
        }

        select:focus, input:focus {
            border-color: var(--color-primary);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(70, 176, 148, 0.15);
        }

        /* Botón */
        .btn { 
            background: var(--color-primary); 
            color: white; 
            border: none; 
            padding: 14px; 
            width: 100%; 
            border-radius: 8px; 
            font-size: 16px; 
            font-weight: bold; 
            cursor: pointer; 
            margin-top: 15px; 
            transition: background 0.3s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(70, 176, 148, 0.2);
        }

        .btn:hover { 
            background: var(--color-support); 
            transform: translateY(-2px);
        }

        .btn:active { transform: translateY(0); }

        .error { 
            background-color: #fff5f5; 
            color: #dc3545; 
            padding: 10px; 
            border-radius: 6px; 
            font-size: 14px; 
            margin-bottom: 15px; 
            border: 1px solid #ffc9c9;
            display: flex; align-items: center; justify-content: center; gap: 8px;
        }

        .footer-link {
            display: block;
            margin-top: 25px;
            color: #aaa;
            font-size: 13px;
            text-decoration: none;
        }
        .footer-link:hover { text-decoration: underline; color: var(--color-support); }

    </style>
</head>
<body>

    <div class="login-card">
        
        <div class="logo-container">
            <img src="../assets/logo.png" alt="Acción Honduras" class="logo-img"
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
            
            <div style="display:none; color:var(--color-primary);">
                <i class="fa-solid fa-user-hard-hat fa-3x"></i>
            </div>
        </div>

        <h2>¡Hola, Técnico!</h2>
        <p class="subtitle">Ingresa tus datos para comenzar</p>
        
        <?php if($error): ?>
            <div class="error">
                <i class="fa-solid fa-circle-xmark"></i> <?= $error ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div style="position:relative;">
                <select name="tech_id" required>
                    <option value="">Selecciona tu nombre...</option>
                    <?php foreach($tecnicos as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['full_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <input type="tel" name="phone" placeholder="📞 Tu número de teléfono" required autocomplete="off">
            
            <button type="submit" class="btn">
                Ingresar <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>

        <a href="../index.php" class="footer-link">Soy administrador</a>
    </div>

</body>
</html>