<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/lib/bootstrap.php';

// API remota para Cosmo: index.php?r=remote&a=acción (y ?r=status). Sin sesión ni cookies.
$route = $_GET['r'] ?? '';
if ($route === 'remote' || $route === 'status') {
    require_once __DIR__ . '/lib/remote/api.php';
    neutron_remote_handle($route === 'status' ? 'status' : (string)($_GET['a'] ?? ''));
    exit;
}

require_once __DIR__ . '/lib/routing.php';

// Iconos que los navegadores piden en la raíz aunque la página diga otra cosa: los de site/
$path = neutron_request_path();
if (in_array($path, NEUTRON_SITE_ICONS, true)) {
    neutron_serve_site_icon($path);
    exit;
}

neutron_block_if_maintenance();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/form.php';
require_once __DIR__ . '/lib/cookies.php';
require_once __DIR__ . '/lib/posts.php';
require_once __DIR__ . '/lib/feeds.php';
require_once __DIR__ . '/lib/panel_lang.php';

// La sesión solo hace falta en el editor: así los visitantes no reciben la cookie de sesión
if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) {
    neutron_start_session();
}
$editing = isset($_GET['admin']) && neutron_is_logged_in();

// Página: la plantilla del sitio o una de site/pages/ (carta.html → index.php?page=carta).
// Colecciones (blog): /blog/ es site/pages/blog.html, /blog/mi-entrada una entrada, /blog/feed.xml su RSS.
// Varios idiomas (site/languages.json): todo eso va detrás del idioma, /ca/…, /es/…; $route es la ruta sin él.
// Cualquier otra ruta (las URLs de la web anterior) va a site/redirects.json o da 404.
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
$route = $path;
$langs = neutron_languages();
$qs = ($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : '';
$hasSlash = str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/');
if ($langs !== null) {
    if (preg_match('~^([a-z]{2}(?:-[a-z]{2})?)(?:/(.*))?$~', $path, $lm) && isset($langs['languages'][$lm[1]])) {
        neutron_lang($lm[1]);
        $route = $lm[2] ?? '';
        if ($route === '' && !$hasSlash) neutron_redirect($lm[1] . '/' . $qs); // /ca → /ca/
        $page = preg_match('~^([a-z0-9][a-z0-9-]{0,60})\.html$~', $route, $pm) ? $pm[1] : '';
    } elseif ($page !== '' && $path === $page . '.html') {
        neutron_redirect($langs['default'] . '/' . $page . '.html' . $qs); // /biomassa.html → /ca/biomassa.html
    }
}
if ($page !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $page)) {
    $page = '-';
}
$collection = null;
$post = null;
$category = null;
// Índice del buscador (lib/search.php): /search.json y, en una web de varios idiomas, /ca/search.json…
if ($page === '' && $route === 'search.json' && ($langs === null || neutron_lang() !== null)) {
    require_once __DIR__ . '/lib/search.php';
    neutron_search_serve();
    exit;
}
if ($page === '' && $route === 'sitemap.xml' && neutron_lang() === null) {
    neutron_sitemap();
    exit;
}
// Contacto para avisar de fallos de seguridad (lib/security.php): el de site/security.txt o security.contact
if ($page === '' && $route === '.well-known/security.txt') {
    neutron_security_txt();
    exit;
}
// Para buscadores y asistentes de IA (lib/meta.php): /robots.txt y /llms.txt (o los de site/, si los hay)
if ($page === '' && in_array($route, ['robots.txt', 'llms.txt'], true) && neutron_lang() === null) {
    require_once __DIR__ . '/lib/meta.php';
    $route === 'robots.txt' ? neutron_robots() : neutron_llms();
    exit;
}
// Página de una categoría: /noticies/categoria/activitats (el listado de la colección, solo con esa categoría)
if ($page === '' && preg_match('~^([a-z0-9][a-z0-9-]*)/([a-z0-9][a-z0-9-]*)/([a-z0-9][a-z0-9-]*)/?$~', $route, $m)
    && neutron_collection_has_page($m[1]) && $m[2] === neutron_collection_category_path($m[1])
    && neutron_collection_category_field($m[1]) !== null && neutron_category_get($m[1], $m[3]) !== null) {
    if ($langs !== null && neutron_lang() === null) neutron_redirect($langs['default'] . '/' . $route . $qs);
    if (str_ends_with($route, '/')) neutron_redirect(neutron_lang_prefix() . rtrim($route, '/') . $qs);
    $collection = $m[1];
    $category = $m[3];
    $page = $collection;
} elseif ($page === '' && preg_match('~^([a-z0-9][a-z0-9-]*)(?:/([a-z0-9][a-z0-9.-]*)?)?$~', $route, $m) && neutron_collection_has_page($m[1])) {
    $rest = $m[2] ?? '';
    // Varios idiomas: /noticies/… sin idioma va al principal; si no existe, puede ser una URL antigua (redirects.json)
    if ($langs !== null && neutron_lang() === null) {
        if ($rest === '' || $rest === 'feed.xml' || neutron_post_load($m[1], $rest) !== null) {
            neutron_redirect($langs['default'] . '/' . $m[1] . '/' . $rest . $qs);
        }
        $rest = '-';
    }
    if ($rest === 'feed.xml') {
        neutron_feed($m[1]);
        exit;
    }
    // /blog → /blog/ ($route no lleva la barra final: se mira en la URL pedida)
    if ($rest === '' && !$hasSlash) {
        neutron_redirect(neutron_lang_prefix() . $m[1] . '/' . $qs);
    }
    $collection = $m[1];
    if ($rest === '') {
        $page = $collection;
    } else {
        $post = neutron_post_load($collection, $rest);
        // Los borradores solo los ve quien ha entrado al editor
        $page = $post !== null && ($post['status'] === 'published' || neutron_is_logged_in()) ? '@post' : '-';
        if ($page === '-') $post = null;
    }
} elseif ($page !== '' && neutron_collection_has_page($page) && $route === $page . '.html') {
    neutron_redirect(neutron_lang_prefix() . $page . '/');
}
$known = ($page !== '' && $page !== '-') || in_array($route, ['', 'index.php'], true);
$file = match (true) {
    $page === '' => NEUTRON_SITE . '/template.html',
    $page === '@post' => NEUTRON_SITE . '/' . $collection . '/_item.html',
    default => NEUTRON_SITE . '/pages/' . $page . '.html',
};

