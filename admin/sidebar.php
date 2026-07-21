<?php
$current_script = basename($_SERVER['PHP_SELF'] ?? '');
$module_groups = require __DIR__ . '/module_catalog.php';
$is_portal_user = ($_SESSION['auth_source'] ?? '') === 'portal';

if ($is_portal_user) {
    $is_admin = false;
    $user_perms = ['panel_tecnico.php'];
} else {
    $user_id_sidebar = (int)($_SESSION['user_id'] ?? 0);
    $stmt_side = $db->prepare("SELECT role, permissions FROM users WHERE id = ?");
    $stmt_side->execute([$user_id_sidebar]);
    $user_side = $stmt_side->fetch(PDO::FETCH_ASSOC) ?: ['role' => '', 'permissions' => '[]'];
    $is_admin = $user_side['role'] === 'admin';
    $user_perms = json_decode($user_side['permissions'] ?: '[]', true) ?: [];
}

if (!function_exists('canView')) {
    function canView($module, $is_admin, $user_perms) {
        return $is_admin || in_array($module, $user_perms, true);
    }
}
?>

<link rel="stylesheet" href="/admin/assets/css/table-ux.css?v=1">
<script src="/admin/assets/js/table-ux.js?v=1" defer></script>

<style>
    .sidebar { width: 280px; background: #0f172a; color: white; display: flex; flex-direction: column; flex-shrink: 0; min-height: 100vh; transition: margin-left 0.3s ease; position: relative; z-index: 100; }
    .sidebar.collapsed { margin-left: -280px; }
    .sidebar-header { padding: 20px 22px; border-bottom: 1px solid rgba(255,255,255,0.1); display: flex; align-items: center; justify-content: space-between; gap: 12px; }
    .sidebar-brand { display: flex; align-items: center; gap: 11px; min-width: 0; }
    .sidebar-brand-mark { width: 42px; height: 26px; overflow: hidden; flex: 0 0 42px; display: flex; align-items: flex-start; justify-content: center; }
    .sidebar-brand-mark img { display: block; width: 42px; height: auto; filter: grayscale(1) brightness(0) invert(1); transform: translateY(-1px); }
    .sidebar-brand-name { color: #fff; font-weight: 750; font-size: 1.05rem; line-height: 1.15; white-space: nowrap; letter-spacing: -0.01em; }
    .sidebar-content { padding: 14px 18px 24px; flex-grow: 1; overflow-y: auto; }
    .nav-group { margin-top: 18px; }
    .nav-group:first-child { margin-top: 4px; }
    .nav-group-title { color: #64748b; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.1em; text-transform: uppercase; padding: 0 10px 7px; display: flex; align-items: center; gap: 8px; }
    .nav-link { color: #cbd5e1; text-decoration: none; display: flex; align-items: center; gap: 10px; min-height: 42px; padding: 6px 10px; border-radius: 8px; transition: 0.2s; font-size: 0.92rem; }
    .nav-link:hover { color: white; background: rgba(255,255,255,0.06); }
    .nav-link.active { color: white; background: rgba(70,176,148,0.16); }
    .nav-link.active i { color: #46B094; }
    .nav-link i { width: 20px; text-align: center; }
    .btn-toggle-close { background: none; border: none; color: #cbd5e1; cursor: pointer; font-size: 1.2rem; padding: 10px; display: flex; align-items: center; }
    .btn-toggle-open { position: fixed; top: 20px; left: 20px; z-index: 101; background: #0f172a; color: white; border: none; padding: 12px 15px; border-radius: 8px; cursor: pointer; font-size: 1.2rem; display: none; box-shadow: 0 4px 10px rgba(0,0,0,0.15); }
</style>

<button type="button" class="btn-toggle-open" id="btn-open-sidebar" onclick="toggleSidebar()" aria-label="Mostrar menú"><i class="fa-solid fa-bars"></i></button>

<aside class="sidebar" id="main-sidebar" aria-label="Navegación administrativa">
    <div class="sidebar-header">
        <div class="sidebar-brand">
            <span class="sidebar-brand-mark" aria-hidden="true"><img src="/uploads/images/logo.png" alt=""></span>
            <span class="sidebar-brand-name">Acción Honduras</span>
        </div>
        <button type="button" class="btn-toggle-close" onclick="toggleSidebar()" aria-label="Ocultar menú"><i class="fa-solid fa-angle-left"></i></button>
    </div>
    <nav class="sidebar-content" aria-label="Módulos administrativos">
        <?php foreach ($module_groups as $group_name => $group): ?>
            <?php
            $visible_items = array_filter($group['items'], function ($item, $permission) use ($is_admin, $user_perms) {
                return !empty($item['sidebar']) && canView($permission, $is_admin, $user_perms);
            }, ARRAY_FILTER_USE_BOTH);
            if (empty($visible_items)) continue;
            ?>
            <section class="nav-group" aria-labelledby="group-<?php echo md5($group_name); ?>">
                <div class="nav-group-title" id="group-<?php echo md5($group_name); ?>"><i class="fa-solid <?php echo htmlspecialchars($group['icon']); ?>"></i><?php echo htmlspecialchars($group_name); ?></div>
                <?php foreach ($visible_items as $permission => $item): ?>
                    <?php
                    $active_scripts = $item['active'] ?? [$permission];
                    $is_active = in_array($current_script, $active_scripts, true);
                    $href = $item['href'] ?? $permission;
                    ?>
                    <a href="<?php echo htmlspecialchars($href); ?>" class="nav-link <?php echo $is_active ? 'active' : ''; ?>"<?php echo !empty($item['external']) ? ' target="_blank" rel="noopener noreferrer"' : ''; ?>>
                        <i class="fa-solid <?php echo htmlspecialchars($item['icon']); ?>"></i><span><?php echo htmlspecialchars($item['label']); ?></span>
                    </a>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>
    </nav>
    <div style="padding:18px 24px; border-top:1px solid rgba(255,255,255,0.1);"><a href="logout.php" style="color:#f87171; text-decoration:none; font-size:0.9rem;"><i class="fa-solid fa-arrow-right-from-bracket"></i> Salir</a></div>
</aside>

<script>
function toggleSidebar() {
    const sidebar = document.getElementById('main-sidebar');
    const openBtn = document.getElementById('btn-open-sidebar');
    const collapsed = sidebar.classList.toggle('collapsed');
    openBtn.style.display = collapsed ? 'block' : 'none';
}
</script>
