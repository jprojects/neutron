<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Copias de seguridad en data/backups/ (protegida por data/.htaccess).
//
// Neutron no tiene base de datos; los tipos se llaman como en Quasar para que Cosmo los use igual:
//   full   toda la instalación (núcleo, site/, config.local.php y data/ salvo logs y copias)
//   files  lo mismo que full
//   db     solo el sitio: site/ (plantilla, contenido, páginas, imágenes y subidas)
//
// Cada copia es un ZIP con backup.json (también como comentario del ZIP, para listar rápido) y files/….

const NEUTRON_BACKUP_TYPES = ['full', 'db', 'files'];
const NEUTRON_BACKUP_NAME = '/^backup-\d{8}-\d{6}-(full|db|files)-[0-9a-f]{6}\.zip$/';

function neutron_backups_dir(): string {
    return neutron_data_dir('backups');
}

function neutron_backup_create(string $type, string $label = ''): array {
    if (!in_array($type, NEUTRON_BACKUP_TYPES, true)) {
        throw new InvalidArgumentException("Tipo de copia no válido: $type");
    }
    if (!class_exists(ZipArchive::class)) {
        throw new RuntimeException('Falta la extensión zip de PHP');
    }
    $started = microtime(true);
    $name = sprintf('backup-%s-%s-%s.zip', date('Ymd-His'), $type, bin2hex(random_bytes(3)));
    $file = neutron_backups_dir() . '/' . $name;
    $partial = $file . '.part';

    $meta = [
        'app' => 'neutron',
        'version' => neutron_version(),
        'name' => neutron_site_name(),
        'type' => $type,
        'label' => mb_substr($label, 0, 100),
        'created_at' => date(DATE_ATOM),
        'db' => null,
        'files' => null,
    ];

    try {
        $zip = new ZipArchive();
        if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("No se puede crear $partial");
        }
        $count = $bytes = 0;
        $root = $type === 'db' ? NEUTRON_SITE : NEUTRON_ROOT;
        $prefix = $type === 'db' ? 'files/site/' : 'files/';
        if (is_dir($root)) {
            foreach (neutron_walk($root) as $rel => $abs) {
                $zip->addFile($abs, $prefix . $rel);
                $count++;
                $bytes += (int)filesize($abs);
            }
        }
        $meta['files'] = ['count' => $count, 'bytes' => $bytes];
        $zip->addFromString('backup.json', json_encode($meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $zip->setArchiveComment((string)json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) {
            throw new RuntimeException('No se pudo cerrar el ZIP: ' . $zip->getStatusString());
        }
        if (!rename($partial, $file)) {
            throw new RuntimeException("No se puede mover la copia a $file");
        }
    } catch (Throwable $e) {
        @unlink($partial);
        throw $e;
    }

    neutron_backups_prune();
    neutron_log('notice', 'Copia {type} creada: {file}', ['type' => $type, 'file' => $name, 'bytes' => filesize($file)]);
    return neutron_backup_describe($file) + ['sha256' => hash_file('sha256', $file), 'seconds' => round(microtime(true) - $started, 1)];
}

/** @return list<array> la más reciente primero */
function neutron_backups_list(): array {
    $out = [];
    foreach (glob(neutron_backups_dir() . '/backup-*.zip') ?: [] as $file) {
        if (preg_match(NEUTRON_BACKUP_NAME, basename($file))) {
            $out[] = neutron_backup_describe($file);
        }
    }
    usort($out, static fn(array $a, array $b) => strcmp($b['file'], $a['file']));
    return $out;
}

// Ruta de una copia por su nombre, o null si no existe / el nombre no es válido.
function neutron_backup_path(string $name): ?string {
    if (!preg_match(NEUTRON_BACKUP_NAME, $name)) return null;
    $file = neutron_backups_dir() . '/' . $name;
    return is_file($file) ? $file : null;
}

function neutron_backup_delete(string $name): bool {
    $file = neutron_backup_path($name);
    return $file !== null && unlink($file);
}

// Conserva las remote.backups_keep más recientes.
function neutron_backups_prune(): void {
    $keep = max(1, (int)(neutron_config()['remote']['backups_keep'] ?? 5));
    foreach (array_slice(neutron_backups_list(), $keep) as $old) {
        @unlink(neutron_backups_dir() . '/' . $old['file']);
    }
}

function neutron_backup_describe(string $file): array {
    $meta = [];
    if (class_exists(ZipArchive::class)) {
        $zip = new ZipArchive();
        if ($zip->open($file, ZipArchive::RDONLY) === true) {
            $meta = json_decode((string)$zip->getArchiveComment(), true) ?: [];
            $zip->close();
        }
    }
    preg_match(NEUTRON_BACKUP_NAME, basename($file), $m);
    return [
        'file' => basename($file),
        'size' => (int)filesize($file),
        'type' => $meta['type'] ?? ($m[1] ?? null),
        'label' => $meta['label'] ?? '',
        'version' => $meta['version'] ?? null,
        'created_at' => $meta['created_at'] ?? date(DATE_ATOM, (int)filemtime($file)),
    ];
}

function neutron_site_name(): string {
    $name = (string)neutron_config()['name'];
    return $name !== '' ? $name : preg_replace('/[^a-z0-9.:-]/i', '', $_SERVER['HTTP_HOST'] ?? 'Neutron');
}
