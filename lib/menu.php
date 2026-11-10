<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Menú editable desde el panel (/admin/ → Menú): la plantilla marca su menú con data-menu (el <nav> de la cabecera, el
// del pie…) y, si hay site/menu.json, los enlaces de ese elemento salen de ahí; si no, tal como están en la plantilla.
//
//   site/menu.json  {"items": [{"href": "la-festa.html", "label": {"ca": "La Festa", "es": "La Fiesta"},
//                              "button": false, "hidden": false}]}
//
// Cada enlace se hace con el primero de la plantilla como molde (clases, atributos); los marcados como botón, con el
// primero que tenga la clase «btn». Los internos van sin idioma (el núcleo lo pone) y los externos se abren aparte.

/** ¿La plantilla marca algún menú con data-menu? (si no, el panel no enseña la sección) */
function neutron_menu_available(): bool {
    foreach (array_merge(glob(NEUTRON_SITE . '/partials/*.html') ?: [], [NEUTRON_SITE . '/template.html']) as $f) {
        if (preg_match('/<[a-z][^>]*\sdata-menu[\s=>]/i', (string)@file_get_contents($f))) return true;
    }
    return false;
}

/** Destinos para elegir en el panel: la portada, las páginas de site/pages y las colecciones con listado */
function neutron_menu_targets(): array {
    $out = ['./'];
    foreach (glob(NEUTRON_SITE . '/pages/*.html') ?: [] as $f) $out[] = basename($f, '.html') === 'blog' ? 'blog/' : basename($f);
    return array_values(array_unique($out));
}

function neutron_menu_file(): string {
    return NEUTRON_SITE . '/menu.json';
}

/** @return list<array{href:string,label:array<string,string>,button:bool,hidden:bool}>|null null = sin menu.json */
function neutron_menu_items(): ?array {
    $file = neutron_menu_file();
    if (!is_file($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    if (!is_array($data['items'] ?? null)) return null;
    $out = [];
    foreach ($data['items'] as $it) {
        $clean = neutron_menu_clean($it);
        if ($clean !== null) $out[] = $clean;
    }
    return $out;
}

function neutron_menu_clean(mixed $it): ?array {
    if (!is_array($it)) return null;
    $href = trim((string)($it['href'] ?? ''));
    if ($href === '' || preg_match('~^\s*javascript:~i', $href) || (preg_match('~^([a-z][a-z0-9+.-]*):~i', $href, $m) && !in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true))) return null;
    $labels = [];
    foreach ((array)($it['label'] ?? []) as $l => $t) {
        if (is_string($l) && is_string($t) && trim($t) !== '') $labels[$l] = mb_substr(trim($t), 0, 80);
    }
    if (!$labels) return null;
    return ['href' => mb_substr($href, 0, 500), 'label' => $labels, 'button' => !empty($it['button']), 'hidden' => !empty($it['hidden'])];
}

function neutron_menu_save(array $items): bool {
    $clean = array_values(array_filter(array_map('neutron_menu_clean', $items)));
    require_once __DIR__ . '/remote/state.php';
    try {
        neutron_write_json(neutron_menu_file(), ['items' => $clean]);
        return true;
    } catch (RuntimeException) {
        return false;
    }
}

/** El texto de un enlace en el idioma que se ve (si no lo tiene, el del principal, o el primero) */
function neutron_menu_label(array $item): string {
    $lang = neutron_lang();
    $default = neutron_languages()['default'] ?? null;
    return $item['label'][$lang ?? ''] ?? $item['label'][$default ?? ''] ?? (string)reset($item['label']);
}

function neutron_menu_external(string $href): bool {
    return (bool)preg_match('~^https?://~i', $href);
}

/**
 * El menú tal como está ahora en la plantilla (para empezar a editarlo en el panel): los enlaces del primer data-menu,
 * con sus textos en cada idioma (de content*.json, si son editables).
 */
function neutron_menu_from_template(): ?array {
    require_once __DIR__ . '/render.php';
    require_once __DIR__ . '/content.php';
    $src = neutron_include_partials((string)@file_get_contents(NEUTRON_SITE . '/template.html'));   // en el orden de la página
    $nav = neutron_find_first(neutron_parse_html($src), fn($n) => isset($n->attrs['data-menu']));
    if (!$nav) return null;
    $langs = neutron_languages();
    $codes = $langs ? array_keys($langs['languages']) : ['ca'];
    $files = [];
    foreach ($codes as $l) {
        $files[$l] = array_merge(neutron_read_content(neutron_content_path()), $langs ? neutron_read_content(neutron_content_path($langs['default'])) : [],
            $langs ? neutron_read_content(neutron_content_path($l)) : []);
    }
    $items = [];
    foreach ($nav->children as $a) {
        if (($a->type ?? '') !== 'element' || $a->tag !== 'a') continue;
        $text = trim(implode('', array_map(fn($c) => $c->text ?? '', $a->children ?? [])));
        $key = $a->attrs['data-edit'] ?? null;
        $label = [];
        foreach ($codes as $l) $label[$l] = $key !== null && isset($files[$l][$key]) && is_string($files[$l][$key]) ? $files[$l][$key] : $text;
        $items[] = ['href' => (string)($a->attrs['href'] ?? ''), 'label' => $label, 'button' => (bool)preg_match('/\bbtn\b/', (string)($a->attrs['class'] ?? '')), 'hidden' => false];
    }
    return $items;
}
