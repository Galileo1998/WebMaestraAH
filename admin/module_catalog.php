<?php
// Fuente única para navegación y permisos del panel administrativo.
return [
    'Blog' => [
        'icon' => 'fa-newspaper',
        'items' => [
            'index.php' => ['label' => 'Páginas', 'icon' => 'fa-window-maximize', 'sidebar' => true, 'active' => ['index.php', 'editar_pagina.php', 'builder.php']],
            'editar_pagina.php' => ['label' => 'Editar páginas', 'icon' => 'fa-pen-to-square'],
            'builder.php' => ['label' => 'Constructor de páginas', 'icon' => 'fa-wand-magic-sparkles'],
            'noticias.php' => ['label' => 'Noticias', 'icon' => 'fa-newspaper', 'sidebar' => true, 'active' => ['noticias.php', 'editar_noticia.php']],
            'editar_noticia.php' => ['label' => 'Editor de noticias', 'icon' => 'fa-pen'],
            'socios.php' => ['label' => 'Socios y proyectos', 'icon' => 'fa-handshake', 'sidebar' => true, 'active' => ['socios.php', 'proyectos.php', 'editar_proyecto.php']],
            'proyectos.php' => ['label' => 'Proyectos por socio', 'icon' => 'fa-diagram-project'],
            'editar_proyecto.php' => ['label' => 'Editar proyectos', 'icon' => 'fa-pen-to-square'],
            'mensajes.php' => ['label' => 'Mensajes', 'icon' => 'fa-envelope', 'sidebar' => true],
            'uploads_manager.php' => ['label' => 'Nube y multimedia', 'icon' => 'fa-cloud-arrow-up', 'sidebar' => true]
        ]
    ],
    'Academia' => [
        'icon' => 'fa-graduation-cap',
        'items' => [
            'cursos.php' => ['label' => 'Cursos', 'icon' => 'fa-book-open-reader', 'sidebar' => true, 'active' => ['cursos.php', 'constructor_curso.php', 'constructor_quiz.php', 'constructor_evaluaciones.php', 'constructor_certificado.php']],
            'constructor_curso.php' => ['label' => 'Constructor de cursos', 'icon' => 'fa-layer-group'],
            'constructor_quiz.php' => ['label' => 'Constructor de cuestionarios', 'icon' => 'fa-circle-question'],
            'constructor_evaluaciones.php' => ['label' => 'Constructor de evaluaciones', 'icon' => 'fa-list-check'],
            'constructor_certificado.php' => ['label' => 'Constructor de certificados', 'icon' => 'fa-certificate'],
            'estudiantes.php' => ['label' => 'Estudiantes', 'icon' => 'fa-user-graduate', 'sidebar' => true],
            'calificaciones.php' => ['label' => 'Calificaciones', 'icon' => 'fa-chart-line', 'sidebar' => true],
            'accesos_portal.php' => ['label' => 'Accesos al portal', 'icon' => 'fa-key', 'sidebar' => true]
        ]
    ],
    'Programas' => [
        'icon' => 'fa-diagram-project',
        'items' => [
            'dashboard_programatico.php' => ['label' => 'Panel programático', 'icon' => 'fa-chart-pie', 'sidebar' => true],
            'monitoreo.php' => ['label' => 'Gestión programática', 'icon' => 'fa-list-check', 'sidebar' => true],
            'catalogos_monitoreo.php' => ['label' => 'Catálogos de monitoreo', 'icon' => 'fa-tags', 'sidebar' => true],
            'panel_tecnico.php' => ['label' => 'Panel técnico', 'icon' => 'fa-screwdriver-wrench', 'sidebar' => true],
            'gestor_centros.php' => ['label' => 'Gestor de centros', 'icon' => 'fa-school', 'sidebar' => true],
            'equipos_zonas.php' => ['label' => 'Estructura territorial', 'icon' => 'fa-map-location-dot', 'sidebar' => true],
            'historial_actividad.php' => ['label' => 'Historial de actividad', 'icon' => 'fa-clock-rotate-left'],
            'historico_actividades.php' => ['label' => 'Histórico de actividades', 'icon' => 'fa-box-archive'],
            'formularios_web' => ['label' => 'Formularios web', 'icon' => 'fa-file-circle-check', 'sidebar' => true, 'href' => 'https://accionhonduras.org/form/admin/formularios.php', 'external' => true]
        ]
    ],
    'Financiero' => [
        'icon' => 'fa-coins',
        'items' => [
            'mis_compras.php' => ['label' => 'Mis compras', 'icon' => 'fa-basket-shopping', 'sidebar' => true],
            'compras.php' => ['label' => 'Expedientes de compra', 'icon' => 'fa-folder-tree', 'sidebar' => true, 'active' => ['compras.php', 'imprimir_compra.php']],
            'imprimir_compra.php' => ['label' => 'Impresión de compras', 'icon' => 'fa-print'],
            'poa.php' => ['label' => 'Presupuesto (POA)', 'icon' => 'fa-table-cells-large', 'sidebar' => true],
            'dashboard.php' => ['label' => 'Panel gerencial', 'icon' => 'fa-chart-column', 'sidebar' => true]
        ]
    ],
    'Configuración' => [
        'icon' => 'fa-gears',
        'items' => [
            'apariencia.php' => ['label' => 'Menú y apariencia', 'icon' => 'fa-paint-roller', 'sidebar' => true],
            'configuracion.php' => ['label' => 'Configuración y usuarios', 'icon' => 'fa-users-gear', 'sidebar' => true],
            'seguridad.php' => ['label' => 'Seguridad y respaldos', 'icon' => 'fa-shield-halved', 'sidebar' => true]
        ]
    ]
];
