<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/render.php';

// Limpieza del texto con formato (el cuerpo de las entradas y los campos data-edit-html) con una lista blanca. Se aplica al guardar y otra
// vez al pintar: nunca se confía en lo que manda el navegador.
//
// - Etiquetas permitidas: p, h2, h3, strong, em, a, ul, ol, li, blockquote, img, br, code y tablas (table, thead, tbody, tr, th, td).
// - Se convierten: b → strong, i → em, h1 → h2, h4-h6 → h3, div → p.
// - Se eliminan con todo su contenido: script, style, iframe, formularios, svg…
// - El resto (span, font, section…) se quita dejando su contenido.
// - Atributos: solo href en <a> (http, https, mailto, tel o rutas relativas) y src + alt en <img> (solo imágenes
//   del propio sitio: site/uploads/… o site/assets/…). Ningún style, class ni on….
// - El texto suelto en la raíz se mete en párrafos.
// - Bloques (solo en la raíz; los monta assets/embeds.js en la web):
//     <figure data-embed="video" data-src="https://www.youtube-nocookie.com/embed/ID"></figure>   un vídeo de YouTube o Vimeo
//     <figure data-embed="slider"><img src="site/uploads/…" alt="…">…</figure>                     un pase de fotos
//   Del vídeo solo cuenta la dirección (se normaliza con neutron_video_embed); del pase, sus imágenes (las de dentro, a
//   cualquier profundidad: lo que añade el editor alrededor se descarta).

const NEUTRON_SANITIZE_ALLOWED = ['p', 'h2', 'h3', 'strong', 'em', 'a', 'ul', 'ol', 'li', 'blockquote', 'img', 'br', 'code',
    'table', 'thead', 'tbody', 'tr', 'th', 'td'];
const NEUTRON_SANITIZE_RENAME = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'];
const NEUTRON_SANITIZE_DROP = ['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'form', 'input',
    'button', 'select', 'option', 'textarea', 'svg', 'math', 'template', 'noscript', 'head', 'title', 'meta', 'link', 'base',
    'video', 'audio', 'source', 'track', 'canvas', 'picture', 'map', 'area'];
const NEUTRON_SANITIZE_INLINE = ['strong', 'em', 'a', 'br', 'img', 'code'];
const NEUTRON_SANITIZE_MAX = 200000;

function neutron_sanitize_html(string $html): string {
    $html = substr($html, 0, NEUTRON_SANITIZE_MAX);
    $root = neutron_parse_html($html);
    $clean = neutron_sanitize_children($root->children ?? [], true);

    // Texto e inline sueltos en la raíz → párrafos
    $out = '';
    $run = '';
    foreach ($clean as $node) {
        $isInline = $node->type === 'text' || in_array($node->tag ?? '', NEUTRON_SANITIZE_INLINE, true);
        if ($isInline) {
            $run .= neutron_sanitize_serialize($node);
            continue;
        }
        if (trim(strip_tags($run, '<img>')) !== '') $out .= '<p>' . trim($run) . '</p>';
        $run = '';
        $out .= neutron_sanitize_serialize($node);
    }
    if (trim(strip_tags($run, '<img>')) !== '') $out .= '<p>' . trim($run) . '</p>';
    return $out;
}

/** @return list<object> nodos limpios (texto y elementos permitidos) */
function neutron_sanitize_children(array $children, bool $root = false): array {
    $out = [];
    foreach ($children as $node) {
        $type = $node->type ?? '';
        if ($type === 'text') {
            if ($node->text !== '') $out[] = (object)['type' => 'text', 'text' => $node->text];
            continue;
        }
        if ($type !== 'element') continue; // comentarios, doctype, contenido de script/style
        $tag = strtolower((string)$node->tag);
        if (in_array($tag, NEUTRON_SANITIZE_DROP, true)) continue;
        if ($tag === 'figure') {
            // Solo los bloques conocidos y en la raíz; una figura cualquiera deja su contenido
            $embed = $root ? neutron_sanitize_embed($node) : null;
            if ($embed !== null) $out[] = $embed;
            elseif (!isset($node->attrs['data-embed'])) array_push($out, ...neutron_sanitize_children($node->children ?? []));
            continue;
        }
        $tag = NEUTRON_SANITIZE_RENAME[$tag] ?? $tag;
        $kids = neutron_sanitize_children($node->children ?? []);
        // Un <div> que envuelve bloques (una tabla, párrafos) se quita; el que solo lleva texto pasa a <p>
        if ($tag === 'p' && strtolower((string)$node->tag) === 'div' && array_filter($kids, fn($k) => $k->type === 'element'
            && !in_array($k->tag, NEUTRON_SANITIZE_INLINE, true))) {
            array_push($out, ...$kids);
            continue;
        }
        if (!in_array($tag, NEUTRON_SANITIZE_ALLOWED, true)) {
            array_push($out, ...$kids); // se quita la etiqueta, se queda el contenido
            continue;
        }
        $attrs = [];
        if ($tag === 'a') {
            $href = neutron_sanitize_href((string)($node->attrs['href'] ?? ''));
            if ($href === null) { array_push($out, ...$kids); continue; }
            $attrs['href'] = $href;
            if (preg_match('~^https?://~i', $href)) $attrs['rel'] = 'noopener';
        } elseif ($tag === 'img') {
            $src = (string)($node->attrs['src'] ?? '');
            if (!preg_match('~^site/(uploads|assets)/[A-Za-z0-9._/-]+$~', $src) || str_contains($src, '..')) continue;
            $attrs['src'] = $src;
            $attrs['alt'] = mb_substr(trim((string)($node->attrs['alt'] ?? '')), 0, 300);
        }
        $out[] = (object)['type' => 'element', 'tag' => $tag, 'attrs' => $attrs, 'children' => $kids];
    }
    return $out;
}

