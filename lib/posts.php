<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/sanitize.php';

// Colecciones con página propia (blog, noticias…). Una colección es una carpeta del sitio con su plantilla:
//
//   site/blog/_item.html        la página de una entrada (data-post="title", data-post-html="body"…)
//   site/blog/mi-entrada.json   una entrada: título, fecha, imagen, resumen, cuerpo y estado
//   site/pages/blog.html        el listado (data-posts="blog"), que se abre en /blog/
//
// La entrada se abre en /blog/mi-entrada. Las borradas van a data/deleted/ (se pueden recuperar a mano).

const NEUTRON_POST_FIELDS = ['title', 'date', 'image', 'summary', 'body', 'status'];
// En una web de varios idiomas, estos van traducidos en "i18n": {"es": {...}, "en": {...}}; lo de fuera es el idioma
// principal (y lo que se ve si falta la traducción). La imagen, por si lleva texto (un cartel); fecha y estado son
// comunes.
const NEUTRON_POST_TRANSLATED = ['title', 'image', 'summary', 'body'];
const NEUTRON_RESERVED_DIRS = ['assets', 'pages', 'partials', 'uploads'];

function neutron_collection_valid(string $name): bool {
    return preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $name) && !in_array($name, NEUTRON_RESERVED_DIRS, true)
        && is_file(NEUTRON_SITE . '/' . $name . '/_item.html');
}

/** @return list<string> */
function neutron_collections(): array {
    $out = [];
    foreach (glob(NEUTRON_SITE . '/*/_item.html') ?: [] as $file) {
        $name = basename(dirname($file));
        if (neutron_collection_valid($name)) $out[] = $name;
    }
    return $out;
}

function neutron_post_file(string $collection, string $slug): ?string {
    if (!neutron_collection_valid($collection) || !preg_match('/^[a-z0-9][a-z0-9-]{0,80}$/', $slug)) return null;
    return NEUTRON_SITE . '/' . $collection . '/' . $slug . '.json';
}

function neutron_post_load(string $collection, string $slug): ?array {
    $file = neutron_post_file($collection, $slug);
    if ($file === null || !is_file($file)) return null;
    $data = json_decode((string)file_get_contents($file), true);
    return is_array($data) ? neutron_post_normalize($data, $collection, $slug) : null;
}

function neutron_post_normalize(array $data, string $collection, string $slug): array {
    $post = ['collection' => $collection, 'slug' => $slug];
    foreach (NEUTRON_POST_FIELDS as $f) {
        $post[$f] = is_string($data[$f] ?? null) ? $data[$f] : '';
    }
    // Idioma que se ve (si no es el principal): su traducción encima; lo que no esté traducido, del principal
    $lang = neutron_lang();
    $post['translated'] = true;
    if ($lang !== null && $lang !== neutron_languages()['default']) {
        $tr = is_array($data['i18n'][$lang] ?? null) ? $data['i18n'][$lang] : [];
        $post['translated'] = ($tr['title'] ?? '') !== '';
        foreach (NEUTRON_POST_TRANSLATED as $f) {
            if (is_string($tr[$f] ?? null) && trim(strip_tags($tr[$f])) !== '') $post[$f] = $tr[$f];
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post['date'])) $post['date'] = '';
    if ($post['status'] !== 'published') $post['status'] = 'draft';
    $post['created'] = (string)($data['created'] ?? '');
    $post['updated'] = (string)($data['updated'] ?? '');
    return $post;
}

/**
 * Entradas de la colección, la más reciente primero (por fecha y, a igual fecha, por creación).
 * @return list<array>
 */
function neutron_posts_list(string $collection, bool $withDrafts = false): array {
    if (!neutron_collection_valid($collection)) return [];
    $posts = [];
    foreach (glob(NEUTRON_SITE . '/' . $collection . '/*.json') ?: [] as $file) {
        $slug = basename($file, '.json');
        $post = neutron_post_load($collection, $slug);
        if ($post !== null && ($withDrafts || $post['status'] === 'published')) $posts[] = $post;
    }
    usort($posts, static fn(array $a, array $b) => [$b['date'], $b['created']] <=> [$a['date'], $a['created']]);
    return $posts;
}

