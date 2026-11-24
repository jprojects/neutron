<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Chat web de Asteroid (el gestor de incidencias de AFI) en todas las páginas de la web: los visitantes escriben y el
// cliente contesta desde Asteroid (Suport → Xat web) o desde Telegram.
//
// La clave la da Asteroid (Suport → El meu xat web: el código lleva «k=CLAVE»). Se puede poner en dos sitios; manda el
// primero que la tenga:
//   1. El panel de la web (/admin/ → Chat) o la acción remota «asteroid-set» (Cosmo): data/asteroid.json
//   2. config.local.php: 'asteroid' => ['key' => '…']
// Ninguno es del núcleo, así que una actualización no lo borra. Sin clave (o desactivado), no se añade nada.
// El script va justo antes de </body>, salvo en la edición en la propia página (?admin) o si la plantilla ya lo lleva.
// Es independiente del chat de Kepler (lib/kepler.php): pueden estar los dos.

function neutron_asteroid_file(): string {
    return neutron_data_dir() . '/asteroid.json';
}

/** @return array{key: string, enabled: bool, url: string, source: string} source: panel | config | '' */
function neutron_asteroid_settings(): array {
    $config = (array)(neutron_config()['asteroid'] ?? []);
    $url = rtrim((string)($config['url'] ?? 'https://sat.aficat.com'), '/');
    $saved = is_file(neutron_asteroid_file()) ? json_decode((string)file_get_contents(neutron_asteroid_file()), true) : null;
    if (is_array($saved) && array_key_exists('key', $saved)) {
        return ['key' => (string)$saved['key'], 'enabled' => !empty($saved['enabled']), 'url' => $url, 'source' => 'panel'];
    }
    $key = (string)($config['key'] ?? '');
    return ['key' => $key, 'enabled' => $key !== '' && ($config['enabled'] ?? true) !== false, 'url' => $url, 'source' => $key !== '' ? 'config' : ''];
}

function neutron_asteroid_valid_key(string $key): bool {
    return (bool)preg_match('/^[0-9a-f]{24}$/', $key);
}

/** Saca la clave de lo que se pegue: el código entero de Asteroid (…&k=CLAVE…) o solo la clave. */
function neutron_asteroid_parse_key(string $value): string {
    $value = trim($value);
    if (preg_match('/[?&;]k=([0-9a-fA-F]{24})\b/', $value, $m)) return strtolower($m[1]);
    return strtolower($value);
}

/** Guarda la clave (vacía = quitarla) y vacía la caché de páginas, que lleva el script dentro. */
function neutron_asteroid_save(string $key, bool $enabled): bool {
    $key = neutron_asteroid_parse_key($key);
    if ($key !== '' && !neutron_asteroid_valid_key($key)) return false;
    require_once __DIR__ . '/remote/state.php';
    neutron_write_json(neutron_asteroid_file(), ['key' => $key, 'enabled' => $enabled && $key !== '', 'updated' => date(DATE_ATOM)]);
    require_once __DIR__ . '/cache.php';
    neutron_cache_clear();
    neutron_log('info', 'Chat de Asteroid {state}', ['state' => $enabled && $key !== '' ? 'activado' : 'desactivado']);
    return true;
}

function neutron_asteroid_snippet(): string {
    $s = neutron_asteroid_settings();
    if (!$s['enabled'] || !neutron_asteroid_valid_key($s['key']) || !preg_match('~^https://[A-Za-z0-9.-]+(:\d+)?(/[A-Za-z0-9._/-]*)?$~', $s['url'] . '/')) {
        return '';
    }
    $src = $s['url'] . '/index.php?r=public&m=webchat_widget&a=loader&k=' . $s['key'];
    return '<script src="' . htmlspecialchars($src, ENT_QUOTES) . '" async></script>';
}

/** Añade el script antes del último </body> (si la página no lo lleva ya). */
function neutron_asteroid_inject(string $html): string {
    $snippet = neutron_asteroid_snippet();
    if ($snippet === '' || str_contains($html, 'm=webchat_widget')) return $html;
    $pos = strripos($html, '</body>');
    return $pos === false ? $html . $snippet : substr($html, 0, $pos) . $snippet . "\n" . substr($html, $pos);
}
