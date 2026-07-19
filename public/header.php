<?php
// public/header.php (O el archivo que incluyes en todas tus páginas públicas)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Asegurarnos de tener la conexión a la BD para consultar las imágenes y el menú
require_once __DIR__ . '/../config/Database.php';
$database = new Database();
$db = $database->getConnection();

// =======================================================
// EXTRAER EL MENÚ DINÁMICO DESDE LA BASE DE DATOS
// =======================================================
$menu_items = [];
try {
    $query_menu = "SELECT label, url FROM menu_items ORDER BY id ASC"; 
    
    $stmt_menu = $db->query($query_menu);
    if ($stmt_menu) {
        $menu_items = $stmt_menu->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error al cargar el menú dinámico: " . $e->getMessage());
}

// 1. CONSTRUIR LA URL BASE ABSOLUTA
$base_url = "https://accionhonduras.org";
$request_path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$current_url = $base_url . $request_path;

// 2. VALORES POR DEFECTO
$og_title = "Acción Honduras | Transformando el futuro";
$og_description = "Alianzas estratégicas que multiplican el impacto y transforman el futuro de las comunidades vulnerables en Honduras.";
$og_image = $base_url . "/uploads/images/social_accion_honduras_1200x630.jpg";
$og_type = 'website';

// 3. LÓGICA DINÁMICA
$current_page = basename($_SERVER['SCRIPT_NAME']);
$current_slug = isset($_GET['slug']) ? trim($_GET['slug']) : '';

if ($current_page == 'index.php' && empty($current_slug)) {
    $current_slug = 'inicio';
}

// Las paginas del CMS tienen una sola URL indexable, sin index.php ni query string.
if ($current_page === 'index.php') {
    $canonical_path = ($current_slug === '' || $current_slug === 'inicio')
        ? '/'
        : '/' . rawurlencode(strtolower($current_slug));
    $current_url = $base_url . $canonical_path;
}

if (!empty($current_slug)) {
    $stmt_og = $db->prepare("SELECT title, meta_description, meta_image FROM pages WHERE slug = :slug LIMIT 1");
    $stmt_og->execute(['slug' => $current_slug]);
    $page_og = $stmt_og->fetch(PDO::FETCH_ASSOC);
    
    if ($page_og) {
        $og_title = $page_og['title'] . " | Acción Honduras";
        
        if (!empty($page_og['meta_description'])) {
            $og_description = $page_og['meta_description'];
        }
        
        if (!empty($page_og['meta_image'])) {
            $clean_page_img = ltrim($page_og['meta_image'], '/');
            $og_image = $base_url . "/" . $clean_page_img;
        }
    }
} elseif ($current_page == 'noticia.php' && isset($_GET['id'])) {
    $stmt_og = $db->prepare("SELECT name, description, logo_url FROM partners WHERE id = :id LIMIT 1");
    $stmt_og->execute(['id' => (int)$_GET['socio_id']]);
    $partner_og = $stmt_og->fetch(PDO::FETCH_ASSOC);
    
    if ($partner_og) {
        $og_title = "Impacto y Proyectos con " . $partner_og['name'] . " | Acción Honduras";
        $og_description = !empty($partner_og['description']) ? $partner_og['description'] : "Conoce nuestras zonas de intervención junto a " . $partner_og['name'];
        if (!empty($partner_og['logo_url'])) {
            $clean_logo_path = ltrim($partner_og['logo_url'], '/');
            $og_image = $base_url . "/" . $clean_logo_path;
        }
    }
}

// Las vistas especiales (por ejemplo, una noticia) pueden definir sus datos
// antes de incluir este encabezado. Se aplican al final para evitar duplicados.
if (!empty($social_meta) && is_array($social_meta)) {
    $og_title = $social_meta['title'] ?? $og_title;
    $og_description = $social_meta['description'] ?? $og_description;
    $og_image = $social_meta['image'] ?? $og_image;
    $current_url = $social_meta['url'] ?? $current_url;
    $og_type = $social_meta['type'] ?? $og_type;
}

if (!filter_var($og_image, FILTER_VALIDATE_URL)) {
    $og_image = $base_url . '/' . ltrim($og_image, '/');
}

$social_image_path = parse_url($og_image, PHP_URL_PATH) ?: '';
$social_image_file = realpath(dirname(__DIR__) . '/' . ltrim($social_image_path, '/'));
$social_image_info = ($social_image_file && is_file($social_image_file)) ? @getimagesize($social_image_file) : false;

$page_labels = [
    'noticias.php' => 'Noticias',
    'academia.php' => 'Academia',
    'impacto.php' => 'Nuestro impacto',
    'donantes.php' => 'Donantes',
    'aula.php' => 'Aula virtual'
];
$breadcrumb_label = $social_meta['breadcrumb'] ?? ($page_labels[$current_page] ?? ($page_og['title'] ?? $og_title));
$breadcrumb_label = trim(preg_replace('/\s*\|\s*Acción Honduras$/u', '', $breadcrumb_label));
$breadcrumb_items = [];
if (rtrim($current_url, '/') !== rtrim($base_url, '/')) {
    $breadcrumb_items[] = ['name' => 'Inicio', 'url' => $base_url . '/'];
    if ($og_type === 'article') {
        $breadcrumb_items[] = ['name' => 'Noticias', 'url' => $base_url . '/noticias'];
    }
    $breadcrumb_items[] = ['name' => $breadcrumb_label, 'url' => $current_url];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($og_title); ?></title>
    <link rel="icon" type="image/x-icon" href="/favicon.ico?v=2">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png?v=2">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png?v=2">
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png?v=2">
    <link rel="manifest" href="/site.webmanifest?v=2">

    <meta name="description" content="<?php echo htmlspecialchars($og_description); ?>">
    <meta name="robots" content="<?php echo htmlspecialchars($robots_meta ?? 'index, follow, max-image-preview:large'); ?>">
    <link rel="canonical" href="<?php echo htmlspecialchars($current_url); ?>">

    <meta property="og:type" content="<?php echo htmlspecialchars($og_type); ?>" />
    <meta property="og:url" content="<?php echo htmlspecialchars($current_url); ?>" />
    <meta property="og:title" content="<?php echo htmlspecialchars($og_title); ?>" />
    <meta property="og:description" content="<?php echo htmlspecialchars($og_description); ?>" />
    <meta property="og:image" content="<?php echo htmlspecialchars($og_image); ?>" />
    <meta property="og:image:secure_url" content="<?php echo htmlspecialchars($og_image); ?>" />
    <?php if ($social_image_info): ?>
    <meta property="og:image:type" content="<?php echo htmlspecialchars($social_image_info['mime']); ?>" />
    <meta property="og:image:width" content="<?php echo (int)$social_image_info[0]; ?>" />
    <meta property="og:image:height" content="<?php echo (int)$social_image_info[1]; ?>" />
    <?php endif; ?>
    <meta property="og:image:alt" content="Acción Honduras" />

    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?php echo htmlspecialchars($og_title); ?>">
    <meta name="twitter:description" content="<?php echo htmlspecialchars($og_description); ?>">
    <meta name="twitter:image" content="<?php echo htmlspecialchars($og_image); ?>">

    <script type="application/ld+json"><?php
        echo json_encode([
            '@context' => 'https://schema.org',
            '@type' => ['NGO', 'LocalBusiness'],
            '@id' => $base_url . '/#organization',
            'name' => 'Acción Honduras',
            'url' => $base_url . '/',
            'logo' => $base_url . '/uploads/images/logo.png',
            'image' => $base_url . '/uploads/images/social_accion_honduras_1200x630.jpg',
            'description' => $og_description,
            'email' => 'info@accionhonduras.org',
            'sameAs' => ['https://www.facebook.com/AccionHonduras'],
            'hasMap' => 'https://maps.app.goo.gl/Efz89C2QcuxnyCkL6',
            'address' => [
                '@type' => 'PostalAddress',
                'addressLocality' => 'Lepaterique',
                'addressRegion' => 'Francisco Morazán',
                'addressCountry' => 'HN'
            ],
            'geo' => [
                '@type' => 'GeoCoordinates',
                'latitude' => 14.046436,
                'longitude' => -87.458656
            ],
            'openingHoursSpecification' => [
                '@type' => 'OpeningHoursSpecification',
                'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'],
                'opens' => '08:00',
                'closes' => '17:00'
            ]
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?></script>
    <script type="application/ld+json"><?php
        echo json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            '@id' => $base_url . '/#website',
            'url' => $base_url . '/',
            'name' => 'Acción Honduras',
            'alternateName' => 'Mater Acción Honduras',
            'publisher' => ['@id' => $base_url . '/#organization']
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?></script>
    <?php if (count($breadcrumb_items) >= 2): ?>
    <script type="application/ld+json"><?php
        echo json_encode([
            '@context' => 'https://schema.org',
            '@type' => 'BreadcrumbList',
            'itemListElement' => array_map(function ($item, $index) {
                return [
                    '@type' => 'ListItem',
                    'position' => $index + 1,
                    'name' => $item['name'],
                    'item' => $item['url']
                ];
            }, $breadcrumb_items, array_keys($breadcrumb_items))
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    ?></script>
    <?php endif; ?>
    <meta property="og:site_name" content="Acción Honduras" />

    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/style.css">
    <?php if (!empty($page_head_html)) echo $page_head_html; ?>
</head>
<body>
    <a class="skip-link" href="#main-content">Saltar al contenido principal</a>
    <header class="main-header" style="background: white; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; width: 100%; z-index: 99999;">
        <div class="container" style="max-width: 1200px; margin: 0 auto; padding: 0 20px;">
            <div class="header-content" style="display: flex; justify-content: space-between; align-items: center; height: 80px; width: 100%; position: relative;">
                
                <a href="/" class="logo-link" aria-label="Acción Honduras, inicio" style="display: flex; align-items: center;">
                    <img src="/uploads/images/logo.png" alt="Acción Honduras" width="65" height="50" decoding="async" class="logo" style="max-height: 50px; width: auto;">
                </a>

                <nav class="main-nav" id="pubMainNav" aria-label="Navegación principal">
                    <?php if (empty($menu_items)): ?>
                        <a href="/" style="color: #1e293b;">Inicio</a>
                        <a href="/?slug=noticia" style="color: #1e293b;">Noticias</a>
                    <?php else: ?>
                        <?php foreach($menu_items as $item): ?>
                            <?php 
                                $item_slug = str_replace('?slug=', '', $item['url']);
                                $item_slug = str_replace('index.php', '', $item_slug); 
                                $is_active = ($current_slug === $item_slug);
                                $color = $is_active ? '#34859B' : '#1e293b'; 
                            ?>
                            <a href="<?php echo htmlspecialchars($item['url']); ?>" 
                               class="<?php echo $is_active ? 'active' : ''; ?>"
                               style="color: <?php echo $color; ?>;">
                                <?php echo htmlspecialchars($item['label']); ?>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                <ul class="nav-menu">

                    <?php 
                    // Asegúrate de que la sesión esté iniciada al principio del archivo
                    if (session_status() === PHP_SESSION_NONE) { session_start(); }
                
                    // Condicional: Solo mostrar si el usuario ha iniciado sesión
                    if (isset($_SESSION['user_id'])): 
                    ?>
                        <li>
                            <a href="logout.php" style="color: #ef4444; font-weight: bold;">
                                <i class="fa-solid fa-right-from-bracket"></i> Cerrar sesión
                            </a>
                        </li>
                    <?php else: ?>
                 
                    <?php endif; ?>
                </ul>
                                </nav>

                <button type="button" class="mobile-menu-toggle" id="pubToggleBtn" aria-label="Abrir menú" aria-controls="pubMainNav" aria-expanded="false">
                    <i class="fa-solid fa-bars" id="pubMenuIcon"></i>
                </button>

            </div>
        </div>
    </header>

    <?php if (count($breadcrumb_items) >= 2): ?>
    <nav class="public-breadcrumbs" aria-label="Migas de pan">
        <ol>
            <?php foreach ($breadcrumb_items as $index => $item): ?>
                <li>
                    <?php if ($index < count($breadcrumb_items) - 1): ?>
                        <a href="<?php echo htmlspecialchars($item['url']); ?>"><?php echo htmlspecialchars($item['name']); ?></a>
                        <span aria-hidden="true">›</span>
                    <?php else: ?>
                        <span aria-current="page"><?php echo htmlspecialchars($item['name']); ?></span>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ol>
    </nav>
    <?php endif; ?>

    <style>
        /* =========================================
           ESTILOS BASE (ESCRITORIO)
           ========================================= */
        .main-nav {
            display: flex;
            align-items: center;
            gap: 25px;
        }

        .main-nav a {
            text-decoration: none;
            font-weight: 600;
            font-size: 0.98rem;
            transition: color 0.2s;
            min-height: 44px;
            padding: 0 4px;
            display: inline-flex;
            align-items: center;
        }

        .public-breadcrumbs { background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .public-breadcrumbs ol { max-width: 1200px; margin: 0 auto; padding: 10px 20px; list-style: none; display: flex; align-items: center; gap: 8px; font-size: 0.86rem; color: #64748b; overflow-x: auto; white-space: nowrap; }
        .public-breadcrumbs li { display: inline-flex; align-items: center; gap: 8px; }
        .public-breadcrumbs a { color: #34859B; text-decoration: none; font-weight: 700; }
        .public-breadcrumbs a:hover { text-decoration: underline; }

        .mobile-menu-toggle {
            display: none; /* Oculto en computadoras */
            cursor: pointer;
            font-size: 1.8rem;
            color: #34859B;
            z-index: 100000;
            transition: transform 0.2s ease;
            width: 48px;
            height: 48px;
            padding: 0;
            border: 0;
            border-radius: 8px;
            background: transparent;
            align-items: center;
            justify-content: center;
        }

        .skip-link { position: fixed; left: 12px; top: -80px; z-index: 100001; background: #0f172a; color: #fff; padding: 12px 16px; border-radius: 8px; text-decoration: none; }
        .skip-link:focus { top: 12px; }
        a:focus-visible, button:focus-visible { outline: 3px solid #46B094; outline-offset: 3px; }

        .mobile-menu-toggle:active {
            transform: scale(0.9);
        }

        /* =========================================
           ESTILOS MÓVILES (TELÉFONOS Y TABLETS)
           ========================================= */
        @media (max-width: 900px) {
            .mobile-menu-toggle {
                display: inline-flex;
                margin-left: auto; /* Garantiza que se empuje totalmente a la derecha */
            }

            .ah-impact-label, .ah-badge, .ah-pillar-tag { font-size: 0.875rem !important; }
            
            .main-nav {
                position: fixed;
                top: 0;
                right: 0;
                width: 280px;
                height: 100vh;
                background: #ffffff;
                box-shadow: -5px 0 25px rgba(0,0,0,0.15);
                padding: 100px 30px 30px 30px;
                box-sizing: border-box;
                display: flex !important;
                flex-direction: column;
                justify-content: flex-start;
                align-items: flex-start;
                gap: 25px;
                transform: translateX(100%);
                transition: transform 0.3s cubic-bezier(0.4, 0, 0.2, 1);
                z-index: 99998;
            }

            .main-nav.active {
                transform: translateX(0) !important;
            }

            .main-nav a {
                font-size: 1.15rem;
                display: block;
                width: 100%;
                padding: 12px 0;
                border-bottom: 1px solid #f1f5f9;
            }
        }
    </style>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const pubToggleBtn = document.getElementById('pubToggleBtn');
            const pubMainNav = document.getElementById('pubMainNav');
            const pubMenuIcon = document.getElementById('pubMenuIcon');

            if (pubToggleBtn && pubMainNav) {
                pubToggleBtn.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const isOpen = pubMainNav.classList.toggle('active');
                    
                    if (isOpen) {
                        pubMenuIcon.className = 'fa-solid fa-xmark';
                        pubToggleBtn.setAttribute('aria-label', 'Cerrar menú');
                    } else {
                        pubMenuIcon.className = 'fa-solid fa-bars';
                        pubToggleBtn.setAttribute('aria-label', 'Abrir menú');
                    }
                    pubToggleBtn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
                });

                document.addEventListener('click', (e) => {
                    if (!pubMainNav.contains(e.target) && pubMainNav.classList.contains('active')) {
                        pubMainNav.classList.remove('active');
                        if (pubMenuIcon) pubMenuIcon.className = 'fa-solid fa-bars';
                        pubToggleBtn.setAttribute('aria-label', 'Abrir menú');
                        pubToggleBtn.setAttribute('aria-expanded', 'false');
                    }
                });
            }
        });
    </script>
