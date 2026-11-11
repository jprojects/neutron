<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Lo que leen buscadores, redes sociales y asistentes de IA, sin preparar nada en cada web:
//
// - En el <head> de cada página (los dos motores, si la plantilla no lo trae ya): URL canónica, Open Graph (título,
//   descripción, imagen, URL, nombre de la web, idioma) y Twitter. La imagen para compartir es site/og.jpg (o .png,
//   .webp) si existe; si no, la de la ficha, la primera de <main> o el logo de la cabecera.
// - Datos estructurados (JSON-LD, schema.org): en la portada, la organización o el negocio (de site/legal.json, con
//   site/business.json para el tipo, el horario, la ubicación…) y el sitio (WebSite); en las demás páginas, la ruta
//   (BreadcrumbList); en las fichas de una colección, además, el elemento (Article o el «schema» de _collection.json).
// - /robots.txt (o site/robots.txt) y /llms.txt (o site/llms.txt): resumen de la web en Markdown para los asistentes.
//
//   site/business.json  {"type": "HairSalon", "openingHours": ["Mo-Fr 09:00-19:00"], "priceRange": "€€",
//                        "geo": {"latitude": 41.9, "longitude": 2.25}, …}   (todo menos «type» va tal cual al JSON-LD)

require_once __DIR__ . '/posts.php';

/** Nombre de la web: config «name» (Cosmo), el nombre comercial de legal.json, o lo de detrás de « · » del <title> */
function neutron_site_name(): string {
    $name = trim((string)(neutron_config()['name'] ?? ''));
    if ($name !== '') return $name;
    $legal = neutron_meta_legal();
    if (($legal['trade_name'] ?? '') !== '') return $legal['trade_name'];
    $html = (string)@file_get_contents(NEUTRON_SITE . '/template.html', false, null, 0, 8192);
    if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
        $parts = preg_split('/\s+[·|–—-]\s+/u', trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $title = trim((string)end($parts));
        if ($title !== '') return $title;
    }
    return (string)($_SERVER['HTTP_HOST'] ?? 'Web');
}

/** Datos del titular (site/legal.json), sin los de ejemplo que deja tools/legal.py hasta tener los de verdad */
function neutron_meta_legal(): array {
    static $legal = null;
    if ($legal !== null) return $legal;
    $data = json_decode((string)@file_get_contents(NEUTRON_SITE . '/legal.json'), true);
    $legal = [];
    foreach (is_array($data) ? $data : [] as $k => $v) {
        if (!is_string($v) || trim($v) === '' || neutron_meta_placeholder($v)) continue;
        $legal[$k] = trim($v);
    }
    return $legal;
}

function neutron_meta_placeholder(string $v): bool {
    return (bool)preg_match('/exemple\.|example\.|^[0\s.+-]+$|^(carrer|calle), n[úu]mero/iu', trim($v));
}

/** Código de idioma de Open Graph (ca → ca_ES) */
function neutron_meta_locale(string $lang): string {
    $lang = strtolower(substr($lang, 0, 2));
    $map = ['ca' => 'ca_ES', 'es' => 'es_ES', 'en' => 'en_GB', 'fr' => 'fr_FR', 'de' => 'de_DE', 'it' => 'it_IT', 'pt' => 'pt_PT', 'eu' => 'eu_ES', 'gl' => 'gl_ES'];
    return $map[$lang] ?? $lang;
}

/** URL absoluta de un recurso de la web (site/assets/…) */
function neutron_meta_abs(string $src): string {
    if ($src === '' || str_starts_with($src, 'data:')) return '';
    if (preg_match('~^https?://~i', $src)) return $src;
    if (str_starts_with($src, '//')) return 'https:' . $src;
    return neutron_site_url() . '/' . ltrim(preg_replace('~^\./~', '', $src), '/');
}

/** ¿Sirve para compartir? (no los SVG, que las redes no enseñan, ni las incrustadas) */
function neutron_meta_image_ok(string $src): bool {
    return $src !== '' && !str_starts_with($src, 'data:') && !preg_match('/\.svg(\?|$)/i', $src);
}

/** La imagen para compartir de toda la web, si la hay: site/og.jpg, .png o .webp */
function neutron_meta_site_image(): string {
    foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
        if (is_file(NEUTRON_SITE . '/og.' . $ext)) return 'site/og.' . $ext;
    }
    return '';
}

/**
 * Lo que necesita el <head> de esta página, preparado en index.php: url (canónica), home, lang, crumbs
 * ([[nombre, url]…] desde la portada), post y collection (en una ficha), content (los textos, para las redes).
 */
