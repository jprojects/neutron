<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Imágenes adaptadas a la pantalla: de cada imagen grande del sitio (site/uploads, site/assets/img) se guardan versiones
// de NEUTRON_IMAGE_WIDTHS px de ancho al lado de la original («foto.webp» → «foto.w640.webp», «foto.w1024.webp»…) y los
// dos motores ponen en cada <img> local un srcset con las que existan, así un móvil no se baja la de 2000 px.
//
// Se hacen al subir una imagen (admin/upload.php) y, para las que ya estaban, en la migración de cada actualización
// (API remota, migrate) o con `php bin/images.php`. Sin GD, no se hace nada (las páginas siguen con la imagen original).

const NEUTRON_IMAGE_WIDTHS = [640, 1024, 1600];
const NEUTRON_IMAGE_DIRS = ['uploads', 'assets/img'];

// «site/assets/img/foto.webp» + 640 → «site/assets/img/foto.w640.webp»
function neutron_image_variant(string $src, int $w): string {
    return preg_replace('/\.(webp|jpe?g|png)$/i', '.w' . $w . '.$1', $src);
}

function neutron_image_is_local(string $src): bool {
    return (bool)preg_match('~^site/(uploads|assets/img)/[^?#"\'<>]+\.(webp|jpe?g|png)$~i', $src)
        && !str_contains($src, '..') && !preg_match('/\.w\d+\.(webp|jpe?g|png)$/i', $src);
}

/** srcset de una imagen local con sus versiones (las que existan), o '' si no tiene */
function neutron_image_srcset(string $src): string {
    static $cache = [];
    if (isset($cache[$src])) return $cache[$src];
    if (!neutron_image_is_local($src) || !is_file(NEUTRON_ROOT . '/' . $src)) return $cache[$src] = '';
    $parts = [];
    foreach (NEUTRON_IMAGE_WIDTHS as $w) {
        $v = neutron_image_variant($src, $w);
        if (is_file(NEUTRON_ROOT . '/' . $v)) $parts[] = $v . ' ' . $w . 'w';
    }
    if (!$parts) return $cache[$src] = '';
    $size = @getimagesize(NEUTRON_ROOT . '/' . $src);
    if ($size) $parts[] = $src . ' ' . $size[0] . 'w';
    return $cache[$src] = implode(', ', $parts);
}

function neutron_image_can_resize(): bool {
    return function_exists('imagecreatetruecolor') && function_exists('imagecopyresampled');
}

/**
 * Las versiones que falten de una imagen (ruta absoluta). Devuelve cuántas ha hecho; 0 si no hace falta, no se puede
 * (sin GD, sin memoria, formato no soportado) o ya estaban.
 */
function neutron_image_make_variants(string $abs): int {
    if (!neutron_image_can_resize() || !is_file($abs) || preg_match('/\.w\d+\.(webp|jpe?g|png)$/i', $abs)) return 0;
    $info = @getimagesize($abs);
    if (!$info) return 0;
    [$width, $height] = $info;
    $todo = array_filter(NEUTRON_IMAGE_WIDTHS, fn($w) => $w < $width * 0.9 && !is_file(neutron_image_variant($abs, $w)));
    if (!$todo) return 0;
    // Memoria: unos 5 bytes por píxel para la original y la reducida
    $limit = ini_get('memory_limit');
    $bytes = $limit === '-1' ? PHP_INT_MAX : (int)$limit * (str_ends_with(strtolower($limit), 'g') ? 1 << 30 : (str_ends_with(strtolower($limit), 'm') ? 1 << 20 : 1));
    if ($width * $height * 5 * 2 > $bytes - memory_get_usage()) return 0;
    $img = match ($info[2]) {
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($abs) : false,
        IMAGETYPE_JPEG => @imagecreatefromjpeg($abs),
        IMAGETYPE_PNG => @imagecreatefrompng($abs),
        default => false,
    };
    if (!$img) return 0;
    $made = 0;
    foreach ($todo as $w) {
        $h = (int)round($height * $w / $width);
        $dst = imagecreatetruecolor($w, $h);
        if ($info[2] !== IMAGETYPE_JPEG) { imagealphablending($dst, false); imagesavealpha($dst, true); }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $w, $h, $width, $height);
        $out = neutron_image_variant($abs, $w);
        $tmp = $out . '.tmp';
        $ok = match ($info[2]) {
            IMAGETYPE_WEBP => imagewebp($dst, $tmp, 80),
            IMAGETYPE_JPEG => imagejpeg($dst, $tmp, 82),
            default => imagepng($dst, $tmp, 8),
        };
        imagedestroy($dst);
        if ($ok && @rename($tmp, $out)) $made++; else @unlink($tmp);
    }
    imagedestroy($img);
    return $made;
}

/**
 * Las versiones que falten de todas las imágenes del sitio, hasta $seconds segundos (la siguiente vez sigue).
 * @return array{made:int,images:int,pending:bool}
 */
function neutron_images_generate_all(int $seconds = 20): array {
    $start = microtime(true);
    $made = $images = 0;
    foreach (NEUTRON_IMAGE_DIRS as $dir) {
        $root = NEUTRON_SITE . '/' . $dir;
        if (!is_dir($root)) continue;
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (!$f->isFile() || !preg_match('/\.(webp|jpe?g|png)$/i', $f->getFilename()) || preg_match('/\.w\d+\./', $f->getFilename())) continue;
            if (microtime(true) - $start > $seconds) return ['made' => $made, 'images' => $images, 'pending' => true];
            $n = neutron_image_make_variants($f->getPathname());
            if ($n) { $made += $n; $images++; }
        }
    }
    return ['made' => $made, 'images' => $images, 'pending' => false];
}
