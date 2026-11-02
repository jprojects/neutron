<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Faldón de cookies del núcleo (RGPD + art. 22.2 LSSI). Se activa si el sitio tiene site/cookies.json:
//
//   {
//     "policy": "politica-de-cookies.html",          enlace a la política del sitio
//     "categories": [                                  las que necesitan consentimiento (las técnicas no van aquí)
//       { "id": "media", "name": "Multimèdia (YouTube)", "description": "Permeten reproduir…" }
//     ],
//     "texts": { "title": "…", "text": "… {policy} …", … },   en el idioma de la web (ver neutron_cookies_default_texts)
//     "accent": "#1a9c00",                             color de los botones (opcional)
//     "cookie": "neutron_consent",                     nombre de la cookie que guarda la elección (opcional)
//     "version": 1                                     subirla vuelve a preguntar a todo el mundo
//   }
//
// Sin categorías (solo cookies técnicas) no se muestra faldón: la ley no lo pide. En la página:
//   <iframe data-consent="media" data-consent-src="https://…">   se carga al aceptar "media"
//   <script type="text/plain" data-consent="analytics">…</script> se ejecuta al aceptar "analytics" (también con src)
//   <button data-cookie-accept="media">…</button>                  acepta esa categoría (p. ej. en el aviso de un vídeo)
//   <button data-cookie-settings>…</button>                        vuelve a abrir el faldón con las opciones

function neutron_cookies_default_texts(): array {
    return [
        'title' => 'Este sitio web utiliza cookies',
        'text' => 'Usamos cookies técnicas, necesarias para que la web funcione, y, solo si las aceptas, cookies de terceros. Puedes aceptarlas, rechazarlas o elegir cuáles quieres. Más información en la {policy}.',
        'policy' => 'Política de cookies',
        'accept' => 'Aceptar todas',
        'reject' => 'Rechazar',
        'configure' => 'Configurar',
        'save' => 'Guardar selección',
        'technical' => 'Técnicas (siempre activas)',
        'technical_description' => 'Imprescindibles para que la web funcione y para recordar tu elección sobre las cookies.',
        'label' => 'Aviso de cookies',
    ];
}

// Configuración del faldón para cookies.js, o null si el sitio no tiene site/cookies.json.
function neutron_cookies_client_config(): ?array {
    $file = NEUTRON_SITE . '/cookies.json';
    if (!is_file($file)) return null;
    $def = json_decode((string)file_get_contents($file), true);
    if (!is_array($def)) {
        neutron_log('error', 'site/cookies.json no es un JSON válido');
        return null;
    }
    $categories = [];
    foreach ((array)($def['categories'] ?? []) as $c) {
        if (!is_array($c) || !preg_match('/^[a-z0-9_-]{1,32}$/', (string)($c['id'] ?? ''))) continue;
        $categories[] = [
            'id' => (string)$c['id'],
            'name' => (string)($c['name'] ?? $c['id']),
            'description' => (string)($c['description'] ?? ''),
        ];
    }
    $accent = (string)($def['accent'] ?? '');
    return [
        'cookie' => preg_match('/^[A-Za-z0-9_-]{1,40}$/', (string)($def['cookie'] ?? '')) ? $def['cookie'] : 'neutron_consent',
        'version' => max(1, (int)($def['version'] ?? 1)),
        'policy' => (string)($def['policy'] ?? ''),
        'accent' => preg_match('/^#[0-9a-f]{3,8}$/i', $accent) ? $accent : '',
        'categories' => $categories,
        'texts' => array_merge(neutron_cookies_default_texts(), array_filter((array)($def['texts'] ?? []), 'is_string')),
    ];
}
