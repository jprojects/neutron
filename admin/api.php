<?php
// Datos del panel (admin/index.php), en JSON y con la sesión del editor. Solo lectura: se guarda con admin/save.php,
// como en la edición en la propia página (así los avisos de conflicto valen para los dos).
//
//   GET admin/api.php?a=home                inicio: datos de la web, últimos cambios y avisos de AFI
//   GET admin/api.php?a=content[&lang=es]   lo editable (lib/schema.php) con los valores de ese idioma
//   POST admin/api.php?a=notice-click&id=…   clic en el botón de un aviso de AFI (estadística para Cosmo)
//   GET admin/api.php?a=seo                 análisis con Pulsar: páginas, últimos informes, historial y los que quedan hoy
//   POST admin/api.php?a=seo-run            {"type": "seo"|"geo", "page": ""|slug}: analiza con Pulsar (lib/seo.php)
//   GET admin/api.php?a=messages[&offset=]  mensajes del formulario (lib/messages.php) · GET ?a=message&id= (lo marca leído)
//   POST admin/api.php?a=message-delete&id= · POST ?a=message-unread&id=
//   GET admin/api.php?a=history             copias del contenido (lib/history.php) · GET ?a=history-detail&id=
//   POST admin/api.php?a=history-restore&id= vuelve a esa versión
//   GET admin/api.php?a=menu                el menú editable (lib/menu.php): enlaces y páginas a las que puede ir
//   POST admin/api.php?a=menu-save          {"items": [{href, label: {idioma: texto}, button, hidden}]} · {"reset": true}
//   GET admin/api.php?a=kepler              chat de Kepler (lib/kepler.php) · POST ?a=kepler-save {"token": "…", "enabled": true}
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/content.php';
require_once __DIR__ . '/../lib/schema.php';
require_once __DIR__ . '/../lib/posts.php';
require_once __DIR__ . '/../lib/announcements.php';
require_once __DIR__ . '/../lib/seo.php';
require_once __DIR__ . '/../lib/messages.php';
require_once __DIR__ . '/../lib/history.php';
require_once __DIR__ . '/../lib/stats.php';
require_once __DIR__ . '/../lib/menu.php';
require_once __DIR__ . '/../lib/meta.php';
require_once __DIR__ . '/../lib/kepler.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');
neutron_require_login();

$langs = neutron_languages();
$action = (string)($_GET['a'] ?? '');


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
    $cols = [];
    foreach (neutron_collections() as $c) $cols[] = ['label' => neutron_collection_schema($c)['label'], 'count' => count(neutron_posts_list($c, true))];
    usort($cols, fn($a, $b) => $b['count'] <=> $a['count']);
    // Avisos de AFI (los envía Cosmo, lib/announcements.php): los vigentes; una vista por sesión
    $notices = neutron_announcements_active();
    $seen = (array)($_SESSION['neutron_notices_seen'] ?? []);
    foreach ($notices as $n) {
        if (in_array($n['id'], $seen, true)) continue;
        neutron_announcements_count($n['id'], 'views');
        $seen[] = $n['id'];
    }
    $_SESSION['neutron_notices_seen'] = $seen;
    echo json_encode([
        'ok' => true,
        'name' => neutron_site_name(),
        'version' => trim((string)@file_get_contents(NEUTRON_ROOT . '/VERSION')),
        'languages' => $langs,
        'pages' => count(neutron_site_pages()),
        'collections' => $cols,
        'recent' => array_slice($recent, 0, 8),
        'notices' => $notices,
        'unread' => neutron_messages_unread(),
        'stats' => neutron_stats_enabled() ? neutron_stats_summary(30) : null,
    ]);
    exit;
}

if ($action === 'notice-click' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    neutron_require_csrf();
    neutron_announcements_count((string)($_GET['id'] ?? ''), 'clicks');
    echo json_encode(['ok' => true]);
    exit;
}

