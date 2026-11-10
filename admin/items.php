<?php
// Gestor de colecciones del panel (blog, documentos, galería, vídeos…), con la sesión del editor. Los campos salen de
// la definición de cada colección (lib/posts.php, site/<colección>/_collection.json).
//
//   GET  ?a=collections                         colecciones con su definición, categorías y número de elementos
//   GET  ?a=list&c=&lang=                       elementos (también borradores) en ese idioma
//   GET  ?a=get&c=&i=&lang=                     un elemento, con qué campos están traducidos en ese idioma
//   POST {"a": "create", "c", "title", "lang"}  → {slug}
//   POST {"a": "save", "c", "i", "lang", "fields": {...}, "status"}
//   POST {"a": "delete", "c", "i"}              (va a data/deleted/)
//   POST {"a": "order", "c", "slugs": [...]}    orden manual (sort "order")
//   POST {"a": "categories", "c", "categories": [{"id", "title", "description"}]}
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/posts.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');
neutron_require_login();

function items_reply(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data);
    exit;
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
$in = $isPost ? (json_decode((string)file_get_contents('php://input'), true) ?: []) : $_GET;
$action = (string)($in['a'] ?? '');
$collection = (string)($in['c'] ?? '');
if ($isPost) neutron_require_csrf();

// Idioma en que se leen y guardan los textos traducibles
$langs = neutron_languages();
$lang = null;
if ($langs !== null) {
    $lang = isset($langs['languages'][$in['lang'] ?? '']) ? (string)$in['lang'] : $langs['default'];
    neutron_lang($lang);
}

if ($action === 'collections') {
    $out = [];
    foreach (neutron_collections() as $c) {
        $schema = neutron_collection_schema($c);
        $out[] = ['name' => $c, 'label' => $schema['label'], 'item' => $schema['item'], 'page' => $schema['page'],
            'custom' => $schema['custom'], 'fields' => $schema['fields'], 'translated' => $schema['translated'],
            'sort' => $schema['sort'], 'help' => $schema['help'] ?? '',
            'categories' => neutron_collection_categories($c, true),
            'count' => count(neutron_posts_list($c, true))];
    }
    items_reply(['ok' => true, 'collections' => $out, 'lang' => $lang]);
}

if (!neutron_collection_valid($collection)) items_reply(['ok' => false, 'error' => 'Esa colección no existe'], 404);
$schema = neutron_collection_schema($collection);
$fields = neutron_collection_fields($collection);

// Lo que necesita la lista: título, estado, fecha, categoría, una imagen y las descargas
function items_row(array $p, array $fields): array {
    $thumb = '';
    foreach ($fields as $f) {
        if ($thumb === '' && $f['type'] === 'image' && is_string($p[$f['name']] ?? null)) $thumb = $p[$f['name']];
        if ($thumb === '' && $f['type'] === 'images' && !empty($p[$f['name']])) $thumb = $p[$f['name']][0];
        if ($thumb === '' && $f['type'] === 'video' && is_string($p[$f['name']] ?? null)) $thumb = neutron_video_thumb($p[$f['name']]);
    }
    $row = ['slug' => $p['slug'], 'title' => (string)($p['title'] ?? ''), 'status' => $p['status'], 'date' => $p['date'],
        'thumb' => $thumb, 'translated' => $p['translated'], 'order' => $p['order']];
    foreach ($fields as $f) {
        if ($f['type'] === 'category') $row['category'] = $p[$f['name']] ?? '';
        if ($f['type'] === 'file') {
            $row['file'] = basename((string)($p[$f['name']] ?? ''));
            $row['downloads'] = ($row['downloads'] ?? 0) + neutron_downloads_count($p['collection'], $p['slug'], $f['name']);
        }
        if ($f['type'] === 'images') $row['images'] = count((array)($p[$f['name']] ?? []));
    }
    return $row;
}

if ($action === 'list') {
    $rows = array_map(fn($p) => items_row($p, $fields), neutron_posts_list($collection, true));
    items_reply(['ok' => true, 'items' => $rows]);
}

if ($action === 'get') {
    $post = neutron_post_load($collection, (string)($in['i'] ?? ''));
    if ($post === null) items_reply(['ok' => false, 'error' => 'Ese elemento ya no existe'], 404);
    // Qué campos traducibles tienen ya texto propio en este idioma (los demás se ven en el idioma principal)
    $raw = json_decode((string)file_get_contents((string)neutron_post_file($collection, $post['slug'])), true) ?: [];
    $own = [];
    if ($lang !== null && $lang !== $langs['default']) {
        foreach ($schema['translated'] as $f) if (!empty($raw['i18n'][$lang][$f])) $own[] = $f;
    }
    $files = [];
    foreach ($fields as $f) {
        if ($f['type'] !== 'file' || !is_string($post[$f['name']] ?? null) || $post[$f['name']] === '') continue;
        $path = NEUTRON_ROOT . '/' . $post[$f['name']];
        $files[$f['name']] = ['name' => basename($post[$f['name']]), 'size' => is_file($path) ? filesize($path) : null,
            'downloads' => neutron_downloads_count($collection, $post['slug'], $f['name'])];
    }
    items_reply(['ok' => true, 'item' => $post, 'own' => $own, 'files' => $files,
        'url' => $schema['page'] ? neutron_lang_prefix() . neutron_post_href($post) : null]);
}

