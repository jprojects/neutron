<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

neutron_require_csrf();

neutron_log('info', 'Salida del editor');
$_SESSION = [];
session_destroy();
echo json_encode(['ok' => true]);
