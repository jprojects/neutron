<?php
// Datos del panel (admin/index.php), en JSON y con la sesión del editor. Solo lectura: se guarda con admin/save.php,
// como en la edición en la propia página (así los avisos de conflicto valen para los dos).
//
//   GET admin/api.php?a=home                inicio: datos de la web, últimos cambios y avisos de AFI
//   GET admin/api.php?a=content[&lang=es]   lo editable (lib/schema.php) con los valores de ese idioma
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/content.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/posts.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');
neutron_require_login();

$langs = neutron_languages();
$action = (string)($_GET['a'] ?? '');

// Nombre de la web: config (Cosmo), o el <title> de la portada sin lo de delante del separador, o el dominio
function neutron_panel_site_name(): string {
    $name = trim((string)(neutron_config()['name'] ?? ''));
    if ($name !== '') return $name;
    $html = (string)@file_get_contents(NEUTRON_SITE . '/template.html', false, null, 0, 8192);
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $parts = preg_split('/\s+[·|–—-]\s+/u', trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $title = trim((string)end($parts));
        if ($title !== '') return $title;
    }
    return (string)($_SERVER['HTTP_HOST'] ?? 'Web');
}

if ($action === 'content') {
    $lang = null;
    if ($langs !== null) {
        $lang = isset($langs['languages'][$_GET['lang'] ?? '']) ? (string)$_GET['lang'] : $langs['default'];
    }
    $shared = neutron_read_content(neutron_content_path());
    $own = $lang !== null ? neutron_read_content(neutron_content_path($lang)) : $shared;
    $fallback = $lang !== null && $lang !== $langs['default'] ? neutron_read_content(neutron_content_path($langs['default'])) : [];
    echo json_encode([
        'ok' => true,
        'base' => microtime(true),
        'lang' => $lang,
        'schema' => neutron_schema(),
        // Valores: lo propio del idioma; «fallback»: lo que se ve donde aún no está traducido (idioma principal)
        'values' => $lang !== null ? $own : $shared,
        'shared' => $lang !== null ? $shared : new stdClass(),
        'fallback' => $fallback ?: new stdClass(),
    ]);
    exit;
}

if ($action === 'home') {
    $times = neutron_edit_times();
    $recent = [];
    foreach ($times as $scope => $keys) {
        foreach ($keys as $key => $t) $recent[] = ['key' => $key, 'lang' => $scope === '_' ? null : $scope, 'time' => $t];
    }
    usort($recent, fn($a, $b) => $b['time'] <=> $a['time']);
    $posts = 0;
    foreach (neutron_collections() as $c) $posts += count(neutron_posts_list($c, true));
    // Avisos de AFI (los deja Cosmo por la API remota): solo los vigentes
    $notices = [];
    $file = NEUTRON_DATA . '/announcements.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    foreach ((array)($data['items'] ?? []) as $n) {
        if (!is_array($n) || empty($n['title'])) continue;
        if (!empty($n['until']) && strtotime((string)$n['until']) < time()) continue;
        $notices[] = ['title' => (string)$n['title'], 'text' => (string)($n['text'] ?? ''),
            'url' => (string)($n['url'] ?? ''), 'button' => (string)($n['button'] ?? '')];
    }
    echo json_encode([
        'ok' => true,
        'name' => neutron_panel_site_name(),
        'version' => trim((string)@file_get_contents(NEUTRON_ROOT . '/VERSION')),
        'languages' => $langs,
        'pages' => count(neutron_site_pages()),
        'posts' => $posts,
        'recent' => array_slice($recent, 0, 8),
        'notices' => $notices,
    ]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción desconocida']);
