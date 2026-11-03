<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// site/content.json, o site/content.<idioma>.json con $lang en una web de varios idiomas (ver lib/i18n.php)
function neutron_content_path(?string $lang = null): string {
    return NEUTRON_SITE . '/content' . ($lang !== null ? '.' . $lang : '') . '.json';
}

// Contenido de la página. En una web de varios idiomas: content.json (lo común: imágenes) + los textos del idioma
// principal + los del idioma que se ve (cada uno pisa al anterior), así lo que falta por traducir no queda vacío.
function neutron_load_content(): array {
    $data = neutron_read_content(neutron_content_path());
    $lang = function_exists('neutron_lang') ? neutron_lang() : null;
    if ($lang !== null) {
        $default = neutron_languages()['default'];
        $data = array_replace($data, neutron_read_content(neutron_content_path($default)));
        if ($lang !== $default) $data = array_replace($data, neutron_read_content(neutron_content_path($lang)));
    }
    return $data;
}

function neutron_read_content(string $path): array {
    if (!file_exists($path)) return [];
    $fp = fopen($path, 'r');
    if (!$fp) return [];
    flock($fp, LOCK_SH);
    $raw = stream_get_contents($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function neutron_save_content(array $data, ?string $path = null): bool {
    $path ??= neutron_content_path();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) return false;

    $fp = fopen($path, 'c+');
    if (!$fp) return false;
    if (!flock($fp, LOCK_EX)) { fclose($fp); return false; }
    ftruncate($fp, 0);
    rewind($fp);
    $ok = fwrite($fp, $json) !== false;
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $ok;
}
