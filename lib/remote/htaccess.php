<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// .htaccess compartido entre el núcleo y la instalación (desde la 1.2.0).
//
// El núcleo solo es dueño del bloque "# BEGIN Neutron" … "# END Neutron". Lo que haya fuera (redirección a
// HTTPS, la versión de PHP que pone cPanel…) es de la instalación: una actualización sustituye el bloque y
// conserva el resto, y la integridad solo compara el bloque.

const NEUTRON_HTACCESS_BEGIN = '# BEGIN Neutron';
const NEUTRON_HTACCESS_END = '# END Neutron';

// .htaccess de la 1.0.0 y la 1.1.0, que no tenían bloque: se quitan enteros de un .htaccess sin marcas y lo
// que queda es de la instalación.
const NEUTRON_HTACCESS_LEGACY_FILES = [
    <<<'EOT'
# Neutron (núcleo: lo sustituye cada actualización)
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Ficheros internos: nunca se sirven
    RewriteRule ^(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php|\.git.*)$ - [F,L]
    RewriteRule ^(lib|data|bin|examples|\.git)(/|$) - [F,L]
    RewriteRule ^site/(template\.html|[^/]+\.json|pages/) - [F,L]

    # Páginas del sitio: avis-legal.html → index.php?page=avis-legal (site/pages/avis-legal.html)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^([a-z0-9][a-z0-9-]*)\.html$ index.php?page=$1 [QSA,L]
</IfModule>

# La API remota (Cosmo) lee el token de Authorization en hostings con PHP como CGI/FPM
<IfModule mod_setenvif.c>
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
</IfModule>
EOT,
    <<<'EOT'
# Neutron (núcleo: lo sustituye cada actualización)
Options -Indexes
DirectoryIndex index.php

<IfModule mod_rewrite.c>
    RewriteEngine On

    # Ficheros internos: nunca se sirven
    RewriteRule ^(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php|\.git.*)$ - [F,L]
    RewriteRule ^(lib|data|bin|examples|\.git)(/|$) - [F,L]
    RewriteRule ^site/(template\.html|[^/]+\.json|pages/|partials/) - [F,L]

    # Páginas del sitio: avis-legal.html → index.php?page=avis-legal (site/pages/avis-legal.html)
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteRule ^([a-z0-9][a-z0-9-]*)\.html$ index.php?page=$1 [QSA,L]

    # Cualquier otra ruta que no exista (URLs de la web anterior): index.php decide entre
    # site/redirects.json y 404
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>

# La API remota (Cosmo) lee el token de Authorization en hostings con PHP como CGI/FPM
<IfModule mod_setenvif.c>
    SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1
</IfModule>
EOT,
];

// Si alguien editó por dentro uno de esos ficheros, se quitan sus líneas una a una (recortadas)…
const NEUTRON_HTACCESS_LEGACY_LINES = [
    '# Neutron (núcleo: lo sustituye cada actualización)',
    'Options -Indexes',
    'DirectoryIndex index.php',
    '<IfModule mod_rewrite.c>',
    'RewriteEngine On',
    '# Ficheros internos: nunca se sirven',
    'RewriteRule ^(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php|\.git.*)$ - [F,L]',
    'RewriteRule ^(lib|data|bin|examples|\.git)(/|$) - [F,L]',
    'RewriteRule ^site/(template\.html|[^/]+\.json|pages/) - [F,L]',
    'RewriteRule ^site/(template\.html|[^/]+\.json|pages/|partials/) - [F,L]',
    '# Páginas del sitio: avis-legal.html → index.php?page=avis-legal (site/pages/avis-legal.html)',
    'RewriteCond %{REQUEST_FILENAME} !-f',
    'RewriteCond %{REQUEST_FILENAME} !-d',
    'RewriteRule ^([a-z0-9][a-z0-9-]*)\.html$ index.php?page=$1 [QSA,L]',
    '# Cualquier otra ruta que no exista (URLs de la web anterior): index.php decide entre',
    '# site/redirects.json y 404',
    'RewriteRule ^ index.php [QSA,L]',
    '</IfModule>',
    '# La API remota (Cosmo) lee el token de Authorization en hostings con PHP como CGI/FPM',
    '<IfModule mod_setenvif.c>',
    'SetEnvIf Authorization "(.+)" HTTP_AUTHORIZATION=$1',
];

// …salvo las estructurales, que también pueden ser de reglas propias (y si quedan vacías, se borran).
const NEUTRON_HTACCESS_STRUCTURAL = ['<IfModule mod_rewrite.c>', '<IfModule mod_setenvif.c>', '</IfModule>', 'RewriteEngine On'];

