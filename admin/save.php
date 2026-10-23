<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/content.php';

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

$content = neutron_load_content();

if (!empty($body['fields']) && is_array($body['fields'])) {
    foreach ($body['fields'] as $key => $value) {
        if (!is_string($key)) continue;
        $content[$key] = is_string($value) ? trim($value) : $value;
    }
}

if (!empty($body['collections']) && is_array($body['collections'])) {
    foreach ($body['collections'] as $key => $items) {
        if (!is_string($key) || !is_array($items)) continue;
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) continue;
            $cleanItem = [];
            foreach ($item as $fk => $fv) {
                if (!is_string($fk)) continue;
                $cleanItem[$fk] = is_string($fv) ? trim($fv) : $fv;
            }
            $clean[] = $cleanItem;
        }
        $content[$key] = $clean;
    }
}

if (neutron_save_content($content)) {
    neutron_log('info', 'Contenido guardado', [
        'fields' => count((array)($body['fields'] ?? [])),
        'collections' => array_keys((array)($body['collections'] ?? [])),
    ]);
    echo json_encode(['ok' => true]);
} else {
    neutron_log('error', 'No se pudo escribir site/content.json (¿permisos?)');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar']);
}
