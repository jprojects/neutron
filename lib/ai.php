<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// IA para editar textos (admin/ai.php): mejorar, corregir, traducir y escribir la descripción o el título para Google.
// API de Anthropic (Messages) por HTTP, sin dependencias; la respuesta llega como JSON con un esquema fijo
// (output_config.format). La IA solo propone: el editor y el panel enseñan la propuesta y el cliente decide.
//
//   data/ai/usage.json   {"AAAA-MM": {"queries", "errors", "input_tokens", "output_tokens", "cost_usd"}}
//
// Mismos nombres de configuración y de estado que la IA de Quasar: Cosmo muestra el gasto del mes y avisa del límite.

require_once __DIR__ . '/remote/state.php';

const NEUTRON_AI_API = 'https://api.anthropic.com/v1/messages';
const NEUTRON_AI_VERSION = '2026-10-21';
// Si un filtro de seguridad rechaza la petición, la API la repite en el modelo que Anthropic recomienda
const NEUTRON_AI_FALLBACK_BETA = 'server-side-fallback-2026-07-01';
const NEUTRON_AI_MAX_INPUT = 12000;     // caracteres de texto que se mandan como mucho (una página entera cabe)
const NEUTRON_AI_ACTIONS = ['improve', 'fix', 'translate', 'seo_description', 'seo_title'];

function neutron_ai_config(): array {
    return (array)(neutron_config()['ai'] ?? []);
}

function neutron_ai_enabled(): bool {
    $c = neutron_ai_config();
    return !empty($c['enabled']) && trim((string)($c['api_key'] ?? '')) !== '';
}

function neutron_ai_usage(): array {
    $file = NEUTRON_DATA . '/ai/usage.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : [];
}

