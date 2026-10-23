<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

define('NEUTRON_THROTTLE_MAX_ATTEMPTS', 5);
define('NEUTRON_THROTTLE_WINDOW', 900); // 15 minutos

function neutron_throttle_path(): string {
    return NEUTRON_DATA . '/throttle.json';
}

function neutron_throttle_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function neutron_throttle_load(): array {
    $path = neutron_throttle_path();
    if (!file_exists($path)) return [];
    $raw = @file_get_contents($path);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function neutron_throttle_save(array $data): void {
    @file_put_contents(neutron_throttle_path(), json_encode($data), LOCK_EX);
}

function neutron_throttle_allow(): bool {
    $data = neutron_throttle_load();
    $entry = $data[neutron_throttle_ip()] ?? null;
    if (!$entry) return true;
    if (time() - $entry['first'] > NEUTRON_THROTTLE_WINDOW) return true;
    return $entry['count'] < NEUTRON_THROTTLE_MAX_ATTEMPTS;
}

function neutron_throttle_register_failure(): void {
    $data = neutron_throttle_load();
    $ip = neutron_throttle_ip();
    $entry = $data[$ip] ?? null;
    if (!$entry || time() - $entry['first'] > NEUTRON_THROTTLE_WINDOW) {
        $entry = ['count' => 0, 'first' => time()];
    }
    $entry['count']++;
    $data[$ip] = $entry;
    neutron_throttle_save($data);
}

function neutron_throttle_reset(): void {
    $data = neutron_throttle_load();
    unset($data[neutron_throttle_ip()]);
    neutron_throttle_save($data);
}