// El bloque del núcleo, de "# BEGIN Neutron" a "# END Neutron" (incluidas, con el salto final), o null.
function neutron_htaccess_block(string $content): ?string {
    $start = strpos($content, NEUTRON_HTACCESS_BEGIN);
    $end = $start === false ? false : strpos($content, NEUTRON_HTACCESS_END, $start);
    if ($end === false) return null;
    $end += strlen(NEUTRON_HTACCESS_END);
    return substr($content, $start, $end - $start) . "\n";
}

// Lo que la integridad y el manifiesto comparan: solo el bloque (o el fichero entero si no tiene).
function neutron_htaccess_hash(string $content): string {
    return sha1(neutron_htaccess_block($content) ?? $content);
}

// Líneas que añadió la instalación a un .htaccess: fuera del bloque, o, sin bloque (1.0.0/1.1.0), lo que no es
// de esas versiones.
function neutron_htaccess_custom(string $content): string {
    $content = str_replace("\r\n", "\n", $content);
    $block = neutron_htaccess_block($content);
    if ($block !== null) {
        $outside = str_replace(rtrim($block, "\n"), '', $content);
    } else {
        $outside = null;
        foreach (NEUTRON_HTACCESS_LEGACY_FILES as $legacy) {
            if (str_contains($content, rtrim($legacy, "\n"))) {
                $outside = str_replace(rtrim($legacy, "\n"), '', $content);
                break;
            }
        }
        if ($outside === null) {
            $keep = [];
            foreach (explode("\n", $content) as $line) {
                $t = trim($line);
                if (!in_array($t, NEUTRON_HTACCESS_LEGACY_LINES, true) || in_array($t, NEUTRON_HTACCESS_STRUCTURAL, true)) {
                    $keep[] = rtrim($line);
                }
            }
            $outside = implode("\n", $keep);
            // Secciones que se han quedado vacías (eran de Neutron)
            $outside = preg_replace('~<IfModule [^>]+>\s*(RewriteEngine On\s*)?</IfModule>~i', '', $outside);
        }
    }
    $outside = trim(preg_replace("/\n{3,}/", "\n\n", $outside), "\n");
    return trim($outside) === '' ? '' : $outside . "\n";
}

// .htaccess resultante: lo propio de la instalación arriba (las reglas se evalúan antes que las del núcleo)
// y el bloque nuevo debajo.
function neutron_htaccess_merge(string $current, string $package): string {
    $block = neutron_htaccess_block($package) ?? $package;
    $custom = neutron_htaccess_custom($current);
    return $custom === '' ? $block : $custom . "\n" . $block;
}

// Paso de migrate para las instalaciones que llegan de la 1.0.0/1.1.0: su actualizador copió el .htaccess
// entero y se perdieron las líneas propias. Se recuperan de la copia completa previa a la actualización.
// Devuelve el texto del paso, o null si no hay nada que hacer.
function neutron_htaccess_recover(): ?string {
    $marker = neutron_remote_dir() . '/htaccess-recovered.json';
    $last = neutron_updates()[0] ?? null;
    if ($last === null || is_file($marker) || version_compare((string)($last['from'] ?? '0'), '1.2.0', '>=')) {
        return null;
    }
    $result = 'sin líneas propias';
    $file = NEUTRON_ROOT . '/.htaccess';
    $backup = null;
    foreach (neutron_backups_list() as $b) {
        if ($b['type'] === 'full' && $b['version'] === $last['from']) { $backup = $b; break; }
    }
    if ($backup === null) {
        $result = 'no hay copia completa de la ' . $last['from'] . ': revisa que el .htaccess conserva las reglas propias';
    } else {
        $old = false;
        $zip = new ZipArchive();
        if ($zip->open(neutron_backups_dir() . '/' . $backup['file'], ZipArchive::RDONLY) === true) {
            $old = $zip->getFromName('files/.htaccess');
            $zip->close();
        }
        $custom = $old === false ? '' : neutron_htaccess_custom($old);
        $current = (string)@file_get_contents($file);
        if ($custom !== '' && !str_contains($current, trim($custom))) {
            $block = neutron_htaccess_block($current) ?? $current;
            if (file_put_contents($file, $custom . "\n" . $block, LOCK_EX) === false) {
                throw new RuntimeException('No se puede escribir el .htaccess');
            }
            $lines = count(array_filter(explode("\n", $custom), fn($l) => trim($l) !== ''));
            $result = "$lines líneas propias recuperadas de " . $backup['file'];
            neutron_log('notice', '.htaccess: {result}', ['result' => $result]);
        }
    }
    neutron_write_json($marker, ['at' => date(DATE_ATOM), 'from' => $last['from'], 'result' => $result]);
    return '.htaccess: ' . $result;
}
