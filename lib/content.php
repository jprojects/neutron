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

// Cuándo se guardó cada campo por última vez (data/edits.json: {ámbito: {clave: segundos}}), para que dos ventanas del
// editor (la página y el panel, o dos personas) no se pisen: lo que se cambió después de abrir la página es un conflicto.
// Ámbito: el idioma del fichero de textos, o "_" para content.json (web de un idioma, o las imágenes comunes).
function neutron_edits_path(): string {
    return NEUTRON_DATA . '/edits.json';
}

function neutron_edit_times(): array {
    $data = is_file(neutron_edits_path()) ? json_decode((string)file_get_contents(neutron_edits_path()), true) : null;
    return is_array($data) ? $data : [];
}

function neutron_record_edits(array $times, array $keysByScope, float $now): void {
    foreach ($keysByScope as $scope => $keys) {
        foreach ($keys as $key) $times[$scope][$key] = $now;
    }
    require_once NEUTRON_ROOT . '/lib/remote/state.php';
    try {
        neutron_write_json(neutron_edits_path(), $times);
    } catch (RuntimeException $e) {
        neutron_log('warning', 'No se pudo apuntar la hora de los cambios: {error}', ['error' => $e->getMessage()]);
    }
}
