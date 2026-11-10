<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Copias del contenido (panel → Copias): antes de cada guardado (admin/save.php) se copia cómo estaban los ficheros de
// textos que se van a escribir, con los campos que cambian. Desde el panel se ve qué había antes, se recupera un campo o
// se vuelve a una versión entera (que a su vez deja copia de lo que había, así que también se puede deshacer).
//
//   data/history/<AAAAMMDD-HHMMSS>-<aleatorio>/meta.json   {"id", "at", "source", "lang", "keys": [...]}
//   data/history/<id>/content.json, content.<idioma>.json   los ficheros tal como estaban
//
// Elementos de las colecciones (noticias, documentos, álbumes…) y sus categorías: data/history/<id>/item/<colección>/<fichero>
// con meta.json {"kind": "item", "collection", "file", "title", "action": "save"|"delete"|"categories"} (lib/posts.php la
// hace antes de guardar o borrar). Volver a esa versión repone el fichero (también si se había borrado).
//
// Se guardan las últimas NEUTRON_HISTORY_KEEP versiones. Son las copias del día a día; las completas de la web
// (núcleo, site/ y configuración) las hace Cosmo (API remota, data/backups).

require_once __DIR__ . '/remote/state.php';
require_once __DIR__ . '/content.php';

const NEUTRON_HISTORY_KEEP = 300;

function neutron_history_dir(): string {
    return NEUTRON_DATA . '/history';
}

function neutron_history_valid_id(string $id): bool {
    return (bool)preg_match('/^\d{8}-\d{6}-[a-f0-9]{6}$/', $id);
}

// Ficheros de textos de la web (content.json y content.<idioma>.json)
function neutron_history_files(): array {
    $files = [];
    foreach (glob(NEUTRON_SITE . '/content*.json') ?: [] as $f) {
        if (preg_match('/^content(\.[a-z]{2}(-[a-z]{2})?)?\.json$/', basename($f))) $files[] = basename($f);
    }
    return $files;
}

/** Copia de los ficheros $files (nombres, p. ej. content.ca.json) antes de cambiarlos. Devuelve el id o null. */
function neutron_history_snapshot(array $files, array $keys, string $source, ?string $lang): ?string {
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $dir = neutron_history_dir() . '/' . $id;
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) {
        neutron_log('warning', 'No se pudo crear la copia del contenido en {dir}', ['dir' => $dir]);
        return null;
    }
    foreach (array_unique($files) as $name) {
        if (in_array($name, neutron_history_files(), true)) @copy(NEUTRON_SITE . '/' . $name, $dir . '/' . $name);
    }
    try {
        neutron_write_json($dir . '/meta.json', ['id' => $id, 'at' => date(DATE_ATOM), 'source' => $source, 'lang' => $lang,
            'keys' => array_values(array_unique($keys))]);
    } catch (RuntimeException $e) {
        neutron_log('warning', 'No se pudo apuntar la copia del contenido: {error}', ['error' => $e->getMessage()]);
    }
    neutron_history_prune();
    return $id;
}

/** @return list<array> versiones, la más nueva primero */
function neutron_history_list(int $limit = 100): array {
    $out = [];
    foreach (array_slice(neutron_history_ids(), 0, $limit) as $id) {
        $meta = neutron_history_meta($id);
        if ($meta !== null) $out[] = $meta;
    }
    return $out;
}

function neutron_history_ids(): array {
    $ids = array_values(array_filter(array_map('basename', glob(neutron_history_dir() . '/*', GLOB_ONLYDIR) ?: []), 'neutron_history_valid_id'));
    rsort($ids);
    return $ids;
}

function neutron_history_meta(string $id): ?array {
    if (!neutron_history_valid_id($id)) return null;
    $file = neutron_history_dir() . '/' . $id . '/meta.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : null;
}

/** Lo que había en esa versión para sus campos, y lo que hay ahora: [{key, scope, before, now}] */
function neutron_history_detail(string $id): ?array {
    $meta = neutron_history_meta($id);
    if ($meta === null) return null;
    if (($meta['kind'] ?? '') === 'item') return $meta + ['changes' => neutron_history_item_changes($meta), 'deleted' => !is_file(NEUTRON_SITE . '/' . $meta['collection'] . '/' . $meta['file'])];
    $dir = neutron_history_dir() . '/' . $id;
    $old = $cur = [];
    foreach (neutron_history_files() as $name) {
        $cur[$name] = neutron_read_content(NEUTRON_SITE . '/' . $name);
    }
    foreach (glob($dir . '/content*.json') ?: [] as $f) {
        $old[basename($f)] = neutron_read_content($f);
    }
    $changes = [];
    foreach ($meta['keys'] as $key) {
        // El campo puede estar en el fichero del idioma o en el común (imágenes)
        foreach (array_keys($old) as $name) {
            if (!array_key_exists($key, $old[$name]) && !array_key_exists($key, $cur[$name] ?? [])) continue;
            $scope = preg_match('/^content\.([a-z-]+)\.json$/', $name, $m) ? $m[1] : null;
            $changes[] = ['key' => $key, 'lang' => $scope, 'before' => $old[$name][$key] ?? null, 'now' => $cur[$name][$key] ?? null];
            break;
        }
    }
    return $meta + ['changes' => $changes];
}

