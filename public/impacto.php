<?php
// public/impacto.php
require_once '../config/Database.php';

$database = new Database();
$db = $database->getConnection();

// 1. Detectar si venimos de la vitrina de un socio específico
$socio_id = isset($_GET['socio_id']) && is_numeric($_GET['socio_id']) ? (int)$_GET['socio_id'] : 0;

$where_clause = "WHERE pt.status = 'active'";
$params = [];

if ($socio_id > 0) {
    $where_clause .= " AND pt.id = :socio_id";
    $params[':socio_id'] = $socio_id;
}

// 2. Extraemos los proyectos dinámicamente
$query = "
    SELECT 
        p.id as project_id, p.title, p.fiscal_period, p.what_we_did, p.achievements_html, p.status as project_status,
        pt.name as partner_name, pt.logo_url,
        GROUP_CONCAT(pl.municipality SEPARATOR ', ') as municipalities
    FROM projects p
    JOIN partners pt ON p.partner_id = pt.id
    LEFT JOIN project_locations pl ON p.id = pl.project_id
    $where_clause
    GROUP BY p.id
    ORDER BY pt.name ASC, p.id DESC
";
$stmt = $db->prepare($query);
$stmt->execute($params);
$proyectos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$json_proyectos = json_encode($proyectos);
$social_meta = [
    'title' => 'Nuestro Impacto | Acción Honduras',
    'description' => 'Conoce los proyectos, alianzas y territorios donde Acción Honduras transforma comunidades.',
    'image' => '/uploads/images/social_accion_honduras_1200x630.jpg',
    'url' => 'https://accionhonduras.org/impacto.php' . ($socio_id > 0 ? '?socio_id=' . $socio_id : ''),
    'type' => 'website',
    'breadcrumb' => 'Nuestro impacto'
];
ob_start();
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>

    <style>
        body { margin: 0; font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; flex-direction: column; min-height: 100vh; }
        main { flex-grow: 1; }
        
        .impact-header { background: #0f172a; color: white; padding: 60px 20px; text-align: center; }
        
        /* Layout del mapa y tarjetas */
        .impact-container { max-width: 1300px; margin: 40px auto; padding: 0 20px; display: grid; grid-template-columns: 1fr 400px; gap: 30px; }
        
        #map { height: 600px; width: 100%; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); border: 2px solid white; z-index: 10; }
        
        .projects-sidebar { height: 600px; overflow-y: auto; padding-right: 10px; }
        .projects-sidebar::-webkit-scrollbar { width: 8px; }
        .projects-sidebar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        
        .project-card { background: white; border-radius: 12px; padding: 25px; margin-bottom: 20px; border: 1px solid #e2e8f0; cursor: pointer; transition: all 0.3s; box-shadow: 0 4px 6px rgba(0,0,0,0.02); }
        .project-card:hover, .project-card.active { border-color: #34859B; box-shadow: 0 10px 20px rgba(52, 133, 155, 0.1); transform: translateX(-5px); }
        
        .partner-logo-mini { height: 40px; max-width: 120px; object-fit: contain; margin-bottom: 15px; }
        
        .achievements-box { background: #f1f5f9; padding: 15px; border-radius: 8px; margin-top: 15px; font-size: 0.9rem; color: #475569; }
        .achievements-box ul { margin: 0; padding-left: 20px; }
        
        @media (max-width: 992px) {
            .impact-container { grid-template-columns: 1fr; }
            #map { height: 400px; }
            .projects-sidebar { height: auto; overflow-y: visible; }
        }
    </style>
<?php
$page_head_html = ob_get_clean();
include __DIR__ . '/header.php';
?>

    <main>
        <section class="impact-header">
            <h1 style="font-size: 2.8rem; font-weight: 800; margin-bottom: 15px;">Transparencia y Resultados</h1>
            <p style="font-size: 1.15rem; color: #cbd5e1; max-width: 700px; margin: 0 auto;">Descubre cómo, junto a nuestros socios y cooperantes, estamos transformando el futuro de los municipios de Francisco Morazán.</p>
        </section>

        <div class="impact-container">
            <div>
                <div id="map"></div>
                <div style="text-align: center; margin-top: 15px; color: #64748b; font-size: 0.9rem;">
                    <i class="fa-solid fa-circle-info"></i> Selecciona un proyecto de la lista para ver su área de intervención.
                </div>
            </div>

            <div class="projects-sidebar">
                <?php if (empty($proyectos)): ?>
                    <div style="text-align:center; padding: 50px; background: white; border-radius: 12px;">No hay proyectos registrados aún.</div>
                <?php else: ?>
                    <?php foreach ($proyectos as $index => $p): ?>
                        <div class="project-card" onclick="focusProject(<?php echo $index; ?>)" id="card-<?php echo $index; ?>">
                            
                            <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                <?php if($p['logo_url']): ?>
                                    <img src="<?php echo htmlspecialchars($p['logo_url']); ?>" class="partner-logo-mini" title="<?php echo htmlspecialchars($p['partner_name']); ?>">
                                <?php else: ?>
                                    <strong style="color: #64748b;"><?php echo htmlspecialchars($p['partner_name']); ?></strong>
                                <?php endif; ?>
                                <span style="background: #e0f2fe; color: #0284c7; padding: 4px 8px; border-radius: 4px; font-size: 0.75rem; font-weight: bold;"><?php echo htmlspecialchars($p['fiscal_period']); ?></span>
                            </div>

                            <h3 style="color: #1e293b; margin: 0 0 10px 0; font-size: 1.2rem;"><?php echo htmlspecialchars($p['title']); ?></h3>
                            <p style="color: #64748b; font-size: 0.95rem; line-height: 1.5; margin: 0 0 15px 0;">
                                <?php echo htmlspecialchars($p['what_we_did']); ?>
                            </p>

                            <div style="font-size: 0.85rem; color: #34859B; font-weight: 600;">
                                <i class="fa-solid fa-location-dot"></i> Intervención: <?php echo !empty($p['municipalities']) ? htmlspecialchars($p['municipalities']) : 'General'; ?>
                            </div>

                            <?php if(!empty($p['achievements_html'])): ?>
                                <div class="achievements-box">
                                    <strong style="color: #1e293b; display: block; margin-bottom: 5px;">Logros Principales:</strong>
                                    <?php echo $p['achievements_html']; ?> </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </main>

    <?php include 'footer.php'; ?>

<script>
        const proyectos = <?php echo $json_proyectos; ?>;
        
        let map;
        let geojsonLayer; 
        let hondurasGeoData = null; 

        // 1. Definimos la función en el ámbito global para que el HTML la encuentre siempre
       // 1. Función maestra para seleccionar proyectos y pintar polígonos
        window.focusProject = function(index) {
            if (!hondurasGeoData) {
                console.warn("El mapa aún está descargando la cartografía...");
                return;
            }

            const project = proyectos[index];
            
            // Efecto visual en la lista de la derecha
            document.querySelectorAll('.project-card').forEach(c => c.classList.remove('active'));
            const card = document.getElementById('card-' + index);
            if (card) card.classList.add('active');

            // Si ya había polígonos pintados, los borramos
            if (geojsonLayer) {
                map.removeLayer(geojsonLayer);
            }

            if (!project.municipalities) return;

            // 🛠️ LA MAGIA AQUÍ: Función para quitar tildes y mayúsculas
            const normalizeStr = (str) => {
                return str.normalize("NFD").replace(/[\u0300-\u036f]/g, "").toLowerCase().trim();
            };

            // Extraemos y normalizamos los municipios de nuestra BD (Ej: "Alubarén" -> "alubaren")
            const activeMuns = project.municipalities.split(',').map(m => normalizeStr(m));

            geojsonLayer = L.geoJSON(hondurasGeoData, {
                filter: function(feature) {
                    // Extraemos y normalizamos el nombre del mapa oficial
                    let munName = feature.properties.shapeName || "";
                    munName = normalizeStr(munName);
                    
                    // Comparamos sin importar tildes o mayúsculas
                    return activeMuns.some(activeMun => munName.includes(activeMun) || activeMun.includes(munName));
                },
                style: {
                    color: '#46B094',       // Borde verde
                    weight: 2,              // Grosor del borde
                    fillColor: '#34859B',   // Relleno azul
                    fillOpacity: 0.6        // Transparencia
                },
                onEachFeature: function (feature, layer) {
                    // Pop-Up de cada polígono usando el nombre original del mapa
                    let munName = feature.properties.shapeName || "Municipio";
                    const popupContent = `
                        <div style="text-align:center;">
                            <h4 style="margin:0 0 5px 0; color:#1e293b;">${munName}</h4>
                            <p style="margin:0; font-size:0.8rem; color:#64748b;">Área de intervención activa</p>
                        </div>
                    `;
                    layer.bindPopup(popupContent);
                    
                    // Animación al pasar el mouse
                    layer.on('mouseover', function () { this.setStyle({ fillOpacity: 0.9 }); });
                    layer.on('mouseout', function () { this.setStyle({ fillOpacity: 0.6 }); });
                }
            }).addTo(map);

            // Zoom automático a los polígonos dibujados
            if (geojsonLayer.getLayers().length > 0) {
                map.flyToBounds(geojsonLayer.getBounds(), { padding: [50, 50], duration: 1.5 });
            } else {
                console.warn("No se encontró coincidencia cartográfica para:", project.municipalities);
            }
        };
        // 2. Inicializamos todo al cargar la página
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar el mapa
            map = L.map('map').setView([14.5, -86.5], 7);

            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/voyager/{z}/{x}/{y}{r}.png', {
                attribution: '&copy; OpenStreetMap contributors',
                maxZoom: 18
            }).addTo(map);

            // Cargar la cartografía OFICIAL de geoBoundaries (Honduras Nivel Municipal ADM2)
            // Cargar la cartografía desde TU PROPIO ARCHIVO LOCAL (100% seguro y offline)
            // Cargar la cartografía Municipal (ADM2) optimizada
            const geoUrl = 'geoBoundaries-HND-ADM2_simplified_modificado.geojson';
            
            fetch(geoUrl)
                .then(response => {
                    if (!response.ok) throw new Error("No se encontró el archivo local.");
                    return response.json();
                })
                .then(data => {
                    hondurasGeoData = data;
                    console.log("✅ Cartografía local cargada exitosamente.");
                    
                    // Seleccionar automáticamente el primer proyecto
                    if (proyectos.length > 0) {
                        focusProject(0);
                    }
                })
                .catch(error => {
                    console.error("❌ Error leyendo el archivo GeoJSON local:", error);
                    alert("Asegúrate de que el archivo .geojson esté en la misma carpeta que impacto.php");
                });
        });
    </script>
</body>
</html>
