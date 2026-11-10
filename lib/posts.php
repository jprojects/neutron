<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/sanitize.php';

// Colecciones: blog, noticias, documentos, galería, vídeos, eventos… Una colección es una carpeta del sitio:
//
//   site/blog/_item.html         (opcional) la página de cada elemento → /blog/mi-entrada; sin ella no hay página propia
//   site/blog/_collection.json   (opcional) la definición: campos, orden, qué se traduce; sin ella, la de un blog
//   site/blog/_categories.json   (opcional) categorías [{"id", "title", "description", "i18n": {"es": {...}}}]
//   site/blog/mi-entrada.json    un elemento: sus campos, "status" (draft | published) y, traducidos, "i18n"
//
// Hace falta _item.html o _collection.json. Los listados (data-posts) van en cualquier página; el de una colección con
// página propia se abre en /blog/. Los borrados van a data/deleted/ (se pueden recuperar a mano).

const NEUTRON_RESERVED_DIRS = ['assets', 'pages', 'partials', 'uploads'];
const NEUTRON_FIELD_TYPES = ['text', 'textarea', 'html', 'date', 'image', 'images', 'file', 'category', 'url', 'video'];
// La definición de un blog: la de las colecciones sin _collection.json (las webs de antes de la 1.18)
const NEUTRON_BLOG_SCHEMA = [
    'fields' => [
        ['name' => 'title', 'type' => 'text', 'label' => 'Título'],
        ['name' => 'date', 'type' => 'date', 'label' => 'Fecha'],
        ['name' => 'image', 'type' => 'image', 'label' => 'Imagen'],
        ['name' => 'summary', 'type' => 'textarea', 'label' => 'Resumen'],
        ['name' => 'body', 'type' => 'html', 'label' => 'Texto'],
    ],
    // En una web de varios idiomas, estos van traducidos en "i18n": {"es": {...}}; lo de fuera es el idioma principal
    // (y lo que se ve si falta la traducción). La imagen, por si lleva texto (un cartel).
    'translated' => ['title', 'image', 'summary', 'body'],
    'sort' => '-date',
];

function neutron_collection_name_ok(string $name): bool {
    return (bool)preg_match('/^[a-z0-9][a-z0-9-]{0,40}$/', $name) && !in_array($name, NEUTRON_RESERVED_DIRS, true);
}

function neutron_collection_valid(string $name): bool {
    return neutron_collection_name_ok($name)
        && (is_file(NEUTRON_SITE . '/' . $name . '/_item.html') || is_file(NEUTRON_SITE . '/' . $name . '/_collection.json'));
}

// Tiene página propia por elemento (/blog/mi-entrada), listado en /blog/, RSS y entra en el sitemap
function neutron_collection_has_page(string $name): bool {
    return neutron_collection_name_ok($name) && is_file(NEUTRON_SITE . '/' . $name . '/_item.html');
}

