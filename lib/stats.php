<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Visitas para el inicio del panel, sin cookies ni servicios externos (no piden consentimiento): cada página vista por
// una persona (no los robots, el editor ni las precargas) es una línea en data/stats/AAAA-MM-DD.log con la página, una
// huella del visitante de ese día (sha1 de una sal del día + IP + navegador, con la sal al azar que se borra al acabar el
// día), la hora, el país (lib/geoip.php, sin salir del servidor), el navegador, el tipo de dispositivo y la IP
// anonimizada (sin el último bloque: 83.45.120.0, 2a01:4f8:1::). La IP completa no se guarda nunca.
// Al día siguiente, el registro se resume en data/stats/days.json (vistas, visitantes, páginas más vistas, países,
// navegadores y dispositivos; 400 días) y pasa a AAAA-MM-DD.visits, la lista de «Últimas visitas», que se borra a los
// 30 días. Config «stats»: enabled.

const NEUTRON_STATS_KEEP_DAYS = 400;
const NEUTRON_STATS_VISITS_DAYS = 30;
const NEUTRON_STATS_BOTS = '/bot|crawl|spider|slurp|bing|yandex|baidu|duckduck|facebookexternalhit|embedly|preview|monitor|uptime|pingdom|curl|wget|python|go-http|java\/|headless|lighthouse|pulsar|neutron/i';

function neutron_stats_dir(): string {
    return NEUTRON_DATA . '/stats';
}

function neutron_stats_enabled(): bool {
    return (neutron_config()['stats']['enabled'] ?? true) !== false;
}

/** Navegador por el User-Agent (el orden importa: Edge y Opera también dicen «Chrome», y Chrome dice «Safari») */
function neutron_stats_browser(string $ua): string {
    foreach ([
        'Edge' => '~\bEdg(e|A|iOS)?/~', 'Opera' => '~\b(OPR|Opera)\b~', 'Samsung Internet' => '~SamsungBrowser/~',
        'Firefox' => '~\b(Firefox|FxiOS)/~', 'Chrome' => '~\b(Chrome|CriOS)/~', 'Safari' => '~Version/[\d.]+.*Safari/~',
    ] as $name => $re) {
        if (preg_match($re, $ua)) return $name;
    }
    return 'Otro';
}

/** 'mobile', 'tablet' o 'desktop' (los iPad recientes se presentan como un Mac: cuentan como ordenador) */
function neutron_stats_device(string $ua): string {
    if (preg_match('~iPad|Tablet|PlayBook|Silk/|Kindle|(Android(?!.*Mobile))~i', $ua)) return 'tablet';
    if (preg_match('~Mobi|iPhone|iPod|Android|Windows Phone|BlackBerry|Opera Mini~i', $ua)) return 'mobile';
    return 'desktop';
}

/** La IP sin su último bloque: 83.45.120.7 → 83.45.120.0; en IPv6, los primeros 48 bits (2a01:4f8:1::) */
function neutron_stats_ip_anon(string $ip): string {
    $bin = @inet_pton($ip);
    if ($bin === false) return '';
    $bin = strlen($bin) === 4 ? substr($bin, 0, 3) . "\0" : substr($bin, 0, 6) . str_repeat("\0", 10);
    return (string)@inet_ntop($bin);
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
    require_once __DIR__ . '/geoip.php';
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $who = substr(sha1($salt . '|' . $ip . '|' . $ua), 0, 12);
    $path = '/' . ltrim(mb_substr(neutron_request_path(), 0, 200), '/');
    $line = [$path, $who, date('H:i:s'), neutron_geoip_country($ip), neutron_stats_browser($ua), neutron_stats_device($ua), neutron_stats_ip_anon($ip)];
    $line = array_map(fn($v) => str_replace(["\t", "\n", "\r"], ' ', (string)$v), $line);
    @file_put_contents($dir . '/' . $day . '.log', implode("\t", $line) . "\n", FILE_APPEND | LOCK_EX);
}

/** Una línea del registro (las de antes de 1.33.0 solo traen página y huella) */
function neutron_stats_line(string $line): array {
    [$path, $who, $time, $country, $browser, $device, $ip] = array_pad(explode("\t", $line, 7), 7, '');
    return compact('path', 'who', 'time', 'country', 'browser', 'device', 'ip');
}

