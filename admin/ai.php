<?php
// Propuestas de la IA para un texto (lib/ai.php), con la sesión del editor. Solo propone: no guarda nada.
//   POST admin/ai.php  {"action": "improve"|"fix"|"translate"|"seo_description"|"seo_title", "text", "lang", "kind",
//                       "context", "to": ["es", …], "page": "texto de la página"}
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/ai.php';

neutron_block_if_maintenance(true);
neutron_start_session();
header('Content-Type: application/json');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método no permitido']);
    exit;
}
neutron_require_login();
neutron_require_csrf();
// La respuesta tarda unos segundos: la sesión no bloquea el resto del editor mientras tanto
session_write_close();

$body = json_decode((string)file_get_contents('php://input'), true);
$result = neutron_ai_suggest(is_array($body) ? $body : []);
if (!$result['ok']) http_response_code(422);
echo json_encode($result);
