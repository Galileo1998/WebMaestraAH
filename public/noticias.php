<?php
// public/noticias.php
require_once '../config/Database.php';

$database = new Database();
$db = $database->getConnection();

// Lógica de Paginación
$noticias_por_pagina = 6; 
$pagina_actual = isset($_GET['p']) && is_numeric($_GET['p']) ? (int)$_GET['p'] : 1;
if ($pagina_actual < 1) $pagina_actual = 1;

$offset = ($pagina_actual - 1) * $noticias_por_pagina;

$stmt_count = $db->query("SELECT COUNT(id) FROM news WHERE status = 'published'");
$total_noticias = $stmt_count->fetchColumn();
$total_paginas = ceil($total_noticias / $noticias_por_pagina);

$query = "SELECT title, slug, excerpt, cover_image, created_at FROM news WHERE status = 'published' ORDER BY created_at DESC LIMIT :limit OFFSET :offset";
$stmt = $db->prepare($query);
$stmt->bindValue(':limit', $noticias_por_pagina, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$noticias = $stmt->fetchAll(PDO::FETCH_ASSOC);

$social_meta = [
    'title' => 'Noticias | Acción Honduras',
    'description' => 'Actualidad, proyectos e historias de impacto de Acción Honduras en Francisco Morazán.',
    'image' => '/uploads/images/social_accion_honduras_1200x630.jpg',
    'url' => 'https://accionhonduras.org/noticias',
    'type' => 'website',
    'breadcrumb' => 'Noticias'
];
ob_start();
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin: 0; padding: 0; font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; flex-direction: column; min-height: 100vh; }
        main { flex-grow: 1; }
        .ah-news-header { background: linear-gradient(135deg, #0f172a 0%, #34859B 100%); padding: 80px 20px; text-align: center; color: white; }
        .news-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(320px, 1fr)); gap: 40px; padding: 60px 20px; max-width: 1200px; margin: 0 auto; }
        .news-card { background: white; border-radius: 16px; overflow: hidden; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0; display: flex; flex-direction: column; text-decoration: none; transition: transform 0.3s; }
        .news-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(52, 133, 155, 0.15); border-color: #34859B;}
        .news-img { width: 100%; height: 220px; object-fit: cover; background: #e2e8f0; }
        .news-body { padding: 30px 25px; display: flex; flex-direction: column; flex-grow: 1; }
        .news-title { font-size: 1.3rem; color: #1e293b; margin-bottom: 15px; font-weight: 800; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .news-excerpt { color: #64748b; font-size: 0.95rem; line-height: 1.6; margin-bottom: 25px; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
        .news-footer { margin-top: auto; color: #34859B; font-weight: 700; font-size: 0.9rem; }
    </style>
<?php
$page_head_html = ob_get_clean();
include __DIR__ . '/header.php';
?>

    <main>
        <section class="ah-news-header">
            <h1 style="font-size: 3rem; font-weight: 800; margin-bottom: 15px;">Actualidad y Noticias</h1>
            <p style="font-size: 1.15rem; color: #cbd5e1; max-width: 600px; margin: 0 auto;">Descubre el impacto de nuestros proyectos en Francisco Morazán.</p>
        </section>

        <?php if (empty($noticias)): ?>
            <div style="text-align: center; padding: 100px 20px;">
                <i class="fa-regular fa-folder-open" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 20px;"></i>
                <h3 style="color: #1e293b; font-size: 1.5rem;">Aún no hay publicaciones</h3>
                <p style="color: #64748b;">Las noticias publicadas aparecerán aquí.</p>
            </div>
        <?php else: ?>
            <div class="news-grid">
                <?php foreach ($noticias as $n): ?>
                    <a href="noticia_single.php?slug=<?php echo htmlspecialchars($n['slug']); ?>" class="news-card">
                        <img src="<?php echo !empty($n['cover_image']) ? htmlspecialchars($n['cover_image']) : 'https://images.unsplash.com/photo-1542601906990-b4d3fb778b09?auto=format&fit=crop&q=80&w=800'; ?>" class="news-img" alt="Portada de noticia">
                        <div class="news-body">
                            <h2 class="news-title"><?php echo htmlspecialchars($n['title']); ?></h2>
                            <p class="news-excerpt"><?php echo htmlspecialchars($n['excerpt']); ?></p>
                            <div class="news-footer">Leer artículo <i class="fa-solid fa-arrow-right"></i></div>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
