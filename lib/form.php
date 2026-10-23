<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/recaptcha.php';

// Formulario de contacto genérico. Cada sitio lo describe en site/contact.json (opcional):
//
//   {
//     "recipient": "email",                    clave de content.json con el destinatario
//     "subject": "Contacto web: {name}",       {campo} = valor enviado
//     "fields": [
//       { "name": "name",    "label": "Nombre",  "required": true, "max": 200 },
//       { "name": "email",   "label": "Correo",  "required": true, "type": "email" },
//       { "name": "message", "label": "Mensaje", "required": true, "max": 5000 }
//     ],
//     "messages": { "success": "¡Gracias!", … }   textos que ve el visitante (ver neutron_form_default_messages)
//   }
//
// Sin contact.json se envían todos los campos del formulario tal cual (sin obligatorios).

const NEUTRON_FORM_RESERVED = ['website', 'g-recaptcha-response'];
const NEUTRON_FORM_MAX_FIELDS = 30;

function neutron_form_default_messages(): array {
    return [
        'sending' => 'Enviando…',
        'success' => '¡Gracias! Hemos recibido tu mensaje.',
        'invalid' => 'Revisa los campos obligatorios (*) y el correo electrónico.',
        'network' => 'Error de red. Inténtalo de nuevo.',
        'admin' => 'El formulario está desactivado en el modo edición.',
        'captcha_load' => 'No se ha podido cargar la verificación anti-spam. Recarga la página o escríbenos directamente.',
        'required' => 'Rellena todos los campos obligatorios.',
        'email' => 'El correo electrónico no es válido.',
        'rate_limited' => 'Has enviado demasiados mensajes. Inténtalo más tarde.',
        'captcha' => 'No se ha podido verificar que no eres un robot. Inténtalo de nuevo.',
        'not_configured' => 'El formulario no está configurado correctamente.',
        'send_failed' => 'No se ha podido enviar el mensaje. Escríbenos directamente a {to}.',
        'method' => 'Método no permitido.',
    ];
}

function neutron_form_definition(): array {
    $file = NEUTRON_SITE . '/contact.json';
    $def = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    if (!is_array($def)) {
        neutron_log('error', 'site/contact.json no es un JSON válido');
        $def = [];
    }
    $def += ['recipient' => 'email', 'subject' => 'Contacto web', 'fields' => [], 'messages' => []];
    $def['messages'] = array_merge(neutron_form_default_messages(), (array)$def['messages']);
    return $def;
}

// Lo que necesita assets/form.js (se inyecta en la página solo si hay un form[data-neutron-form]).
function neutron_form_client_config(): array {
    $def = neutron_form_definition();
    $client = array_intersect_key($def['messages'], array_flip(['sending', 'success', 'invalid', 'network', 'admin', 'captcha_load']));
    $config = ['messages' => $client, 'recaptcha' => null];
    if (neutron_recaptcha_enabled()) {
        $r = neutron_config()['recaptcha'];
        $config['recaptcha'] = ['siteKey' => (string)$r['site_key'], 'badge' => (string)($r['badge'] ?: 'inline')];
    }
    return $config;
}

/**
 * Campos enviados, limpios y en el orden de contact.json.
 * @return array<string,array{label:string,value:string,required:bool,type:string}>
 */
function neutron_form_fields(array $def, array $post): array {
    $fields = [];
    if ($def['fields']) {
        foreach ($def['fields'] as $f) {
            if (!is_array($f) || !is_string($f['name'] ?? null)) continue;
            $value = is_string($post[$f['name']] ?? null) ? trim($post[$f['name']]) : '';
            $fields[$f['name']] = [
                'label' => (string)($f['label'] ?? $f['name']),
                'value' => mb_substr($value, 0, (int)($f['max'] ?? 1000)),
                'required' => !empty($f['required']),
                'type' => (string)($f['type'] ?? ($f['name'] === 'email' ? 'email' : 'text')),
            ];
        }
        return $fields;
    }
    foreach ($post as $name => $value) {
        if (!is_string($name) || !is_string($value) || in_array($name, NEUTRON_FORM_RESERVED, true)) continue;
        if (!preg_match('/^[a-z0-9_-]{1,40}$/i', $name) || count($fields) >= NEUTRON_FORM_MAX_FIELDS) continue;
        $fields[$name] = [
            'label' => ucfirst(str_replace(['_', '-'], ' ', $name)),
            'value' => mb_substr(trim($value), 0, 5000),
            'required' => false,
            'type' => $name === 'email' ? 'email' : 'text',
        ];
    }
    return $fields;
}

function neutron_form_rate_limited(): bool {
    $cfg = neutron_config()['contact'];
    $max = max(1, (int)$cfg['max_per_window']);
    $window = max(60, (int)$cfg['window']);
    $ip = neutron_client_ip();
    $now = time();

    $fp = fopen(neutron_data_dir() . '/contact_throttle.json', 'c+');
    if (!$fp) return false;
    flock($fp, LOCK_EX);
    $data = json_decode((string)stream_get_contents($fp), true);
    $data = is_array($data) ? $data : [];

    foreach ($data as $key => $entry) {
        if ($now - ($entry['first'] ?? 0) > $window) unset($data[$key]);
    }
    $entry = $data[$ip] ?? ['count' => 0, 'first' => $now];
    $limited = $entry['count'] >= $max;
    if (!$limited) {
        $entry['count']++;
        $data[$ip] = $entry;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, json_encode($data));
    }
    flock($fp, LOCK_UN);
    fclose($fp);
    return $limited;
}