function neutron_ai_month(): array {
    return (neutron_ai_usage()[date('Y-m')] ?? []) + ['queries' => 0, 'errors' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
}

/** Estado del límite: used y limit en euros; state ok | warn | blocked (como Quasar) */
function neutron_ai_state(): array {
    $c = neutron_ai_config();
    $used = round((float)neutron_ai_month()['cost_usd'] * (float)($c['eur_per_usd'] ?? 0.92), 6);
    $limit = (float)($c['monthly_limit_eur'] ?? 0);
    $state = 'ok';
    if ($limit > 0 && $used >= $limit) $state = 'blocked';
    elseif ($limit > 0 && $used >= $limit * (float)($c['warn_at'] ?? 0.8)) $state = 'warn';
    return ['used' => $used, 'limit' => $limit, 'state' => $state];
}

/** Para el estado remoto (Cosmo): mismos campos que Quasar */
function neutron_ai_status(): array {
    if (!neutron_ai_enabled()) return ['enabled' => false];
    $c = neutron_ai_config();
    $m = neutron_ai_month();
    $st = neutron_ai_state();
    return ['enabled' => true, 'provider' => 'anthropic', 'model' => (string)($c['model'] ?? ''),
        'month_cost_eur' => $st['used'], 'monthly_limit_eur' => $st['limit'], 'limit_state' => $st['state'],
        'queries' => (int)$m['queries'], 'errors' => (int)$m['errors'],
        'input_tokens' => (int)$m['input_tokens'], 'output_tokens' => (int)$m['output_tokens']];
}

function neutron_ai_record(array $usage, string $model, bool $ok): void {
    $prices = (array)(neutron_ai_config()['prices'][$model] ?? []);
    $in = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0);
    $out = (int)($usage['output_tokens'] ?? 0);
    $cost = ($in * (float)($prices['input'] ?? 0) + $out * (float)($prices['output'] ?? 0)) / 1e6;
    $lock = @fopen(NEUTRON_DATA . '/ai.lock', 'c');
    if ($lock) flock($lock, LOCK_EX);
    $all = neutron_ai_usage();
    $k = date('Y-m');
    $m = ($all[$k] ?? []) + ['queries' => 0, 'errors' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
    $m['queries']++;
    if (!$ok) $m['errors']++;
    $m['input_tokens'] += $in;
    $m['output_tokens'] += $out;
    $m['cost_usd'] = round($m['cost_usd'] + $cost, 6);
    $all[$k] = $m;
    try {
        neutron_write_json(NEUTRON_DATA . '/ai/usage.json', array_slice($all, -24, null, true));   // dos años
    } catch (RuntimeException $e) {
        neutron_log('warning', 'No se pudo apuntar el consumo de IA: {error}', ['error' => $e->getMessage()]);
    }
    if ($lock) { flock($lock, LOCK_UN); fclose($lock); }
}

// Nombre del idioma para el modelo («ca» → «catalán»)
function neutron_ai_lang_name(string $code): string {
    $names = ['ca' => 'catalán', 'es' => 'castellano', 'en' => 'inglés', 'fr' => 'francés', 'de' => 'alemán', 'it' => 'italiano', 'pt' => 'portugués', 'eu' => 'euskera', 'gl' => 'gallego'];
    return $names[strtolower(substr($code, 0, 2))] ?? $code;
}

/**
 * Pide a Claude una propuesta para un texto de la web.
 * $in: action, text, lang, kind (title | text | button | description), context (nombre de la web, página, sección),
 *      to (idiomas, para translate), page (texto de la página, para seo_*)
 * @return array{ok:bool,error?:string,text?:string,translations?:array<string,string>}
 */
function neutron_ai_suggest(array $in): array {
    if (!neutron_ai_enabled()) return ['ok' => false, 'error' => 'La IA no está activada en esta web'];
    if (neutron_ai_state()['state'] === 'blocked') return ['ok' => false, 'error' => 'Se ha llegado al límite de IA de este mes. Habla con AFI para ampliarlo.'];
    $action = (string)($in['action'] ?? '');
    if (!in_array($action, NEUTRON_AI_ACTIONS, true)) return ['ok' => false, 'error' => 'Acción no válida'];

    $cut = fn($s, $n = NEUTRON_AI_MAX_INPUT) => mb_substr(trim((string)$s), 0, $n);
    $text = $cut($in['text'] ?? '');
    $lang = neutron_ai_lang_name((string)($in['lang'] ?? 'ca'));
    $kind = (string)($in['kind'] ?? 'text');
    $context = $cut($in['context'] ?? '', 300);
    $kinds = ['title' => 'un título', 'button' => 'el texto de un botón o enlace (muy corto)', 'description' => 'la descripción para Google',
        'tab' => 'el título de la pestaña del navegador', 'text' => 'un texto'];
    $what = $kinds[$kind] ?? $kinds['text'];

    $system = "Eres el redactor de una pequeña empresa que edita los textos de su propia web. Escribes en el idioma que se te pide, "
        . "con un tono natural y profesional, sin exagerar ni inventar datos (precios, años, premios, servicios) que no estén en el texto o el contexto. "
        . "Devuelves solo el texto final, sin comillas, sin explicaciones, sin Markdown ni HTML. Si el texto es corto (un título, un botón), "
        . "la propuesta también lo es.";
    $schema = ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text'], 'additionalProperties' => false];

    switch ($action) {
        case 'improve':
            if ($text === '') return ['ok' => false, 'error' => 'No hay texto que mejorar'];
            $prompt = "Mejora $what de la web en $lang: más claro y atractivo, con la misma información y una longitud parecida.\n\nContexto: $context\n\nTexto:\n$text";
            break;
        case 'fix':
            if ($text === '') return ['ok' => false, 'error' => 'No hay texto que corregir'];
            $prompt = "Corrige la ortografía, la gramática y la puntuación de este texto en $lang. No cambies el estilo ni las palabras que estén bien.\n\nTexto:\n$text";
            break;
        case 'translate':
            $to = array_values(array_filter(array_map('strval', (array)($in['to'] ?? [])), fn($l) => preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $l)));
            if ($text === '' || !$to) return ['ok' => false, 'error' => 'No hay texto o idiomas para traducir'];
            $props = [];
            foreach ($to as $l) $props[$l] = ['type' => 'string'];
            $schema = ['type' => 'object', 'properties' => ['translations' => ['type' => 'object', 'properties' => $props, 'required' => $to, 'additionalProperties' => false]],
                'required' => ['translations'], 'additionalProperties' => false];
            $list = implode(', ', array_map(fn($l) => "$l (" . neutron_ai_lang_name($l) . ')', $to));
            $prompt = "Traduce $what de la web del $lang a estos idiomas: $list. Traducción natural para una web, no literal; mantén los nombres propios.\n\nContexto: $context\n\nTexto:\n$text";
            break;
        case 'seo_description':
        case 'seo_title':
            $page = $cut($in['page'] ?? '');
            if ($page === '') return ['ok' => false, 'error' => 'La página no tiene texto'];
            $prompt = $action === 'seo_description'
                ? "Escribe la descripción para Google (meta description) de esta página en $lang: entre 120 y 155 caracteres, con lo que ofrece, dónde (si se sabe) y una invitación a entrar. Sin comillas.\n\nContexto: $context\n\nTexto actual: $text\n\nContenido de la página:\n$page"
                : "Escribe el título para Google (etiqueta <title>) de esta página en $lang: entre 35 y 60 caracteres, con la palabra clave principal al principio y el nombre de la web al final, separados por « · ».\n\nContexto: $context\n\nTítulo actual: $text\n\nContenido de la página:\n$page";
            break;
    }

    $c = neutron_ai_config();
    $model = (string)($c['model'] ?? 'claude-opus-5-5');
    $request = [
        'model' => $model,
        'max_tokens' => 4000,
        'system' => $system,
        'messages' => [['role' => 'user', 'content' => $prompt]],
        'output_config' => ['effort' => (string)($c['effort'] ?? 'low'), 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        'fallbacks' => 'default',
    ];
    [$code, $data, $error] = neutron_ai_http($request, (string)$c['api_key'], (int)($c['timeout'] ?? 60));

    if ($code !== 200 || !is_array($data)) {
        neutron_ai_record((array)($data['usage'] ?? []), $model, false);
        $type = (string)($data['error']['type'] ?? '');
        $msg = (string)($data['error']['message'] ?? $error ?? '');
        neutron_log('warning', 'IA: error {code} {type}: {message}', ['code' => $code, 'type' => $type, 'message' => mb_substr($msg, 0, 300)]);
        return ['ok' => false, 'error' => match (true) {
            $code === 401 || $type === 'authentication_error' => 'La clave de IA de esta web no es válida. Avisa a AFI.',
            $code === 429 => 'La IA está recibiendo muchas peticiones. Prueba de nuevo en un minuto.',
            $code === 400 && stripos($msg, 'credit') !== false, $code === 400 && stripos($msg, 'limit') !== false
                => 'Se ha llegado al límite de gasto de IA. Avisa a AFI.',
            $code >= 500 || $code === 0 => 'La IA no responde ahora mismo. Prueba de nuevo más tarde.',
            default => 'La IA no ha podido hacer la propuesta.',
        }];
    }

    // El modelo que respondió (puede ser el de reserva) es el que se cobra
    neutron_ai_record((array)($data['usage'] ?? []), (string)($data['model'] ?? $model), ($data['stop_reason'] ?? '') !== 'refusal');
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'error' => 'La IA no ha querido hacer esta propuesta. Escríbelo tú o cambia el texto.'];
    }
    $json = '';
    foreach ((array)($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') $json .= (string)$block['text'];
    }
    $out = json_decode($json, true);
    if (!is_array($out)) return ['ok' => false, 'error' => 'La IA ha respondido algo que no se entiende. Prueba de nuevo.'];
    if ($action === 'translate') {
        $tr = array_map(fn($v) => trim((string)$v), (array)($out['translations'] ?? []));
        return $tr ? ['ok' => true, 'translations' => $tr] : ['ok' => false, 'error' => 'La IA no ha devuelto la traducción'];
    }
    $result = trim((string)($out['text'] ?? ''), " \t\n\r\"«»");
    return $result !== '' ? ['ok' => true, 'text' => $result] : ['ok' => false, 'error' => 'La IA no ha devuelto ningún texto'];
}