/** Resume un día: vistas, visitantes, páginas (las 30 más vistas) y, por visitante, países, navegadores y dispositivos */
function neutron_stats_day(string $file): array {
    $views = 0; $who = []; $pages = []; $countries = []; $browsers = []; $devices = [];
    foreach (@file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $v = neutron_stats_line($line);
        $views++;
        $pages[$v['path']] = ($pages[$v['path']] ?? 0) + 1;
        if (isset($who[$v['who']])) continue;
        $who[$v['who']] = true;
        if ($v['browser'] === '') continue;                      // línea antigua
        $cc = $v['country'] !== '' ? $v['country'] : '??';
        $countries[$cc] = ($countries[$cc] ?? 0) + 1;
        $browsers[$v['browser']] = ($browsers[$v['browser']] ?? 0) + 1;
        $devices[$v['device']] = ($devices[$v['device']] ?? 0) + 1;
    }
    arsort($pages); arsort($countries); arsort($browsers); arsort($devices);
    return ['views' => $views, 'visitors' => count($who), 'pages' => array_slice($pages, 0, 30, true),
        'countries' => array_slice($countries, 0, 30, true), 'browsers' => $browsers, 'devices' => $devices];
}

/** Resume los días ya terminados (su registro pasa a .visits) y borra las sales y las visitas viejas */
function neutron_stats_close_days(): array {
    $dir = neutron_stats_dir();
    $sumFile = $dir . '/days.json';
    $sum = is_file($sumFile) ? (json_decode((string)file_get_contents($sumFile), true) ?: []) : [];
    $today = date('Y-m-d');
    $changed = false;
    foreach (glob($dir . '/*.log') ?: [] as $f) {
        $d = basename($f, '.log');
        if ($d >= $today || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) continue;
        $sum[$d] = neutron_stats_day($f);
        if (!@rename($f, $dir . '/' . $d . '.visits')) @unlink($f);
        $changed = true;
    }
    $oldest = date('Y-m-d', strtotime('-' . NEUTRON_STATS_VISITS_DAYS . ' days'));
    foreach (glob($dir . '/*.visits') ?: [] as $f) {
        if (basename($f, '.visits') < $oldest) @unlink($f);
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
    return $sum;
}

/**
 * Los últimos $days días (los cerrados resumidos en days.json; hoy, en directo). Países, navegadores y dispositivos
 * cuentan visitantes (los días anteriores a 1.33.0 no los tienen).
 */
function neutron_stats_summary(int $days = 30): array {
    $dir = neutron_stats_dir();
    $sum = neutron_stats_close_days();
    $today = date('Y-m-d');
    $empty = ['views' => 0, 'visitors' => 0, 'pages' => []];
    $live = is_file($dir . '/' . $today . '.log') ? neutron_stats_day($dir . '/' . $today . '.log') : $empty;
    $out = ['days' => [], 'views' => 0, 'visitors' => 0, 'pages' => [], 'countries' => [], 'browsers' => [], 'devices' => []];
    for ($i = $days - 1; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $row = $d === $today ? $live : ($sum[$d] ?? $empty);
        $out['days'][$d] = ['views' => (int)$row['views'], 'visitors' => (int)$row['visitors']];
        $out['views'] += (int)$row['views'];
        $out['visitors'] += (int)$row['visitors'];
        foreach (['pages', 'countries', 'browsers', 'devices'] as $k) {
            foreach ((array)($row[$k] ?? []) as $name => $n) $out[$k][$name] = ($out[$k][$name] ?? 0) + (int)$n;
        }
    }
    foreach (['pages' => 8, 'countries' => 8, 'browsers' => 6, 'devices' => 3] as $k => $max) {
        arsort($out[$k]);
        $out[$k] = array_slice($out[$k], 0, $max, true);
    }
    $out['recent'] = neutron_stats_visits(0, 10)['items'];
    return $out;
}

/**
 * Las visitas una a una, de la más reciente a la más antigua (hoy y los 30 días anteriores)
 * @return array{items:list<array{date:string,time:string,path:string,country:string,browser:string,device:string,ip:string}>,more:bool}
 */
function neutron_stats_visits(int $offset = 0, int $limit = 50): array {
    $dir = neutron_stats_dir();
    $files = array_merge(glob($dir . '/*.log') ?: [], glob($dir . '/*.visits') ?: []);
    usort($files, fn($a, $b) => strcmp(basename($b), basename($a)));
    $items = []; $skip = max(0, $offset); $more = false;
    foreach ($files as $f) {
        $date = substr(basename($f), 0, 10);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) continue;
        foreach (array_reverse(@file($f, FILE_IGNORE_NEW_LINES) ?: []) as $line) {
            $v = neutron_stats_line($line);
            if ($v['time'] === '') continue;                     // línea antigua, sin hora
            if ($skip > 0) { $skip--; continue; }
            if (count($items) >= $limit) { $more = true; break 2; }
            unset($v['who']);
            $items[] = ['date' => $date] + $v;
        }
    }
    return ['items' => $items, 'more' => $more];
}
