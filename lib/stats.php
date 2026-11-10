<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Visitas para el inicio del panel, sin cookies ni servicios externos (no piden consentimiento): cada página vista por
// una persona (no los robots, el editor ni las precargas) es una línea en data/stats/AAAA-MM-DD.log con la página y una
// huella del visitante de ese día: sha1(sal del día + IP + navegador), con una sal al azar que se borra al acabar el día.
// La IP no se guarda nunca. Al día siguiente, el registro se resume en data/stats/days.json (vistas, visitantes y páginas
// más vistas) y se borra. Config «stats»: enabled.

const NEUTRON_STATS_KEEP_DAYS = 400;
const NEUTRON_STATS_BOTS = '/bot|crawl|spider|slurp|bing|yandex|baidu|duckduck|facebookexternalhit|embedly|preview|monitor|uptime|pingdom|curl|wget|python|go-http|java\/|headless|lighthouse|pulsar|neutron/i';

function neutron_stats_dir(): string {
    return NEUTRON_DATA . '/stats';
}

function neutron_stats_enabled(): bool {
    return (neutron_config()['stats']['enabled'] ?? true) !== false;
}

/** Cuenta la página que se acaba de servir (llamar solo con un 200 de HTML) */
function neutron_stats_hit(): void {
    if (!neutron_stats_enabled() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;
    if (isset($_GET['admin']) || isset($_COOKIE['neutron_admin_sid'])) return;
    $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($ua === '' || preg_match(NEUTRON_STATS_BOTS, $ua)) return;
    $purpose = strtolower((string)($_SERVER['HTTP_SEC_PURPOSE'] ?? $_SERVER['HTTP_PURPOSE'] ?? ''));
    if (str_contains($purpose, 'prefetch')) return;
    $dir = neutron_stats_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return;
    $day = date('Y-m-d');
    $saltFile = $dir . '/salt-' . $day;
    $salt = @file_get_contents($saltFile);
    if (!$salt) {
        $salt = bin2hex(random_bytes(16));
        @file_put_contents($saltFile, $salt, LOCK_EX);
    }
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $who = substr(sha1($salt . '|' . $ip . '|' . $ua), 0, 12);
    $path = '/' . ltrim(mb_substr(neutron_request_path(), 0, 200), '/');
    @file_put_contents($dir . '/' . $day . '.log', str_replace(["\t", "\n"], ' ', $path) . "\t" . $who . "\n", FILE_APPEND | LOCK_EX);
}

/** Resume un día cerrado: vistas, visitantes y páginas (las 30 más vistas) */
function neutron_stats_day(string $file): array {
    $views = 0; $who = []; $pages = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        [$path, $hash] = array_pad(explode("\t", $line, 2), 2, '');
        $views++; $who[$hash] = true; $pages[$path] = ($pages[$path] ?? 0) + 1;
    }
    arsort($pages);
    return ['views' => $views, 'visitors' => count($who), 'pages' => array_slice($pages, 0, 30, true)];
}

/**
 * Los últimos $days días (los cerrados resumidos en days.json; hoy, en directo).
 * @return array{days:array<string,array{views:int,visitors:int}>,views:int,visitors:int,pages:array<string,int>}
 */
function neutron_stats_summary(int $days = 30): array {
    $dir = neutron_stats_dir();
    $sumFile = $dir . '/days.json';
    $sum = is_file($sumFile) ? (json_decode((string)file_get_contents($sumFile), true) ?: []) : [];
    $today = date('Y-m-d');
    $changed = false;
    foreach (glob($dir . '/*.log') ?: [] as $f) {                   // días que ya han terminado: se resumen y se borran
        $d = basename($f, '.log');
        if ($d >= $today || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $sum[$d] = neutron_stats_day($f);
        @unlink($f);
        $changed = true;
    }
    foreach (glob($dir . '/salt-*') ?: [] as $f) {
        if (substr(basename($f), 5) < $today) @unlink($f);
    }
    if ($changed) {
        ksort($sum);
        $sum = array_slice($sum, -NEUTRON_STATS_KEEP_DAYS, null, true);
        require_once __DIR__ . '/remote/state.php';
        try { neutron_write_json($sumFile, $sum); } catch (RuntimeException) {}
    }
    $live = is_file($dir . '/' . $today . '.log') ? neutron_stats_day($dir . '/' . $today . '.log') : ['views' => 0, 'visitors' => 0, 'pages' => []];
    $out = ['days' => [], 'views' => 0, 'visitors' => 0, 'pages' => []];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $row = $d === $today ? $live : ($sum[$d] ?? ['views' => 0, 'visitors' => 0, 'pages' => []]);
        $out['days'][$d] = ['views' => (int)$row['views'], 'visitors' => (int)$row['visitors']];
        $out['views'] += (int)$row['views'];
        $out['visitors'] += (int)$row['visitors'];
        foreach ((array)$row['pages'] as $p => $n) $out['pages'][$p] = ($out['pages'][$p] ?? 0) + $n;
    }
    arsort($out['pages']);
    $out['pages'] = array_slice($out['pages'], 0, 8, true);
    return $out;
}
