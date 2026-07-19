<?php
session_start();
if (isset($_SESSION['usuario_id'])) {
    header("Location: admin/admin_panel.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Login - Magic Letters</title>
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
            /* Fondo con degradado de tu paleta */
            background: linear-gradient(135deg, var(--color-primary) 0%, var(--color-support) 100%);
            display: flex; 
            justify-content: center; 
            align-items: center; 
            height: 100vh; 
            margin: 0; 
        }

        .login-card { 
            background: white; 
            padding: 40px; 
            border-radius: 12px; 
            box-shadow: 0 15px 35px rgba(0,0,0,0.2); 
            width: 320px; 
            text-align: center;
        }

        /* --- ESTILOS DEL LOGO --- */
        .logo-placeholder {
            margin-bottom: 20px;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100px; /* Altura fija para evitar saltos */
        }

        .main-logo {
            max-width: 100%;
            max-height: 120px; /* Ajusta esto según el tamaño real de tu logo */
            object-fit: contain;
        }

        h2 { 
            color: var(--color-support); 
            margin: 10px 0 25px 0; 
            font-weight: 800;
            font-size: 1.5rem;
        }

        /* Inputs */
        input { 
            width: 100%; 
            padding: 12px; 
            margin: 8px 0; 
            border: 1px solid #ddd; 
            border-radius: 6px; 
            box-sizing: border-box; 
            font-size: 14px;
            transition: all 0.3s;
            background-color: #f9f9f9;
        }

        input:focus {
            outline: none;
            border-color: var(--color-primary);
            background-color: #fff;
            box-shadow: 0 0 0 3px rgba(70, 176, 148, 0.1);
        }

        /* Botón */
        button { 
            width: 100%; 
            padding: 12px; 
            background: var(--color-primary); 
            color: white; 
            border: none; 
            border-radius: 6px; 
            cursor: pointer; 
            font-weight: bold; 
            font-size: 16px;
            margin-top: 15px;
            transition: background 0.3s, transform 0.2s;
            box-shadow: 0 4px 6px rgba(70, 176, 148, 0.2);
        }

        button:hover { 
            background: var(--color-support); 
            transform: translateY(-2px);
        }

        .error { 
            background-color: #f8d7da; 
            color: #721c24; 
            border: 1px solid #f5c6cb;
            padding: 10px;
            border-radius: 5px;
            font-size: 0.9rem; 
            margin-bottom: 15px;
        }
        
        .footer-link {
            display: block;
            margin-top: 25px;
            font-size: 13px;
            color: #888;
            text-decoration: none;
            transition: color 0.3s;
        }
        .footer-link:hover { 
            text-decoration: underline; 
            color: var(--color-support);
        }
    </style>
</head>
<body>

    <div class="login-card">
        
        <div class="logo-placeholder">
            <img src="assets/logo.png" alt="Acción Honduras" class="main-logo" 
                 onerror="this.style.display='none'; this.nextElementSibling.style.display='block';"> 
            
        </div>

        <h2>Magic Letters</h2>

        <?php if (isset($_GET['error'])): ?>
            <div class="error">
                <i class="fa-solid fa-circle-exclamation"></i> Credenciales incorrectas
            </div>
        <?php endif; ?>

        <form action="auth.php" method="POST">
            <input type="email" name="email" placeholder="Correo electrónico" required>
            <input type="password" name="password" placeholder="Contraseña" required>
            
            <button type="submit">Iniciar Sesión</button>
        </form>

        <a href="tecnico/index.php" class="footer-link">
            <i class="fa-solid fa-user-gear"></i> Soy técnico, ir a mi zona
        </a>
    </div>

</body>
</html>