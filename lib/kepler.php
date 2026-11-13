<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Chat de Kepler (el asistente con IA de pelu.online) en todas las páginas de la web.
//
// El token lo da el panel de Kepler (Ponerlo en tu web). Se puede poner en tres sitios; manda el primero que lo tenga:
//   1. El panel de la web (/admin/ → Chat) o la acción remota «kepler-set» (Cosmo): data/kepler.json
//   2. config.local.php: 'kepler' => ['token' => '…']
// Ninguno es del núcleo, así que una actualización no lo borra. Sin token (o desactivado), no se añade nada.
// El script va justo antes de </body>, salvo en la edición en la propia página (?admin) o si la plantilla ya lo lleva.

function neutron_kepler_file(): string {
    return neutron_data_dir() . '/kepler.json';
}

/** @return array{token: string, enabled: bool, url: string, source: string} source: panel | config | '' */
function neutron_kepler_settings(): array {
    $config = (array)(neutron_config()['kepler'] ?? []);
    $url = rtrim((string)($config['url'] ?? 'https://kepler.pelu.online'), '/');
    $saved = is_file(neutron_kepler_file()) ? json_decode((string)file_get_contents(neutron_kepler_file()), true) : null;
    if (is_array($saved) && array_key_exists('token', $saved)) {
        return ['token' => (string)$saved['token'], 'enabled' => !empty($saved['enabled']), 'url' => $url, 'source' => 'panel'];
    }
    $token = (string)($config['token'] ?? '');
    return ['token' => $token, 'enabled' => $token !== '' && ($config['enabled'] ?? true) !== false, 'url' => $url, 'source' => $token !== '' ? 'config' : ''];
}

function neutron_kepler_valid_token(string $token): bool {
    return (bool)preg_match('/^[0-9a-f]{32}$/', $token);
}

/** Guarda el token (vacío = quitarlo) y vacía la caché de páginas, que lleva el script dentro. */
function neutron_kepler_save(string $token, bool $enabled): bool {
    $token = strtolower(trim($token));
    if ($token !== '' && !neutron_kepler_valid_token($token)) return false;
    require_once __DIR__ . '/remote/state.php';
    neutron_write_json(neutron_kepler_file(), ['token' => $token, 'enabled' => $enabled && $token !== '', 'updated' => date(DATE_ATOM)]);
    require_once __DIR__ . '/cache.php';
    neutron_cache_clear();
    neutron_log('info', 'Chat de Kepler {state}', ['state' => $enabled && $token !== '' ? 'activado' : 'desactivado']);
    return true;
}

function neutron_kepler_snippet(): string {
    $s = neutron_kepler_settings();
    if (!$s['enabled'] || !neutron_kepler_valid_token($s['token']) || !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$~', $s['url'] . '/')) {
        return '';
    }
    return '<script src="' . htmlspecialchars($s['url'] . '/widget.js', ENT_QUOTES) . '" data-kepler="' . $s['token'] . '" async></script>';
}

/** Añade el script antes del último </body> (si la página no lo lleva ya). */
function neutron_kepler_inject(string $html): string {
    $snippet = neutron_kepler_snippet();
    if ($snippet === '' || str_contains($html, 'data-kepler=')) return $html;
    $pos = strripos($html, '</body>');
    return $pos === false ? $html . $snippet : substr($html, 0, $pos) . $snippet . "\n" . substr($html, $pos);
}