/** Vuelve a una versión: deja copia de cómo está ahora y repone sus ficheros */
function neutron_history_restore(string $id): bool {
    $meta = neutron_history_meta($id);
    if ($meta === null) return false;
    if (($meta['kind'] ?? '') === 'item') {
        $src = neutron_history_dir() . '/' . $id . '/item/' . $meta['collection'] . '/' . $meta['file'];
        $dest = NEUTRON_SITE . '/' . $meta['collection'] . '/' . $meta['file'];
        if (!is_file($src) || !is_dir(dirname($dest))) return false;
        neutron_history_snapshot_item($meta['collection'], $meta['file'], 'restore', (string)$meta['title']);
        if (!@copy($src, $dest)) return false;
        neutron_log('notice', 'Elemento restaurado a la copia {id}: {c}/{f}', ['id' => $id, 'c' => $meta['collection'], 'f' => $meta['file']]);
        return true;
    }
    $dir = neutron_history_dir() . '/' . $id;
    $files = array_map('basename', glob($dir . '/content*.json') ?: []);
    if (!$files) return false;
    neutron_history_snapshot($files, $meta['keys'], 'restore', $meta['lang']);
    foreach ($files as $name) {
        $data = neutron_read_content($dir . '/' . $name);
        if (!neutron_save_content($data, NEUTRON_SITE . '/' . $name)) return false;
    }
    // Para los avisos de conflicto: lo que se repone cuenta como un guardado de ahora
    $byScope = [];
    foreach ($files as $name) {
        $scope = preg_match('/^content\.([a-z-]+)\.json$/', $name, $m) ? $m[1] : '_';
        $byScope[$scope] = $meta['keys'];
    }
    neutron_record_edits(neutron_edit_times(), $byScope, microtime(true));
    neutron_log('notice', 'Contenido restaurado a la copia {id}', ['id' => $id]);
    return true;
}

function neutron_history_prune(): void {
    foreach (array_slice(neutron_history_ids(), NEUTRON_HISTORY_KEEP) as $id) {
        $dir = neutron_history_dir() . '/' . $id;
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

// Quién guarda (para la lista de copias): «editor» (la página) o «panel»; lo ponen admin/post.php y admin/items.php
function neutron_history_source(?string $set = null): string {
    static $source = 'panel';
    if ($set !== null) $source = $set;
    return $source;
}

// No hacer copias (ordenar con las flechas cambia todos los elementos y llenaría la lista)
function neutron_history_paused(?bool $set = null): bool {
    static $paused = false;
    if ($set !== null) $paused = $set;
    return $paused;
}

/** Copia de un fichero de una colección antes de cambiarlo o borrarlo ($file: «mi-entrada.json» o «_categories.json») */
function neutron_history_snapshot_item(string $collection, string $file, string $action, string $title): ?string {
    if (neutron_history_paused()) return null;
    $src = NEUTRON_SITE . '/' . $collection . '/' . $file;
    if (!is_file($src) || !preg_match('/^[a-z0-9_][a-z0-9_-]*\.json$/', $file)) return null;
    $id = date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $dir = neutron_history_dir() . '/' . $id . '/item/' . $collection;
    if (!@mkdir($dir, 0775, true) && !is_dir($dir)) return null;
    @copy($src, $dir . '/' . $file);
    try {
        neutron_write_json(neutron_history_dir() . '/' . $id . '/meta.json', ['id' => $id, 'at' => date(DATE_ATOM), 'source' => neutron_history_source(),
            'lang' => null, 'keys' => [], 'kind' => 'item', 'collection' => $collection, 'file' => $file, 'title' => $title, 'action' => $action]);
    } catch (RuntimeException $e) {
        return null;
    }
    neutron_history_prune();
    return $id;
}

/** Lo que tenía el elemento en esa copia frente a lo que tiene ahora: [{key, lang, before, now}] por campo */
function neutron_history_item_changes(array $meta): array {
    $old = json_decode((string)@file_get_contents(neutron_history_dir() . '/' . $meta['id'] . '/item/' . $meta['collection'] . '/' . $meta['file']), true);
    $cur = json_decode((string)@file_get_contents(NEUTRON_SITE . '/' . $meta['collection'] . '/' . $meta['file']), true);
    $old = is_array($old) ? $old : []; $cur = is_array($cur) ? $cur : [];
    if ($meta['file'] === '_categories.json') {
        $fmt = fn($list) => implode("\n", array_map(fn($c) => (string)($c['title'] ?? $c['id'] ?? ''), $list));
        return [['key' => 'categories', 'lang' => null, 'before' => $fmt($old), 'now' => $cur ? $fmt($cur) : null]];
    }
    $flat = function (array $d): array {
        $out = [];
        foreach ($d as $k => $v) {
            if (in_array($k, ['created', 'updated', 'order'], true)) continue;
            if ($k === 'i18n' && is_array($v)) { foreach ($v as $l => $t) foreach ((array)$t as $f => $x) $out["$f|$l"] = $x; continue; }
            $out["$k|"] = $v;
        }
        return $out;
    };
    $a = $flat($old); $b = $cur ? $flat($cur) : [];
    $changes = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
        if (($a[$k] ?? null) == ($b[$k] ?? null) && $cur) continue;
        [$f, $l] = explode('|', $k, 2);
        $changes[] = ['key' => $f, 'lang' => $l !== '' ? $l : null, 'before' => $a[$k] ?? null, 'now' => $cur ? ($b[$k] ?? null) : null];
    }
    return $changes;
}
