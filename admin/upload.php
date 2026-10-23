<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

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

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Archivo inválido']);
    exit;
}

$file = $_FILES['image'];
$maxBytes = 5 * 1024 * 1024;
if ($file['size'] > $maxBytes) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'La imagen supera 5MB']);
    exit;
}

// SVG queda fuera a propósito: puede llevar <script> embebido (riesgo XSS).
$allowed = [
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp',
];

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

if (!isset($allowed[$mime])) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Tipo de imagen no permitido']);
    exit;
}

$ext = $allowed[$mime];
$name = bin2hex(random_bytes(10)) . '.' . $ext;
$dir = NEUTRON_SITE . '/uploads';
if (!is_dir($dir)) @mkdir($dir, 0775, true);
$dest = $dir . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    neutron_log('error', 'No se pudo guardar la imagen subida en site/uploads (¿permisos?)');
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'No se pudo guardar el archivo']);
    exit;
}

neutron_log('info', 'Imagen subida: {name}', ['name' => $name, 'bytes' => $file['size']]);
echo json_encode(['ok' => true, 'url' => 'site/uploads/' . $name]);
