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

neutron_block_if_maintenance();

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/render.php';
require_once __DIR__ . '/lib/form.php';

// Página: la plantilla del sitio o una de site/pages/ (avis-legal.html → index.php?page=avis-legal)
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : '';
if ($page !== '' && !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', $page)) {
    $page = '-';
}
$file = $page === '' ? NEUTRON_SITE . '/template.html' : NEUTRON_SITE . '/pages/' . $page . '.html';

if (!is_file($file)) {
    header('Content-Type: text/html; charset=utf-8');
    if ($page !== '') {
        http_response_code(404);
        echo '<!doctype html><meta charset="utf-8"><title>404</title><p>Página no encontrada. <a href="./">Inicio</a></p>';
    } else {
        http_response_code(503);
        echo '<!doctype html><meta charset="utf-8"><title>Neutron</title>'
            . '<p>Esta instalación todavía no tiene sitio: crea <code>site/template.html</code> y <code>site/content.json</code> '
            . '(o <code>php bin/install.php</code> para copiar un ejemplo).</p>';
    }
    exit;
}

// La sesión solo hace falta en el editor: así los visitantes no reciben la cookie de sesión
if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) {
    neutron_start_session();
}

$content = neutron_load_content();
$template = file_get_contents($file);

$options = [];
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