if ($action === 'seo') {
    $cfg = neutron_seo_config();
    echo json_encode([
        'ok' => true,
        'enabled' => $cfg !== null,
        'limit' => $cfg['limit'] ?? 0,
        'remaining' => neutron_seo_remaining(),
        'pages' => array_values(neutron_seo_pages()),
        'reports' => neutron_seo_reports(),
        'history' => array_slice(neutron_seo_read('history.json'), 0, 20),
    ]);
    exit;
}

if ($action === 'seo-run' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    neutron_require_csrf();
    // Un análisis tarda: se suelta la sesión para que el panel siga respondiendo mientras tanto
    session_write_close();
    $body = json_decode((string)file_get_contents('php://input'), true);
    $result = neutron_seo_run((string)($body['type'] ?? ''), (string)($body['page'] ?? ''));
    if (!$result['ok']) http_response_code(422);
    echo json_encode($result);
    exit;
}

$post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
$id = (string)($_GET['id'] ?? '');

if ($action === 'messages') {
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    echo json_encode(['ok' => true, 'items' => neutron_messages_list(50, $offset), 'total' => count(neutron_message_ids()),
        'unread' => neutron_messages_unread(), 'keep_days' => neutron_messages_keep_days()]);
    exit;
}
if ($action === 'message') {
    $m = neutron_message_get($id);
    if ($m === null) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Ese mensaje ya no existe']); exit; }
    if (empty($m['read'])) neutron_message_update($id, ['read' => true]);
    echo json_encode(['ok' => true, 'message' => $m + neutron_message_summary($m)]);
    exit;
}
if (($action === 'message-delete' || $action === 'message-unread') && $post) {
    neutron_require_csrf();
    $ok = $action === 'message-delete' ? neutron_message_delete($id) : neutron_message_update($id, ['read' => false]);
    if ($ok && $action === 'message-delete') neutron_log('notice', 'Mensaje del formulario borrado desde el panel');
    echo json_encode(['ok' => $ok]);
    exit;
}

if ($action === 'history') {
    echo json_encode(['ok' => true, 'items' => neutron_history_list(150)]);
    exit;
}
if ($action === 'history-detail') {
    $d = neutron_history_detail($id);
    if ($d === null) { http_response_code(404); echo json_encode(['ok' => false, 'error' => 'Esa copia ya no existe']); exit; }
    echo json_encode(['ok' => true, 'version' => $d]);
    exit;
}
if ($action === 'history-restore' && $post) {
    neutron_require_csrf();
    $ok = neutron_history_restore($id);
    if (!$ok) http_response_code(422);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'No se ha podido volver a esa versión']);
    exit;
}

if ($action === 'menu') {
    $items = neutron_menu_items();
    echo json_encode(['ok' => true, 'available' => neutron_menu_available(), 'custom' => $items !== null,
        'items' => $items ?? neutron_menu_from_template() ?? [], 'targets' => neutron_menu_targets()]);
    exit;
}
if ($action === 'kepler') {
    $k = neutron_kepler_settings();
    echo json_encode(['ok' => true, 'token' => $k['token'], 'enabled' => $k['enabled'], 'url' => $k['url'], 'source' => $k['source']]);
    exit;
}

if ($action === 'kepler-save' && $post) {
    neutron_require_csrf();
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $ok = neutron_kepler_save((string)($in['token'] ?? ''), !empty($in['enabled']));
    if (!$ok) http_response_code(422);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'El código no es válido: copia el valor de data-kepler del panel de Kepler']);
    exit;
}

if ($action === 'menu-save' && $post) {
    neutron_require_csrf();
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    if (!empty($in['reset'])) {                                  // volver al menú de la plantilla
        $ok = !is_file(neutron_menu_file()) || @unlink(neutron_menu_file());
    } else {
        $ok = is_array($in['items'] ?? null) && neutron_menu_save($in['items']);
    }
    if ($ok) neutron_log('info', 'Menú guardado desde el panel');
    else http_response_code(422);
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'No se ha podido guardar el menú']);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Acción desconocida']);
