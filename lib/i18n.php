<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Webs en varios idiomas. Se activa con site/languages.json:
//
//   { "default": "ca", "languages": { "ca": "Català", "es": "Español", "en": "English", "fr": "Français" } }
//
// - URLs con el idioma delante: /ca/, /es/biomassa.html, /en/noticies/una-entrada. La raíz (/) va al idioma del
//   navegador si es uno de la lista; si no, al principal.
// - Textos: site/content.<idioma>.json (lo que falte sale del idioma principal); las imágenes (data-edit-img) son
//   comunes a todos los idiomas y van en site/content.json.
// - Formulario y cookies: site/contact.<idioma>.json y site/cookies.<idioma>.json si existen.
// - Las plantillas son las mismas para todos los idiomas: los enlaces se escriben sin idioma (biomassa.html,
//   ./#contacte, noticies/) y el núcleo les pone el del idioma que se está viendo.
//
// Sin site/languages.json la web es de un solo idioma y nada de esto se aplica.

/** @return array{default:string,languages:array<string,string>}|null */
function neutron_languages(): ?array {
    static $cache = false;
    if ($cache !== false) return $cache;
    $file = NEUTRON_SITE . '/languages.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $langs = [];
    foreach ((array)($data['languages'] ?? []) as $code => $name) {
        if (is_string($code) && preg_match('/^[a-z]{2}(-[a-z]{2})?$/', $code)) $langs[$code] = (string)$name;
    }
    if (count($langs) < 1) return $cache = null;
    $default = (string)($data['default'] ?? '');
    if (!isset($langs[$default])) $default = (string)array_key_first($langs);
    return $cache = ['default' => $default, 'languages' => $langs];
}

// Idioma que se está viendo (null en una web de un solo idioma)
function neutron_lang(?string $set = null): ?string {
    static $lang = null;
    if ($set !== null) $lang = $set;
    return $lang;
}

// Prefijo de las URLs del idioma actual: "ca/" (o "" en una web de un solo idioma)
function neutron_lang_prefix(?string $lang = null): string {
    $lang ??= neutron_lang();
    return $lang !== null ? $lang . '/' : '';
}

// Idioma del navegador (Accept-Language) que tenga la web, o el principal
function neutron_lang_detect(): string {
    $langs = neutron_languages();
    $header = strtolower((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
    $best = [];
    foreach (explode(',', $header) as $part) {
        if (!preg_match('/^\s*([a-z]{2})(?:-[a-z]{2})?\s*(?:;\s*q=([0-9.]+))?/', $part, $m)) continue;
        $q = isset($m[2]) ? (float)$m[2] : 1.0;
        if (isset($langs['languages'][$m[1]]) && $q > ($best[$m[1]] ?? -1)) $best[$m[1]] = $q;
    }
    arsort($best);
    return (string)(array_key_first($best) ?? $langs['default']);
}

// Fichero JSON del sitio en el idioma actual si existe (contact.es.json), si no el general (contact.json)
function neutron_site_json(string $name): string {
    $lang = neutron_lang();
    if ($lang !== null && is_file(NEUTRON_SITE . '/' . $name . '.' . $lang . '.json')) {
        return NEUTRON_SITE . '/' . $name . '.' . $lang . '.json';
    }
    return NEUTRON_SITE . '/' . $name . '.json';
}

// Enlace interno de una plantilla → con el idioma delante. No toca los externos, los absolutos, los recursos
// (site/, assets/…) ni los que ya llevan idioma. "#seccio" pasa a ser la sección de esta misma página.
function neutron_lang_href(string $href, string $prefix, string $self): ?string {
    $href = trim($href);
    if ($href === '' || $prefix === '') return null;
    if (preg_match('~^([a-z][a-z0-9+.-]*:|/|site/|assets/|admin/|contact\.php|favicon\.ico|apple-touch-icon|index\.php\?r=|sitemap\.xml)~i', $href)) return null;
    $langs = neutron_languages();
    if ($langs && preg_match('~^([a-z]{2}(?:-[a-z]{2})?)(/|$)~', $href, $m) && isset($langs['languages'][$m[1]])) return null;
    if ($href[0] === '#') return $prefix . $self . $href;
    if ($href === '.' || $href === './') return $prefix;
    if (str_starts_with($href, './')) return $prefix . substr($href, 2);
    return $prefix . $href;
}

/**
 * Enlaces a esta misma página en cada idioma (selector de idioma y hreflang).
 * @return array<string,string> idioma => ruta relativa a la instalación ("es/biomassa.html")
 */
function neutron_lang_alternates(string $self): array {
    $out = [];
    foreach (neutron_languages()['languages'] ?? [] as $code => $name) $out[$code] = $code . '/' . $self;
    return $out;
}

/**
 * <link rel="alternate" hreflang> de la página en cada idioma (URLs absolutas) y x-default (la raíz).
 * @return list<array{0:string,1:string}>
 */
function neutron_lang_head_links(array $l): array {
    $out = [];
    foreach ($l['alternates'] as $code => $href) $out[] = [$code, $l['site_url'] . '/' . $href];
    $out[] = ['x-default', $l['site_url'] . '/'];
    return $out;
}
