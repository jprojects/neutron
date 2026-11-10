<?php
// Descarga de un fichero de una colección (campo de tipo "file"): suma una descarga y lleva al fichero.
//   download.php?c=documentacio&i=festa-del-pi-1970&f=file
// Cuenta en data/downloads.json {"coleccion/elemento/campo": n}; los robots no cuentan. Solo elementos publicados
// (o cualquiera con la sesión del editor).
define('NEUTRON_APP', true);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/routing.php';
require_once __DIR__ . '/lib/posts.php';
require_once __DIR__ . '/lib/remote/state.php';

neutron_block_if_maintenance();

$c = (string)($_GET['c'] ?? '');
$i = (string)($_GET['i'] ?? '');
$f = (string)($_GET['f'] ?? '');
$post = neutron_collection_valid($c) ? neutron_post_load($c, $i) : null;
$field = $post !== null ? (neutron_collection_fields($c)[$f] ?? null) : null;
$path = $field !== null && $field['type'] === 'file' ? (string)($post[$f] ?? '') : '';
if ($post !== null && $post['status'] !== 'published') {
    if (isset($_COOKIE['neutron_admin_sid'])) neutron_start_session();
    if (!neutron_is_logged_in()) $path = '';
}
$real = $path !== '' ? realpath(NEUTRON_ROOT . '/' . $path) : false;
$allowed = [realpath(NEUTRON_SITE . '/uploads'), realpath(NEUTRON_SITE . '/assets')];
$inside = $real !== false && array_filter($allowed, fn($dir) => $dir !== false && str_starts_with($real, $dir . DIRECTORY_SEPARATOR));
if (!$inside || !is_file($real)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'No se encuentra el fichero';
    exit;
}

$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua !== '' && !preg_match('/bot|crawl|spider|slurp|facebookexternalhit|preview|curl|wget|python|monitor/i', $ua)) {
    $lock = @fopen(NEUTRON_DATA . '/downloads.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $file = NEUTRON_DATA . '/downloads.json';
    $counts = is_file($file) ? (json_decode((string)file_get_contents($file), true) ?: []) : [];
    $key = $c . '/' . $i . '/' . $f;
    $counts[$key] = (int)($counts[$key] ?? 0) + 1;
    try {
        neutron_write_json($file, $counts);
    } catch (RuntimeException $e) {
        neutron_log('warning', 'No se pudo contar la descarga: {error}', ['error' => $e->getMessage()]);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}

header('Cache-Control: no-store');
header('Location: ' . neutron_base_path() . '/' . implode('/', array_map('rawurlencode', explode('/', $path))), true, 302);
