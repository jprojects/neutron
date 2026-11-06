<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/content.php';
require_once __DIR__ . '/../lib/render.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

neutron_require_login();
neutron_require_csrf();

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'JSON inválido']);
    exit;
}

// Web de un idioma: todo a site/content.json. Varios idiomas: los textos y las listas al fichero del idioma que se
// edita (site/content.<idioma>.json) y las imágenes (images) a site/content.json, que es común.
$langs = neutron_languages();
$lang = null;
if ($langs !== null) {
    $lang = is_string($body['lang'] ?? null) && isset($langs['languages'][$body['lang']]) ? $body['lang'] : $langs['default'];
}
$shared = neutron_read_content(neutron_content_path());
$content = $lang !== null ? neutron_read_content(neutron_content_path($lang)) : $shared;
// En un idioma que no es el principal, la página muestra el texto del principal donde falta la traducción: si llega
// tal cual, no se guarda (así sigue cambiando cuando se cambia en el idioma principal).
$fallback = $lang !== null && $lang !== $langs['default']
    ? array_merge($shared, neutron_read_content(neutron_content_path($langs['default'])))
    : null;

if (!empty($body['fields']) && is_array($body['fields'])) {
    foreach ($body['fields'] as $key => $value) {
        if (!is_string($key)) continue;
        $value = is_string($value) ? trim($value) : $value;
        if ($fallback !== null && !array_key_exists($key, $content) && ($fallback[$key] ?? null) === $value) continue;
        $content[$key] = $value;
    }
}

// Imágenes sueltas (data-edit-img). Los editores anteriores a la 1.7 las mandaban dentro de fields.
if (!empty($body['images']) && is_array($body['images'])) {
    foreach ($body['images'] as $key => $value) {
        if (!is_string($key) || !is_string($value)) continue;
        if ($lang !== null) $shared[$key] = trim($value); else $content[$key] = trim($value);
    }
}

if (!empty($body['collections']) && is_array($body['collections'])) {
    foreach ($body['collections'] as $key => $items) {
        if (!is_string($key) || !is_array($items)) continue;
        $items = neutron_clean_items($items);
        // Igual que los textos: una lista que aún es la del idioma principal no se copia al idioma de la página
        if ($fallback !== null && !array_key_exists($key, $content) && ($fallback[$key] ?? null) == $items) continue;
        $content[$key] = $items;
    }
}

// Elementos de una lista: textos recortados y, en las sublistas, lo mismo (hasta NEUTRON_MAX_NESTING niveles).
function neutron_clean_items(array $items, int $depth = 0): array {
    $clean = [];
    foreach ($items as $item) {
        if (!is_array($item)) continue;
        $cleanItem = [];
        foreach ($item as $fk => $fv) {
            if (!is_string($fk)) continue;
            if (is_string($fv)) {
                $cleanItem[$fk] = trim($fv);
            } elseif (is_array($fv) && $depth < NEUTRON_MAX_NESTING) {
                $cleanItem[$fk] = neutron_clean_items($fv, $depth + 1);
            }
        }
        $clean[] = $cleanItem;
    }
    return $clean;
}

$saved = $lang !== null
    ? neutron_save_content($content, neutron_content_path($lang)) && neutron_save_content($shared)
    : neutron_save_content($content);
if ($saved) {
    neutron_log('info', 'Contenido guardado' . ($lang !== null ? ' ({lang})' : ''), [
        'lang' => $lang,
        'fields' => count((array)($body['fields'] ?? [])),
        'collections' => array_keys((array)($body['collections'] ?? [])),
    ]);
    echo json_encode(['ok' => true]);
} else {
    neutron_log('error', 'No se pudo escribir site/content.json (¿permisos?)');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
}
