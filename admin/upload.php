<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

// Lado máximo de las imágenes subidas (el mismo que MAX_SIDE en assets/editor.js)
const NEUTRON_UPLOAD_MAX_SIDE = 2000;

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

// Documentos (campo de tipo "file" de una colección): mismo punto de entrada con el campo «file»
if (!empty($_FILES['file'])) {
    neutron_upload_document($_FILES['file']);
    exit;
}

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

// Red de seguridad: el editor ya reduce y pasa a WebP antes de subir; si llega una grande (navegador antiguo), se hace aquí
$optimized = neutron_upload_optimize($dest, $mime);
if ($optimized !== null) {
    @unlink($dest);
    $dest = $optimized;
    $name = basename($optimized);
}

neutron_log('info', 'Imagen subida: {name}', ['name' => $name, 'bytes' => filesize($dest), 'original' => $file['size']]);
echo json_encode(['ok' => true, 'url' => 'site/uploads/' . $name]);

/**
 * Reduce a NEUTRON_UPLOAD_MAX_SIDE px de lado y guarda en WebP (o JPG sin soporte WebP) una imagen grande.
 * Devuelve la ruta nueva, o null si no hace falta o no se puede (sin GD, sin memoria, GIF, orientación EXIF sin ext-exif).
 */
function neutron_upload_optimize(string $path, string $mime): ?string {
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true) || !extension_loaded('gd')) return null;
    $info = @getimagesize($path);
    if (!$info) return null;
    [$w, $h] = $info;
    $max = NEUTRON_UPLOAD_MAX_SIDE;
    if (max($w, $h) <= $max && filesize($path) <= 1024 * 1024) return null;

    // Memoria: la imagen descomprimida (4 bytes por píxel) más la reducida, con margen
    $limit = neutron_upload_bytes((string)ini_get('memory_limit'));
    if ($limit > 0 && ($w * $h * 4 * 1.8) + memory_get_usage() > $limit) return null;

    $orientation = 1;
    if ($mime === 'image/jpeg') {
        $exif = function_exists('exif_read_data') ? @exif_read_data($path) : false;
        $orientation = (int)($exif['Orientation'] ?? 1);
        // Sin ext-exif no se sabe si la foto va girada: mejor dejarla como está (el navegador la gira bien)
        if (!function_exists('exif_read_data') && neutron_upload_has_exif($path)) return null;
    }
    $src = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($path),
        'image/png' => @imagecreatefrompng($path),
        'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
    };
    if (!$src) return null;
    $rot = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
    if ($rot) { $r = imagerotate($src, $rot, 0); imagedestroy($src); $src = $r; [$w, $h] = [imagesx($src), imagesy($src)]; }

    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $webp = function_exists('imagewebp');
    $out = dirname($path) . '/' . bin2hex(random_bytes(10)) . ($webp ? '.webp' : '.jpg');
    $ok = $webp ? imagewebp($dst, $out, 82) : imagejpeg($dst, $out, 85);
    imagedestroy($dst);
    if (!$ok || !is_file($out) || filesize($out) >= filesize($path)) { @unlink($out); return null; }
    return $out;
}

function neutron_upload_bytes(string $v): int {
    $v = trim($v);
    if ($v === '' || $v === '-1') return -1;
    $n = (int)$v;
    return match (strtolower(substr($v, -1))) { 'g' => $n << 30, 'm' => $n << 20, 'k' => $n << 10, default => $n };
}

// ¿Lleva el JPEG un bloque EXIF (APP1 «Exif»)? Basta mirar el principio del fichero
function neutron_upload_has_exif(string $path): bool {
    $head = (string)@file_get_contents($path, false, null, 0, 65536);
    return str_contains($head, "Exif\0\0");
}

/**
 * Sube un documento a site/uploads/docs/ con un nombre legible («festa-del-pi-1970-3fa2.pdf»). El tipo sale del
 * contenido (finfo), no del nombre: PDF, Office, LibreOffice, texto, CSV y ZIP. Tamaño máximo: uploads.max_doc_mb.
 */
function neutron_upload_document(array $file): void {
    $fail = function (string $msg, int $code = 400): void {
        http_response_code($code);
        echo json_encode(['ok' => false, 'error' => $msg]);
    };
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE) {
        $fail('El fichero es más grande de lo que deja subir el hosting (' . ini_get('upload_max_filesize') . ')');
        return;
    }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK) { $fail('Archivo inválido'); return; }
    $maxMb = (float)(neutron_config()['uploads']['max_doc_mb'] ?? 20);
    if ($file['size'] > $maxMb * 1024 * 1024) { $fail('El fichero supera ' . $maxMb . ' MB'); return; }

    $byMime = [
        'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/rtf' => 'rtf', 'text/rtf' => 'rtf',
        'application/msword' => 'doc', 'application/vnd.ms-excel' => 'xls', 'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'application/vnd.oasis.opendocument.text' => 'odt', 'application/vnd.oasis.opendocument.spreadsheet' => 'ods',
        'application/vnd.oasis.opendocument.presentation' => 'odp', 'application/zip' => 'zip',
    ];
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $original = (string)($file['name'] ?? 'document');
    $origExt = strtolower((string)pathinfo($original, PATHINFO_EXTENSION));
    $ext = $byMime[$mime] ?? null;
    // Los Office modernos son ZIP por dentro: algunos finfo los dan como application/zip u octet-stream
    if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/x-zip-compressed'], true)
        && in_array($origExt, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp', 'zip'], true)) $ext = $origExt;
    if ($ext === 'txt' && $origExt === 'csv') $ext = 'csv';
    if ($ext === null) { $fail('Tipo de fichero no permitido: PDF, Word, Excel, PowerPoint, LibreOffice, texto, CSV o ZIP'); return; }

    $map = ['à'=>'a','á'=>'a','è'=>'e','é'=>'e','í'=>'i','ï'=>'i','ò'=>'o','ó'=>'o','ú'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','·'=>''];
    $base = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtr(mb_strtolower((string)pathinfo($original, PATHINFO_FILENAME)), $map)), '-');
    $base = substr($base !== '' ? $base : 'document', 0, 60);
    $dir = NEUTRON_SITE . '/uploads/docs';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = $base . '-' . bin2hex(random_bytes(2)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        neutron_log('error', 'No se pudo guardar el documento en site/uploads/docs (¿permisos?)');
        $fail('No se pudo guardar el archivo', 500);
        return;
    }
    neutron_log('info', 'Documento subido: {name}', ['name' => $name, 'bytes' => $file['size']]);
    echo json_encode(['ok' => true, 'url' => 'site/uploads/docs/' . $name, 'name' => $original, 'size' => (int)$file['size']]);
}

