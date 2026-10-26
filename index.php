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

// Página: la plantilla del sitio o una de site/pages/ (carta.html → index.php?page=carta).
// Cualquier otra ruta (las URLs de la web anterior) va a site/redirects.json o da 404.
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
if ($page !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $page)) {
    $page = '-';
}
$known = $page !== '' || in_array($path, ['', 'index.php'], true);
$file = $page === '' ? NEUTRON_SITE . '/template.html' : NEUTRON_SITE . '/pages/' . $page . '.html';

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

// La sesión solo hace falta en el editor: así los visitantes no reciben la cookie de sesión
if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) {
    neutron_start_session();
}

$content = neutron_load_content();
$template = file_get_contents($file);

$options = ['current_href' => neutron_current_hrefs($page === '404' ? '-' : $page)];
if (isset($_GET['admin'])) {
    $options['admin_bootstrap'] = [
        'loggedIn' => neutron_is_logged_in(),
        'csrf' => $_SESSION['csrf'],
    ];
}
if (str_contains($template, 'data-neutron-form')) {
    $options['form_bootstrap'] = neutron_form_client_config();
}

header('Content-Type: text/html; charset=utf-8');
echo neutron_render($template, $content, $options);
