<?php
// Panel de la web (/admin/): inicio, contenido en formularios y acceso a la edición en la propia página (?admin).
// La interfaz es assets/panel.js; los datos salen de admin/api.php y se guardan con admin/save.php.
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/ai.php';
require_once __DIR__ . '/../lib/posts.php';
require_once __DIR__ . '/../lib/menu.php';
require_once __DIR__ . '/../lib/panel_lang.php';

neutron_block_if_maintenance();
neutron_start_session();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$langs = neutron_languages();
$uiLang = neutron_panel_lang();
$config = [
    // Idioma del panel (lib/panel_lang.php) y sus traducciones
    'ui' => $uiLang,
    'i18n' => neutron_panel_strings($uiLang),
    'loggedIn' => neutron_is_logged_in(),
    'csrf' => $_SESSION['csrf'],
    'languages' => $langs,
    // Sección SEO (Pulsar): solo si está configurado
    'seo' => neutron_is_logged_in() && neutron_seo_config() !== null,
    // Botones de IA en los textos (lib/ai.php): solo si está activada
    'ai' => neutron_is_logged_in() && neutron_ai_enabled(),
    // Una sección del menú por colección (blog, documentos, galería…): admin/items.php
    // Sección Menú: solo si la plantilla marca su menú con data-menu (lib/menu.php)
    'menu' => neutron_is_logged_in() && neutron_menu_available(),
    'collections' => neutron_is_logged_in() ? array_map(fn($c) => ['name' => $c, 'label' => neutron_collection_schema($c)['label']], neutron_collections()) : [],
];
$version = rawurlencode(trim((string)@file_get_contents(NEUTRON_ROOT . '/VERSION')));
?>
<!doctype html>
<html lang="<?= $uiLang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel</title>
<link rel="icon" href="../favicon.ico">
<link rel="stylesheet" href="../assets/panel.css?v=<?= $version ?>">
</head>
<body>
<div id="panel" class="np-loading"><?= $uiLang === 'ca' ? 'Carregant…' : 'Cargando…' ?></div>
<script type="application/json" id="neutron-panel-config"><?= json_encode($config) ?></script>
<script src="../assets/panel.js?v=<?= $version ?>"></script>
</body>
</html>
