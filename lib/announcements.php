<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Avisos de AFI en el inicio del panel (/admin/): campañas que envía Cosmo por la API remota (announcements-set).
//
//   data/announcements.json         {"items": [{"id", "title", "text", "url", "button", "until"}], "updated_at"}
//   data/announcements-stats.json   {"<id>": {"views": n, "clicks": n}}  (vistas: una por sesión del panel)
//
// Cosmo manda siempre la lista entera de las campañas que tocan a esta web: lo que no está, se quita. Las
// estadísticas se conservan por id aunque la campaña se quite, para que Cosmo pueda leer las últimas.

require_once __DIR__ . '/remote/state.php';

const NEUTRON_ANNOUNCEMENT_LIMITS = ['title' => 120, 'text' => 600, 'button' => 40, 'url' => 500];

function neutron_announcements_file(): string {
    return NEUTRON_DATA . '/announcements.json';
}

function neutron_announcements_stats_file(): string {
    return NEUTRON_DATA . '/announcements-stats.json';
}

// Un aviso válido (o null): textos recortados, enlace solo http(s), caducidad AAAA-MM-DD
function neutron_announcement_clean(mixed $item): ?array {
    if (!is_array($item)) return null;
    $id = (string)($item['id'] ?? '');
    $title = trim((string)($item['title'] ?? ''));
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $id) || $title === '') return null;
    $out = ['id' => $id];
    foreach (NEUTRON_ANNOUNCEMENT_LIMITS as $key => $max) {
        $out[$key] = mb_substr(trim((string)($key === 'title' ? $title : ($item[$key] ?? ''))), 0, $max);
    }
    if ($out['url'] !== '' && !preg_match('~^https?://~i', $out['url'])) $out['url'] = '';
    $until = (string)($item['until'] ?? '');
    $out['until'] = preg_match('/^\d{4}-\d{2}-\d{2}$/', $until) ? $until : '';
    return $out;
}

/** @return list<array> todos los avisos guardados (también los caducados) */
function neutron_announcements_all(): array {
    $file = neutron_announcements_file();
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return array_values(array_filter(array_map('neutron_announcement_clean', (array)($data['items'] ?? []))));
}

/** @return list<array> los vigentes: sin caducidad o hasta el final de ese día */
function neutron_announcements_active(): array {
    $today = date('Y-m-d');
    return array_values(array_filter(neutron_announcements_all(), fn($n) => $n['until'] === '' || $n['until'] >= $today));
}

function neutron_announcements_save(array $items): array {
    $clean = [];
    foreach ($items as $item) {
        $n = neutron_announcement_clean($item);
        if ($n !== null) $clean[$n['id']] = $n;            // un id repetido: vale el último
    }
    neutron_write_json(neutron_announcements_file(), ['items' => array_values($clean), 'updated_at' => date(DATE_ATOM)]);
    return array_values($clean);
}

function neutron_announcements_stats(): array {
    $file = neutron_announcements_stats_file();
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

// Suma una vista o un clic a un aviso que existe (con bloqueo: dos sesiones a la vez no se pisan)
function neutron_announcements_count(string $id, string $what): void {
    if (!in_array($what, ['views', 'clicks'], true)) return;
    if (!in_array($id, array_column(neutron_announcements_all(), 'id'), true)) return;
    $lock = @fopen(NEUTRON_DATA . '/announcements.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $stats = neutron_announcements_stats();
    $stats[$id]['views'] = (int)($stats[$id]['views'] ?? 0);
    $stats[$id]['clicks'] = (int)($stats[$id]['clicks'] ?? 0);
    $stats[$id][$what]++;
    try {
        neutron_write_json(neutron_announcements_stats_file(), $stats);
    } catch (RuntimeException $e) {
        neutron_log('warning', 'No se pudo apuntar la estadística del aviso: {error}', ['error' => $e->getMessage()]);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}
