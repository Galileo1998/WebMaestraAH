<?php
class Render {
    private $conn;

    public function __construct($db) {
        $this->conn = $db;
    }

    public function getPageBySlug($slug) {
        $query = "SELECT id, title FROM pages WHERE slug = :slug AND status = 'published' LIMIT 1";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':slug', $slug);
        $stmt->execute();
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function getBlocks($page_id) {
        $query = "SELECT block_type, content_json FROM page_blocks WHERE page_id = :page_id ORDER BY order_index ASC";
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(':page_id', $page_id);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function renderBlock($block) {
        $type = $block['block_type'];
        $content = json_decode($block['content_json'], true); 
        $html = '';

        switch ($type) {
            case 'hero_banner':
                $bg_image = !empty($content['background_image']) ? htmlspecialchars($content['background_image']) : '';
                $html .= '<section class="ah-hero" style="background-image: url(\'' . $bg_image . '\');">';
                $html .= '<div class="ah-hero-overlay"></div>';
                $html .= '<div class="ah-hero-content container">';
                $html .= '<h1>' . htmlspecialchars($content['title'] ?? '') . '</h1>';
                $html .= '<p>' . htmlspecialchars($content['subtitle'] ?? '') . '</p>';
                $html .= '</div></section>';
                break;

            case 'text_columns':
                $html .= '<section class="ah-text-section container">';
                $html .= '<div class="ah-grid-2">';
                $html .= '<div class="ah-column">' . ($content['column_1'] ?? '') . '</div>'; 
                $html .= '<div class="ah-column">' . ($content['column_2'] ?? '') . '</div>';
                $html .= '</div></section>';
                break;

            case 'pdf_3d':
                $pdf_url = htmlspecialchars($content['pdf_url'] ?? '#');
                $btn_text = htmlspecialchars($content['button_text'] ?? 'Leer Documento');
                $html .= '<section class="ah-pdf-section container">';
                $html .= '<div class="ah-pdf-wrapper">';
                $html .= '<div class="flipbook-container" data-pdf="' . $pdf_url . '">';
                $html .= '';
                $html .= '<div class="pdf-placeholder"><i class="fa-solid fa-book-open"></i><br>Cargando visor interactivo...</div>';
                $html .= '</div>';
                $html .= '<div class="ah-pdf-actions">';
                $html .= '<a href="' . $pdf_url . '" target="_blank" class="ah-btn ah-btn-primary"><i class="fa-solid fa-download"></i> ' . $btn_text . '</a>';
                $html .= '</div></div></section>';
                break;
        }

        return $html;
    }
}
?>