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

// La sesión solo hace falta en el editor: así los visitantes no reciben la cookie de sesión
if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) {
    neutron_start_session();
}
$editing = isset($_GET['admin']) && neutron_is_logged_in();

// Página: la plantilla del sitio o una de site/pages/ (carta.html → index.php?page=carta).
// Colecciones (blog): /blog/ es site/pages/blog.html, /blog/mi-entrada una entrada, /blog/feed.xml su RSS.
// Cualquier otra ruta (las URLs de la web anterior) va a site/redirects.json o da 404.
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
if ($page !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $page)) {
    $page = '-';
}
$collection = null;
$post = null;
if ($page === '' && $path === 'sitemap.xml') {
    neutron_sitemap();
    exit;
}
if ($page === '' && preg_match('~^([a-z0-9][a-z0-9-]*)(?:/([a-z0-9][a-z0-9.-]*)?)?$~', $path, $m) && neutron_collection_valid($m[1])) {
    $rest = $m[2] ?? '';
    if ($rest === 'feed.xml') {
        neutron_feed($m[1]);
        exit;
    }
    // /blog → /blog/ ($path no lleva la barra final: se mira en la URL pedida)
    if ($rest === '' && !str_ends_with((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH), '/')) {
        neutron_redirect($m[1] . '/' . (($_SERVER['QUERY_STRING'] ?? '') !== '' ? '?' . $_SERVER['QUERY_STRING'] : ''));
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
} elseif ($page !== '' && neutron_collection_valid($page) && $path === $page . '.html') {
    neutron_redirect($page . '/');
}
$known = $page !== '' || in_array($path, ['', 'index.php'], true);
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

$content = neutron_load_content();
$template = file_get_contents($file);

$options = ['current_href' => neutron_current_hrefs($page === '404' ? '-' : ($collection ?? $page))];
if ($collection !== null) {
    // /blog/… está un nivel por debajo: <base> para que las rutas relativas (site/assets/…) sigan valiendo
    $options['base'] = neutron_base_path() . '/';
}
if ($post !== null || str_contains($template, 'data-posts')) {
    $options['posts'] = [
        'lang' => preg_match('~<html[^>]*\blang="([A-Za-z-]+)"~', $template, $lm) ? $lm[1] : 'es',
        'page' => max(1, (int)($_GET['p'] ?? 1)),
        'self' => $collection !== null ? $collection . '/' : ($page === '' ? '' : $page . '.html'),
        'admin' => $editing,
        'post' => $post,
    ];
}
if (isset($_GET['admin'])) {
    $options['admin_bootstrap'] = [
        'loggedIn' => neutron_is_logged_in(),
        'csrf' => $_SESSION['csrf'],
    ];
    if (neutron_is_logged_in()) {
        // Selector de páginas de la barra del editor
        $options['admin_bootstrap']['pages'] = neutron_site_pages();
        $options['admin_bootstrap']['current'] = $collection !== null ? $collection . '/' : ($page === '' ? './' : $page . '.html');
        // Blog: la entrada que se edita y las colecciones con listado en esta página (botón «Nueva entrada»)
        if ($post !== null) {
            $options['admin_bootstrap']['post'] = ['collection' => $collection, 'slug' => $post['slug'], 'status' => $post['status'], 'date' => $post['date']];
        }
        preg_match_all('~data-posts="([a-z0-9-]+)"~', $template, $lists);
        $options['admin_bootstrap']['collections'] = array_values(array_unique(array_filter($lists[1], 'neutron_collection_valid')));
    }
}
if (str_contains($template, 'data-neutron-form')) {
    $options['form_bootstrap'] = neutron_form_client_config();
}
$cookies = neutron_cookies_client_config();
if ($cookies !== null) {
    $options['cookies_bootstrap'] = $cookies;
}

header('Content-Type: text/html; charset=utf-8');
echo neutron_render($template, $content, $options);
