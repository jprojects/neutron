<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Mensajes del formulario de contacto guardados en la web, para leerlos en el panel (/admin/ → Mensajes) aunque el
// correo se pierda o falle. Uno por fichero: data/messages/<AAAAMMDD-HHMMSS>-<aleatorio>.json
//   {"id", "at", "lang", "page", "fields": [{"name", "label", "value", "type"}], "read", "mailed"}
// No se guarda la IP. Se borran solos a los contact.keep_days días (protección de datos; 0 = no se guardan).

require_once __DIR__ . '/remote/state.php';

function neutron_messages_dir(): string {
    return NEUTRON_DATA . '/messages';
}

function neutron_messages_keep_days(): int {
    return max(0, (int)(neutron_config()['contact']['keep_days'] ?? 365));
}

function neutron_message_valid_id(string $id): bool {
    return (bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
}

/** Guarda un mensaje del formulario; devuelve su id, o null si no se guardan o no se ha podido */
function neutron_message_store(array $fields, ?string $lang, string $page): ?string {
    if (neutron_messages_keep_days() === 0) return null;
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $data = [
        'id' => $id,
        'at' => date(DATE_ATOM),
        'lang' => $lang,
        'page' => mb_substr($page, 0, 300),
        'fields' => array_values(array_map(fn($name, $f) => ['name' => (string)$name, 'label' => $f['label'], 'value' => $f['value'], 'type' => $f['type']],
            array_keys($fields), $fields)),
        'read' => false,
        'mailed' => null,
    ];
    try {
        neutron_write_json(neutron_messages_dir() . '/' . $id . '.json', $data);
    } catch (RuntimeException $e) {
        neutron_log('error', 'No se pudo guardar el mensaje del formulario: {error}', ['error' => $e->getMessage()]);
        return null;
    }
    neutron_messages_purge();
    return $id;
}

function neutron_message_get(string $id): ?array {
    if (!neutron_message_valid_id($id)) return null;
    $file = neutron_messages_dir() . '/' . $id . '.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : null;
}

function neutron_message_update(string $id, array $changes): bool {
    $m = neutron_message_get($id);
    if ($m === null) return false;
    try {
        neutron_write_json(neutron_messages_dir() . '/' . $id . '.json', array_merge($m, $changes));
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

function neutron_message_delete(string $id): bool {
    return neutron_message_valid_id($id) && @unlink(neutron_messages_dir() . '/' . $id . '.json');
}

/** @return list<string> ids, el más nuevo primero */
function neutron_message_ids(): array {
    $ids = array_map(fn($f) => basename($f, '.json'), glob(neutron_messages_dir() . '/*.json') ?: []);
    $ids = array_values(array_filter($ids, 'neutron_message_valid_id'));
    rsort($ids);
    return $ids;
}

/** Lista para el panel: remitente, asunto (el principio del mensaje), fecha y si está leído */
function neutron_messages_list(int $limit = 50, int $offset = 0): array {
    $out = [];
    foreach (array_slice(neutron_message_ids(), $offset, $limit) as $id) {
        $m = neutron_message_get($id);
        if ($m === null) continue;
        $out[] = ['id' => $id, 'at' => $m['at'], 'read' => !empty($m['read']), 'mailed' => $m['mailed'] ?? null] + neutron_message_summary($m);
    }
    return $out;
}

// Quién (nombre o correo) y de qué (el primer texto largo)
function neutron_message_summary(array $m): array {
    $from = $email = $text = '';
    foreach ((array)$m['fields'] as $f) {
        $v = trim((string)$f['value']);
        if ($v === '') continue;
        if ($f['type'] === 'email' && $email === '') $email = $v;
        elseif ($from === '' && preg_match('/^(nom|name|nombre|nome)/i', (string)$f['name'])) $from = $v;
        elseif ($text === '' && mb_strlen($v) > 25) $text = $v;
    }
    $text = preg_replace('/\s+/u', ' ', $text);
    return ['from' => $from !== '' ? $from : $email, 'email' => $email, 'excerpt' => mb_strlen($text) > 120 ? mb_substr($text, 0, 120) . '…' : $text];
}

function neutron_messages_unread(): int {
    $n = 0;
    foreach (neutron_message_ids() as $id) {
        $m = neutron_message_get($id);
        if ($m !== null && empty($m['read'])) $n++;
    }
    return $n;
}

// Borra los que tienen más de contact.keep_days días (por el nombre del fichero, sin abrirlos)
function neutron_messages_purge(): void {
    $days = neutron_messages_keep_days();
    if ($days === 0) return;
    $limit = date('Ymd', strtotime("-$days days"));
    foreach (neutron_message_ids() as $id) {
        if (substr($id, 0, 8) < $limit) @unlink(neutron_messages_dir() . '/' . $id . '.json');
    }
}
