<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Buscador de la web (opcional): solo si una plantilla o un parcial tiene un formulario con data-search. El índice es
// /search.json (en una web de varios idiomas, /ca/search.json…): los textos de cada página (sus campos editables,
// lib/schema.php) y los elementos publicados de las colecciones; la búsqueda la hace assets/search.js en el navegador.
//
// site/search.json (opcional): {"pages": true, "collections": ["noticies", "documentacio"] | "all",
//                               "exclude": ["avis-legal", "politica-de-privacitat", "politica-de-cookies"]}
// Por defecto: todas las páginas menos las legales y la 404, y todas las colecciones. Cada resultado lleva a la página,
// a la ficha del elemento, a la descarga de su documento, a su vídeo o enlace, o al listado donde sale.

require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/posts.php';
require_once __DIR__ . '/content.php';
require_once __DIR__ . '/cache.php';

const NEUTRON_SEARCH_TEXT_MAX = 700;

function neutron_search_config(): array {
    $file = NEUTRON_SITE . '/search.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $data = is_array($data) ? $data : [];
    return [
        'pages' => ($data['pages'] ?? true) !== false,
        'collections' => $data['collections'] ?? 'all',
        'exclude' => (array)($data['exclude'] ?? ['404', 'avis-legal', 'aviso-legal', 'politica-de-privacitat', 'politica-de-privacidad', 'politica-de-cookies']),
    ];
}

function neutron_search_plain(mixed $v): string {
    if (!is_string($v)) return '';
    return trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(str_replace('<', ' <', $v)), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
}

/** @return list<array{t:string,u:string,x:string,k:string}> título, dirección (relativa a la instalación), texto, tipo */
function neutron_search_index(): array {
    $cfg = neutron_search_config();
    $prefix = neutron_lang_prefix();
    $content = neutron_load_content();
    $out = [];
    $schema = neutron_schema();
    $listing = [];                                                   // colección → página donde está su listado
    foreach ($schema['pages'] as $p) {
        $file = $p['slug'] === '' ? NEUTRON_SITE . '/template.html' : NEUTRON_SITE . '/pages/' . $p['slug'] . '.html';
        if (preg_match_all('~data-posts="([a-z0-9-]+)"~', (string)@file_get_contents($file), $m)) {
            foreach ($m[1] as $c) $listing[$c] ??= $prefix . ($p['href'] === './' ? '' : $p['href']);
        }
        if (!$cfg['pages'] || in_array($p['slug'], $cfg['exclude'], true) || $p['href'] === '404.html') continue;
        $texts = [];
        foreach ($p['sections'] as $s) {
            foreach ($s['fields'] as $f) {
                if (!in_array($f['type'], ['text', 'html'], true)) continue;
                $v = neutron_search_plain(array_key_exists($f['key'], $content) ? $content[$f['key']] : $f['default']);
                if ($v !== '' && !in_array($v, $texts, true)) $texts[] = $v;
            }
        }
        $title = $p['title'];
        $out[] = ['t' => $title, 'u' => $prefix . ($p['href'] === './' ? '' : $p['href']), 'x' => mb_substr(implode(' · ', $texts), 0, NEUTRON_SEARCH_TEXT_MAX), 'k' => 'page'];
    }
    $cols = $cfg['collections'] === 'all' ? neutron_collections() : array_values(array_intersect((array)$cfg['collections'], neutron_collections()));
    foreach ($cols as $c) {
        $fields = neutron_collection_fields($c);
        $label = neutron_collection_schema($c)['label'];
        foreach (neutron_posts_list($c) as $post) {
            $texts = [];
            $url = neutron_collection_has_page($c) ? $prefix . neutron_post_href($post) : '';
            foreach ($fields as $name => $f) {
                if ($name === 'title') continue;
                if (in_array($f['type'], ['text', 'textarea', 'html', 'category'], true)) {
                    $v = $f['type'] === 'category' ? neutron_post_text($post, $name, (string)(neutron_lang() ?? 'ca')) : neutron_search_plain($post[$name] ?? '');
                    if ($v !== '') $texts[] = $v;
                }
                if ($url === '' && in_array($f['type'], ['file', 'video', 'url'], true)) $url = neutron_post_href_of($post, $name);
            }
            $url = $url !== '' ? $url : ($listing[$c] ?? '');
            if ($url === '') continue;
            $out[] = ['t' => (string)$post['title'], 'u' => $url, 'x' => mb_substr(implode(' · ', $texts), 0, NEUTRON_SEARCH_TEXT_MAX), 'k' => $label];
        }
    }
    return $out;
}

/** Responde /search.json (con caché: se rehace cuando cambia el sitio, como las páginas) */
function neutron_search_serve(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    $file = neutron_cache_dir() . '/search-' . (neutron_lang() ?? 'web') . '.json';
    $fp = neutron_cache_fingerprint();
    $meta = is_file($file) ? json_decode((string)fgets($h = fopen($file, 'r')), true) : null;
    if (is_array($meta) && ($meta['fp'] ?? '') === $fp) {
        fpassthru($h);
        fclose($h);
        return;
    }
    if (isset($h) && $h) fclose($h);
    $json = json_encode(neutron_search_index(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (neutron_cache_enabled() && (is_dir(neutron_cache_dir()) || @mkdir(neutron_cache_dir(), 0775, true))) {
        @file_put_contents($file, json_encode(['fp' => $fp]) . "\n" . $json, LOCK_EX);
    }
    echo $json;
}

/** Configuración para assets/search.js: dónde está el índice y los textos (los del formulario tienen preferencia) */
function neutron_search_client_config(): array {
    $lang = (string)(neutron_lang() ?? 'ca');
    $texts = [
        'ca' => ['empty' => 'No hi ha cap resultat per «{q}».', 'page' => 'Pàgina', 'more' => '{n} resultats'],
        'es' => ['empty' => 'No hay resultados para «{q}».', 'page' => 'Página', 'more' => '{n} resultados'],
        'en' => ['empty' => 'No results for «{q}».', 'page' => 'Page', 'more' => '{n} results'],
        'fr' => ['empty' => 'Aucun résultat pour «{q}».', 'page' => 'Page', 'more' => '{n} résultats'],
    ];
    return ['index' => neutron_base_path() . '/' . neutron_lang_prefix() . 'search.json', 'root' => neutron_base_path() . '/', 'texts' => $texts[substr($lang, 0, 2)] ?? $texts['es']];
}