// Portada con parámetros de la web anterior (index.php?option=com_content…)
if ($page === '' && $known && array_diff(array_keys($_GET), ['admin'])) {
    $to = neutron_redirect_target($path, true);
    if ($to !== null) {
        neutron_redirect($to);
    }
}

// Raíz de una web de varios idiomas: al idioma del navegador (o al principal)
if ($langs !== null && neutron_lang() === null && $known) {
    neutron_redirect(neutron_lang_detect() . '/' . (isset($_GET['admin']) ? '?admin' : ''));
}

if (!$known || ($page !== '' && !is_file($file))) {
    $to = neutron_redirect_target($path);
    if ($to !== null) {
        neutron_redirect($to);
    }
    http_response_code(404);
    $file = NEUTRON_SITE . '/pages/404.html';
    $page = '404';
    if (!is_file($file)) {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Página no encontrada. <a href="./">Inicio</a></p>';
        exit;
    }
} elseif (!is_file($file)) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Neutron</title>'
        . '<p>Esta instalación todavía no tiene sitio: crea <code>site/template.html</code> y <code>site/content.json</code> '
        . '(o <code>php bin/install.php</code> para copiar un ejemplo).</p>';
    exit;
}

// Una 404 fuera de cualquier idioma se ve en el principal
if ($langs !== null && neutron_lang() === null) {
    neutron_lang($langs['default']);
}
$lang = neutron_lang();

// Caché de páginas para los visitantes (lib/cache.php): si la página ya está montada y el sitio no ha cambiado, tal cual
require_once __DIR__ . '/lib/cache.php';
neutron_cache_serve();

$content = neutron_load_content();
$template = file_get_contents($file);

// Esta página dentro del idioma ("biomassa.html", "noticies/", "noticies/una-entrada"): para el selector de idioma
$self = match (true) {
    $page === '@post' => $collection . '/' . $post['slug'],
    $category !== null => neutron_category_href($collection, $category),
    $collection !== null => $collection . '/',
    $page === '' || $page === '404' => '',
    default => $page . '.html',
};

