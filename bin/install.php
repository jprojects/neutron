<?php
// Prepara una instalación de Neutron (CLI):
//
//   php bin/install.php                     comprueba el entorno, crea config.local.php y las carpetas
//   php bin/install.php --example=NOMBRE    además copia examples/NOMBRE a site/ (si site/ no existe)
//   php bin/install.php --password          cambia la contraseña del editor
//   php bin/install.php --token             genera un token nuevo para Cosmo
//
// Nunca sobrescribe site/ ni borra nada de config.local.php: solo añade o cambia las claves que se piden.

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

define('NEUTRON_APP', true);
require_once __DIR__ . '/../lib/bootstrap.php';

$opts = getopt('', ['example:', 'password', 'token', 'help']);
if (isset($opts['help'])) {
    echo preg_replace('/^\/\/ ?/m', '', implode("\n", array_slice(explode("\n", (string)file_get_contents(__FILE__)), 1, 8))), "\n";
    exit(0);
}

function out(string $s): void { fwrite(STDOUT, $s . "\n"); }
function ask(string $q): string { fwrite(STDOUT, $q); return trim((string)fgets(STDIN)); }
function ask_hidden(string $q): string {
    fwrite(STDOUT, $q);
    $tty = stream_isatty(STDIN);
    if ($tty) shell_exec('stty -echo');
    $v = trim((string)fgets(STDIN));
    if ($tty) { shell_exec('stty echo'); fwrite(STDOUT, "\n"); }
    return $v;
}

// --- entorno ---
out('Neutron ' . neutron_version() . ' · PHP ' . PHP_VERSION);
if (PHP_VERSION_ID < 80100) out('  ✗ Hace falta PHP 8.1 o superior');
foreach (['mbstring' => 'textos', 'fileinfo' => 'subida de imágenes', 'zip' => 'copias y actualizaciones (Cosmo)',
          'curl' => 'reCAPTCHA y autoprueba (sin curl se usa allow_url_fopen)', 'dom' => 'render (sin dom se usa el parser propio)'] as $ext => $for) {
    out(sprintf('  %s %s (%s)', extension_loaded($ext) ? '✓' : '✗', $ext, $for));
}

// --- carpetas ---
foreach (['logs', 'backups', 'remote'] as $d) neutron_data_dir($d);

if (!is_dir(NEUTRON_SITE)) {
    $example = $opts['example'] ?? null;
    $available = array_map('basename', glob(NEUTRON_ROOT . '/examples/*', GLOB_ONLYDIR) ?: []);
    if ($example === null && $available) {
        $example = ask('No hay site/. Ejemplo a copiar (' . implode(', ', $available) . ', Enter = ninguno): ');
    }
    if ($example) {
        if (!in_array($example, $available, true)) { out("  ✗ No existe examples/$example"); exit(1); }
        neutron_copy_tree(NEUTRON_ROOT . '/examples/' . $example, NEUTRON_SITE);
        out("  ✓ site/ creado a partir de examples/$example");
    }
}
if (is_dir(NEUTRON_SITE) && !is_dir(NEUTRON_SITE . '/uploads')) mkdir(NEUTRON_SITE . '/uploads', 0775, true);

// --- config.local.php ---
$localFile = NEUTRON_ROOT . '/config.local.php';
$isNew = !is_file($localFile);
$local = $isNew ? [] : (require $localFile);
$changed = false;

if ($isNew || isset($opts['password'])) {
    do {
        $p1 = ask_hidden('Contraseña del editor (?admin): ');
        $p2 = ask_hidden('Repítela: ');
        if ($p1 !== $p2 || strlen($p1) < 8) out('  Las contraseñas no coinciden o tienen menos de 8 caracteres.');
    } while ($p1 !== $p2 || strlen($p1) < 8);
    $local['admin_password_hash'] = password_hash($p1, PASSWORD_DEFAULT);
    $changed = true;
}
if ($isNew) {
    $local['name'] = ask('Nombre de la web (para Cosmo, Enter = el dominio): ');
    $site = ask('reCAPTCHA invisible · clave del sitio (Enter para ponerla luego): ');
    $secret = $site !== '' ? ask('reCAPTCHA invisible · clave secreta: ') : '';
    $local['recaptcha'] = ['site_key' => $site, 'secret_key' => $secret];
}
if ($isNew || isset($opts['token'])) {
    $answer = $isNew ? strtolower(ask('¿Activar la API remota para Cosmo? [S/n]: ')) : 's';
    if ($answer === '' || $answer[0] === 's' || $answer[0] === 'y') {
        $local['remote']['token'] = bin2hex(random_bytes(32));
        out("\n  Token para Cosmo (pégalo en «Nueva instalación»):\n  " . $local['remote']['token'] . "\n");
    }
    $changed = true;
}

if ($isNew || $changed) {
    $php = "<?php\nif (!defined('NEUTRON_APP')) { http_response_code(403); exit; }\n\n"
        . "// Configuración de esta instalación (no se versiona ni la toca una actualización). Ver config.php.\n"
        . 'return ' . neutron_export($local) . ";\n";
    file_put_contents($localFile, $php, LOCK_EX);
    @chmod($localFile, 0640);
    out('  ✓ ' . ($isNew ? 'Creado' : 'Actualizado') . ' config.local.php');
}

if (!neutron_recaptcha_keys($local)) out('  ! Sin reCAPTCHA: los formularios de contacto funcionan, pero sin protección anti-spam de Google.');
out("\nListo. Desarrollo: php -S localhost:8000 router.php  ·  editor: /?admin");

// ------------------------------------------------------------------

function neutron_recaptcha_keys(array $local): bool {
    return !empty($local['recaptcha']['site_key']) && !empty($local['recaptcha']['secret_key']);
}

function neutron_copy_tree(string $from, string $to): void {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
    if (!is_dir($to)) mkdir($to, 0775, true);
    foreach ($it as $f) {
        $dest = $to . '/' . substr($f->getPathname(), strlen($from) + 1);
        $f->isDir() ? (is_dir($dest) || mkdir($dest, 0775, true)) : copy($f->getPathname(), $dest);
    }
}

// var_export con sintaxis corta y sangría de 4 espacios
function neutron_export(mixed $value, int $level = 0): string {
    if (!is_array($value)) return var_export($value, true);
    $pad = str_repeat('    ', $level + 1);
    $list = array_is_list($value);
    $lines = [];
    foreach ($value as $k => $v) {
        $lines[] = $pad . ($list ? '' : var_export($k, true) . ' => ') . neutron_export($v, $level + 1) . ',';
    }
    return $lines ? "[\n" . implode("\n", $lines) . "\n" . str_repeat('    ', $level) . ']' : '[]';
}
