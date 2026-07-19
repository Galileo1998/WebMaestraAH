<?php
// public/donantes.php
require_once '../config/Database.php';

$database = new Database();
$db = $database->getConnection();

$stmt = $db->query("SELECT id, name, logo_url, description FROM partners WHERE status = 'active' ORDER BY name ASC");
$socios = $stmt->fetchAll(PDO::FETCH_ASSOC);
$social_meta = [
    'title' => 'Socios y Cooperantes | Acción Honduras',
    'description' => 'Conoce las organizaciones y alianzas que multiplican el impacto de Acción Honduras.',
    'image' => '/uploads/images/social_accion_honduras_1200x630.jpg',
    'url' => 'https://accionhonduras.org/donantes',
    'type' => 'website',
    'breadcrumb' => 'Socios y cooperantes'
];
ob_start();
?>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { margin: 0; font-family: 'Inter', sans-serif; background: #f8fafc; display: flex; flex-direction: column; min-height: 100vh; }
        .donors-header { background: #0f172a; color: white; padding: 80px 20px; text-align: center; }
        .donors-grid { max-width: 1200px; margin: 60px auto; padding: 0 20px; display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 40px; }
        
        .donor-card { background: white; border-radius: 16px; padding: 40px 30px; text-align: center; box-shadow: 0 10px 30px rgba(0,0,0,0); border: 1px solid #e2e8f0; transition: transform 0.3s, box-shadow 0.3s; text-decoration: none; display: flex; flex-direction: column; align-items: center; }
        .donor-card:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(52, 133, 155, 0.15); border-color: #34859B; }
        
        .donor-logo { height: 80px; max-width: 100%; object-fit: contain; margin-bottom: 25px; }
        .donor-name { font-size: 1.4rem; color: #1e293b; font-weight: 800; margin-bottom: 15px; }
        .donor-desc { color: #64748b; font-size: 0.95rem; line-height: 1.6; flex-grow: 1; margin-bottom: 25px; }
        .donor-btn { color: #46B094; font-weight: 700; font-size: 1rem; display: flex; align-items: center; gap: 8px; }
        .donor-card:hover .donor-btn { color: #34859B; }
    </style>
<?php
$page_head_html = ob_get_clean();
include __DIR__ . '/header.php';
?>

    <main style="flex-grow: 1;">
        <section class="donors-header">
            <h1 style="font-size: 3rem; font-weight: 800; margin-bottom: 15px;">Socios y Cooperantes</h1>
            <p style="font-size: 1.15rem; color: #cbd5e1; max-width: 700px; margin: 0 auto;">Alianzas estratégicas que multiplican el impacto en nuestras comunidades.</p>
        </section>

        <div class="donors-grid">
            <?php foreach ($socios as $s): ?>
                <a href="impacto.php?socio_id=<?php echo $s['id']; ?>" class="donor-card">
                    <?php if($s['logo_url']): ?>
                        <img src="<?php echo htmlspecialchars($s['logo_url']); ?>" class="donor-logo" alt="<?php echo htmlspecialchars($s['name']); ?>">
                    <?php else: ?>
                        <div class="donor-logo" style="display:flex; align-items:center; justify-content:center; background:#f1f5f9; width:100%; border-radius:8px; color:#94a3b8; font-size:2rem;"><i class="fa-solid fa-building-ngo"></i></div>
                    <?php endif; ?>
                    
                    <h2 class="donor-name"><?php echo htmlspecialchars($s['name']); ?></h2>
                    <p class="donor-desc"><?php echo htmlspecialchars($s['description']); ?></p>
                    <div class="donor-btn">Ver Impacto y Proyectos <i class="fa-solid fa-arrow-right"></i></div>
                </a>
            <?php endforeach; ?>
        </div>
    </main>

    <?php include 'footer.php'; ?>

</body>
</html>