function neutron_meta_context(array $ctx): array {
    return $ctx + ['url' => '', 'home' => false, 'lang' => '', 'crumbs' => [], 'post' => null, 'collection' => null, 'content' => []];
}

/**
 * Las etiquetas que faltan y el JSON-LD. $found (de la página ya montada): title, description, image (la primera de
 * <main>), logo (la de la cabecera) y have (las etiquetas que ya están: "meta property og:title" => true…).
 * @return array{tags:list<array{0:string,1:string,2:string,3:string}>,jsonld:?string}
 */
function neutron_meta_head(array $meta, array $found): array {
    $title = trim((string)($found['title'] ?? ''));
    $desc = trim((string)($found['description'] ?? ''));
    $have = (array)($found['have'] ?? []);
    $image = neutron_meta_abs(neutron_meta_site_image() ?: (string)($found['image'] ?? '') ?: (string)($found['logo'] ?? ''));
    $siteName = neutron_site_name();
    $url = (string)$meta['url'];
    $tags = [
        ['link', 'rel', 'canonical', $url],
        ['meta', 'property', 'og:type', 'website'],
        ['meta', 'property', 'og:title', $title],
        ['meta', 'property', 'og:description', $desc],
        ['meta', 'property', 'og:url', $url],
        ['meta', 'property', 'og:image', $image],
        ['meta', 'property', 'og:site_name', $siteName],
        ['meta', 'property', 'og:locale', $meta['lang'] !== '' ? neutron_meta_locale((string)$meta['lang']) : ''],
        ['meta', 'name', 'twitter:card', ($image !== '' || isset($have['meta property og:image'])) ? 'summary_large_image' : 'summary'],
    ];
    $tags = array_values(array_filter($tags, fn($t) => $t[3] !== '' && !isset($have[$t[0] . ' ' . $t[1] . ' ' . $t[2]])));
    return ['tags' => $tags, 'jsonld' => isset($have['jsonld']) ? null : neutron_meta_jsonld($meta, $title, $desc, $image, (string)($found['logo'] ?? ''))];
}

function neutron_meta_jsonld(array $meta, string $title, string $desc, string $image, string $logo): ?string {
    $site = neutron_site_url() . '/';
    $graph = [];
    $lang = (string)$meta['lang'];
    if ($meta['home']) {
        $graph[] = neutron_meta_organization($meta, $desc, $image, $logo);
        $graph[] = array_filter(['@type' => 'WebSite', '@id' => $site . '#website', 'url' => $meta['url'], 'name' => neutron_site_name(),
            'description' => $desc, 'inLanguage' => $lang, 'publisher' => ['@id' => $site . '#organization']]);
    }
    $post = $meta['post'];
    if (is_array($post)) {
        $schema = neutron_collection_schema((string)$meta['collection']);
        $type = (string)($schema['schema'] ?? '') ?: (($post['date'] ?? '') !== '' ? 'Article' : 'CreativeWork');
        $node = ['@type' => $type, 'url' => $meta['url'], 'name' => (string)($post['title'] ?? ''), 'description' => $desc, 'image' => $image, 'inLanguage' => $lang];
        if (in_array($type, ['Article', 'BlogPosting', 'NewsArticle'], true)) {
            $node['headline'] = $node['name'];
            $node['datePublished'] = (string)($post['date'] ?? '');
            $node['dateModified'] = substr((string)($post['updated'] ?? ''), 0, 10) ?: $node['datePublished'];
            $node['publisher'] = ['@type' => 'Organization', 'name' => neutron_site_name()];
        }
        if ($type === 'Event' && ($post['date'] ?? '') !== '') $node['startDate'] = (string)$post['date'];
        $graph[] = array_filter($node, fn($v) => $v !== '' && $v !== null);
    }
    if (count($meta['crumbs']) > 1) {
        $items = [];
        foreach (array_values($meta['crumbs']) as $i => [$name, $u]) {
            $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => $u];
        }
        $graph[] = ['@type' => 'BreadcrumbList', 'itemListElement' => $items];
    }
    if (!$graph) return null;
    return json_encode(['@context' => 'https://schema.org', '@graph' => $graph], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
}

