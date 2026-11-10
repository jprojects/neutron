<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Qué se puede editar en la web, sacado de las plantillas (para el panel, admin/index.php): por página y sección, cada
// campo con su tipo, una etiqueta para el cliente y el valor de la plantilla. No necesita DOMDocument (usa el parser del
// motor sin DOM). Las partes comunes (site/partials/: cabecera, pie) van aparte, una vez, en «Común a todas las páginas».

require_once __DIR__ . '/render.php';
require_once __DIR__ . '/render_fallback.php';
require_once __DIR__ . '/routing.php';

const NEUTRON_SCHEMA_SECTION_TAGS = ['section', 'header', 'footer', 'main', 'article', 'aside', 'nav', 'form'];

// Etiqueta por defecto según la etiqueta HTML del campo
function neutron_schema_label(object $node, string $kind): string {
    if ($kind === 'img') return 'Imagen';
    if ($kind === 'href') return 'Enlace';
    if ($kind === 'html') return 'Texto con formato';
    $tag = $node->tag ?? '';
    if (in_array($tag, ['h1', 'h2'], true)) return 'Título';
    if (in_array($tag, ['h3', 'h4', 'h5', 'h6'], true)) return 'Subtítulo';
    if ($tag === 'a' || $tag === 'button') return 'Botón o enlace';
    if ($tag === 'p' || $tag === 'blockquote') return 'Texto';
    if ($tag === 'li') return 'Elemento';
    if ($tag === 'title') return 'Título de la pestaña';
    return 'Texto';
}

function neutron_schema_text(object $node): string {
    $out = '';
    foreach ($node->children ?? [] as $c) {
        if (($c->type ?? '') === 'text') $out .= $c->text;
        elseif (($c->type ?? '') === 'element') $out .= ($c->tag === 'br' ? "\n" : '') . neutron_schema_text($c);
    }
    return $out;
}

function neutron_schema_inner_html(object $node): string {
    return implode('', array_map('neutron_serialize', $node->children ?? []));
}

// Si el texto es largo o tiene saltos, el panel pone un área de texto en vez de una línea
function neutron_schema_multiline(object $node, string $text): bool {
    return in_array($node->tag ?? '', ['p', 'blockquote', 'address'], true) || mb_strlen($text) > 90 || str_contains($text, "\n");
}

// Campos propios de un nodo (sin entrar en los elementos de lista, que son de su lista) y de sus listas
function neutron_schema_fields(object $node, bool $inItem = false): array {
    $fields = [];
    $walk = function (object $n) use (&$walk, &$fields, $inItem): void {
        foreach ($n->children ?? [] as $c) {
            if (($c->type ?? '') !== 'element') continue;
            $a = $c->attrs ?? [];
            if (isset($a['data-edit-collection'])) {
                $fields[] = neutron_schema_collection($c);
                continue;                                   // lo de dentro es de la lista
            }
            if ($inItem && isset($a['data-edit-field'])) {
                $kind = ($c->tag ?? '') === 'img' ? 'img' : 'text';
                $text = $kind === 'img' ? (string)($a['src'] ?? '') : trim(neutron_schema_text($c));
                $fields[] = ['key' => $a['data-edit-field'], 'type' => $kind, 'label' => neutron_schema_label($c, $kind),
                    'default' => $text, 'multiline' => $kind === 'text' && neutron_schema_multiline($c, $text)];
            }
            if (!$inItem || !isset($a['data-edit-field'])) {
                foreach (['data-edit' => 'text', 'data-edit-html' => 'html', 'data-edit-img' => 'img', 'data-edit-href' => 'href', 'data-edit-content' => 'meta'] as $attr => $kind) {
                    if (!isset($a[$attr])) continue;
                    $default = match ($kind) {
                        'img' => (string)($a['src'] ?? ''),
                        'href' => (string)($a['href'] ?? ''),
                        'html' => trim(neutron_schema_inner_html($c)),
                        'meta' => (string)($a['content'] ?? ''),
                        default => trim(neutron_schema_text($c)),
                    };
                    $field = ['key' => $a[$attr], 'type' => $kind, 'label' => neutron_schema_label($c, $kind), 'default' => $default];
                    if ($kind === 'text') $field['multiline'] = neutron_schema_multiline($c, $default);
                    if ($kind === 'meta') {
                        // <meta name="description" data-edit-content>: texto plano (el panel lo trata como un texto)
                        $name = (string)($a['name'] ?? $a['property'] ?? '');
                        $field = ['key' => $a[$attr], 'type' => 'text', 'multiline' => true, 'default' => $default,
                            'label' => $name === 'description' ? 'Descripción para Google' : 'Dato de la página (' . $name . ')'];
                    }
                    if ($kind === 'href') $field['label'] = 'Enlace ' . ucfirst(str_replace(['social_', '_'], ['', ' '], $a[$attr]));
                    if ($kind === 'img' && !empty($a['alt'])) $field['label'] = 'Imagen: ' . $a['alt'];
                    $fields[] = $field;
                }
            }
            $walk($c);
        }
    };
    $walk($node);
    return $fields;
}

// Una lista: los campos de su primer elemento (el modelo) y los elementos de la plantilla como valor por defecto
function neutron_schema_collection(object $node): array {
    $items = array_values(array_filter($node->children ?? [], fn($c) => ($c->type ?? '') === 'element' && isset($c->attrs['data-edit-item'])));
    $model = $items ? neutron_schema_fields($items[0], true) : [];
    $defaults = array_map(function (object $item): array {
        $data = [];
        foreach (neutron_schema_fields($item, true) as $f) {
            $data[$f['key']] = $f['type'] === 'collection' ? $f['default'] : $f['default'];
        }
        return $data;
    }, $items);
    return ['key' => $node->attrs['data-edit-collection'], 'type' => 'collection', 'label' => 'Lista',
        'add' => (string)($node->attrs['data-edit-add'] ?? ''), 'fields' => $model, 'default' => $defaults];
}