$options = ['current_href' => neutron_current_hrefs($page === '404' ? '-' : ($collection ?? $page)), 'editing' => $editing];
if ($collection !== null || $lang !== null) {
    // /blog/… y /ca/… están por debajo de la raíz: <base> para que las rutas relativas (site/assets/…) sigan valiendo
    $options['base'] = neutron_base_path() . '/';
}
if ($lang !== null) {
    $options['lang'] = [
        'code' => $lang,
        'prefix' => neutron_lang_prefix(),
        'self' => $self,
        'names' => $langs['languages'],
        'alternates' => neutron_lang_alternates($self),
        'site_url' => neutron_site_url(),
    ];
}
// Listados de colecciones y listas de categorías (también en los parciales: la barra lateral de un blog)
$expanded = str_contains($template, 'data-include') ? neutron_include_partials($template) : $template;
if ($post !== null || str_contains($expanded, 'data-posts') || str_contains($expanded, 'data-post-categories')) {
    $options['posts'] = [
        'lang' => $lang ?? (preg_match('~<html[^>]*\blang="([A-Za-z-]+)"~', $template, $lm) ? $lm[1] : 'es'),
        'page' => max(1, (int)($_GET['p'] ?? 1)),
        'self' => $category !== null ? neutron_category_href($collection, $category) : ($collection !== null ? $collection . '/' : ($page === '' ? '' : $page . '.html')),
        'admin' => $editing,
        'post' => $post,
        'collection' => $collection,
        'category' => $category,
    ];
}
if (isset($_GET['admin'])) {
    $options['admin_bootstrap'] = [
        'loggedIn' => neutron_is_logged_in(),
        'csrf' => $_SESSION['csrf'],
        // Hora en que se abre la página: al guardar, lo que otra ventana haya cambiado después es un conflicto
        'base' => microtime(true),
        // Idioma de la barra del editor (lib/panel_lang.php) y sus traducciones
        'ui' => neutron_panel_lang(),
        'i18n' => neutron_panel_strings(),
    ];
    if (neutron_is_logged_in()) {
        // Selector de páginas (y de idiomas) de la barra del editor; rutas relativas a la instalación
        $prefix = neutron_lang_prefix();
        $options['admin_bootstrap']['pages'] = array_map(fn($p) => ['href' => $prefix . ($p['href'] === './' ? '' : $p['href']) ?: './', 'title' => $p['title']], neutron_site_pages());
        $options['admin_bootstrap']['current'] = $prefix . ($collection !== null ? $collection . '/' : ($page === '' ? '' : $page . '.html')) ?: './';
        if ($lang !== null) {
            $options['admin_bootstrap']['lang'] = $lang;
            $options['admin_bootstrap']['languages'] = array_map(fn($code, $name) => ['code' => $code, 'name' => $name, 'href' => $code . '/' . $self], array_keys($langs['languages']), $langs['languages']);
        }
        // Blog: la entrada que se edita y las colecciones con listado en esta página (botón «Nueva entrada»)
        if ($post !== null) {
            $options['admin_bootstrap']['post'] = ['collection' => $collection, 'slug' => $post['slug'], 'status' => $post['status'], 'date' => $post['date'], 'translated' => $post['translated']];
        }
        preg_match_all('~data-posts="([a-z0-9-]+)"~', $template, $lists);
        $options['admin_bootstrap']['collections'] = array_values(array_unique(array_filter($lists[1], 'neutron_collection_has_page')));
    }
}
// Canónica, Open Graph y datos estructurados (lib/meta.php); no en las 404 ni en el editor
if ($page !== '404' && !$editing) {
    require_once __DIR__ . '/lib/meta.php';
    $siteUrl = neutron_site_url() . '/' . neutron_lang_prefix();
    $pageNo = max(1, (int)($_GET['p'] ?? 1));
    $crumbs = [[neutron_site_name(), $siteUrl]];
    if ($collection !== null) $crumbs[] = [neutron_meta_page_title(NEUTRON_SITE . '/pages/' . $collection . '.html', $lang, neutron_collection_schema($collection)['label']), $siteUrl . $collection . '/'];
    elseif ($page !== '') $crumbs[] = [neutron_meta_page_title($file, $lang, $page), $siteUrl . $self];
    if ($post !== null) $crumbs[] = [(string)$post['title'], $siteUrl . $self];
    if ($category !== null) $crumbs[] = [(string)(neutron_category_get($collection, $category)['title'] ?? $category), $siteUrl . $self];
    $options['meta'] = neutron_meta_context([
        'url' => $siteUrl . $self . ($pageNo > 1 && $post === null ? '?p=' . $pageNo : ''),
        'home' => $page === '',
        'lang' => $lang ?? (preg_match('~<html[^>]*\blang="([A-Za-z-]+)"~', $template, $hm) ? $hm[1] : ''),
        'crumbs' => $crumbs,
        'post' => $post,
        'collection' => $collection,
        'content' => $content,
    ]);
}
// Buscador: solo si la página (o un parcial suyo) tiene un formulario con data-search
if (str_contains($template, 'data-search') || (str_contains($template, 'data-include') && str_contains(neutron_include_partials($template), 'data-search'))) {
    require_once __DIR__ . '/lib/search.php';
    $options['search_bootstrap'] = neutron_search_client_config();
}
if (str_contains($template, 'data-neutron-form')) {
    $options['form_bootstrap'] = neutron_form_client_config();
}
$cookies = neutron_cookies_client_config();
if ($cookies !== null) {
    $options['cookies_bootstrap'] = $cookies;
}

header('Content-Type: text/html; charset=utf-8');
$html = neutron_render($template, $content, $options);
neutron_cache_store($html);
echo $html;
// Visitas (lib/stats.php): las páginas vistas por personas, sin cookies
if (http_response_code() === 200) {
    require_once __DIR__ . '/lib/stats.php';
    neutron_stats_hit();
}
