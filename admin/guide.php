<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/guide.php';

// La guía de edición de la web (site/guia-edicio.pdf), solo para quien ha entrado en el panel (lib/guide.php).

neutron_start_session();
neutron_require_login();

$file = neutron_guide_path();
if (!is_file($file)) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Esta web no tiene guía de edición.';
    exit;
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="guia-edicio.pdf"');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
readfile($file);