// El titular de la web: Organization, o LocalBusiness (o el tipo de site/business.json) si tiene dirección
function neutron_meta_organization(array $meta, string $desc, string $image, string $logo): array {
    $legal = neutron_meta_legal();
    $business = json_decode((string)@file_get_contents(NEUTRON_SITE . '/business.json'), true);
    $business = is_array($business) ? $business : [];
    $type = is_string($business['type'] ?? null) && preg_match('/^[A-Za-z]+$/', $business['type']) ? $business['type']
        : (isset($legal['address']) ? 'LocalBusiness' : 'Organization');
    $social = [];
    foreach ((array)$meta['content'] as $k => $v) {
        if (str_starts_with((string)$k, 'social_') && is_string($v) && preg_match('~^https?://~i', $v)) $social[] = $v;
    }
    $site = neutron_site_url() . '/';
    $node = [
        '@type' => $type,
        '@id' => $site . '#organization',
        'name' => $legal['trade_name'] ?? $legal['name'] ?? neutron_site_name(),
        'legalName' => isset($legal['trade_name'], $legal['name']) && $legal['trade_name'] !== $legal['name'] ? $legal['name'] : '',
        'url' => $site,
        'description' => $desc,
        'logo' => neutron_meta_abs($logo),
        'image' => $image,
        'telephone' => $legal['phone'] ?? '',
        'email' => $legal['email'] ?? '',
        'address' => $legal['address'] ?? '',
        'sameAs' => $social,
    ];
    unset($business['type']);
    $node = $business + $node;
    $node['@type'] = $type;
    return array_filter($node, fn($v) => $v !== '' && $v !== [] && $v !== null);
}

// --- /robots.txt y /llms.txt ---

function neutron_robots(): void {
    header('Content-Type: text/plain; charset=utf-8');
    if (is_file(NEUTRON_SITE . '/robots.txt')) { readfile(NEUTRON_SITE . '/robots.txt'); return; }
    $base = neutron_base_path();
    echo "User-agent: *\nAllow: " . $base . "/\nDisallow: " . $base . "/admin/\n\nSitemap: " . neutron_site_url() . "/sitemap.xml\n";
}

// El título de una página en el idioma $lang (su <title>, editable o no), sin lo de detrás de « · »
function neutron_meta_page_title(string $file, ?string $lang, string $default): string {
    $html = (string)@file_get_contents($file, false, null, 0, 16384);
    if (!preg_match('~<title\b([^>]*)>(.*?)</title>~is', $html, $m)) return $default;
    $text = html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (preg_match('~\bdata-edit="([^"]+)"~', $m[1], $k)) {
        $content = neutron_meta_content($lang);
        if (is_string($content[$k[1]] ?? null)) $text = $content[$k[1]];
    }
    return trim((string)preg_split('/\s+[·|–—-]\s+/u', trim($text))[0]) ?: $default;
}

// La descripción de una página (su <meta name="description">, editable o no) en el idioma $lang
function neutron_meta_page_description(string $file, ?string $lang): string {
    $html = (string)@file_get_contents($file, false, null, 0, 16384);
    if (!preg_match('~<meta\b[^>]*\bname="description"[^>]*>~i', $html, $m)) return '';
    $tag = $m[0];
    $text = preg_match('~\bcontent="([^"]*)"~i', $tag, $c) ? html_entity_decode($c[1], ENT_QUOTES | ENT_HTML5, 'UTF-8') : '';
    if (preg_match('~\bdata-edit-content="([^"]+)"~i', $tag, $k)) {
        $content = neutron_meta_content($lang);
        if (is_string($content[$k[1]] ?? null)) $text = $content[$k[1]];
    }
    return trim((string)preg_replace('/\s+/u', ' ', $text));
}

// Los textos de la web en un idioma (lo que falte, del principal), como al montar la página
function neutron_meta_content(?string $lang): array {
    static $cache = [];
    $key = $lang ?? '';
    if (isset($cache[$key])) return $cache[$key];
    require_once __DIR__ . '/content.php';
    $langs = neutron_languages();
    $data = neutron_read_content(neutron_content_path());
    if ($langs !== null) {
        $data = array_merge($data, neutron_read_content(neutron_content_path($langs['default'])));
        if ($lang !== null && $lang !== $langs['default']) $data = array_merge($data, neutron_read_content(neutron_content_path($lang)));
    }
    return $cache[$key] = $data;
}

