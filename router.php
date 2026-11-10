<?php
// Router para el servidor de desarrollo de PHP, que no lee .htaccess:
//   php -S localhost:8000 router.php
// Reproduce las reglas de .htaccess (ficheros internos prohibidos, páginas del sitio y el resto a index.php).

$path = rawurldecode((string)parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$rel = ltrim($path, '/');

if (preg_match('~^(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php|\.git)~', $rel)
    || preg_match('~^(lib|data|bin|examples|tools|\.git)(/|$)~', $rel)
    || preg_match('~^site/(template\.html|.+\.json$|pages/|partials/|[^/]+/_item\.html)~', $rel)
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

// Ficheros que existen (y /index.php/…): el servidor los sirve tal cual. El resto, a index.php
// (redirecciones de site/redirects.json o 404), igual que la última regla de .htaccess.
// Carpeta con index.php (el panel, /admin/): como DirectoryIndex; sin la barra final, se añade (como mod_dir)
if ($rel !== '' && is_dir(__DIR__ . '/' . rtrim($rel, '/')) && is_file(__DIR__ . '/' . rtrim($rel, '/') . '/index.php')) {
    if (!str_ends_with($rel, '/')) {
        header('Location: /' . $rel . '/' . (($q = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY)) ? '?' . $q : ''), true, 301);
        return true;
    }
    return false;
}
if ($rel === '' || is_file(__DIR__ . '/' . $rel) || preg_match('~^index\.php(/|$)~', $rel)) {
    return false;
}
require __DIR__ . '/index.php';
return true;
