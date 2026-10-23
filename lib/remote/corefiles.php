<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Qué ficheros son del núcleo (core.json) y cuáles instaló la última actualización (manifiesto).
// El manifiesto (data/remote/manifest.json) guarda el sha1 de cada fichero del núcleo instalado;
// sirve para detectar cambios hechos a mano y para borrar los que una versión nueva ya no trae.

// Carpetas que nunca se recorren
const NEUTRON_SKIP_DIRS = ['.git', 'node_modules', 'data/backups', 'data/remote', 'data/logs'];

/** @return list<string> patrones de core.json de la carpeta $root */
function neutron_core_patterns(string $root): array {
    $data = neutron_read_json($root . '/core.json');
    $core = is_array($data['core'] ?? null) ? $data['core'] : [];
    return array_values(array_filter($core, 'is_string'));
}

// ** = cualquier subcarpeta, * = dentro de una carpeta
function neutron_core_matches(string $path, array $patterns): bool {
    foreach ($patterns as $pattern) {
        $regex = '~^' . strtr(preg_quote($pattern, '~'), ['\*\*' => '.*', '\*' => '[^/]*']) . '$~';
        if (preg_match($regex, $path)) return true;
    }
    return false;
}

/** @return array<string,string> ruta relativa => sha1 de los ficheros del núcleo bajo $root */
function neutron_core_scan(string $root, array $patterns): array {
    $files = [];
    foreach (neutron_walk($root) as $rel => $abs) {
        if (neutron_core_matches($rel, $patterns)) {
            $files[$rel] = (string)sha1_file($abs);
        }
    }
    ksort($files);
    return $files;
}

/** @return Generator<string,string> ruta relativa => absoluta, sin las carpetas de NEUTRON_SKIP_DIRS */
function neutron_walk(string $root, array $extraSkip = []): Generator {
    $skip = array_merge(NEUTRON_SKIP_DIRS, $extraSkip);
    $it = new RecursiveIteratorIterator(
        new RecursiveCallbackFilterIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            static function (SplFileInfo $f) use ($root, $skip): bool {
                $rel = substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1);
                return !($f->isDir() && in_array($rel, $skip, true)) && !$f->isLink();
            }
        )
    );
    foreach ($it as $f) {
        if ($f->isFile()) {
            yield substr(str_replace('\\', '/', $f->getPathname()), strlen($root) + 1) => $f->getPathname();
        }
    }
}

function neutron_manifest_file(): string {
    return NEUTRON_DATA . '/remote/manifest.json';
}

/** @return array{version:string,installed_at:string,files:array<string,string>}|null */
function neutron_manifest(): ?array {
    $data = neutron_read_json(neutron_manifest_file());
    return is_array($data['files'] ?? null) ? $data : null;
}

function neutron_save_manifest(string $version, array $files): void {
    neutron_write_json(neutron_manifest_file(), [
        'version' => $version,
        'installed_at' => date(DATE_ATOM),
        'files' => $files,
    ]);
}

/** @return array{modified:list<string>,missing:list<string>}|null null si no hay manifiesto */
function neutron_core_check(): ?array {
    $manifest = neutron_manifest();
    if ($manifest === null) return null;
    $modified = $missing = [];
    foreach ($manifest['files'] as $rel => $hash) {
        $abs = NEUTRON_ROOT . '/' . $rel;
        if (!is_file($abs)) {
            $missing[] = $rel;
        } elseif (sha1_file($abs) !== $hash) {
            $modified[] = $rel;
        }
    }
    return ['modified' => $modified, 'missing' => $missing];
}
