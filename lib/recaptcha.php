<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// reCAPTCHA v2 invisible. Activo cuando config.local.php tiene site_key y secret_key.

function neutron_recaptcha_enabled(): bool {
    $r = neutron_config()['recaptcha'];
    return trim((string)$r['site_key']) !== '' && trim((string)$r['secret_key']) !== '';
}

/** @return array{ok:bool,error:string} error = códigos de Google o del transporte (solo para el log) */
function neutron_recaptcha_verify(string $response, string $ip): array {
    if ($response === '') {
        return ['ok' => false, 'error' => 'sin respuesta del widget'];
    }
    $fields = http_build_query([
        'secret' => neutron_config()['recaptcha']['secret_key'],
        'response' => $response,
        'remoteip' => $ip,
    ]);
    $url = 'https://www.google.com/recaptcha/api/siteverify';

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $error = $body === false ? curl_error($ch) : '';
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => 'Content-Type: application/x-www-form-urlencoded',
            'content' => $fields,
            'timeout' => 10,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        $error = $body === false ? 'sin conexión' : '';
    }
    if ($body === false) {
        return ['ok' => false, 'error' => 'no se puede conectar con Google: ' . $error];
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data) || empty($data['success'])) {
        return ['ok' => false, 'error' => implode(', ', (array)($data['error-codes'] ?? ['respuesta no válida']))];
    }
    return ['ok' => true, 'error' => ''];
}
