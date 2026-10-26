<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/render_dom.php';
require_once __DIR__ . '/render_fallback.php';

// Niveles de listas dentro de listas (data-edit-collection dentro de un data-edit-item)
const NEUTRON_MAX_NESTING = 3;

function neutron_render(string $html, array $content, array $options = []): string {
    $html = neutron_include_partials($html);
    if (class_exists('DOMDocument')) {
        return neutron_render_dom($html, $content, $options);
    }
    return neutron_render_fallback($html, $content, $options);
}

function neutron_is_void_element(string $tag): bool {
    static $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    return in_array($tag, $void, true);
}

// Parciales: <div data-include="header"></div> se sustituye por site/partials/header.html.
// Se hace sobre el texto, antes del render, así que los parciales pueden llevar data-edit y otros parciales.
function neutron_include_partials(string $html, int $depth = 0): string {
    if ($depth > 5 || !str_contains($html, 'data-include')) return $html;
    return preg_replace_callback(
        '~<([a-z][a-z0-9-]*)\b[^>]*\bdata-include="([a-z0-9][a-z0-9_-]{0,60})"[^>]*>\s*</\1>~i',
        function (array $m) use ($depth): string {
            $file = NEUTRON_SITE . '/partials/' . $m[2] . '.html';
            if (!is_file($file)) {
                neutron_log('warning', 'Parcial no encontrado: site/partials/{name}.html', ['name' => $m[2]]);
                return '<!-- parcial no encontrado: ' . htmlspecialchars($m[2]) . ' -->';
            }
            return neutron_include_partials((string)file_get_contents($file), $depth + 1);
        },
        $html
    ) ?? $html;
}

// Enlaces que apuntan a la página actual: '' (portada) o 'carta.html'.
function neutron_current_hrefs(string $page): array {
    return $page === '' ? ['', 'index.php'] : [$page . '.html', 'index.php?page=' . $page];
}

// "./carta.html", "/carta.html", "carta.html?admin" → "carta.html"; "./" → ''. Los enlaces con # no cuentan
// (en la portada, "./#contacte" es una sección, no la página).
function neutron_normalize_href(string $href): ?string {
    $href = trim($href);
    if ($href === '' || str_contains($href, '#') || preg_match('~^[a-z][a-z0-9+.-]*:~i', $href)) return null;
    $path = (string)parse_url($href, PHP_URL_PATH);
    $query = (string)parse_url($href, PHP_URL_QUERY);
    $path = preg_replace('~^(\./|/)+~', '', $path);
    if ($path === 'index.php' && preg_match('/(?:^|&)page=([a-z0-9-]+)/', $query, $m)) {
        return 'index.php?page=' . $m[1];
    }
    return $path;
}
