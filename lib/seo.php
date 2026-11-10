<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Análisis SEO y GEO de las páginas desde el panel (/admin/ → SEO), con la API de Pulsar (config «pulsar»).
//
//   data/seo/usage.json            {"AAAA-MM-DD": n}   análisis de cada día (límite: pulsar.daily_limit)
//   data/seo/<tipo>-<página>.json  el último informe de esa página (tipo: seo | geo; página: «home» o su slug)
//   data/seo/history.json          los últimos 60 análisis: tipo, página, nota y fecha
//
// Pulsar responde nota, puntuación por categoría y problemas (fail/warn) con su solución, en catalán. Los del título
// y la descripción llevan «fix» para que el panel lleve directamente a su campo.

require_once __DIR__ . '/remote/state.php';
require_once __DIR__ . '/routing.php';
require_once __DIR__ . '/posts.php';

const NEUTRON_SEO_TYPES = ['seo', 'geo'];

/** @return array{url:string,token:string,limit:int}|null null = no configurado */
function neutron_seo_config(): ?array {
    $c = (array)(neutron_config()['pulsar'] ?? []);
    $url = rtrim(trim((string)($c['url'] ?? '')), '/');
    $token = trim((string)($c['token'] ?? ''));
    if ($url === '' || $token === '' || !preg_match('~^https?://~', $url)) return null;
    return ['url' => $url, 'token' => $token, 'limit' => max(0, (int)($c['daily_limit'] ?? 3))];
}

function neutron_seo_dir(): string {
    return NEUTRON_DATA . '/seo';
}

function neutron_seo_read(string $name, array $default = []): array {
    $file = neutron_seo_dir() . '/' . $name;
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : $default;
}

function neutron_seo_used_today(): int {
    return (int)(neutron_seo_read('usage.json')[date('Y-m-d')] ?? 0);
}

function neutron_seo_remaining(): int {
    $cfg = neutron_seo_config();
    return $cfg ? max(0, $cfg['limit'] - neutron_seo_used_today()) : 0;
}

// Página de la web → nombre del fichero del informe («home» para la portada)
function neutron_seo_page_key(string $page): string {
    return $page === '' ? 'home' : $page;
}

// Las páginas que se pueden analizar: las del panel (portada y site/pages/), con su ruta
function neutron_seo_pages(): array {
    $out = [];
    foreach (neutron_site_pages() as $p) {
        $slug = $p['href'] === './' ? '' : preg_replace('~(\.html|/)$~', '', $p['href']);
        $out[$slug] = ['page' => $slug, 'href' => $p['href'] === './' ? '' : $p['href'], 'title' => $p['title']];
    }
    return $out;
}

/** Último informe de cada página y tipo: ["seo" => ["home" => informe…], "geo" => …] */
function neutron_seo_reports(): array {
    $out = ['seo' => [], 'geo' => []];
    foreach (glob(neutron_seo_dir() . '/{seo,geo}-*.json', GLOB_BRACE) ?: [] as $file) {
        [$type, $key] = explode('-', basename($file, '.json'), 2);
        $data = json_decode((string)file_get_contents($file), true);
        if (is_array($data)) $out[$type][$key] = $data;
    }
    return $out;
}

// Problema de Pulsar → campo del panel que lo corrige (el título de la pestaña o la descripción para Google)
function neutron_seo_fix(array $issue): ?string {
    if (($issue['cat'] ?? '') !== 'meta') return null;
    $title = (string)($issue['title'] ?? '');
    if (stripos($title, 'description') !== false) return 'description';
    if (preg_match('/t[ií]tol|<title>|t[ií]tulo/iu', $title)) return 'title';
    return null;
}

/**
 * Analiza una página con Pulsar y guarda el informe. Cuenta para el límite del día solo si Pulsar responde.
 * @return array{ok:bool,error?:string,report?:array,remaining?:int}
 */
