<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/state.php';
require_once __DIR__ . '/corefiles.php';
require_once __DIR__ . '/backups.php';
require_once __DIR__ . '/logs.php';
require_once __DIR__ . '/updater.php';
require_once __DIR__ . '/../recaptcha.php';
require_once __DIR__ . '/../content.php';

// API para el panel central de instalaciones (Cosmo): index.php?r=remote&a=acción (y ?r=status).
// Mismas acciones y respuestas que la de Quasar, así Cosmo gestiona las dos igual.
//
// Solo responde si remote.token (config.local.php) tiene al menos 32 caracteres; Cosmo lo envía en la
// cabecera X-Quasar-Token (también vale X-Neutron-Token o Authorization: Bearer …). Sin token válido
// responde 404. Las acciones que cambian algo exigen POST. No abre sesión ni envía cookies.

const NEUTRON_REMOTE_API = 1;

// acción => [método HTTP, función]
const NEUTRON_REMOTE_ACTIONS = [
    'status' => ['GET', 'neutron_remote_status'],
    'logs' => ['GET', 'neutron_remote_logs'],
    'integrity' => ['GET', 'neutron_remote_integrity'],
    'backups' => ['GET', 'neutron_remote_backups'],
    'download' => ['GET', 'neutron_remote_download'],
    'backup' => ['POST', 'neutron_remote_backup'],
    'backup-delete' => ['POST', 'neutron_remote_backup_delete'],
    'update' => ['POST', 'neutron_remote_update'],
    'migrate' => ['POST', 'neutron_remote_migrate'],
    'maintenance' => ['POST', 'neutron_remote_maintenance'],
];

function neutron_remote_handle(string $action): void {
    header('Cache-Control: no-store');
    neutron_remote_authenticate();
    if (!isset(NEUTRON_REMOTE_ACTIONS[$action])) {
        neutron_remote_not_found();
    }
    [$method, $fn] = NEUTRON_REMOTE_ACTIONS[$action];
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        neutron_json(['ok' => false, 'error' => 'Method Not Allowed'], 405);
        return;
    }
    if ($method === 'POST') {
        // Las tareas largas siguen aunque el panel corte la conexión
        ignore_user_abort(true);
        @set_time_limit(0);
        neutron_log('info', 'Acción remota {action}', ['action' => $action]);
    }
    try {
        $fn();
    } catch (Throwable $e) {
        neutron_log('error', 'Acción remota fallida', ['action' => $action, 'exception' => $e]);
        neutron_remote_fail($e->getMessage(), 500);
    }
}

// Mismo cuerpo que el 404 de Quasar: Cosmo lo reconoce como "token incorrecto o API desactivada".
function neutron_remote_not_found(): void {
    neutron_json(['ok' => false, 'error' => 'Not Found'], 404);
    exit;
}

function neutron_remote_authenticate(): void {
    $remote = neutron_config()['remote'];
    $token = (string)$remote['token'];
    if (strlen($token) < 32) {
        neutron_remote_not_found();
    }
    $ip = neutron_client_ip();
    $allowed = (array)$remote['allow_ips'];
    $sent = neutron_remote_sent_token();
    if ($sent === '' || !hash_equals($token, $sent) || ($allowed && !in_array($ip, $allowed, true))) {
        neutron_log('warning', 'Consulta remota con token no válido o IP no permitida');
        neutron_remote_not_found();
    }
}

function neutron_remote_sent_token(): string {
    foreach (['HTTP_X_NEUTRON_TOKEN', 'HTTP_X_QUASAR_TOKEN'] as $h) {
        if (!empty($_SERVER[$h])) return trim((string)$_SERVER[$h]);
    }
    // Algunos hostings (PHP como CGI/FPM) solo la pasan como REDIRECT_HTTP_AUTHORIZATION
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? $m[1] : '';
}

function neutron_remote_fail(string $message, int $status, array $data = []): void {
    neutron_json(['ok' => false, 'error' => $message] + ($data ? ['data' => $data] : []), $status);
}

// ------------------------------------------------------------------ lectura