// La API de Anthropic, o ai.base_url (un proxy o pasarela) + /v1/messages
function neutron_ai_url(): string {
    $base = rtrim(trim((string)(neutron_ai_config()['base_url'] ?? '')), '/');
    return $base === '' ? NEUTRON_AI_API : $base . '/v1/messages';
}

/** @return array{0:int,1:?array,2:?string} [código HTTP, respuesta JSON, error de conexión] */
function neutron_ai_http(array $request, string $key, int $timeout): array {
    $headers = ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: ' . NEUTRON_AI_VERSION,
        'anthropic-beta: ' . NEUTRON_AI_FALLBACK_BETA];
    $body = json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if (function_exists('curl_init')) {
        $ch = curl_init(neutron_ai_url());
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => max(10, $timeout), CURLOPT_CONNECTTIMEOUT => 10]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? curl_error($ch) : null;
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['method' => 'POST', 'header' => implode("\r\n", $headers), 'content' => $body,
            'timeout' => max(10, $timeout), 'ignore_errors' => true]]);
        $raw = @file_get_contents(neutron_ai_url(), false, $ctx);
        $code = isset($http_response_header[0]) && preg_match('~\s(\d{3})\s~', $http_response_header[0], $m) ? (int)$m[1] : 0;
        $error = $raw === false ? 'Sin respuesta' : null;
    }
    $data = is_string($raw) ? json_decode($raw, true) : null;
    return [$code, is_array($data) ? $data : null, $error];
}