function neutron_seo_run(string $type, string $page): array {
    $cfg = neutron_seo_config();
    if ($cfg === null) return ['ok' => false, 'error' => 'El análisis con Pulsar no está configurado'];
    if (!in_array($type, NEUTRON_SEO_TYPES, true)) return ['ok' => false, 'error' => 'Tipo de análisis no válido'];
    $pages = neutron_seo_pages();
    if (!isset($pages[$page])) return ['ok' => false, 'error' => 'Esa página no existe'];

    // Bloqueo: dos análisis a la vez no se saltan el límite
    if (!is_dir(neutron_seo_dir())) @mkdir(neutron_seo_dir(), 0775, true);
    $lock = @fopen(neutron_seo_dir() . '/run.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['ok' => false, 'error' => 'Ya hay un análisis en marcha. Espera a que termine.'];
    }
    try {
        if (neutron_seo_remaining() < 1) {
            return ['ok' => false, 'error' => 'Ya has hecho los ' . $cfg['limit'] . ' análisis de hoy. Mañana podrás hacer más.'];
        }
        // Sin https:// (el cortafuegos de algunos hostings bloquea las URL en los parámetros)
        $langs = neutron_languages();
        $site = preg_replace('~^https?://~', '', neutron_site_url()) . '/' . ($langs !== null ? $langs['default'] . '/' : '') . $pages[$page]['href'];
        @set_time_limit(200);
        [$code, $body, $error] = neutron_seo_post($cfg['url'] . '/api.php', $cfg['token'], ['site' => $site, 'type' => $type]);
        $data = is_string($body) ? json_decode($body, true) : null;
        if (!is_array($data) || empty($data['ok'])) {
            $reason = $error ?: (is_array($data) ? (string)($data['error'] ?? '') : 'HTTP ' . $code);
            neutron_log('warning', 'Análisis {type} con Pulsar fallido: {reason}', ['type' => $type, 'reason' => $reason, 'page' => $site]);
            return ['ok' => false, 'error' => $reason === 'token' ? 'Pulsar no acepta el token de esta web' : 'Pulsar no ha podido hacer el análisis. Inténtalo más tarde.'];
        }

        $issues = array_map(fn($i) => [
            'id' => (string)($i['id'] ?? ''), 'cat' => (string)($i['cat'] ?? ''), 'status' => (string)($i['status'] ?? 'warn'),
            'title' => (string)($i['title'] ?? ''), 'detail' => (string)($i['detail'] ?? ''), 'solution' => (string)($i['solution'] ?? ''),
            'fix' => neutron_seo_fix($i),
        ], array_values(array_filter((array)($data['issues'] ?? []), 'is_array')));
        $report = [
            'type' => $type, 'page' => $page, 'url' => (string)($data['final_url'] ?? $data['url'] ?? $site),
            'score' => (int)($data['score'] ?? 0), 'categories' => (array)($data['categories'] ?? []),
            'issues' => $issues, 'passed' => (int)($data['passed'] ?? 0), 'total' => (int)($data['total'] ?? 0),
            'error' => $data['error'] ?? null, 'created_at' => date(DATE_ATOM),
        ];
        $key = neutron_seo_page_key($page);
        neutron_write_json(neutron_seo_dir() . '/' . $type . '-' . $key . '.json', $report);

        $usage = neutron_seo_read('usage.json');
        $usage = array_slice($usage + [date('Y-m-d') => 0], -30, null, true);   // un mes de historia
        $usage[date('Y-m-d')]++;
        neutron_write_json(neutron_seo_dir() . '/usage.json', $usage);

        $history = neutron_seo_read('history.json');
        array_unshift($history, ['type' => $type, 'page' => $page, 'score' => $report['score'], 'at' => $report['created_at']]);
        neutron_write_json(neutron_seo_dir() . '/history.json', array_slice($history, 0, 60));

        neutron_log('notice', 'Análisis {type} con Pulsar: {score}/100', ['type' => strtoupper($type), 'score' => $report['score'], 'page' => $site]);
        return ['ok' => true, 'report' => $report, 'remaining' => neutron_seo_remaining()];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** @return array{0:int,1:string|false,2:?string} [código HTTP, cuerpo, error] */
function neutron_seo_post(string $url, string $token, array $payload): array {
    $headers = ['Authorization: Bearer ' . $token, 'Content-Type: application/json', 'Accept: application/json', 'User-Agent: Neutron'];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $json, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 180, CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $body === false ? curl_error($ch) : null;
        curl_close($ch);
        return [$code, $body, $error];
    }
    $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $json,
        'timeout' => 180, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    $code = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$code, $body, $body === false ? 'Sin respuesta' : null];
}
