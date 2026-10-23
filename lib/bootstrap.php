<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Arranque común a todos los puntos de entrada (index.php, contact.php, admin/*, bin/*).

define('NEUTRON_ROOT', dirname(__DIR__));
define('NEUTRON_SITE', NEUTRON_ROOT . '/site');   // lo propio de cada instalación
define('NEUTRON_DATA', NEUTRON_ROOT . '/data');   // estado interno: logs, copias, throttle… (no público)

require_once NEUTRON_ROOT . '/config.php';
require_once __DIR__ . '/log.php';

date_default_timezone_set((string)(neutron_config()['timezone'] ?: 'UTC'));
ini_set('display_errors', neutron_config()['debug'] ? '1' : '0');
neutron_log_register_handlers();

function neutron_version(): string {
    $v = is_file(NEUTRON_ROOT . '/VERSION') ? trim((string)file_get_contents(NEUTRON_ROOT . '/VERSION')) : '';
    return $v !== '' ? $v : '0.0.0';
}

function neutron_data_dir(string $sub = ''): string {
    $dir = NEUTRON_DATA . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("No se puede crear $dir");
    }
    return $dir;
}

function neutron_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function neutron_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

// --- modo mantenimiento (lo activa Cosmo o una actualización) ---

function neutron_maintenance_file(): string {
    return NEUTRON_DATA . '/remote/maintenance.json';
}

function neutron_maintenance(): ?array {
    $file = neutron_maintenance_file();
    if (!is_file($file)) return null;
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? $data : ['since' => '', 'reason' => ''];
}

// Corta la petición con 503 si la web está en mantenimiento.
function neutron_block_if_maintenance(bool $json = false): void {
    if (neutron_maintenance() === null) return;
    header('Retry-After: 300');
    if ($json) {
        neutron_json(['ok' => false, 'error' => 'Web en mantenimiento. Inténtalo en unos minutos.'], 503);
        exit;
    }
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<meta name="robots" content="noindex"><title>Manteniment</title>'
        . '<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#333;text-align:center;padding:16px}</style>'
        . '</head><body><div><h1>En manteniment · En mantenimiento</h1><p>Tornem d’aquí a uns minuts · Volvemos en unos minutos.</p></div></body></html>';
    exit;
}
