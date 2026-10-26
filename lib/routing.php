<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Rutas de la petición y redirecciones de URLs antiguas (site/redirects.json).
//
// site/redirects.json — de la URL vieja a la nueva, relativas a la carpeta de la instalación:
//   {
//     "index.php/serveis/carta": "carta.html",
//     "index.php/avis-legal": "avis-legal.html",
//     "index.php?option=com_content&view=article&id=2": "politica-de-cookies.html",
//     "antic/*": "./"                        * al final = cualquier ruta que empiece así
//   }
// Se responde con 301. El destino puede ser una URL completa (https://…).

// Ruta pedida relativa a la instalación, sin barras a los lados: "", "index.php", "carta.html",
// "index.php/serveis/carta"…
function neutron_request_path(): string {
    $uri = rawurldecode((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH));
    $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
    if ($base !== '' && str_starts_with($uri, $base . '/')) {
        $uri = substr($uri, strlen($base));
    }
    return trim($uri, '/');
}

// Destino para la ruta pedida, o null si no hay redirección. Con $withQueryOnly solo vale una entrada que
// incluya la consulta (en la portada: "index.php?option=…" sí, "index.php" a secas nunca).
function neutron_redirect_target(string $path, bool $withQueryOnly = false): ?string {
    $file = NEUTRON_SITE . '/redirects.json';
    if (!is_file($file)) return null;
    $map = json_decode((string)file_get_contents($file), true);
    if (!is_array($map)) {
        neutron_log('error', 'site/redirects.json no es un JSON válido');
        return null;
    }
    $query = (string)($_SERVER['QUERY_STRING'] ?? '');
    $candidates = $query !== '' ? [$path . '?' . $query, $path] : [$path];
    if ($withQueryOnly) {
        if ($query === '') return null;
        $candidates = [$path . '?' . $query];
    }

    foreach ($candidates as $wanted) {
        foreach ($map as $from => $to) {
            if (!is_string($from) || !is_string($to)) continue;
            $from = trim($from, '/');
            if ($from === $wanted
                || (str_ends_with($from, '*') && str_starts_with($wanted, substr($from, 0, -1)))) {
                return $to;
            }
        }
    }
    return null;
}

function neutron_redirect(string $to): void {
    if (!preg_match('~^https?://~i', $to)) {
        // Relativa a la instalación
        $base = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php'))), '/');
        $to = $base . '/' . ltrim($to === './' ? '' : $to, '/');
    }
    header('Location: ' . $to, true, 301);
    exit;
}
