<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Cabeceras de seguridad de todas las respuestas (las carga bootstrap.php, antes de servir la caché) y
// /.well-known/security.txt. Configuración en 'security' (config.php).

// Si la petición llega por HTTPS, también detrás de un proxy o del nginx del hosting (X-Forwarded-Proto)
function neutron_is_https(): bool {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') return true;
    if ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443) return true;
    return strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function neutron_security_headers(): void {
    if (PHP_SAPI === 'cli' || headers_sent()) return;
    $cfg = (array)(neutron_config()['security'] ?? []);
    header_remove('X-Powered-By');                       // no anunciar la versión de PHP
    if (($cfg['headers'] ?? true) === false) return;
    header('X-Content-Type-Options: nosniff');           // un fichero subido no se interpreta como script o HTML
    header('X-Frame-Options: SAMEORIGIN');               // la web no se puede incrustar en otra (clickjacking)
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=(), usb=()');
    // HSTS: el navegador ya no intenta HTTP. Sin includeSubDomains salvo que se pida: un subdominio sin HTTPS
    // quedaría inaccesible durante el max-age
    if (($cfg['hsts'] ?? true) !== false && neutron_is_https()) {
        $maxAge = max(0, (int)($cfg['hsts_max_age'] ?? 31536000));
        header('Strict-Transport-Security: max-age=' . $maxAge . (!empty($cfg['hsts_subdomains']) ? '; includeSubDomains' : ''));
    }
}

// /.well-known/security.txt (RFC 9116): el de site/ si existe; si no, a partir de security.contact. Sin contacto, 404.
function neutron_security_txt(): void {
    $own = NEUTRON_SITE . '/security.txt';
    $contact = trim((string)(neutron_config()['security']['contact'] ?? ''));
    if (!is_file($own) && $contact === '') {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "Not found\n";
        return;
    }
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: public, max-age=86400');
    if (is_file($own)) { readfile($own); return; }
    if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $contact)) {
        $contact = 'mailto:' . $contact;                 // un correo sin «mailto:»
    }
    $lines = ['Contact: ' . $contact, 'Expires: ' . gmdate('Y-m-d\TH:i:s\Z', time() + 365 * 86400)];
    $langs = trim((string)(neutron_config()['security']['languages'] ?? ''));
    if ($langs !== '') $lines[] = 'Preferred-Languages: ' . $langs;
    echo implode("\n", $lines), "\n";
}
