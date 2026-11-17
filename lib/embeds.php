<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/cookies.php';

// Vídeos y pases de fotos dentro del texto con formato (el cuerpo de las entradas, data-edit-html). lib/sanitize.php
// solo deja los bloques <figure data-embed="video|slider">; assets/embeds.js los monta en la página:
//
// - Vídeo: el reproductor de YouTube (youtube-nocookie) o Vimeo en 16:9. Si el sitio tiene categorías de cookies, el
//   iframe espera el consentimiento (data-consent): la categoría cuyo id o nombre habla de vídeos, YouTube o multimedia
//   o, si no hay ninguna así, la primera. Mientras tanto, un aviso con el botón para aceptarla.
// - Pase de fotos: las imágenes en fila, una cada vez, con flechas, contador y deslizamiento en el móvil.
//
// Se inyecta después del render (igual en los dos motores) y solo en las páginas que tienen alguno, fuera del editor.

function neutron_embeds_inject(string $html, string $lang): string {
    if (!str_contains($html, 'data-embed="')) return $html;
    $json = str_replace('</script', '<\/script', (string)json_encode(neutron_embeds_client_config($lang)));
    $head = '<link rel="stylesheet" href="assets/embeds.css">'
        . '<script type="application/json" id="neutron-embeds-config">' . $json . '</script>';
    $pos = stripos($html, '</head>');
    if ($pos !== false) $html = substr($html, 0, $pos) . $head . substr($html, $pos);
    $js = '<script src="assets/embeds.js" defer></script>';
    $pos = strripos($html, '</body>');
    return $pos === false ? $html . $js : substr($html, 0, $pos) . $js . substr($html, $pos);
}

/** Configuración para assets/embeds.js: la categoría de cookies de los vídeos (null = no hace falta) y los textos */
function neutron_embeds_client_config(string $lang): array {
    $texts = [
        'ca' => ['video' => 'Aquest vídeo és d\'un servei extern que fa servir cookies ({category}).', 'accept' => 'Acceptar i veure el vídeo',
            'title' => 'Vídeo', 'prev' => 'Foto anterior', 'next' => 'Foto següent'],
        'es' => ['video' => 'Este vídeo es de un servicio externo que usa cookies ({category}).', 'accept' => 'Aceptar y ver el vídeo',
            'title' => 'Vídeo', 'prev' => 'Foto anterior', 'next' => 'Foto siguiente'],
        'en' => ['video' => 'This video is hosted by an external service that uses cookies ({category}).', 'accept' => 'Accept and watch the video',
            'title' => 'Video', 'prev' => 'Previous photo', 'next' => 'Next photo'],
        'fr' => ['video' => 'Cette vidéo provient d\'un service externe qui utilise des cookies ({category}).', 'accept' => 'Accepter et voir la vidéo',
            'title' => 'Vidéo', 'prev' => 'Photo précédente', 'next' => 'Photo suivante'],
    ];
    // La que habla de vídeos o, si ninguna, la primera
    $categories = neutron_cookies_client_config()['categories'] ?? [];
    $category = $categories[0] ?? null;
    foreach ($categories as $c) {
        if (preg_match('/v[ií]d[eé]o|youtube|vimeo|media|multim/iu', $c['id'] . ' ' . $c['name'])) { $category = $c; break; }
    }
    return [
        'consent' => $category === null ? null : ['id' => $category['id'], 'name' => $category['name']],
        'texts' => $texts[substr(strtolower($lang), 0, 2)] ?? $texts['es'],
    ];
}