// Bloque de vídeo o pase de fotos limpio, o null si no es válido
function neutron_sanitize_embed(object $node): ?object {
    $kind = (string)($node->attrs['data-embed'] ?? '');
    if ($kind === 'video') {
        $src = neutron_video_embed((string)($node->attrs['data-src'] ?? ''));
        return $src === null ? null : (object)['type' => 'element', 'tag' => 'figure', 'attrs' => ['data-embed' => 'video', 'data-src' => $src], 'children' => []];
    }
    if ($kind === 'slider') {
        $imgs = array_values(array_filter(neutron_sanitize_children(neutron_sanitize_find_imgs($node)), fn($n) => ($n->tag ?? '') === 'img'));
        return $imgs ? (object)['type' => 'element', 'tag' => 'figure', 'attrs' => ['data-embed' => 'slider'], 'children' => $imgs] : null;
    }
    return null;
}

/** @return list<object> las <img> de dentro de un nodo, a cualquier profundidad */
function neutron_sanitize_find_imgs(object $node): array {
    $out = [];
    foreach ($node->children ?? [] as $child) {
        if (($child->type ?? '') !== 'element') continue;
        if (strtolower((string)$child->tag) === 'img') $out[] = $child;
        else array_push($out, ...neutron_sanitize_find_imgs($child));
    }
    return $out;
}

// https://www.youtube.com/watch?v=ID, youtu.be/ID… → https://www.youtube-nocookie.com/embed/ID (Vimeo, igual). Si no
// se reconoce, null.
function neutron_video_embed(string $url): ?string {
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return null;
}

// Enlace permitido o null. Se quitan espacios y caracteres de control antes de mirar el esquema
// ("java\tscript:" sigue siendo javascript:).
function neutron_sanitize_href(string $href): ?string {
    // Espacios codificados (%20): DOMDocument los codifica al escribir el href y el motor sin DOM no; así coinciden
    $href = str_replace(' ', '%20', trim($href));
    $probe = preg_replace('/[\x00-\x20]+/', '', $href);
    if ($href === '' || str_starts_with($probe, '//')) return null;
    if (preg_match('~^([a-z][a-z0-9+.-]*):~i', $probe, $m)) {
        return in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true) ? $href : null;
    }
    return $href; // relativo: otra página o una sección (#…)
}

function neutron_sanitize_serialize(object $node): string {
    if ($node->type === 'text') {
        return htmlspecialchars($node->text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $attrs = '';
    foreach ($node->attrs as $k => $v) {
        $attrs .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '"';
    }
    if ($node->tag === 'img' || $node->tag === 'br') return '<' . $node->tag . $attrs . '>';
    if ($node->tag === 'figure' && ($node->attrs['data-embed'] ?? '') === 'video') return '<figure' . $attrs . '></figure>';
    $inner = '';
    foreach ($node->children as $child) $inner .= neutron_sanitize_serialize($child);
    // Elementos vacíos que solo ensucian (un <strong></strong> que deja el editor); las celdas vacías se quedan
    if (trim(strip_tags($inner, '<img><br>')) === '' && !str_contains($inner, '<img') && !in_array($node->tag, ['td', 'th', 'tr'], true)) return '';
    return '<' . $node->tag . $attrs . '>' . $inner . '</' . $node->tag . '>';
}