function neutron_remote_status(): void {
    $config = neutron_config();
    $backups = neutron_backups_list();
    $integrity = neutron_core_check();
    $disk = @disk_free_space(NEUTRON_ROOT);
    $total = @disk_total_space(NEUTRON_ROOT);
    $contentFile = neutron_content_path();
    $contactFile = NEUTRON_SITE . '/contact.json';
    $template = is_file(NEUTRON_SITE . '/template.html') ? (string)file_get_contents(NEUTRON_SITE . '/template.html') : '';

    neutron_json([
        'app' => 'neutron',
        'api' => NEUTRON_REMOTE_API,
        'version' => neutron_version(),
        'name' => neutron_site_name(),
        'php' => PHP_VERSION,
        'time' => date(DATE_ATOM),
        'timezone' => date_default_timezone_get(),
        'server' => [
            'software' => (string)($_SERVER['SERVER_SOFTWARE'] ?? ''),
            'os' => PHP_OS_FAMILY,
            'sapi' => PHP_SAPI,
            'disk_free' => $disk === false ? null : (int)$disk,
            'disk_total' => $total === false ? null : (int)$total,
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => (int)ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            // Para actualizar hace falta escribir en el núcleo; para editar, en site/
            'writable' => is_writable(NEUTRON_ROOT . '/lib') && is_writable(NEUTRON_ROOT . '/assets') && is_writable(NEUTRON_ROOT),
            'site_writable' => is_writable($contentFile) && is_writable(NEUTRON_SITE . '/uploads'),
        ],
        'extensions' => array_combine(
            $ext = ['zip', 'dom', 'fileinfo', 'mbstring', 'openssl', 'curl', 'Zend OPcache'],
            array_map('extension_loaded', $ext)
        ),
        'config' => [
            'debug' => !empty($config['debug']),
            'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'allow_ips' => !empty($config['remote']['allow_ips']),
            'backups_keep' => max(1, (int)$config['remote']['backups_keep']),
            'admin_password' => (string)$config['admin_password_hash'] !== '',
            'mail_transport' => 'mail',
        ],
        'site' => [
            'template' => $template !== '',
            'content_size' => is_file($contentFile) ? (int)filesize($contentFile) : null,
            'content_modified' => is_file($contentFile) ? date(DATE_ATOM, (int)filemtime($contentFile)) : null,
            'pages' => count(glob(NEUTRON_SITE . '/pages/*.html') ?: []),
            'contact_form' => str_contains($template, 'data-neutron-form'),
            'contact_json' => is_file($contactFile),
            'favicon' => is_file(NEUTRON_SITE . '/favicon.ico'),
        ],
        'recaptcha' => [
            'enabled' => neutron_recaptcha_enabled(),
            // Un formulario sin reCAPTCHA es un aviso para Cosmo: la regla es llevarlo siempre
            'missing' => str_contains($template, 'data-neutron-form') && !neutron_recaptcha_enabled(),
        ],
        'maintenance' => neutron_maintenance(),
        'backups' => ['count' => count($backups), 'last' => $backups[0] ?? null],
        'logs' => neutron_logs_counts24h(),
        'integrity' => $integrity === null ? null : [
            'modified' => count($integrity['modified']),
            'missing' => count($integrity['missing']),
        ],
        'last_update' => neutron_updates()[0] ?? null,
    ]);
}

function neutron_remote_logs(): void {
    $level = is_string($_GET['level'] ?? null) ? $_GET['level'] : 'warning';
    $limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));
    $days = min(30, max(1, (int)($_GET['days'] ?? 7)));
    neutron_json(['ok' => true, 'entries' => neutron_logs_recent($level, $limit, $days)]);
}

function neutron_remote_integrity(): void {
    $check = neutron_core_check();
    neutron_json([
        'ok' => true,
        'manifest' => $check !== null,
        'version' => neutron_manifest()['version'] ?? null,
        'modified' => $check['modified'] ?? [],
        'missing' => $check['missing'] ?? [],
        'updates' => neutron_updates(),
    ]);
}

function neutron_remote_backups(): void {
    neutron_json(['ok' => true, 'backups' => neutron_backups_list(), 'disk_free' => @disk_free_space(neutron_backups_dir()) ?: null]);
}

function neutron_remote_download(): void {
    $file = neutron_backup_path(is_string($_GET['file'] ?? null) ? $_GET['file'] : '');
    if ($file === null) {
        neutron_remote_not_found();
    }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($file) . '"');
    header('Content-Length: ' . filesize($file));
    header('X-Backup-Sha256: ' . hash_file('sha256', $file));
    readfile($file);
}

// ------------------------------------------------------------------ acciones

function neutron_remote_backup(): void {
    $type = is_string($_POST['type'] ?? null) ? $_POST['type'] : 'full';
    if (!in_array($type, NEUTRON_BACKUP_TYPES, true)) {
        neutron_remote_fail('Tipo de copia no válido', 400);
        return;
    }
    $label = is_string($_POST['label'] ?? null) ? $_POST['label'] : '';
    neutron_remote_exclusive(fn() => neutron_json(['ok' => true, 'backup' => neutron_backup_create($type, $label)]));
}

function neutron_remote_backup_delete(): void {
    $name = is_string($_POST['file'] ?? null) ? $_POST['file'] : '';
    if (!neutron_backup_delete($name)) {
        neutron_remote_not_found();
    }
    neutron_log('notice', 'Copia borrada: {file}', ['file' => $name]);
    neutron_json(['ok' => true]);
}

