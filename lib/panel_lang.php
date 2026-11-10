<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Idioma del panel y de la barra del editor (no el de la web): castellano o catalán. Config «panel» → «lang» ('es' o
// 'ca'); sin ella, el idioma principal de la web (languages.json o el <html lang> de la plantilla), si es uno de los dos.
//
// Los textos se escriben en castellano en assets/panel.js y assets/editor.js, y assets/i18n/<idioma>.json los traduce:
// {"Guardar cambios": "Desa els canvis", "hace {} min": "fa {} min"}. Una clave con {} vale para los textos montados con
// partes que cambian (números, nombres): cada {} se traduce a su vez, si está en la lista.

const NEUTRON_PANEL_LANGS = ['es', 'ca'];

function neutron_panel_lang(): string {
    $lang = (string)(neutron_config()['panel']['lang'] ?? '');
    if ($lang === '') $lang = (string)(neutron_languages()['default'] ?? '');
    if ($lang === '' && preg_match('~<html[^>]*\blang="([A-Za-z]{2})~', (string)@file_get_contents(NEUTRON_SITE . '/template.html', false, null, 0, 4096), $m)) {
        $lang = $m[1];
    }
    $lang = strtolower(substr($lang, 0, 2));
    return in_array($lang, NEUTRON_PANEL_LANGS, true) ? $lang : 'es';
}

/** Las traducciones del idioma del panel (vacío en castellano, que es el de los textos) */
function neutron_panel_strings(?string $lang = null): array {
    $lang ??= neutron_panel_lang();
    if ($lang === 'es') return [];
    $file = NEUTRON_ROOT . '/assets/i18n/' . $lang . '.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? array_filter($data, fn($v, $k) => is_string($v) && is_string($k) && $k !== '_', ARRAY_FILTER_USE_BOTH) : [];
}

/** Para los mensajes que devuelve el servidor al panel o al editor */
function neutron_t(string $text): string {
    static $strings = null;
    $strings ??= neutron_panel_strings();
    return $strings[$text] ?? $text;
}
