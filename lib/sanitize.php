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
    $clean = neutron_sanitize_children($root->children ?? []);

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
function neutron_sanitize_children(array $children): array {
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

// Enlace permitido o null. Se quitan espacios y caracteres de control antes de mirar el esquema
// ("java\tscript:" sigue siendo javascript:).
function neutron_sanitize_href(string $href): ?string {
    $href = trim($href);
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
    $inner = '';
    foreach ($node->children as $child) $inner .= neutron_sanitize_serialize($child);
    // Elementos vacíos que solo ensucian (un <strong></strong> que deja el editor); las celdas vacías se quedan
    if (trim(strip_tags($inner, '<img><br>')) === '' && !str_contains($inner, '<img') && !in_array($node->tag, ['td', 'th', 'tr'], true)) return '';
    return '<' . $node->tag . $attrs . '>' . $inner . '</' . $node->tag . '>';
}