if (!$isPost) items_reply(['ok' => false, 'error' => 'Acción desconocida'], 400);

if ($action === 'create') {
    $title = trim((string)($in['title'] ?? ''));
    if ($title === '') items_reply(['ok' => false, 'error' => 'Escribe el título'], 422);
    $slug = neutron_post_slug($collection, $title);
    // Con página propia (blog), empieza como borrador; un documento o un álbum se ve al momento
    $data = ['title' => $title, 'date' => date('Y-m-d'), 'status' => $schema['page'] ? 'draft' : 'published'];
    if (isset($fields['body']) && $fields['body']['type'] === 'html') $data['body'] = '<p></p>';
    if ($schema['sort'] === 'order') $data['order'] = count(neutron_posts_list($collection, true)) + 1;
    $post = neutron_post_normalize($data, $collection, $slug);
    if (!neutron_post_save($post, true, $langs['default'] ?? null)) items_reply(['ok' => false, 'error' => 'No se pudo crear (¿permisos de site/' . $collection . '?)'], 500);
    neutron_log('info', 'Elemento creado desde el panel: {c}/{slug}', ['c' => $collection, 'slug' => $slug]);
    items_reply(['ok' => true, 'slug' => $slug]);
}

if ($action === 'categories') {
    $cats = is_array($in['categories'] ?? null) ? $in['categories'] : [];
    $existing = [];
    foreach (neutron_collection_categories($collection, true) as $c) $existing[$c['id']] = $c;
    $clean = [];
    foreach ($cats as $c) {
        if (!is_array($c) || trim((string)($c['title'] ?? '')) === '') continue;
        $id = (string)($c['id'] ?? '');
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $id)) {
            $id = neutron_post_slug('-', (string)$c['title']);   // categoría nueva: id a partir del título
            while (isset($existing[$id]) || in_array($id, array_column($clean, 'id'), true)) $id .= '-2';
        }
        $row = ($existing[$id] ?? []) + ['id' => $id];
        if ($lang !== null && $lang !== $langs['default'] && isset($existing[$id])) {
            $row['i18n'][$lang] = ['title' => trim((string)$c['title']), 'description' => trim((string)($c['description'] ?? ''))];
        } else {
            $row['title'] = trim((string)$c['title']);
            $row['description'] = trim((string)($c['description'] ?? ''));
        }
        $clean[] = $row;
    }
    if (!neutron_collection_categories_save($collection, $clean)) items_reply(['ok' => false, 'error' => 'No se pudieron guardar las categorías'], 500);
    items_reply(['ok' => true, 'categories' => neutron_collection_categories($collection, true)]);
}

if ($action === 'order') {
    $n = 0;
    foreach ((array)($in['slugs'] ?? []) as $slug) {
        $post = neutron_post_load($collection, (string)$slug);
        if ($post === null) continue;
        $post['order'] = ++$n;
        neutron_post_save($post, false);
    }
    items_reply(['ok' => true]);
}

$post = neutron_post_load($collection, (string)($in['i'] ?? ''));
if ($post === null) items_reply(['ok' => false, 'error' => 'Ese elemento ya no existe'], 404);

if ($action === 'save') {
    $values = is_array($in['fields'] ?? null) ? $in['fields'] : [];
    foreach ($fields as $name => $f) {
        if (array_key_exists($name, $values)) $post[$name] = $values[$name];
    }
    if (isset($in['status'])) $post['status'] = $in['status'] === 'published' ? 'published' : 'draft';
    if (trim((string)($post['title'] ?? '')) === '') items_reply(['ok' => false, 'error' => 'El título no puede quedar vacío'], 422);
    if (!neutron_post_save($post, true, $lang)) items_reply(['ok' => false, 'error' => 'No se pudo guardar'], 500);
    neutron_log('info', 'Elemento guardado desde el panel: {c}/{slug}', ['c' => $collection, 'slug' => $post['slug']]);
    items_reply(['ok' => true]);
}

if ($action === 'delete') {
    if (!neutron_post_delete($collection, $post['slug'])) items_reply(['ok' => false, 'error' => 'No se pudo borrar'], 500);
    neutron_log('notice', 'Elemento borrado desde el panel: {c}/{slug}', ['c' => $collection, 'slug' => $post['slug']]);
    items_reply(['ok' => true]);
}

items_reply(['ok' => false, 'error' => 'Acción desconocida'], 400);
