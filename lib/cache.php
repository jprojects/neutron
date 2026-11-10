<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Caché de páginas para los visitantes (config «cache»): la página ya montada en data/cache/<clave>.html, con su
// «huella» del sitio. Se sirve tal cual mientras la huella sea la misma; cualquier cambio (desde el panel, el editor, por
// FTP o una actualización del núcleo) la cambia y la página se vuelve a montar. Además caduca a los cache.ttl segundos
// (lo que cambia solo, como el número de descargas).
//
// Solo GET de visitantes: sin ?admin, sin la cookie del editor y sin más parámetros que ?p= (páginas de un listado).
// Solo respuestas 200 (las 404 no: los robots piden URLs inventadas y la llenarían). Cabecera X-Neutron-Cache: HIT | MISS.

const NEUTRON_CACHE_MAX_FILES = 2000;

function neutron_cache_dir(): string {
    return NEUTRON_DATA . '/cache';
}

function neutron_cache_enabled(): bool {
    $c = (array)(neutron_config()['cache'] ?? []);
    return !empty($c['enabled']);
}

/** ¿Se puede servir o guardar esta petición en la caché? */
function neutron_cache_applies(): bool {
    if (!neutron_cache_enabled() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return false;
    if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) return false;
    foreach (array_keys($_GET) as $k) {
        if (!in_array($k, ['p', 'page'], true)) return false;     // page: la que pone el .htaccess (carta.html → ?page=carta)
    }
    return true;
}

function neutron_cache_key(): string {
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    return sha1(($https ? 'https' : 'http') . '://' . $host . neutron_request_path() . '?p=' . (int)($_GET['p'] ?? 1));
}

// Huella del sitio: la fecha de modificación más reciente de lo que monta una página (plantillas, parciales, contenidos,
// elementos de las colecciones, sus carpetas, la configuración y la versión del núcleo). Unos cientos de stat(): mucho
// menos que montar la página.
function neutron_cache_fingerprint(): string {
    $latest = 0;
    $paths = array_merge(
        [NEUTRON_SITE, NEUTRON_SITE . '/pages', NEUTRON_SITE . '/partials', NEUTRON_ROOT . '/VERSION', NEUTRON_ROOT . '/config.local.php'],
        glob(NEUTRON_SITE . '/*.{json,html}', GLOB_BRACE) ?: [],
        glob(NEUTRON_SITE . '/pages/*.html') ?: [],
        glob(NEUTRON_SITE . '/partials/*.html') ?: [],
        glob(NEUTRON_SITE . '/*', GLOB_ONLYDIR) ?: [],
        glob(NEUTRON_SITE . '/*/*.{json,html}', GLOB_BRACE) ?: [],
    );
    foreach ($paths as $p) {
        $t = @filemtime($p);
        if ($t > $latest) $latest = $t;
    }
    return $latest . '-' . count($paths);
}

/** Sirve la página de la caché si está y vale (y termina); si no, vuelve para montarla. */
function neutron_cache_serve(): void {
    if (!neutron_cache_applies()) return;
    $file = neutron_cache_dir() . '/' . neutron_cache_key() . '.html';
    $fp = @fopen($file, 'r');
    if (!$fp) { header('X-Neutron-Cache: MISS'); return; }
    $meta = json_decode((string)fgets($fp), true);
    $ttl = max(0, (int)(neutron_config()['cache']['ttl'] ?? 3600));
    if (!is_array($meta) || ($meta['fp'] ?? '') !== neutron_cache_fingerprint() || ($ttl > 0 && time() - (int)($meta['t'] ?? 0) > $ttl)) {
        fclose($fp);
        header('X-Neutron-Cache: MISS');
        return;
    }
    header('Content-Type: ' . ($meta['ct'] ?? 'text/html; charset=utf-8'));
    header('X-Neutron-Cache: HIT');
    fpassthru($fp);
    fclose($fp);
    require_once __DIR__ . '/stats.php';
    neutron_stats_hit();                                       // también cuenta como visita
    exit;
}

/** Guarda la página recién montada (solo si es un 200 de visitante). */
function neutron_cache_store(string $html): void {
    if (!neutron_cache_applies() || http_response_code() !== 200) return;
    $dir = neutron_cache_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $files = glob($dir . '/*.html') ?: [];
    if (count($files) >= NEUTRON_CACHE_MAX_FILES) {
        foreach ($files as $f) @unlink($f);                      // demasiadas: se empieza de cero
    }
    $meta = json_encode(['fp' => neutron_cache_fingerprint(), 't' => time(), 'ct' => 'text/html; charset=utf-8']);
    $tmp = $dir . '/' . bin2hex(random_bytes(6)) . '.tmp';
    if (@file_put_contents($tmp, $meta . "\n" . $html) !== false) {
        @rename($tmp, $dir . '/' . neutron_cache_key() . '.html');
    } else {
        @unlink($tmp);
    }
}

/** Vacía la caché (no hace falta para los cambios del sitio: la huella ya los detecta). */
function neutron_cache_clear(): int {
    $n = 0;
    foreach (glob(neutron_cache_dir() . '/*.html') ?: [] as $f) { if (@unlink($f)) $n++; }
    return $n;
}
