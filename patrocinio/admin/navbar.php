<?php
// admin/navbar.php
$pagina_actual = basename($_SERVER['PHP_SELF']);
?>
<style>
    /* --- PALETA DE COLORES INTELIGENTE --- */
    :root {
        --color-identity: #46B094;
        --color-support: #34859B;
        --color-accent: #B4D6E0;
    }

    .main-header {
        background-color: var(--color-identity);
        padding: 0 20px;
        box-shadow: 0 4px 12px rgba(70, 176, 148, 0.25);
        display: flex;
        justify-content: space-between;
        align-items: center;
        height: 60px;
        position: sticky;
        top: 0;
        z-index: 1000;
    }

    .brand-logo {
        color: white;
        font-size: 20px;
        font-weight: 800;
        text-decoration: none;
        display: flex;
        align-items: center;
        gap: 10px;
        letter-spacing: 0.5px;
    }

    .nav-links {
        display: flex;
        gap: 5px;
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .nav-item a {
        color: rgba(255,255,255,0.92);
        text-decoration: none;
        padding: 8px 16px;
        border-radius: 6px;
        font-size: 14px;
        font-weight: 600;
        transition: all 0.3s ease;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    /* Efecto Hover: Color de Apoyo */
    .nav-item a:hover {
        background-color: var(--color-support);
        color: white;
        transform: translateY(-1px);
    }

    /* Estado Activo: Fondo Blanco + Texto Identidad */
    .nav-item a.active {
        background-color: white;
        color: var(--color-identity);
        font-weight: 800;
        box-shadow: 0 2px 5px rgba(0,0,0,0.1);
    }

    .user-menu {
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .btn-logout {
        background-color: rgba(0,0,0,0.15);
        color: white;
        padding: 7px 14px;
        border-radius: 20px;
        text-decoration: none;
        font-size: 13px;
        transition: background 0.2s;
        border: 1px solid rgba(255,255,255,0.1);
    }
    .btn-logout:hover { background-color: #dc3545; border-color: #dc3545; }

    @media (max-width: 768px) {
        .main-header { flex-direction: column; height: auto; padding: 15px; }
        .nav-links { flex-wrap: wrap; justify-content: center; margin-top: 10px; width: 100%; }
        .nav-item { width: 100%; text-align: center; }
        .nav-item a { justify-content: center; }
        .user-menu { margin-top: 15px; width: 100%; justify-content: space-between; }
    }
</style>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

<nav class="main-header">
    <a href="dashboard.php" class="brand-logo">
        <i class="fa-solid fa-envelope-open-text"></i> MagicLetter
    </a>

    <ul class="nav-links">
        <li class="nav-item">
            <a href="dashboard.php" class="<?= $pagina_actual == 'dashboard.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-pie"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a href="admin_panel.php" class="<?= $pagina_actual == 'admin_panel.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-list"></i> Cartas
            </a>
        </li>
        <li class="nav-item">
            <a href="asignaciones.php" class="<?= $pagina_actual == 'asignaciones.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-clipboard-check"></i> Asignar
            </a>
        </li>
        <li class="nav-item">
            <a href="tecnicos.php" class="<?= $pagina_actual == 'tecnicos.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-users-gear"></i> Técnicos
            </a>
        </li>
        <li class="nav-item">
            <a href="revisar_carga.php" class="<?= $pagina_actual == 'revisar_carga.php' ? 'active' : '' ?>">
                <i class="fa-solid fa-cloud-arrow-up"></i> Carga PDF
            </a>
        </li>
    </ul>

    <div class="user-menu">
        <a href="../tecnico/index.php" target="_blank" style="color:white; font-size:12px; text-decoration:none; opacity:0.85; font-weight:500;">
            Ver como Técnico <i class="fa-solid fa-external-link-alt"></i>
        </a>
        <a href="logout.php" class="btn-logout">
            <i class="fa-solid fa-right-from-bracket"></i> Salir
        </a>
    </div>
</nav>