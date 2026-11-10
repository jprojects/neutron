<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/posts.php';

// RSS de una colección (/blog/feed.xml) y mapa del sitio (/sitemap.xml) para los buscadores.

function neutron_xml(string $s): string {
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// Título de una página del sitio (lo de antes de " · ")
function neutron_page_title(string $file, string $default): string {
    $html = is_file($file) ? (string)file_get_contents($file, false, null, 0, 8192) : '';
    if (!preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) return $default;
    $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return trim((string)preg_split('/\s+[·|–—-]\s+/u', $title)[0]) ?: $default;
}

function neutron_feed(string $collection): void {
    $site = neutron_site_url() . (neutron_lang() !== null ? '/' . neutron_lang() : '');
    $title = neutron_page_title(NEUTRON_SITE . '/pages/' . $collection . '.html', $collection);
    $siteName = neutron_page_title(NEUTRON_SITE . '/template.html', (string)($_SERVER['HTTP_HOST'] ?? ''));
    header('Content-Type: application/rss+xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom"><channel>'
        . '<title>' . neutron_xml($title . ' · ' . $siteName) . '</title>'
        . '<link>' . neutron_xml($site . '/' . $collection . '/') . '</link>'
        . '<atom:link href="' . neutron_xml($site . '/' . $collection . '/feed.xml') . '" rel="self" type="application/rss+xml"/>'
        . '<description>' . neutron_xml($title) . '</description>'
        . (neutron_lang() !== null ? '<language>' . neutron_xml(neutron_lang()) . '</language>' : '');
    foreach (array_slice(neutron_posts_list($collection), 0, 20) as $post) {
        $url = $site . '/' . neutron_post_href($post);
        $time = strtotime($post['date'] ?: $post['created']) ?: time();
        echo '<item><title>' . neutron_xml($post['title']) . '</title><link>' . neutron_xml($url) . '</link>'
            . '<guid isPermaLink="true">' . neutron_xml($url) . '</guid><pubDate>' . date(DATE_RSS, $time) . '</pubDate>'
            . '<description>' . neutron_xml($post['summary']) . '</description></item>';
    }
    echo '</channel></rss>';
}

function neutron_sitemap(): void {
    $urls = [];
    // Web de varios idiomas: cada página en cada idioma (/ca/…, /es/…)
    foreach (array_keys(neutron_languages()['languages'] ?? ['' => '']) as $code) {
        $site = neutron_site_url() . ($code !== '' ? '/' . $code : '');
        if ($code !== '') neutron_lang($code);
        $urls[] = [$site . '/', @filemtime(NEUTRON_SITE . '/content' . ($code !== '' ? '.' . $code : '') . '.json')];
        foreach (glob(NEUTRON_SITE . '/pages/*.html') ?: [] as $file) {
            $slug = basename($file, '.html');
            if ($slug === '404' || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)) continue;
            $urls[] = [$site . '/' . (neutron_collection_has_page($slug) ? $slug . '/' : $slug . '.html'), filemtime($file)];
        }
        foreach (array_filter(neutron_collections(), 'neutron_collection_has_page') as $collection) {
            foreach (neutron_posts_list($collection) as $post) {
                $urls[] = [$site . '/' . neutron_post_href($post), strtotime($post['updated'] ?: $post['date']) ?: null];
            }
        }
    }
    header('Content-Type: application/xml; charset=utf-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach ($urls as [$loc, $time]) {
        echo '<url><loc>' . neutron_xml($loc) . '</loc>' . ($time ? '<lastmod>' . date('Y-m-d', $time) . '</lastmod>' : '') . '</url>';
    }
    echo '</urlset>';
}
