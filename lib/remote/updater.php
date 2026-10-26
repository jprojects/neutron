<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Instala un paquete de versión (el ZIP de una etiqueta de GitHub que envía Cosmo).
//
// 1. Valida el ZIP (sha256, rutas, VERSION, core.json) y lo extrae en data/remote/tmp.
// 2. Si hay manifiesto y algún fichero del núcleo se ha cambiado a mano, se detiene (salvo $force).
// 3. Copia los ficheros del núcleo según el core.json NUEVO; guarda los originales para deshacer.
// 4. Borra los del núcleo que la versión anterior instaló y la nueva ya no trae (solo con manifiesto,
//    así nunca se borra un fichero que haya añadido la instalación).
// 5. Autoprueba: pide ?r=remote&a=status a la propia instalación, que ya responde con el código nuevo.
//    Si la versión nueva no arranca, se deshace (si no se puede conectar consigo misma, solo se avisa).
// 6. Si algo falla, restaura todo lo tocado. Si va bien, escribe el manifiesto nuevo.
//
// site/, config.local.php y data/ nunca son del núcleo: una actualización no los toca. Del .htaccess solo
// se sustituye el bloque "# BEGIN Neutron" … "# END Neutron" (ver htaccess.php).

const NEUTRON_UPDATE_MAX_FILES = 5000;

class NeutronUpdateException extends RuntimeException {
    public function __construct(string $message, public readonly array $data = []) {
        parent::__construct($message);
    }
}

/**
 * @param ?Closure(string):array{reachable:bool,ok:bool,error:?string} $selfTest recibe la versión esperada
 */
function neutron_update_install(string $zipFile, string $sha256, string $expectedVersion, bool $force = false, ?Closure $selfTest = null): array {
    if (!class_exists(ZipArchive::class)) {
        throw new NeutronUpdateException('Falta la extensión zip de PHP');
    }
    if (!hash_equals(strtolower($sha256), (string)hash_file('sha256', $zipFile))) {
        throw new NeutronUpdateException('El sha256 del paquete no coincide (subida incompleta o alterada)');
    }

    $work = neutron_remote_dir('tmp') . '/update-' . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
    $pkg = $work . '/pkg';
    $rollback = $work . '/rollback';
    $from = neutron_version();
    $started = microtime(true);
    $touched = []; // [ruta destino, copia del original | null si era nuevo]
    $test = null;

    try {
        neutron_update_extract($zipFile, $pkg);

        $version = trim((string)@file_get_contents($pkg . '/VERSION'));
        if (!preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/', $version)) {
            throw new NeutronUpdateException('El paquete no tiene un fichero VERSION válido');
        }
        if ($expectedVersion !== '' && $version !== $expectedVersion) {
            throw new NeutronUpdateException("El paquete es la versión $version y se esperaba $expectedVersion");
        }
        if (!is_file($pkg . '/lib/render.php') || !is_file($pkg . '/index.php') || !is_file($pkg . '/lib/remote/api.php')) {
            throw new NeutronUpdateException('El paquete no parece una versión de Neutron');
        }
        $patterns = neutron_core_patterns($pkg);
        if ($patterns === []) {
            throw new NeutronUpdateException('El paquete no tiene core.json: no se sabe qué ficheros son del núcleo');
        }

        $manifest = neutron_manifest();
        $local = neutron_core_check();
        $changedByHand = $local === null ? [] : $local['modified'];
        if ($changedByHand && !$force) {
            throw new NeutronUpdateException('Hay ficheros del núcleo modificados a mano', ['modified' => $changedByHand]);
        }

        $newFiles = neutron_core_scan($pkg, $patterns);
        $copy = [];
        foreach ($newFiles as $rel => $hash) {
            if (neutron_update_protected($rel)) {
                throw new NeutronUpdateException("core.json del paquete incluye un fichero de la instalación: $rel");
            }
            $target = NEUTRON_ROOT . '/' . $rel;
            if (!is_file($target) || neutron_core_hash($rel, $target) !== $hash) $copy[] = $rel;
        }
        $delete = [];
        if ($manifest !== null) {
            foreach (array_keys($manifest['files']) as $rel) {
                if (!isset($newFiles[$rel]) && !neutron_update_protected($rel) && is_file(NEUTRON_ROOT . '/' . $rel)) {
                    $delete[] = $rel;
                }
            }
        }

        foreach ($copy as $rel) {
            $source = $pkg . '/' . $rel;
            if ($rel === '.htaccess' && is_file(NEUTRON_ROOT . '/.htaccess')) {
                // Bloque del núcleo nuevo + lo que la instalación tenga fuera de él
                $source = $work . '/htaccess.merged';
                file_put_contents($source, neutron_htaccess_merge((string)file_get_contents(NEUTRON_ROOT . '/.htaccess'), (string)file_get_contents($pkg . '/.htaccess')));
            }
            neutron_update_place($source, NEUTRON_ROOT . '/' . $rel, $rollback . '/' . $rel, $touched);
        }
        foreach ($delete as $rel) {
            neutron_update_remove(NEUTRON_ROOT . '/' . $rel, $rollback . '/' . $rel, $touched);
        }
        neutron_update_flush_opcache(array_map(static fn(array $t) => $t[0], $touched));

        $test = $selfTest !== null && $touched ? $selfTest($version) : null;
        if ($test !== null && $test['reachable'] && !$test['ok']) {
            throw new NeutronUpdateException('La versión nueva no arranca y se ha deshecho la actualización: ' . $test['error']);
        }

        neutron_save_manifest($version, $newFiles);
    } catch (Throwable $e) {
        $restored = neutron_update_undo($touched);
        neutron_update_flush_opcache([]);
        neutron_remove_tree($work);
        if ($restored > 0) {
            neutron_log('error', 'Actualización fallida, {n} ficheros restaurados', ['n' => $restored, 'exception' => $e]);
        }
        throw $e instanceof NeutronUpdateException ? $e : new NeutronUpdateException('Error al instalar: ' . $e->getMessage(), ['restored' => $restored]);
    }

    neutron_remove_tree($work);

    $report = [
        'from' => $from,
        'to' => $version,
        'copied' => count($copy),
        'deleted' => count($delete),
        'overwritten_modified' => $changedByHand,
        'first_manifest' => $manifest === null,
        'self_test' => $test === null ? 'skipped' : ($test['reachable'] ? 'ok' : 'unreachable: ' . $test['error']),
        'seconds' => round(microtime(true) - $started, 1),
        'installed_at' => date(DATE_ATOM),
    ];
    neutron_add_update($report);
    neutron_log('notice', 'Actualizado de {from} a {to}', ['from' => $from, 'to' => $version, 'copied' => count($copy), 'deleted' => count($delete)]);
    return $report;
}

