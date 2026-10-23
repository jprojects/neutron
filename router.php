<?php
// Router para el servidor de desarrollo de PHP, que no lee .htaccess:
//   php -S localhost:8000 router.php
// Reproduce las reglas de .htaccess (ficheros internos prohibidos y páginas del sitio).

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$rel = ltrim($path, '/');

if (preg_match('~^(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php|\.git)~', $rel)
    || preg_match('~^(lib|data|bin|examples|\.git)(/|$)~', $rel)
    || preg_match('~^site/(template\.html|[^/]+\.json|pages/)~', $rel)
    || str_contains($rel, '..')) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

if (preg_match('~^([a-z0-9][a-z0-9-]*)\.html$~', $rel, $m) && !is_file(__DIR__ . '/' . $rel)) {
    $_GET['page'] = $m[1];
    require __DIR__ . '/index.php';
    return true;
}

return false; // el servidor sirve el fichero (o index.php) tal cual
