<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Estado de la API remota en data/remote/: mantenimiento, manifiesto, historial de actualizaciones.
// Esta carpeta nunca la toca una actualización ni entra en las copias.

function neutron_remote_dir(string $sub = ''): string {
    return neutron_data_dir('remote' . ($sub !== '' ? '/' . $sub : ''));
}

function neutron_read_json(string $file): ?array {
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    return is_array($data) ? $data : null;
}

// Escritura atómica (fichero temporal + rename).
function neutron_write_json(string $file, array $data): void {
    $dir = dirname($file);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("No se puede crear $dir");
    }
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false
        || !rename($tmp, $file)) {
        @unlink($tmp);
        throw new RuntimeException("No se puede escribir $file");
    }
}

function neutron_set_maintenance(bool $on, string $reason = ''): void {
    $file = neutron_maintenance_file();
    if ($on) {
        neutron_write_json($file, ['since' => date(DATE_ATOM), 'reason' => $reason]);
    } elseif (is_file($file)) {
        unlink($file);
    }
}

/** @return list<array> la más reciente primero */
function neutron_updates(): array {
    return neutron_read_json(neutron_remote_dir() . '/updates.json') ?? [];
}

function neutron_add_update(array $entry): void {
    $list = neutron_updates();
    array_unshift($list, $entry);
    neutron_write_json(neutron_remote_dir() . '/updates.json', array_slice($list, 0, 50));
}

function neutron_remove_tree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $f) {
        $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
    @rmdir($dir);
}

function neutron_ensure_dir(string $dir): void {
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException("No se puede crear $dir (¿permisos?)");
    }
}