// Lo propio de la instalación nunca se sobrescribe, diga lo que diga el core.json del paquete.
function neutron_update_protected(string $rel): bool {
    return $rel === 'config.local.php' || str_starts_with($rel, 'site/')
        || (str_starts_with($rel, 'data/') && $rel !== 'data/.htaccess');
}

// Extrae solo lo que hay dentro de la carpeta raíz del ZIP (GitHub la llama owner-repo-sha/).
function neutron_update_extract(string $zipFile, string $dest): void {
    $zip = new ZipArchive();
    if ($zip->open($zipFile, ZipArchive::RDONLY) !== true) {
        throw new NeutronUpdateException('El paquete no es un ZIP válido');
    }
    try {
        if ($zip->numFiles > NEUTRON_UPDATE_MAX_FILES) {
            throw new NeutronUpdateException('El paquete tiene demasiados ficheros');
        }
        $prefix = $zip->locateName('VERSION') !== false ? '' : null;
        if ($prefix === null) {
            $first = (string)$zip->getNameIndex(0);
            $prefix = str_contains($first, '/') ? substr($first, 0, strpos($first, '/') + 1) : '';
        }
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            if ($prefix !== '' && !str_starts_with($name, $prefix)) {
                throw new NeutronUpdateException('El paquete tiene más de una carpeta raíz');
            }
            $rel = substr($name, strlen($prefix));
            if ($rel === '' || str_ends_with($rel, '/')) continue;
            if (str_contains($rel, '\\') || str_starts_with($rel, '/') || preg_match('~(^|/)\.\.(/|$)~', $rel) || str_contains($rel, "\0")) {
                throw new NeutronUpdateException("Ruta no permitida en el paquete: $rel");
            }
            $target = $dest . '/' . $rel;
            neutron_ensure_dir(dirname($target));
            $in = $zip->getStream($name);
            $out = $in ? fopen($target, 'wb') : false;
            if (!$in || !$out || stream_copy_to_stream($in, $out) === false) {
                throw new NeutronUpdateException("No se puede extraer $rel");
            }
            fclose($in);
            fclose($out);
        }
    } finally {
        $zip->close();
    }
}

function neutron_update_place(string $source, string $target, string $backup, array &$touched): void {
    neutron_ensure_dir(dirname($target));
    $saved = null;
    if (is_file($target)) {
        neutron_ensure_dir(dirname($backup));
        if (!copy($target, $backup)) {
            throw new RuntimeException("No se puede guardar el original de $target");
        }
        $saved = $backup;
    }
    $touched[] = [$target, $saved];
    $tmp = $target . '.nt-new';
    if (!copy($source, $tmp) || !rename($tmp, $target)) {
        @unlink($tmp);
        throw new RuntimeException("No se puede escribir $target (¿permisos?)");
    }
}

function neutron_update_remove(string $target, string $backup, array &$touched): void {
    neutron_ensure_dir(dirname($backup));
    if (!copy($target, $backup)) {
        throw new RuntimeException("No se puede guardar el original de $target");
    }
    $touched[] = [$target, $backup];
    if (!unlink($target)) {
        throw new RuntimeException("No se puede borrar $target");
    }
}

// Deshace los cambios hechos hasta ahora; devuelve cuántos ficheros ha restaurado.
function neutron_update_undo(array &$touched): int {
    $n = 0;
    foreach (array_reverse($touched) as [$target, $saved]) {
        $saved === null ? @unlink($target) : @copy($saved, $target);
        $n++;
    }
    $touched = [];
    return $n;
}

// Que la siguiente petición compile los ficheros nuevos aunque opcache no revise fechas.
function neutron_update_flush_opcache(array $files): void {
    if (function_exists('opcache_invalidate')) {
        foreach ($files as $file) {
            if (str_ends_with($file, '.php')) @opcache_invalidate($file, true);
        }
    }
    if (function_exists('opcache_reset')) @opcache_reset();
}
