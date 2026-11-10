<?php
define('NEUTRON_APP', true);

require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/lib/content.php';
require_once __DIR__ . '/lib/form.php';
require_once __DIR__ . '/lib/messages.php';

// Recibe los formularios con data-neutron-form (lo envía assets/form.js) y los manda por correo.
// Protecciones: honeypot "website", reCAPTCHA invisible (si está configurado) y límite por IP.

neutron_block_if_maintenance(true);

// Varios idiomas: los mensajes y el destinatario en el idioma de la página (lo manda assets/form.js)
$langs = neutron_languages();
if ($langs !== null) {
    $postLang = (string)($_POST['_lang'] ?? '');
    neutron_lang(isset($langs['languages'][$postLang]) ? $postLang : $langs['default']);
}

$def = neutron_form_definition();
$msg = $def['messages'];

function contact_reply(bool $ok, string $error = '', int $status = 200): void {
    neutron_json($ok ? ['ok' => true] : ['ok' => false, 'error' => $error], $status);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    contact_reply(false, $msg['method'], 405);
}

// Honeypot: los bots rellenan el campo oculto; les respondemos "ok" sin enviar nada.
if (trim((string)($_POST['website'] ?? '')) !== '') {
    neutron_log('info', 'Formulario descartado por el honeypot');
    contact_reply(true);
}

$fields = neutron_form_fields($def, $_POST);
foreach ($fields as $f) {
    if ($f['required'] && $f['value'] === '') {
        contact_reply(false, $msg['required'], 422);
    }
    if ($f['type'] === 'email' && $f['value'] !== '' && !filter_var($f['value'], FILTER_VALIDATE_EMAIL)) {
        contact_reply(false, $msg['email'], 422);
    }
}
if (!array_filter($fields, fn($f) => $f['value'] !== '')) {
    contact_reply(false, $msg['required'], 422);
}

if (neutron_form_rate_limited()) {
    neutron_log('warning', 'Formulario: límite de envíos por IP');
    contact_reply(false, $msg['rate_limited'], 429);
}

if (neutron_recaptcha_enabled()) {
    $check = neutron_recaptcha_verify((string)($_POST['g-recaptcha-response'] ?? ''), neutron_client_ip());
    if (!$check['ok']) {
        neutron_log('warning', 'Formulario rechazado por reCAPTCHA: {reason}', ['reason' => $check['error']]);
        contact_reply(false, $msg['captcha'], 422);
    }
}

// Se guarda antes de enviarlo: si el correo falla, el mensaje está igualmente en el panel (Mensajes)
$page = (string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?? '');
$stored = neutron_message_store($fields, neutron_lang(), $page);

// Destinatario: mail.to de config.local.php, o el campo editable de content.json ("email")
$to = (string)neutron_config()['mail']['to'];
if ($to === '') {
    $to = (string)(neutron_load_content()[$def['recipient']] ?? '');
}
if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
    neutron_log('error', 'Formulario sin destinatario válido (mail.to o content.json → {key})' . ($stored !== null ? ': el mensaje está solo en el panel' : ''), ['key' => $def['recipient']]);
    if ($stored === null) contact_reply(false, $msg['not_configured'], 500);
    neutron_message_update($stored, ['mailed' => false]);
    contact_reply(true);
}

$oneLine = fn(string $s) => trim(str_replace(["\r", "\n"], ' ', $s));


$body = "Nuevo mensaje desde el formulario de " . ($_SERVER['HTTP_HOST'] ?? 'la web') . "\n\n";
$replyTo = '';
foreach ($fields as $name => $f) {
    if ($f['value'] === '') continue;
    $body .= str_contains($f['value'], "\n")
        ? "{$f['label']}:\n{$f['value']}\n\n"
        : "{$f['label']}: {$f['value']}\n";
    if ($f['type'] === 'email' && $replyTo === '') $replyTo = $f['value'];
}

$subject = preg_replace_callback('/\{([a-z0-9_-]+)\}/i', fn($m) => $oneLine($fields[$m[1]]['value'] ?? ''), (string)$def['subject']);
$host = preg_replace('/[^a-z0-9.-]/i', '', $_SERVER['HTTP_HOST'] ?? 'localhost');
$from = (string)neutron_config()['mail']['from'] ?: 'no-reply@' . preg_replace('/^www\./', '', $host);
$headers = [
    'From' => 'Web <' . $oneLine($from) . '>',
    'Content-Type' => 'text/plain; charset=UTF-8',
];
if ($replyTo !== '') $headers['Reply-To'] = $oneLine($replyTo);

$mailed = @mail($to, '=?UTF-8?B?' . base64_encode($oneLine($subject)) . '?=', $body, $headers);
if ($stored !== null) neutron_message_update($stored, ['mailed' => $mailed]);
if (!$mailed) {
    neutron_log('error', 'Formulario: mail() ha fallado al enviar a {to}' . ($stored !== null ? ' (el mensaje está en el panel)' : ''), ['to' => $to]);
    // Guardado en el panel: para el visitante el mensaje ha llegado
    if ($stored === null) contact_reply(false, str_replace('{to}', $to, $msg['send_failed']), 500);
}
neutron_log('info', 'Formulario recibido' . ($mailed ? ' y enviado a {to}' : ''), ['to' => $to, 'fields' => count($fields), 'message' => $stored]);
contact_reply(true);