function neutron_llms(): void {
    header('Content-Type: text/plain; charset=utf-8');
    if (is_file(NEUTRON_SITE . '/llms.txt')) { readfile(NEUTRON_SITE . '/llms.txt'); return; }
    $langs = neutron_languages();
    $lang = $langs['default'] ?? (preg_match('~<html[^>]*\blang="([A-Za-z]{2})~', (string)@file_get_contents(NEUTRON_SITE . '/template.html', false, null, 0, 4096), $m) ? strtolower($m[1]) : 'ca');
    if ($langs !== null) neutron_lang($lang);
    $words = [
        'ca' => ['Pàgines', 'Informació legal', 'Contacte', 'Telèfon', 'Correu', 'Adreça', 'Altres idiomes'],
        'es' => ['Páginas', 'Información legal', 'Contacto', 'Teléfono', 'Correo', 'Dirección', 'Otros idiomas'],
        'fr' => ['Pages', 'Informations légales', 'Contact', 'Téléphone', 'Courriel', 'Adresse', 'Autres langues'],
    ][$lang] ?? ['Pages', 'Legal information', 'Contact', 'Phone', 'Email', 'Address', 'Other languages'];
    $site = neutron_site_url() . '/' . neutron_lang_prefix();
    $line = fn(string $title, string $url, string $desc) => '- [' . str_replace(['[', ']'], ['(', ')'], $title) . '](' . $url . ')' . ($desc !== '' ? ': ' . $desc : '');

    $out = '# ' . neutron_site_name() . "\n\n";
    $home = neutron_meta_page_description(NEUTRON_SITE . '/template.html', $lang);
    if ($home !== '') $out .= '> ' . $home . "\n\n";
    $legal = neutron_meta_legal();
    $contact = array_filter([$words[3] => $legal['phone'] ?? '', $words[4] => $legal['email'] ?? '', $words[5] => $legal['address'] ?? '']);
    if ($contact) {
        $out .= '## ' . $words[2] . "\n\n";
        foreach ($contact as $k => $v) $out .= '- ' . $k . ': ' . $v . "\n";
        $out .= "\n";
    }

    $pages = $legalPages = [];
    foreach (neutron_site_pages() as $p) {
        $slug = $p['href'] === './' ? '' : preg_replace('~(\.html|/)$~', '', $p['href']);
        $file = $slug === '' ? NEUTRON_SITE . '/template.html' : NEUTRON_SITE . '/pages/' . $slug . '.html';
        $row = $line(neutron_meta_page_title($file, $lang, $p['title']), $site . ($p['href'] === './' ? '' : $p['href']),
            neutron_meta_page_description($file, $lang));
        if (preg_match('/legal|privac|cookie|aviso|avis-|condicion|condicions|mentions|terms/i', $slug)) $legalPages[] = $row; else $pages[] = $row;
    }
    $out .= '## ' . $words[0] . "\n\n" . implode("\n", $pages) . "\n\n";

    foreach (neutron_collections() as $c) {
        $schema = neutron_collection_schema($c);
        $posts = array_slice(neutron_posts_list($c), 0, 50);
        if (!$posts) continue;
        $rows = [];
        $fileField = null;
        foreach (neutron_collection_fields($c) as $name => $f) if ($f['type'] === 'file') { $fileField = $name; break; }
        foreach ($posts as $post) {
            $desc = '';
            foreach (neutron_post_meta_tags($post) as $t) if ($t[2] === 'description') $desc = $t[3];
            $date = ($post['date'] ?? '') !== '' ? ' (' . $post['date'] . ')' : '';
            if ($schema['page']) {
                $rows[] = $line((string)$post['title'] . $date, $site . neutron_post_href($post), $desc);
            } elseif ($fileField !== null && is_string($post[$fileField] ?? null) && $post[$fileField] !== '') {
                $rows[] = $line((string)$post['title'] . $date, neutron_site_url() . '/download.php?c=' . rawurlencode($c) . '&i=' . rawurlencode($post['slug']) . '&f=' . $fileField, $desc);
            } else {
                $rows[] = '- ' . $post['title'] . $date . ($desc !== '' ? ': ' . $desc : '');
            }
        }
        $out .= '## ' . $schema['label'] . "\n\n" . implode("\n", $rows) . "\n";
        if ($schema['page']) {                                     // y sus categorías, con su página
            foreach (neutron_categories_view($c, []) as $cat) $out .= $line($schema['label'] . ' · ' . $cat['title'], $site . $cat['href'], $cat['description']) . "\n";
        }
        $out .= "\n";
    }

    if ($langs !== null && count($langs['languages']) > 1) {
        $out .= '## ' . $words[6] . "\n\n";
        foreach ($langs['languages'] as $code => $name) {
            if ($code !== $lang) $out .= '- [' . $name . '](' . neutron_site_url() . '/' . $code . '/)' . "\n";
        }
        $out .= "\n";
    }
    if ($legalPages) $out .= "## Optional\n\n" . implode("\n", $legalPages) . "\n";
    echo rtrim($out) . "\n";
}