// Guarda la entrada. En una web de varios idiomas, título, resumen y cuerpo van al idioma $textLang (por defecto,
// el que se ve; fuera de "i18n" si es el principal) y se conservan las demás traducciones. Con $withTexts = false
// solo se guardan fecha, imagen y estado (publicar desde /es/ no debe copiar el texto principal como traducción).
function neutron_post_save(array $post, bool $withTexts = true, ?string $textLang = null): bool {
    $file = neutron_post_file($post['collection'], $post['slug']);
    if ($file === null) return false;
    $old = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $data = is_array($old) ? $old : [];
    $lang = $textLang ?? neutron_lang();
    $translating = $lang !== null && $lang !== neutron_languages()['default'];
    foreach (NEUTRON_POST_FIELDS as $f) {
        $isText = in_array($f, NEUTRON_POST_TRANSLATED, true);
        if ($isText && !$withTexts) continue;
        $value = (string)($post[$f] ?? '');
        if ($f === 'body') $value = neutron_sanitize_html($value);
        if ($translating && $isText) {
            // Lo que aún se ve en el idioma principal (sin traducir) no se copia a la traducción
            if (!isset($data['i18n'][$lang][$f]) && $value === (string)($data[$f] ?? '')) continue;
            $data['i18n'][$lang][$f] = $value;
        } else {
            $data[$f] = $value;
        }
    }
    $data['created'] = $post['created'] ?: date(DATE_ATOM);
    $data['updated'] = date(DATE_ATOM);
    $tmp = $file . '.' . bin2hex(random_bytes(4)) . '.tmp';
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || file_put_contents($tmp, $json, LOCK_EX) === false || !rename($tmp, $file)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

// Borrar = moverla a data/deleted/ (por si fue un error)
function neutron_post_delete(string $collection, string $slug): bool {
    $file = neutron_post_file($collection, $slug);
    if ($file === null || !is_file($file)) return false;
    return rename($file, neutron_data_dir('deleted') . '/' . $collection . '-' . $slug . '-' . date('Ymd-His') . '.json');
}

// "Què és el coaching amb cavalls?" → "que-es-el-coaching-amb-cavalls", sin repetir uno existente.
function neutron_post_slug(string $collection, string $title): string {
    $map = ['à'=>'a','á'=>'a','â'=>'a','ä'=>'a','è'=>'e','é'=>'e','ê'=>'e','ë'=>'e','ì'=>'i','í'=>'i','î'=>'i','ï'=>'i',
        'ò'=>'o','ó'=>'o','ô'=>'o','ö'=>'o','ù'=>'u','ú'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','·'=>'','l·l'=>'ll'];
    $s = strtr(mb_strtolower(trim($title)), $map);
    $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', $s), '-');
    $s = substr($s !== '' ? $s : 'entrada', 0, 70);
    $s = rtrim($s, '-');
    $slug = $s;
    for ($i = 2; is_file((string)neutron_post_file($collection, $slug)) || in_array($slug, ['feed'], true); $i++) {
        $slug = $s . '-' . $i;
    }
    return $slug;
}

// "2026-11-02" → "2 de novembre de 2026" en el idioma de la web (ca, es, fr; en otro caso, en inglés)
function neutron_post_format_date(string $ymd, string $lang): string {
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m)) return '';
    [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
    $lang = strtolower(substr($lang, 0, 2));
    if ($lang === 'ca') {
        $months = ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre'];
        $name = $months[$mo - 1];
        return $d . (in_array($name[0], ['a', 'o'], true) ? " d'" : ' de ') . $name . ' de ' . $y;
    }
    if ($lang === 'es') {
        $months = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        return $d . ' de ' . $months[$mo - 1] . ' de ' . $y;
    }
    if ($lang === 'fr') {
        $months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
        return ($d === 1 ? '1er' : $d) . ' ' . $months[$mo - 1] . ' ' . $y;
    }
    $months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return $months[$mo - 1] . ' ' . $d . ', ' . $y;
}

// URL absoluta de la instalación (para RSS, sitemap y etiquetas og:)
function neutron_site_url(): string {
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    return ($https ? 'https' : 'http') . '://' . $host . neutron_base_path();
}

// --- para el render (los dos motores) ---

/**
 * Entradas de un listado y su paginación. $opt: admin (ver borradores), page (?p=), self (ruta de la página).
 * @return array{posts:list<array>,empty:?string,prev:?string,next:?string} null = quitar ese elemento
 */
function neutron_posts_view(string $collection, array $opt, int $limit, int $perPage): array {
    $posts = neutron_posts_list($collection, !empty($opt['admin']));
    // En la página de una entrada, sus listados («Otras entradas») no la repiten
    if (!empty($opt['post']) && $opt['post']['collection'] === $collection) {
        $posts = array_values(array_filter($posts, fn($p) => $p['slug'] !== $opt['post']['slug']));
    }
    $view = ['posts' => $posts, 'empty' => $posts ? null : '', 'prev' => null, 'next' => null];
    if ($perPage > 0) {
        $pages = max(1, (int)ceil(count($posts) / $perPage));
        $page = min(max(1, (int)($opt['page'] ?? 1)), $pages);
        $view['posts'] = array_slice($posts, ($page - 1) * $perPage, $perPage);
        $self = (string)($opt['self'] ?? '');
        $url = fn(int $n) => $n > 1 ? $self . '?p=' . $n : ($self !== '' ? $self : './');
        if ($page > 1) $view['prev'] = $url($page - 1);
        if ($page < $pages) $view['next'] = $url($page + 1);
    } elseif ($limit > 0) {
        $view['posts'] = array_slice($posts, 0, $limit);
    }
    return $view;
}

// Texto de un campo para data-post="…" (la fecha, ya en el idioma de la web)
function neutron_post_text(array $post, string $field, string $lang): string {
    if ($field === 'date') return neutron_post_format_date($post['date'], $lang);
    return is_string($post[$field] ?? null) && $field !== 'body' ? $post[$field] : '';
}

// <title data-post="title">Entrada · Salut i Cavalls</title> → "Título de la entrada · Salut i Cavalls"
function neutron_post_title_text(string $current, string $title): string {
    return preg_match('/\s[·|–—-]\s(.+)$/u', $current, $m) ? $title . ' · ' . trim($m[1]) : $title;
}

// Enlace a la entrada, relativo a la instalación (las páginas de colección llevan <base>)
function neutron_post_href(array $post): string {
    return $post['collection'] . '/' . $post['slug'];
}

/** @return list<array{0:string,1:string,2:string,3:string}> [etiqueta, atributo clave, valor clave, contenido] */
function neutron_post_meta_tags(array $post): array {
    $url = neutron_site_url() . '/' . neutron_lang_prefix() . neutron_post_href($post);
    $tags = [
        ['meta', 'name', 'description', $post['summary']],
        ['link', 'rel', 'canonical', $url],
        ['meta', 'property', 'og:type', 'article'],
        ['meta', 'property', 'og:title', $post['title']],
        ['meta', 'property', 'og:description', $post['summary']],
        ['meta', 'property', 'og:url', $url],
        ['meta', 'property', 'og:image', $post['image'] !== '' ? neutron_site_url() . '/' . $post['image'] : ''],
    ];
    return array_values(array_filter($tags, fn($t) => $t[3] !== ''));
}