/** @return list<string> */
function neutron_collections(): array {
    $out = [];
    foreach (glob(NEUTRON_SITE . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
        if (neutron_collection_valid(basename($dir))) $out[] = basename($dir);
    }
    sort($out);
    return $out;
}

/**
 * Definición de la colección: label, item, fields [{name, type, label, …}], translated, sort, per_page, page.
 * Un error en _collection.json se anota en el log y se usa la de un blog.
 */
function neutron_collection_schema(string $name): array {
    static $cache = [];
    if (isset($cache[$name])) return $cache[$name];
    $schema = NEUTRON_BLOG_SCHEMA;
    $file = NEUTRON_SITE . '/' . $name . '/_collection.json';
    if (is_file($file)) {
        $data = json_decode((string)file_get_contents($file), true);
        if (!is_array($data) || !is_array($data['fields'] ?? null)) {
            neutron_log('error', 'site/{name}/_collection.json no es válido: se usa la definición de un blog', ['name' => $name]);
        } else {
            $fields = [];
            foreach ($data['fields'] as $f) {
                if (!is_array($f) || !preg_match('/^[a-z][a-z0-9_]{0,30}$/', (string)($f['name'] ?? ''))
                    || in_array($f['name'], ['status', 'created', 'updated', 'i18n', 'order', 'slug', 'collection'], true)) continue;
                $f['type'] = in_array($f['type'] ?? '', NEUTRON_FIELD_TYPES, true) ? $f['type'] : 'text';
                $f['label'] = (string)($f['label'] ?? ucfirst($f['name']));
                $fields[] = $f;
            }
            if (!in_array('title', array_column($fields, 'name'), true)) array_unshift($fields, ['name' => 'title', 'type' => 'text', 'label' => 'Título']);
            $names = array_column($fields, 'name');
            $schema = [
                'fields' => $fields,
                'translated' => array_values(array_intersect(array_map('strval', (array)($data['translated'] ?? ['title'])), $names)),
                'sort' => in_array($data['sort'] ?? '', ['-date', 'date', 'title', '-title', 'order'], true) ? $data['sort'] : '-date',
                'label' => (string)($data['label'] ?? ''),
                'item' => (string)($data['item'] ?? ''),
                'help' => (string)($data['help'] ?? ''),
            ];
        }
    }
    $schema['label'] = ($schema['label'] ?? '') !== '' ? $schema['label'] : ucfirst(str_replace('-', ' ', $name));
    $schema['item'] = ($schema['item'] ?? '') !== '' ? $schema['item'] : 'Entrada';
    $schema['page'] = neutron_collection_has_page($name);
    $schema['custom'] = is_file($file);
    return $cache[$name] = $schema;
}

/** @return array<string,array> nombre => campo */
function neutron_collection_fields(string $name): array {
    $out = [];
    foreach (neutron_collection_schema($name)['fields'] as $f) $out[$f['name']] = $f;
    return $out;
}

/** Categorías de la colección (en el idioma que se ve): [{id, title, description}] */
function neutron_collection_categories(string $name, bool $raw = false): array {
    $file = NEUTRON_SITE . '/' . $name . '/_categories.json';
    $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
    $out = [];
    $lang = neutron_lang();
    $tr = $lang !== null && $lang !== neutron_languages()['default'];
    foreach ((array)$data as $c) {
        if (!is_array($c) || !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', (string)($c['id'] ?? ''))) continue;
        if ($raw) { $out[] = $c; continue; }
        $t = $tr && is_array($c['i18n'][$lang] ?? null) ? $c['i18n'][$lang] : [];
        $out[] = ['id' => $c['id'], 'title' => (string)(($t['title'] ?? '') !== '' ? $t['title'] : ($c['title'] ?? $c['id'])),
            'description' => (string)(($t['description'] ?? '') !== '' ? $t['description'] : ($c['description'] ?? ''))];
    }
    return $out;
}

function neutron_collection_categories_save(string $name, array $categories): bool {
    if (!neutron_collection_valid($name)) return false;
    $clean = [];
    foreach ($categories as $c) {
        if (!is_array($c) || !preg_match('/^[a-z0-9][a-z0-9-]{0,60}$/', (string)($c['id'] ?? '')) || trim((string)($c['title'] ?? '')) === '') continue;
        $row = ['id' => $c['id'], 'title' => trim((string)$c['title']), 'description' => trim((string)($c['description'] ?? ''))];
        if (is_array($c['i18n'] ?? null)) $row['i18n'] = $c['i18n'];
        $clean[] = $row;
    }
    $json = json_encode($clean, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    require_once __DIR__ . '/history.php';
    neutron_history_snapshot_item($name, '_categories.json', 'categories', neutron_collection_schema($name)['label']);
    return $json !== false && file_put_contents(NEUTRON_SITE . '/' . $name . '/_categories.json', $json, LOCK_EX) !== false;
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

// Valor de un campo según su tipo: imágenes, una lista de rutas; lo demás, texto
function neutron_post_value(array $field, mixed $value): string|array {
    if ($field['type'] === 'images') {
        return array_values(array_filter(array_map(fn($v) => is_string($v) ? $v : '', (array)$value), fn($v) => $v !== ''));
    }
    return is_string($value) ? $value : '';
}

function neutron_post_normalize(array $data, string $collection, string $slug): array {
    $schema = neutron_collection_schema($collection);
    $post = ['collection' => $collection, 'slug' => $slug];
    foreach ($schema['fields'] as $f) {
        $post[$f['name']] = neutron_post_value($f, $data[$f['name']] ?? '');
    }
    foreach (['date', 'status'] as $f) $post[$f] ??= is_string($data[$f] ?? null) ? $data[$f] : '';
    // Idioma que se ve (si no es el principal): su traducción encima; lo que no esté traducido, del principal
    $lang = neutron_lang();
    $post['translated'] = true;
    if ($lang !== null && $lang !== neutron_languages()['default']) {
        $tr = is_array($data['i18n'][$lang] ?? null) ? $data['i18n'][$lang] : [];
        $post['translated'] = ($tr['title'] ?? '') !== '';
        $fields = neutron_collection_fields($collection);
        foreach ($schema['translated'] as $f) {
            $v = isset($fields[$f]) ? neutron_post_value($fields[$f], $tr[$f] ?? '') : '';
            if (is_array($v) ? $v : trim(strip_tags($v)) !== '') $post[$f] = $v;
        }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $post['date'])) $post['date'] = '';
    if ($post['status'] !== 'published') $post['status'] = 'draft';
    $post['order'] = (int)($data['order'] ?? 0);
    $post['created'] = (string)($data['created'] ?? '');
    $post['updated'] = (string)($data['updated'] ?? '');
    return $post;
}

/**
 * Elementos de la colección en su orden (sort de la definición; por defecto, el más reciente primero, por fecha y, a
 * igual fecha, por creación).
 * @return list<array>
 */
function neutron_posts_list(string $collection, bool $withDrafts = false): array {
    if (!neutron_collection_valid($collection)) return [];
    $posts = [];
    foreach (glob(NEUTRON_SITE . '/' . $collection . '/*.json') ?: [] as $file) {
        $slug = basename($file, '.json');
        if ($slug[0] === '_') continue;
        $post = neutron_post_load($collection, $slug);
        if ($post !== null && ($withDrafts || $post['status'] === 'published')) $posts[] = $post;
    }
    $sort = neutron_collection_schema($collection)['sort'];
    usort($posts, match ($sort) {
        'date' => static fn(array $a, array $b) => [$a['date'], $a['created']] <=> [$b['date'], $b['created']],
        'title' => static fn(array $a, array $b) => strnatcasecmp((string)$a['title'], (string)$b['title']),
        '-title' => static fn(array $a, array $b) => strnatcasecmp((string)$b['title'], (string)$a['title']),
        'order' => static fn(array $a, array $b) => [$a['order'], $a['created']] <=> [$b['order'], $b['created']],
        default => static fn(array $a, array $b) => [$b['date'], $b['created']] <=> [$a['date'], $a['created']],
    });
    return $posts;
}

// Valor limpio para guardar: textos recortados, HTML por la lista blanca, rutas de imágenes y ficheros solo del sitio,
// fecha AAAA-MM-DD, categoría existente
function neutron_post_clean_value(string $collection, array $field, mixed $value): string|array {
    $local = fn(string $v) => preg_match('~^site/(uploads|assets)/[^\s"\'<>]+$~', $v) && !str_contains($v, '..') ? $v : '';
    return match ($field['type']) {
        'html' => neutron_sanitize_html((string)$value),
        'images' => array_values(array_filter(array_map(fn($v) => $local(trim((string)$v)), (array)$value))),
        'image', 'file' => $local(trim((string)$value)),
        'date' => preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$value, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? (string)$value : '',
        'category' => in_array((string)$value, array_column(neutron_collection_categories($collection, true), 'id'), true) ? (string)$value : '',
        'url', 'video' => preg_match('~^(https?://|mailto:|tel:|site/)~i', trim((string)$value)) ? trim((string)$value) : '',
        default => trim(mb_substr((string)$value, 0, (int)($field['max'] ?? 5000))),
    };
}

// Guarda el elemento. En una web de varios idiomas, los campos que se traducen van al idioma $textLang (por defecto,
// el que se ve; fuera de "i18n" si es el principal) y se conservan las demás traducciones. Con $withTexts = false
// solo se guardan los campos que no se traducen y el estado (publicar desde /es/ no debe copiar el texto principal).
function neutron_post_save(array $post, bool $withTexts = true, ?string $textLang = null): bool {
    $file = neutron_post_file($post['collection'], $post['slug']);
    if ($file === null) return false;
    $schema = neutron_collection_schema($post['collection']);
    $old = is_file($file) ? json_decode((string)file_get_contents($file), true) : [];
    $data = is_array($old) ? $old : [];
    if (is_file($file)) {                                      // copia de cómo estaba (panel → Copias)
        require_once __DIR__ . '/history.php';
        neutron_history_snapshot_item($post['collection'], $post['slug'] . '.json', 'save', (string)($data['title'] ?? $post['slug']));
    }
    $lang = $textLang ?? neutron_lang();
    $translating = $lang !== null && $lang !== neutron_languages()['default'];
    $fields = $schema['fields'];
    $names = array_column($fields, 'name');
    if (!in_array('date', $names, true)) $fields[] = ['name' => 'date', 'type' => 'date'];
    $fields[] = ['name' => 'status', 'type' => 'text'];
    foreach ($fields as $f) {
        $name = $f['name'];
        if (!array_key_exists($name, $post)) continue;
        $isText = in_array($name, $schema['translated'], true);
        if ($isText && !$withTexts) continue;
        $value = neutron_post_clean_value($post['collection'], $f, $post[$name]);
        if ($translating && $isText) {
            // Lo que aún se ve en el idioma principal (sin traducir) no se copia a la traducción
            if (!isset($data['i18n'][$lang][$name]) && $value === ($data[$name] ?? '')) continue;
            $data['i18n'][$lang][$name] = $value;
        } else {
            $data[$name] = $value;
        }
    }
    if (isset($post['order'])) $data['order'] = (int)$post['order'];
    $data['created'] = ($post['created'] ?? '') ?: date(DATE_ATOM);
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
    require_once __DIR__ . '/history.php';
    $data = json_decode((string)file_get_contents($file), true);
    neutron_history_snapshot_item($collection, $slug . '.json', 'delete', (string)($data['title'] ?? $slug));
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
function neutron_posts_view(string $collection, array $opt, int $limit, int $perPage, string $filter = ''): array {
    $posts = neutron_posts_filter(neutron_posts_list($collection, !empty($opt['admin'])), $filter);
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

// data-posts-filter="category:marc-legal" (campo:valor; varios separados por comas, todos tienen que cumplirse)
function neutron_posts_filter(array $posts, string $filter): array {
    foreach (array_filter(array_map('trim', explode(',', $filter))) as $cond) {
        [$field, $value] = array_pad(explode(':', $cond, 2), 2, '');
        $posts = array_values(array_filter($posts, fn($p) => (string)(is_string($p[$field] ?? null) ? $p[$field] : '') === $value));
    }
    return $posts;
}

/**
 * data-posts-group="category": los elementos por categoría, en el orden de _categories.json; los que no tienen, al
 * final (título vacío). Solo las categorías con elementos.
 * @return list<array{id:string,title:string,description:string,posts:list<array>}>
 */
function neutron_posts_groups(string $collection, array $posts, string $field, string $other = ''): array {
    $groups = [];
    foreach (neutron_collection_categories($collection) as $c) $groups[$c['id']] = $c + ['posts' => []];
    $groups[''] = ['id' => '', 'title' => $other, 'description' => '', 'posts' => []];   // data-posts-group-other
    foreach ($posts as $p) {
        $id = is_string($p[$field] ?? null) && isset($groups[$p[$field]]) ? $p[$field] : '';
        $groups[$id]['posts'][] = $p;
    }
    return array_values(array_filter($groups, fn($g) => $g['posts']));
}

// Origen de un campo (data-post-src): una imagen tal cual; un vídeo, su dirección para insertarlo; «video.thumb», su
// imagen de vista previa
function neutron_post_src_of(array $post, string $spec): string {
    [$name, $part] = array_pad(explode('.', $spec, 2), 2, '');
    $type = neutron_collection_fields($post['collection'])[$name]['type'] ?? '';
    $value = is_string($post[$name] ?? null) ? $post[$name] : '';
    if ($value === '') return '';
    if ($type === 'video') return $part === 'thumb' ? neutron_video_thumb($value) : (string)neutron_video_embed($value);
    return $value;
}

// Texto de un campo para data-post="…": la fecha en el idioma de la web, el nombre de la categoría, y con punto, un dato
// del campo: «category.description», «file.size» (1,9 MB), «file.ext» (PDF), «file.name», «file.downloads»,
// «images.count»
function neutron_post_text(array $post, string $field, string $lang): string {
    [$name, $part] = array_pad(explode('.', $field, 2), 2, '');
    $type = neutron_collection_fields($post['collection'])[$name]['type'] ?? ($name === 'date' ? 'date' : 'text');
    $value = $post[$name] ?? '';
    if ($type === 'date' || $name === 'date') return neutron_post_format_date((string)$value, $lang);
    if ($type === 'category') {
        foreach (neutron_collection_categories($post['collection']) as $c) {
            if ($c['id'] === $value) return $part === 'description' ? $c['description'] : $c['title'];
        }
        return '';
    }
    if ($type === 'file') {
        $path = NEUTRON_ROOT . '/' . $value;
        return match ($part) {
            'size' => $value !== '' && is_file($path) ? neutron_human_size((int)filesize($path), $lang) : '',
            'ext' => strtoupper((string)pathinfo((string)$value, PATHINFO_EXTENSION)),
            'name' => basename((string)$value),
            'downloads' => (string)neutron_downloads_count($post['collection'], $post['slug'], $name),
            default => basename((string)$value),
        };
    }
    if ($type === 'images') return $part === 'count' ? (string)count((array)$value) : '';
    return is_string($value) && $type !== 'html' ? $value : '';
}

// 2081280 → «2 MB»; 768000 → «750 KB» (con coma decimal salvo en inglés)
function neutron_human_size(int $bytes, string $lang = 'ca'): string {
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = 0;
    $n = (float)$bytes;
    while ($n >= 1024 && $i < 3) { $n /= 1024; $i++; }
    $txt = $i === 0 ? (string)$bytes : number_format($n, $n < 10 ? 1 : 0, str_starts_with($lang, 'en') ? '.' : ',', '');
    return preg_replace('/[.,]0$/', '', $txt) . ' ' . $units[$i];
}

// Enlace de un campo (data-post-href): un fichero se descarga por download.php (cuenta las descargas); un enlace o
// un vídeo, tal cual. Vacío = el elemento se quita.
function neutron_post_href_of(array $post, string $field): string {
    $type = neutron_collection_fields($post['collection'])[$field]['type'] ?? '';
    $value = $post[$field] ?? '';
    if (!is_string($value) || $value === '') return '';
    if ($type === 'file') return 'download.php?c=' . rawurlencode($post['collection']) . '&i=' . rawurlencode($post['slug']) . '&f=' . rawurlencode($field);
    return $value;
}

// https://www.youtube.com/watch?v=ID, youtu.be/ID… → https://www.youtube-nocookie.com/embed/ID (Vimeo, igual). Si no
// se reconoce, null.
function neutron_video_embed(string $url): ?string {
    if (preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~', $url, $m)) {
        return 'https://www.youtube-nocookie.com/embed/' . $m[1];
    }
    if (preg_match('~vimeo\.com/(?:video/)?(\d+)~', $url, $m)) return 'https://player.vimeo.com/video/' . $m[1];
    return null;
}

// Imagen de vista previa de un vídeo de YouTube (para listados sin cargar el reproductor)
function neutron_video_thumb(string $url): string {
    return preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/|live/)|youtu\.be/)([\w-]{11})~', $url, $m)
        ? 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg' : '';
}

// Descargas de los ficheros (download.php): data/downloads.json {"coleccion/elemento/campo": n}
function neutron_downloads_count(string $collection, string $slug, string $field): int {
    static $counts = null;
    if ($counts === null) {
        $file = NEUTRON_DATA . '/downloads.json';
        $data = is_file($file) ? json_decode((string)file_get_contents($file), true) : null;
        $counts = is_array($data) ? $data : [];
    }
    return (int)($counts[$collection . '/' . $slug . '/' . $field] ?? 0);
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
    // Descripción e imagen: summary e image o, en una colección con otros campos, el primer texto y la primera imagen
    $desc = is_string($post['summary'] ?? null) ? trim($post['summary']) : '';
    $image = is_string($post['image'] ?? null) ? $post['image'] : '';
    foreach (neutron_collection_fields($post['collection']) as $name => $f) {
        $v = $post[$name] ?? '';
        if ($desc === '' && in_array($f['type'], ['textarea', 'html'], true) && is_string($v) && trim(strip_tags($v)) !== '') {
            $desc = trim((string)preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if (mb_strlen($desc) > 160) $desc = rtrim(mb_substr($desc, 0, 157)) . '…';
        }
        if ($image === '' && $f['type'] === 'image' && is_string($v)) $image = $v;
        if ($image === '' && $f['type'] === 'images' && is_array($v) && $v) $image = (string)$v[0];
    }
    $tags = [
        ['meta', 'name', 'description', $desc],
        ['link', 'rel', 'canonical', $url],
        ['meta', 'property', 'og:type', 'article'],
        ['meta', 'property', 'og:title', (string)$post['title']],
        ['meta', 'property', 'og:description', $desc],
        ['meta', 'property', 'og:url', $url],
        ['meta', 'property', 'og:image', $image !== '' ? neutron_site_url() . '/' . $image : ''],
    ];
    return array_values(array_filter($tags, fn($t) => $t[3] !== ''));
}
