<?php
// public/footer.php

// 1. Asegurarnos de tener la conexión a la base de datos
if (!isset($db)) {
    require_once '../config/Database.php';
    $database = new Database();
    $db = $database->getConnection();
}

// 2. Extraer las configuraciones
$settings = [];
try {
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $settings_raw = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($settings_raw as $s) {
        $settings[$s['setting_key']] = $s['setting_value'];
    }
} catch (PDOException $e) {
    // Si la tabla no existe o hay error, no hacemos nada y usamos los valores por defecto
}

// 3. Limpieza y validación segura de variables
$fb_link = !empty($settings['social_facebook']) ? $settings['social_facebook'] : '#';
$ig_link = !empty($settings['social_instagram']) ? $settings['social_instagram'] : '#';
$tw_link = !empty($settings['social_twitter']) ? $settings['social_twitter'] : '#';
$email_link = !empty($settings['contact_email']) ? $settings['contact_email'] : 'info@accionhonduras.org';
$site_name = !empty($settings['site_name']) ? $settings['site_name'] : 'Acción Honduras';
?>

<footer style="background: #0f172a; color: white; padding: 60px 20px; margin-top: auto; font-family: 'Inter', sans-serif;">
    <div style="max-width: 1200px; margin: 0 auto; display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 40px;">
        
        <div>
            <h3 style="color: #46B094; margin-bottom: 20px; font-size: 1.2rem;">Acción Honduras</h3>
            <p style="color: #cbd5e1; font-size: 0.9rem; line-height: 1.6;">Inspiración y cambio para el desarrollo sostenible en las comunidades de Lepaterique, Reitoca, Alubarén, La Venta y Curarén.</p>
        </div>
        
        <div>
            <h3 style="margin-bottom: 20px; font-size: 1.1rem;">Contacto</h3>
            <p style="color: #cbd5e1; font-size: 0.9rem; margin-bottom: 10px;">
                <i class="fa-solid fa-envelope" style="color: #34859B; margin-right: 10px;"></i> <?php echo htmlspecialchars($email_link); ?>
            </p>
            <p style="color: #cbd5e1; font-size: 0.9rem;">
                <i class="fa-solid fa-location-dot" style="color: #34859B; margin-right: 10px;"></i> Francisco Morazán, Honduras
            </p>
        </div>
        
        <div>
            <h3 style="margin-bottom: 20px; font-size: 1.1rem;">Síguenos</h3>
            <div style="display: flex; gap: 10px;">
                <a href="<?php echo htmlspecialchars($fb_link); ?>" target="_blank" rel="noopener noreferrer" aria-label="Acción Honduras en Facebook" style="color: white; font-size: 1.5rem; transition: transform 0.2s; width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-brands fa-facebook-f" aria-hidden="true"></i></a>

                <a href="<?php echo htmlspecialchars($ig_link); ?>" target="_blank" rel="noopener noreferrer" aria-label="Acción Honduras en Instagram" style="color: white; font-size: 1.5rem; transition: transform 0.2s; width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-brands fa-instagram" aria-hidden="true"></i></a>

                <a href="<?php echo htmlspecialchars($tw_link); ?>" target="_blank" rel="noopener noreferrer" aria-label="Acción Honduras en X" style="color: white; font-size: 1.5rem; transition: transform 0.2s; width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center;"><i class="fa-brands fa-twitter" aria-hidden="true"></i></a>
            </div>
        </div>
        
    </div>
    
    <div style="text-align: center; margin-top: 50px; padding-top: 20px; border-top: 1px solid rgba(255,255,255,0.1); color: #64748b; font-size: 0.85rem;">
        &copy; <?php echo date('Y'); ?> <?php echo htmlspecialchars($site_name); ?>. Todos los derechos reservados.
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        console.log("🛠️ Buscando carrusel en la página..."); 
        
        const slider = document.getElementById('mainHeroSlider');
        
        if (slider) {
            console.log("✅ Carrusel encontrado. Arrancando motor.");
            
            let currentSlide = 0;
            const slides = slider.querySelectorAll('.ah-slide');
            let slideInterval;

            if (slides.length > 0) {
                
                // Función principal de movimiento
                function moveAhSlide(direction) {
                    slides[currentSlide].classList.remove('active');
                    currentSlide = (currentSlide + direction + slides.length) % slides.length;
                    slides[currentSlide].classList.add('active');
                    resetInterval();
                }

                // 🚀 TRUCO ANTI-BUILDER: Asignamos los clics desde JS, sin depender del HTML
                const arrows = slider.querySelectorAll('.ah-arrow');
                if (arrows.length >= 2) {
                    arrows.forEach(function(arrow, index) {
                        arrow.setAttribute('role', 'button');
                        arrow.setAttribute('tabindex', '0');
                        arrow.setAttribute('aria-label', index === 0 ? 'Diapositiva anterior' : 'Diapositiva siguiente');
                        arrow.addEventListener('keydown', function(event) {
                            if (event.key === 'Enter' || event.key === ' ') {
                                event.preventDefault();
                                moveAhSlide(index === 0 ? -1 : 1);
                            }
                        });
                    });
                    // La primera flecha es la izquierda (-1), la segunda es la derecha (1)
                    arrows[0].addEventListener('click', function() { moveAhSlide(-1); });
                    arrows[1].addEventListener('click', function() { moveAhSlide(1); });
                    console.log("✅ Controles de flechas enlazados correctamente.");
                } else {
                    console.warn("⚠️ No se encontraron las flechas (.ah-arrow) en el HTML.");
                }

                // Reproducción automática
                function startInterval() {
                    slideInterval = setInterval(() => { moveAhSlide(1); }, 6000);
                }

                function resetInterval() {
                    clearInterval(slideInterval);
                    startInterval();
                }

                // Arrancamos
                startInterval();
                
            } else {
                console.warn("⚠️ El carrusel existe, pero no tiene imágenes (.ah-slide).");
            }
        }
    });
</script>
