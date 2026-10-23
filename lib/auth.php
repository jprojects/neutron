<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/throttle.php';

function neutron_start_session(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_name('neutron_admin_sid');
        session_start();
    }
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
}

function neutron_is_logged_in(): bool {
    return !empty($_SESSION['neutron_admin']);
}

function neutron_require_login(): void {
    if (!neutron_is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'No autenticado']);
        exit;
    }
}

function neutron_require_csrf(): void {
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$sent || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $sent)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'CSRF inválido']);
        exit;
    }
}

function neutron_attempt_login(string $password): bool {
    if (!neutron_throttle_allow()) {
        neutron_log('warning', 'Acceso al editor bloqueado por demasiados intentos');
        return false;
    }
    $hash = (string)neutron_config()['admin_password_hash'];
    if ($hash && password_verify($password, $hash)) {
        neutron_throttle_reset();
        session_regenerate_id(true);
        $_SESSION['neutron_admin'] = true;
        neutron_log('notice', 'Acceso al editor');
        return true;
    }
    neutron_throttle_register_failure();
    neutron_log('warning', 'Contraseña del editor incorrecta');
    return false;
}