// Nombre de una sección: su primer título (o el id, o el tipo de bloque)
function neutron_schema_section_title(object $node, int $n): string {
    $h = neutron_find_first($node, fn($e) => in_array($e->tag, ['h1', 'h2', 'h3'], true));
    $text = $h ? trim(preg_replace('/\s+/u', ' ', neutron_schema_text($h))) : '';
    if ($text !== '') return mb_strlen($text) > 60 ? mb_substr($text, 0, 60) . '…' : $text;
    $id = (string)($node->attrs['id'] ?? '');
    if ($id !== '') return ucfirst(str_replace(['-', '_'], ' ', $id));
    // Sin título ni id: el principio de su primer texto («Al nostre centre treballem…»)
    $first = neutron_find_first($node, fn($e) => in_array($e->tag, ['p', 'span', 'li', 'h4', 'strong'], true) && trim(neutron_schema_text($e)) !== '');
    $text = $first ? trim(preg_replace('/\s+/u', ' ', neutron_schema_text($first))) : '';
    if ($text !== '') return mb_strlen($text) > 45 ? rtrim(mb_substr($text, 0, 45)) . '…' : $text;
    return match ($node->tag) { 'header' => 'Cabecera', 'footer' => 'Pie', 'nav' => 'Menú', default => 'Sección ' . $n };
}

// Secciones de una plantilla: los bloques de primer nivel que tienen campos (lo de fuera de un bloque va en «General»)
function neutron_schema_sections(string $html): array {
    $root = neutron_parse_html($html);
    $sections = [];
    $loose = (object)['type' => 'root', 'children' => []];
    $n = 0;
    $walk = function (object $node) use (&$walk, &$sections, $loose, &$n): void {
        foreach ($node->children ?? [] as $c) {
            if (($c->type ?? '') !== 'element') continue;
            // Un bloque que contiene secciones (un <main> con <section> dentro) no es una sección: se entra
            $isBlock = in_array($c->tag, NEUTRON_SCHEMA_SECTION_TAGS, true)
                && !neutron_find_first((object)['type' => 'root', 'children' => $c->children ?? []], fn($e) => $e->tag === 'section');
            if ($isBlock) {
                $fields = neutron_schema_fields((object)['type' => 'root', 'children' => [$c]]);
                if ($fields) {
                    $n++;
                    $sections[] = ['id' => (string)($c->attrs['id'] ?? ''), 'title' => neutron_schema_section_title($c, $n), 'fields' => $fields];
                }
                continue;
            }
            $a = $c->attrs ?? [];
            if (isset($a['data-edit']) || isset($a['data-edit-html']) || isset($a['data-edit-img']) || isset($a['data-edit-href']) || isset($a['data-edit-content']) || isset($a['data-edit-collection'])) {
                $loose->children[] = $c;                   // un campo fuera de cualquier bloque
                continue;
            }
            $walk($c);
        }
    };
    $walk($root);
    $looseFields = neutron_schema_fields($loose);
    if ($looseFields) array_unshift($sections, ['id' => '', 'title' => 'Datos de la página', 'fields' => $looseFields]);
    return neutron_schema_dedupe($sections);
}

// Una clave que sale dos veces (el teléfono en la cabecera y en el pie) solo se edita en el primer sitio
function neutron_schema_dedupe(array $sections, array &$seen = []): array {
    $out = [];
    foreach ($sections as $s) {
        $s['fields'] = array_values(array_filter($s['fields'], function ($f) use (&$seen) {
            $id = ($f['type'] === 'img' ? 'img:' : '') . $f['key'];
            if (isset($seen[$id])) return false;
            return $seen[$id] = true;
        }));
        if ($s['fields']) $out[] = $s;
    }
    return $out;
}

// Todo lo editable de la web: las partes comunes y cada página (portada, site/pages/*.html menos la 404)
function neutron_schema(): array {
    $seen = [];
    $common = [];
    $names = ['header' => 'Cabecera', 'footer' => 'Pie', 'nav' => 'Menú', 'menu' => 'Menú', 'head' => 'Datos de todas las páginas'];
    foreach (glob(NEUTRON_SITE . '/partials/*.html') ?: [] as $file) {
        $name = basename($file, '.html');
        $label = $names[$name] ?? ucfirst(str_replace(['-', '_'], ' ', $name));
        $sections = neutron_schema_sections((string)file_get_contents($file));
        foreach ($sections as $s) {
            // Cabecera y pie se llaman por su parcial; si un parcial tiene varias secciones, «Pie · Contacte»
            $s['title'] = count($sections) > 1 && $s['title'] !== 'Datos de la página' ? $label . ' · ' . $s['title'] : $label;
            $common[] = $s;
        }
    }
    $common = neutron_schema_dedupe($common, $seen);
    $pages = [];
    foreach (neutron_site_pages() as $p) {
        $slug = $p['href'] === './' ? '' : preg_replace('~(\.html|/)$~', '', $p['href']);
        $file = $slug === '' ? NEUTRON_SITE . '/template.html' : NEUTRON_SITE . '/pages/' . $slug . '.html';
        if (!is_file($file)) continue;
        $sections = neutron_schema_dedupe(neutron_schema_sections((string)file_get_contents($file)), $seen);
        $pages[] = ['slug' => $slug, 'href' => $p['href'], 'title' => $p['title'], 'sections' => $sections];
    }
    return ['common' => $common, 'pages' => $pages];
}
