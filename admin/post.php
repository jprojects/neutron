<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/routing.php';
require_once __DIR__ . '/../lib/posts.php';

// Entradas de una colección (blog) desde el editor. POST JSON con la sesión y el token CSRF:
//   {action: "create", collection, title}                          → {ok, slug, url}
//   {action: "save", collection, slug, fields: {title, date, image, summary, body}}
//   {action: "status", collection, slug, status: "published"|"draft"}
//   {action: "delete", collection, slug}                           (va a data/deleted/)

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');

function post_reply(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    post_reply(['ok' => false, 'error' => 'Método no permitido'], 405);
}
neutron_require_login();
neutron_require_csrf();

$body = json_decode((string)file_get_contents('php://input'), true);
$action = is_array($body) ? (string)($body['action'] ?? '') : '';
$collection = is_array($body) ? (string)($body['collection'] ?? '') : '';
if (!neutron_collection_valid($collection)) {
    post_reply(['ok' => false, 'error' => 'Colección no válida'], 400);
}

if ($action === 'create') {
    $title = trim(mb_substr((string)($body['title'] ?? ''), 0, 200));
    if ($title === '') post_reply(['ok' => false, 'error' => 'Escribe un título'], 422);
    $post = neutron_post_normalize(['title' => $title, 'date' => date('Y-m-d'), 'status' => 'draft', 'body' => '<p></p>'],
        $collection, neutron_post_slug($collection, $title));
    if (!neutron_post_save($post)) post_reply(['ok' => false, 'error' => 'No se pudo crear (¿permisos de site/' . $collection . '?)'], 500);
    neutron_log('info', 'Entrada creada: {c}/{slug}', ['c' => $collection, 'slug' => $post['slug']]);
    post_reply(['ok' => true, 'slug' => $post['slug'], 'url' => neutron_post_href($post)]);
}

$post = neutron_post_load($collection, (string)($body['slug'] ?? ''));
if ($post === null) {
    post_reply(['ok' => false, 'error' => 'La entrada no existe'], 404);
}

if ($action === 'save') {
    $fields = is_array($body['fields'] ?? null) ? $body['fields'] : [];
    foreach (['title' => 200, 'summary' => 1000] as $f => $max) {
        if (is_string($fields[$f] ?? null)) $post[$f] = trim(mb_substr($fields[$f], 0, $max));
    }
    if (is_string($fields['date'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fields['date']) && checkdate(
        (int)substr($fields['date'], 5, 2), (int)substr($fields['date'], 8, 2), (int)substr($fields['date'], 0, 4))) {
        $post['date'] = $fields['date'];
    }
    if (is_string($fields['image'] ?? null)) {
        $img = $fields['image'];
        // Solo imágenes del propio sitio (las que sube el editor van a site/uploads/)
        if ($img === '' || (preg_match('~^site/(uploads|assets)/[A-Za-z0-9._/-]+$~', $img) && !str_contains($img, '..'))) {
            $post['image'] = $img;
        }
    }
    if (is_string($fields['body'] ?? null)) $post['body'] = $fields['body']; // neutron_post_save() lo limpia
    if ($post['title'] === '') post_reply(['ok' => false, 'error' => 'La entrada necesita un título'], 422);
    if (!neutron_post_save($post)) post_reply(['ok' => false, 'error' => 'No se pudo guardar'], 500);
    neutron_log('info', 'Entrada guardada: {c}/{slug}', ['c' => $collection, 'slug' => $post['slug']]);
    post_reply(['ok' => true]);
}

if ($action === 'status') {
    $post['status'] = ($body['status'] ?? '') === 'published' ? 'published' : 'draft';
    if (!neutron_post_save($post)) post_reply(['ok' => false, 'error' => 'No se pudo guardar'], 500);
    neutron_log('notice', 'Entrada {status}: {c}/{slug}', ['c' => $collection, 'slug' => $post['slug'], 'status' => $post['status'] === 'published' ? 'publicada' : 'pasada a borrador']);
    post_reply(['ok' => true, 'status' => $post['status']]);
}

if ($action === 'delete') {
    if (!neutron_post_delete($collection, $post['slug'])) post_reply(['ok' => false, 'error' => 'No se pudo borrar'], 500);
    neutron_log('notice', 'Entrada borrada: {c}/{slug} (copia en data/deleted)', ['c' => $collection, 'slug' => $post['slug']]);
    post_reply(['ok' => true, 'url' => $collection . '/']);
}

post_reply(['ok' => false, 'error' => 'Acción desconocida'], 400);
