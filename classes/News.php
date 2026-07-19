<?php
class News {
    private $conn;
    private $table_name = "posts";

    public function __construct($db) {
        $this->conn = $db;
    }

    // 1. Guardar una nueva noticia en la BD
    public function createPost($title, $slug, $excerpt, $content_json, $featured_image) {
        $query = "INSERT INTO " . $this->table_name . " 
                  (title, slug, excerpt, content_json, featured_image, published_date) 
                  VALUES (:title, :slug, :excerpt, :content_json, :featured_image, NOW())";
        
        $stmt = $this->conn->prepare($query);

        // Limpiar datos por seguridad
        $title = htmlspecialchars(strip_tags($title));
        $slug = htmlspecialchars(strip_tags($slug));
        $excerpt = htmlspecialchars(strip_tags($excerpt));
        
        // Enlazar parámetros
        $stmt->bindParam(":title", $title);
        $stmt->bindParam(":slug", $slug);
        $stmt->bindParam(":excerpt", $excerpt);
        $stmt->bindParam(":content_json", $content_json);
        $stmt->bindParam(":featured_image", $featured_image);

        if($stmt->execute()) {
            return true;
        }
        return false;
    }

    // 2. Extraer todas las noticias (Para la página principal del blog)
    public function getAllPosts() {
        $query = "SELECT id, title, slug, excerpt, featured_image, published_date 
                  FROM " . $this->table_name . " 
                  ORDER BY published_date DESC";
                  
        $stmt = $this->conn->prepare($query);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // 3. Leer una noticia completa (Cuando el usuario hace clic en "Leer más")
    public function getPostBySlug($slug) {
        $query = "SELECT * FROM " . $this->table_name . " WHERE slug = :slug LIMIT 1";
        
        $stmt = $this->conn->prepare($query);
        $stmt->bindParam(":slug", $slug);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}
?>