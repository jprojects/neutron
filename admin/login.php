<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}

neutron_require_csrf();

$body = json_decode(file_get_contents('php://input'), true);
$password = is_array($body) ? ($body['password'] ?? '') : '';

if ($password !== '' && neutron_attempt_login((string)$password)) {
    echo json_encode(['ok' => true]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Contraseña incorrecta o demasiados intentos']);
}