// POST multipart: package (ZIP), sha256, version, force (0|1), backup (0|1).
// Deja la web en mantenimiento: lo quita la acción migrate (que ya corre con el código nuevo).
function neutron_remote_update(): void {
    $upload = $_FILES['package'] ?? null;
    if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
        neutron_remote_fail('No ha llegado el paquete (código de subida ' . ($upload['error'] ?? 'sin fichero') . ', upload_max_filesize=' . ini_get('upload_max_filesize') . ')', 400);
        return;
    }
    $sha = is_string($_POST['sha256'] ?? null) ? $_POST['sha256'] : '';
    $version = is_string($_POST['version'] ?? null) ? $_POST['version'] : '';
    $force = ($_POST['force'] ?? '') === '1';
    $withBackup = ($_POST['backup'] ?? '1') === '1';

    neutron_remote_exclusive(function () use ($upload, $sha, $version, $force, $withBackup): void {
        $backup = null;
        if ($withBackup) {
            try {
                $backup = neutron_backup_create('full', 'Antes de actualizar a ' . $version);
            } catch (Throwable $e) {
                neutron_remote_fail('No se pudo hacer la copia previa: ' . $e->getMessage(), 500);
                return;
            }
        }
        $wasInMaintenance = neutron_maintenance() !== null;
        neutron_set_maintenance(true, 'Actualización a ' . $version);
        try {
            $selfTest = neutron_config()['remote']['self_test'] ? fn(string $v) => neutron_remote_self_test($v) : null;
            $report = neutron_update_install($upload['tmp_name'], $sha, $version, $force, $selfTest);
        } catch (NeutronUpdateException $e) {
            if (!$wasInMaintenance) neutron_set_maintenance(false);
            neutron_remote_fail($e->getMessage(), 422, $e->data + ['backup' => $backup]);
            return;
        }
        neutron_json(['ok' => true, 'report' => $report, 'backup' => $backup, 'maintenance' => true]);
    });
}

// Neutron no tiene base de datos: "migrar" prepara las carpetas de datos y quita el mantenimiento.
// Si una versión futura necesita convertir site/ o content.json, el paso va aquí.
function neutron_remote_migrate(): void {
    neutron_remote_exclusive(function (): void {
        $steps = [];
        foreach (['logs', 'backups', 'remote'] as $dir) {
            if (!is_dir(NEUTRON_DATA . '/' . $dir)) {
                neutron_data_dir($dir);
                $steps[] = "data/$dir creada";
            }
        }
        if (!is_dir(NEUTRON_SITE . '/uploads') && is_dir(NEUTRON_SITE)) {
            neutron_ensure_dir(NEUTRON_SITE . '/uploads');
            $steps[] = 'site/uploads creada';
        }
        // Desde la 1.0.0/1.1.0 la actualización copió el .htaccess entero: recuperar las líneas propias
        $recovered = neutron_htaccess_recover();
        if ($recovered !== null) $steps[] = $recovered;
        $keep = ($_POST['keep_maintenance'] ?? '') === '1';
        if (!$keep) neutron_set_maintenance(false);
        neutron_json(['ok' => true, 'steps' => $steps, 'version' => neutron_version(), 'maintenance' => $keep]);
    });
}

function neutron_remote_maintenance(): void {
    $on = ($_POST['on'] ?? '') === '1';
    neutron_set_maintenance($on, is_string($_POST['reason'] ?? null) ? mb_substr($_POST['reason'], 0, 200) : '');
    neutron_log('notice', 'Mantenimiento {state}', ['state' => $on ? 'activado' : 'desactivado']);
    neutron_json(['ok' => true, 'maintenance' => neutron_maintenance()]);
}

// ------------------------------------------------------------------ utilidades

// Pide el estado a esta misma instalación (por la misma URL que ha usado el panel), que ya carga el
// código nuevo: así se sabe si la versión instalada arranca.
function neutron_remote_self_test(string $version): array {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/index.php'), PHP_URL_PATH);
    if ($host === '' || !preg_match('/^[A-Za-z0-9.:\[\]-]+$/', $host)) {
        return ['reachable' => false, 'ok' => false, 'error' => 'host desconocido'];
    }
    $url = ($https ? 'https' : 'http') . '://' . $host . $path . '?r=remote&a=status';
    $headers = ['X-Neutron-Token: ' . neutron_config()['remote']['token'], 'Accept: application/json'];

    $code = 0;
    $error = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['header' => implode("\r\n", $headers), 'timeout' => 30, 'ignore_errors' => true]]);
        $body = @file_get_contents($url, false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s?/', $http_response_header[0], $m) ? (int)$m[1] : 0;
        $error = $body === false ? 'sin respuesta' : null;
    }
    if ($body === false || $code === 0) {
        return ['reachable' => false, 'ok' => false, 'error' => (string)$error];
    }
    $data = json_decode((string)$body, true);
    if ($code !== 200 || !is_array($data) || ($data['app'] ?? null) !== 'neutron') {
        return ['reachable' => true, 'ok' => false, 'error' => "HTTP $code: " . mb_substr(trim(strip_tags((string)$body)), 0, 300)];
    }
    if (($data['version'] ?? null) !== $version) {
        return ['reachable' => true, 'ok' => false, 'error' => 'responde la versión ' . ($data['version'] ?? '?') . " en lugar de $version"];
    }
    return ['reachable' => true, 'ok' => true, 'error' => null];
}

// Una sola tarea pesada (copia, actualización, migración) a la vez.
function neutron_remote_exclusive(Closure $task): void {
    $lock = fopen(neutron_remote_dir() . '/task.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        neutron_remote_fail('Ya hay otra tarea en curso en esta instalación', 409);
        return;
    }
    try {
        $task();
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
