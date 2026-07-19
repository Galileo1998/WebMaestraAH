<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/Database.php';

$database = new Database();
$db = $database->getConnection();

// Extraemos SOLO los cursos que tú hayas marcado como "Publicados" en el panel
$stmt = $db->query("SELECT * FROM ah_courses WHERE status = 'published' ORDER BY id DESC");
$cursos = $stmt->fetchAll(PDO::FETCH_ASSOC);

$social_meta = [
    'title' => 'Academia Virtual | Acción Honduras',
    'description' => 'Cursos y oportunidades de formación de Acción Honduras para fortalecer capacidades y transformar comunidades.',
    'image' => '/uploads/images/social_accion_honduras_1200x630.jpg',
    'url' => 'https://accionhonduras.org/academia.php',
    'type' => 'website',
    'breadcrumb' => 'Academia'
];
ob_start();
?>

<style>
    .academia-hero {
        background: linear-gradient(135deg, #0f172a 0%, var(--ah-primary) 100%);
        padding: 80px 20px 100px;
        text-align: center;
        color: white;
    }
    .academia-hero h1 { 
        font-size: 3rem; 
        font-weight: 800; 
        margin-bottom: 15px; 
        margin-top: 0;
    }
    .academia-hero p { 
        font-size: 1.15rem; 
        max-width: 700px; 
        margin: 0 auto; 
        color: #e2e8f0; 
        line-height: 1.6; 
    }

    .cursos-grid {
        max-width: 1200px;
        margin: -50px auto 80px;
        padding: 0 20px;
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
        gap: 30px;
        position: relative;
        z-index: 10;
    }

    .curso-card {
        background: white;
        border-radius: 12px;
        overflow: hidden;
        box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        border: 1px solid #e2e8f0;
        transition: transform 0.3s ease, box-shadow 0.3s ease;
        display: flex;
        flex-direction: column;
    }
    .curso-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(52, 133, 155, 0.15);
        border-color: var(--ah-primary);
    }

    .curso-img {
        width: 100%;
        height: 200px;
        object-fit: cover;
        background: #cbd5e1;
        border-bottom: 4px solid var(--ah-accent);
    }

    .curso-content {
        padding: 25px;
        flex-grow: 1;
        display: flex;
        flex-direction: column;
    }

    .curso-title {
        font-size: 1.3rem;
        color: #0f172a;
        font-weight: 800;
        margin-top: 0;
        margin-bottom: 12px;
        line-height: 1.3;
    }

    .curso-desc {
        color: #475569;
        font-size: 0.95rem;
        line-height: 1.6;
        margin-bottom: 25px;
        flex-grow: 1;
    }

    .btn-comenzar {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
        background: #f8fafc;
        color: var(--ah-primary);
        font-weight: 700;
        padding: 12px;
        border-radius: 8px;
        text-decoration: none;
        border: 1px solid #cbd5e1;
        transition: all 0.2s;
    }
    
    .curso-card:hover .btn-comenzar {
        background: var(--ah-primary);
        color: white;
        border-color: var(--ah-primary);
    }

    /* Ajustes para celulares */
    @media (max-width: 768px) {
        .academia-hero h1 { font-size: 2.2rem; }
        .academia-hero { padding: 60px 20px 80px; }
        .cursos-grid { margin-top: -30px; }
    }
</style>
<?php
$page_head_html = ob_get_clean();
require_once __DIR__ . '/header.php';
?>

<section class="academia-hero">
    <h1>Centro de Formación Digital</h1>
    <p>Aprende, certifícate y desarrolla nuevas habilidades con los programas de Acción Honduras, diseñados para empoderar a la juventud y transformar comunidades.</p>
</section>

<section class="cursos-grid">
    <?php if(empty($cursos)): ?>
        <div style="grid-column: 1/-1; text-align: center; padding: 60px; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;">
            <i class="fa-solid fa-person-chalkboard" style="font-size: 4rem; color: #cbd5e1; margin-bottom: 20px;"></i>
            <h2 style="color: #334155; margin-top:0;">Próximamente nuevos programas</h2>
            <p style="color: #64748b;">Nuestros especialistas están estructurando el material formativo. Vuelve pronto.</p>
        </div>
    <?php else: ?>
        <?php foreach($cursos as $c): ?>
            <?php 
                // Logica de validación de imagen. Si no has subido una, pone una genérica elegante.
                $img_src = !empty($c['cover_image']) ? htmlspecialchars($c['cover_image']) : 'https://images.unsplash.com/photo-1522202176988-66273c2fd55f?q=80&w=800'; 
                
                // Si la imagen viene de tu servidor, nos aseguramos de que la ruta sea correcta
                if (!empty($c['cover_image']) && !filter_var($c['cover_image'], FILTER_VALIDATE_URL)) {
                    $clean_img = ltrim($c['cover_image'], '/');
                    $clean_img = str_replace('../', '', $clean_img);
                    $clean_img = preg_replace('#^(?:web/)+#i', '', $clean_img);
                    $img_src = "/" . $clean_img;
                }
            ?>
            <article class="curso-card">
                <img src="<?php echo $img_src; ?>" alt="<?php echo htmlspecialchars($c['title']); ?>" class="curso-img">
                <div class="curso-content">
                    <h2 class="curso-title"><?php echo htmlspecialchars($c['title']); ?></h2>
                    <p class="curso-desc"><?php echo htmlspecialchars(substr($c['description'], 0, 130)) . '...'; ?></p>
                    
                    <a href="aula.php?curso=<?php echo htmlspecialchars($c['slug']); ?>" class="btn-comenzar">
                        Comenzar Módulo <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</section>

</body>
</html>
