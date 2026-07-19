<?php
header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/../config/Database.php';

$baseUrl = 'https://www.accionhonduras.org';
$urls = [];

$addUrl = function ($location, $changefreq = 'monthly', $priority = '0.7', $lastmod = null) use (&$urls) {
    $urls[$location] = [
        'loc' => $location,
        'changefreq' => $changefreq,
        'priority' => $priority,
        'lastmod' => $lastmod
    ];
};

$addUrl($baseUrl . '/', 'weekly', '1.0');
$addUrl($baseUrl . '/noticias', 'weekly', '0.8');
$addUrl($baseUrl . '/contacto', 'yearly', '0.6');

try {
    $database = new Database();
    $db = $database->getConnection();

    $pages = $db->query("SELECT slug FROM pages WHERE status = 'published' AND slug <> ''");
    foreach ($pages->fetchAll(PDO::FETCH_ASSOC) as $page) {
        $slug = strtolower(trim($page['slug']));
        $location = ($slug === 'inicio') ? $baseUrl . '/' : $baseUrl . '/' . rawurlencode($slug);
        $addUrl($location, 'monthly', $slug === 'inicio' ? '1.0' : '0.7');
    }

    $news = $db->query("SELECT slug, created_at FROM news WHERE status = 'published' AND slug <> '' ORDER BY created_at DESC");
    foreach ($news->fetchAll(PDO::FETCH_ASSOC) as $article) {
        $lastmod = !empty($article['created_at']) ? date('Y-m-d', strtotime($article['created_at'])) : null;
        $addUrl($baseUrl . '/noticia_single.php?slug=' . rawurlencode($article['slug']), 'monthly', '0.7', $lastmod);
    }
} catch (Throwable $error) {
    error_log('No se pudo completar el sitemap dinámico: ' . $error->getMessage());
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
foreach ($urls as $url) {
    echo "  <url>\n";
    echo '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc>\n";
    if ($url['lastmod']) {
        echo '    <lastmod>' . $url['lastmod'] . "</lastmod>\n";
    }
    echo '    <changefreq>' . $url['changefreq'] . "</changefreq>\n";
    echo '    <priority>' . $url['priority'] . "</priority>\n";
    echo "  </url>\n";
}
echo "</urlset>\n";
