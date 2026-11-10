<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Parser HTML mínimo (sin ext-dom) para hostings que no traen esa extensión.
// Tokeniza y arma un árbol de stdClass; no es un parser HTML completo,
// pero cubre lo que las plantillas de Neutron necesitan.

function neutron_render_fallback(string $html, array $content, array $options = []): string {
    $root = neutron_parse_html($html);

    // Menú editable, igual que en render_dom.php
    $menu = neutron_menu_items();
    if ($menu !== null) neutron_fallback_apply_menu($root, $menu);

    // Listas de primer nivel; las anidadas las rellena su elemento (igual que en render_dom.php)
    foreach (neutron_fallback_own($root, fn($n) => isset($n->attrs['data-edit-collection'])) as $container) {
        $key = $container->attrs['data-edit-collection'];
        if (isset($content[$key]) && is_array($content[$key])) {
            neutron_fallback_fill_collection($container, $content[$key]);
        }
    }

    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-edit'])) as $node) {
        $key = $node->attrs['data-edit'];
        if (!array_key_exists($key, $content)) continue;
        neutron_set_text($node, (string)$content[$key]);
    }

    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-edit-img'])) as $node) {
        $key = $node->attrs['data-edit-img'];
        if (!array_key_exists($key, $content)) continue;
        $node->attrs['src'] = (string)$content[$key];
    }

    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-edit-content'])) as $node) {
        $key = $node->attrs['data-edit-content'];
        if (array_key_exists($key, $content) && is_string($content[$key])) $node->attrs['content'] = $content[$key];
    }

    // Texto con formato, igual que en render_dom.php
    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-edit-html'])) as $node) {
        $key = $node->attrs['data-edit-html'];
        if (!array_key_exists($key, $content) || !is_string($content[$key])) continue;
        $html = neutron_sanitize_html($content[$key]);
        $node->children = $html === '' ? [] : neutron_parse_html($html)->children;
    }

    // Enlaces editables, igual que en render_dom.php
    neutron_fallback_filter($root, function (object $node) use ($content, $options): bool {
        if (!isset($node->attrs['data-edit-href'])) return true;
        [$href, $remove, $attrs] = neutron_edit_href($content, $node->attrs['data-edit-href'], $node->attrs['href'] ?? '', !empty($options['editing']));
        if ($remove) return false;
        $node->attrs['href'] = $href;
        foreach ($attrs as $name => $value) $node->attrs[$name] = $value;
        return true;
    });

    // Colecciones (blog), igual que en render_dom.php
    if (isset($options['posts'])) {
        neutron_fallback_fill_posts($root, $options['posts']);
    }

    // Antes que los idiomas: compara con los enlaces tal como están en la plantilla
    if (isset($options['current_href'])) {
        foreach (neutron_find_all($root, fn($n) => $n->tag === 'a' && isset($n->attrs['href'])) as $a) {
            if (in_array(neutron_normalize_href($a->attrs['href']), (array)$options['current_href'], true)) {
                $a->attrs['aria-current'] = 'page';
            }
        }
    }

    // Varios idiomas, igual que en render_dom.php
    if (!empty($options['lang'])) {
        neutron_fallback_apply_lang($root, $options['lang']);
    }

    // Imágenes adaptadas a la pantalla, igual que en render_dom.php
    if (empty($options['editing'])) {
        foreach (neutron_find_all($root, fn($n) => $n->tag === 'img' && isset($n->attrs['src']) && !isset($n->attrs['srcset'])) as $img) {
            $set = neutron_image_srcset((string)$img->attrs['src']);
            if ($set === '') continue;
            $img->attrs['srcset'] = $set;
            $img->attrs['sizes'] ??= '100vw';
        }
    }

    if (!empty($options['base'])) {
        $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
        if ($head) {
            array_unshift($head->children, (object)['type' => 'element', 'tag' => 'base', 'attrs' => ['href' => $options['base']], 'children' => []]);
        }
    }

    if (!empty($options['form_bootstrap'])) {
        neutron_fallback_inject_script($root, 'neutron-form-config', $options['form_bootstrap'], 'assets/form.js');
    }

    if (!empty($options['search_bootstrap'])) {
        neutron_fallback_inject_script($root, 'neutron-search-config', $options['search_bootstrap'], 'assets/search.js', 'assets/search.css');
    }

    if (!empty($options['cookies_bootstrap'])) {
        neutron_fallback_inject_script($root, 'neutron-cookies-config', $options['cookies_bootstrap'], 'assets/cookies.js', 'assets/cookies.css');
    }

    if (!empty($options['admin_bootstrap'])) {
        neutron_fallback_inject_admin_bootstrap($root, $options['admin_bootstrap']);
    }

    $out = '';
    foreach ($root->children as $child) {
        $out .= neutron_serialize($child);
    }
    return $out;
}

// Nodos que cumplen $predicate y pertenecen a $node: no se baja dentro de otro data-edit-item
// (lo que hay dentro es de ese elemento). Sin padres en el árbol, es el equivalente a neutron_dom_owner_item.
function neutron_fallback_own(object $node, callable $predicate): array {
    $out = [];
    foreach ($node->children ?? [] as $child) {
        if (($child->type ?? null) !== 'element') continue;
        if ($predicate($child)) $out[] = $child;
        if (!isset($child->attrs['data-edit-item'])) {
            array_push($out, ...neutron_fallback_own($child, $predicate));
        }
    }
    return $out;
}

function neutron_fallback_fill_collection(object $container, array $items, int $depth = 0): void {
    $itemTemplate = null;
    foreach ($container->children as $child) {
        if (($child->type ?? null) === 'element' && isset($child->attrs['data-edit-item'])) {
            $itemTemplate = $child;
            break;
        }
    }
    if (!$itemTemplate) return;

    $newChildren = [];
    $index = 0;
    foreach ($items as $itemData) {
        if (!is_array($itemData)) continue;
        $clone = neutron_clone_node($itemTemplate);
        $clone->attrs['data-edit-index'] = (string)$index;
        neutron_fallback_fill_item($clone, $itemData, $depth);
        $newChildren[] = $clone;
        $index++;
    }
    $container->children = $newChildren;
}

function neutron_fallback_fill_item(object $itemNode, array $data, int $depth = 0): void {
    if ($depth < NEUTRON_MAX_NESTING) {
        foreach (neutron_fallback_own($itemNode, fn($n) => isset($n->attrs['data-edit-collection'])) as $sub) {
            $key = $sub->attrs['data-edit-collection'];
            if (isset($data[$key]) && is_array($data[$key])) {
                neutron_fallback_fill_collection($sub, $data[$key], $depth + 1);
            }
        }
    }
    foreach (neutron_fallback_own($itemNode, fn($n) => isset($n->attrs['data-edit-field'])) as $field) {
        $fkey = $field->attrs['data-edit-field'];
        if (!array_key_exists($fkey, $data) || is_array($data[$fkey])) continue;
        $val = (string)$data[$fkey];
        if ($field->tag === 'img') {
            $field->attrs['src'] = $val;
        } else {
            neutron_set_text($field, $val);
        }
    }
}

function neutron_fallback_inject_admin_bootstrap(object $root, array $config): void {
    $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
    if ($head) {
        $head->children[] = (object)[
            'type' => 'element', 'tag' => 'link',
            'attrs' => ['rel' => 'stylesheet', 'href' => 'assets/editor.css'],
            'children' => [],
        ];
    }
    neutron_fallback_inject_script($root, 'neutron-config', $config, 'assets/editor.js');
}

function neutron_fallback_inject_script(object $root, string $id, array $config, string $src, ?string $css = null): void {
    $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
    $body = neutron_find_first($root, fn($n) => $n->tag === 'body');

    if ($head && $css !== null) {
        $head->children[] = (object)[
            'type' => 'element', 'tag' => 'link',
            'attrs' => ['rel' => 'stylesheet', 'href' => $css],
            'children' => [],
        ];
    }

    if ($head) {
        // Sin JSON_UNESCAPED_UNICODE, igual que en render_dom.php: DOMDocument pasaría los acentos a entidades, que dentro de <script> no se decodifican
        $json = json_encode($config);
        $json = str_replace('</script', '<\/script', $json);

        $head->children[] = (object)[
            'type' => 'element', 'tag' => 'script',
            'attrs' => ['type' => 'application/json', 'id' => $id],
            'children' => [(object)['type' => 'rawtext', 'text' => $json]],
        ];
    }

    if ($body) {
        $body->children[] = (object)[
            'type' => 'element', 'tag' => 'script',
            'attrs' => ['src' => $src, 'defer' => ''],
            'children' => [],
        ];
    }
}

// --- idiomas (ver lib/i18n.php y render_dom.php) ---

function neutron_fallback_apply_lang(object $root, array $l): void {
    $html = neutron_find_first($root, fn($n) => $n->tag === 'html');
    if ($html) $html->attrs['lang'] = $l['code'];
    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-lang-link'])) as $a) {
        $code = $a->attrs['data-lang-link'];
        if (!isset($l['alternates'][$code])) continue;
        $a->attrs['href'] = $l['alternates'][$code];
        $a->attrs['hreflang'] = $code;
        if ($code === $l['code']) $a->attrs['aria-current'] = 'true';
    }
    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-lang-current'])) as $el) {
        neutron_set_text($el, $l['names'][$l['code']] ?? $l['code']);
    }
    $links = neutron_find_all($root, fn($n) => ($n->tag === 'a' && isset($n->attrs['href']) && !isset($n->attrs['data-lang-link']))
        || ($n->tag === 'link' && ($n->attrs['rel'] ?? '') === 'alternate' && ($n->attrs['type'] ?? '') === 'application/rss+xml' && isset($n->attrs['href'])));
    foreach ($links as $a) {
        $href = neutron_lang_href($a->attrs['href'], $l['prefix'], $l['self']);
        if ($href !== null) $a->attrs['href'] = $href;
    }
    $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
    if ($head) {
        foreach (neutron_lang_head_links($l) as [$hreflang, $url]) {
            $head->children[] = (object)['type' => 'element', 'tag' => 'link', 'attrs' => ['rel' => 'alternate', 'hreflang' => $hreflang, 'href' => $url], 'children' => []];
        }
    }
}

// --- colecciones (ver lib/posts.php y render_dom.php) ---

function neutron_fallback_fill_posts(object $root, array $opt): void {
    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-posts'])) as $list) {
        $collection = $list->attrs['data-posts'];
        $view = neutron_posts_view($collection, $opt, (int)($list->attrs['data-posts-limit'] ?? 0), (int)($list->attrs['data-posts-per-page'] ?? 0),
            (string)($list->attrs['data-posts-filter'] ?? ''));
        $group = (string)($list->attrs['data-posts-group'] ?? '');
        if ($group !== '') {
            neutron_fallback_fill_groups($list, neutron_posts_groups($collection, $view['posts'], $group, (string)($list->attrs['data-posts-group-other'] ?? '')), $opt);
        } else {
            neutron_fallback_fill_items($list, $view['posts'], $opt);
        }
        foreach (['empty', 'prev', 'next'] as $kind) {
            neutron_fallback_filter($root, function (object $n) use ($kind, $collection, $view): bool {
                if (($n->attrs['data-posts-' . $kind] ?? null) !== $collection) return true;
                if ($view[$kind] === null) return false;
                if ($kind !== 'empty') $n->attrs['href'] = $view[$kind];
                return true;
            });
        }
    }
    if (!empty($opt['post'])) {
        neutron_fallback_post_walk($root, $opt['post'], $opt, true);
        $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
        if ($head) {
            foreach (neutron_post_meta_tags($opt['post']) as [$tag, $key, $name, $value]) {
                $el = null;
                foreach ($head->children as $c) {
                    if (($c->tag ?? null) === $tag && ($c->attrs[$key] ?? null) === $name) { $el = $c; break; }
                }
                if (!$el) {
                    $el = (object)['type' => 'element', 'tag' => $tag, 'attrs' => [$key => $name], 'children' => []];
                    $head->children[] = $el;
                }
                $el->attrs[$tag === 'link' ? 'href' : 'content'] = $value;
            }
        }
    }
}

// El molde (primer hijo con data-post-item) repetido para cada elemento
function neutron_fallback_fill_items(object $container, array $posts, array $opt): void {
    $tpl = null;
    foreach ($container->children ?? [] as $child) {
        if (($child->type ?? null) === 'element' && isset($child->attrs['data-post-item'])) { $tpl = $child; break; }
    }
    if (!$tpl) return;
    $items = [];
    foreach ($posts as $post) {
        $item = neutron_clone_node($tpl);
        $item->attrs['data-post-slug'] = $post['slug'];
        if ($post['status'] !== 'published') $item->attrs['class'] = trim(($item->attrs['class'] ?? '') . ' is-draft');
        neutron_fallback_post_node($item, $post, $opt);       // el propio molde no se quita aunque le falte un campo
        neutron_fallback_post_walk($item, $post, $opt, false);
        $items[] = $item;
    }
    $container->children = $items;
}

// data-posts-group: el molde de grupo por cada categoría (como neutron_dom_fill_groups)
function neutron_fallback_fill_groups(object $list, array $groups, array $opt): void {
    $tpl = null;
    foreach ($list->children ?? [] as $child) {
        if (($child->type ?? null) === 'element' && isset($child->attrs['data-post-group'])) { $tpl = $child; break; }
    }
    if (!$tpl) return;
    $out = [];
    foreach ($groups as $g) {
        $el = neutron_clone_node($tpl);
        $el->attrs['data-post-group'] = $g['id'];
        $values = ['title' => $g['title'], 'description' => $g['description'], 'count' => (string)count($g['posts'])];
        neutron_fallback_filter($el, function (object $n) use ($values): bool {
            foreach ($values as $k => $v) {
                if (!isset($n->attrs['data-post-group-' . $k])) continue;
                if ($v === '' && $k !== 'count') return false;
                neutron_set_text($n, $v);
            }
            return true;
        });
        $items = neutron_find_first($el, fn($n) => isset($n->attrs['data-post-group-items']));
        if ($items) neutron_fallback_fill_items($items, $g['posts'], $opt);
        $out[] = $el;
    }
    $list->children = $out;
}

// data-post-images (como neutron_dom_post_images). Devuelve false si hay que quitar el nodo.
function neutron_fallback_post_images(object $node, array $post, array $opt): bool {
    $images = (array)($post[$node->attrs['data-post-images']] ?? []);
    if (!$images && empty($opt['admin'])) return false;
    $tpl = null;
    foreach ($node->children ?? [] as $c) { if (($c->type ?? null) === 'element') { $tpl = $c; break; } }
    if (!$tpl || !$images) return true;
    $out = [];
    foreach ($images as $i => $src) {
        $el = neutron_clone_node($tpl);
        foreach (array_merge([$el], neutron_find_all($el, fn() => true)) as $n) {
            if (($n->type ?? null) !== 'element') continue;
            if (isset($n->attrs['data-post-image'])) {
                $n->attrs['src'] = $src;
                if (($n->attrs['alt'] ?? '') === '') $n->attrs['alt'] = trim(($post['title'] ?? '') . ' ' . ($i + 1));
            }
            if (isset($n->attrs['data-post-image-link'])) $n->attrs['href'] = $src;
        }
        $out[] = $el;
    }
    $node->children = $out;
    return true;
}

// Recorre los descendientes de $node aplicando los campos de la entrada; con $skipLists no entra en los listados.
function neutron_fallback_post_walk(object $node, array $post, array $opt, bool $skipLists): void {
    $kept = [];
    foreach ($node->children ?? [] as $child) {
        if (($child->type ?? null) !== 'element') { $kept[] = $child; continue; }
        if ($skipLists && isset($child->attrs['data-posts'])) { $kept[] = $child; continue; }
        if (!neutron_fallback_post_node($child, $post, $opt)) continue;
        if (!isset($child->attrs['data-post-html'])) neutron_fallback_post_walk($child, $post, $opt, $skipLists);
        $kept[] = $child;
    }
    $node->children = $kept;
}

// Aplica los data-post* de un nodo. Devuelve false si hay que quitarlo (imagen vacía fuera del editor).
function neutron_fallback_post_node(object $node, array $post, array $opt): bool {
    $a = $node->attrs ?? [];
    if (isset($a['data-post-images']) && !neutron_fallback_post_images($node, $post, $opt)) return false;
    foreach (['data-post-href' => 'href', 'data-post-src' => 'src'] as $attr => $target) {
        if (!isset($a[$attr])) continue;
        $value = $attr === 'data-post-href' ? neutron_post_href_of($post, $a[$attr]) : neutron_post_src_of($post, $a[$attr]);
        if ($value === '' && empty($opt['admin'])) return false;
        $node->attrs[$target === 'src' && isset($a['data-consent']) ? 'data-consent-src' : $target] = $value;
    }
    if (isset($a['data-post'])) {
        $text = neutron_post_text($post, $a['data-post'], $opt['lang'] ?? 'ca');
        if ($node->tag === 'title') {
            $current = implode('', array_map(fn($c) => $c->text ?? '', $node->children ?? []));
            $text = neutron_post_title_text($current, $text);
        }
        neutron_set_text($node, $text);
        if ($a['data-post'] === 'date' && $node->tag === 'time') $node->attrs['datetime'] = $post['date'];
    }
    if (isset($a['data-post-img'])) {
        $src = $post[$a['data-post-img']] ?? '';
        if ($src !== '') {
            $node->attrs['src'] = $src;
            if (($node->attrs['alt'] ?? '') === '') $node->attrs['alt'] = $post['title'];
        } elseif (empty($opt['admin'])) {
            return false;
        } else {
            $node->attrs['data-post-empty'] = ''; // el editor no la guardará como imagen de la entrada
        }
    }
    if (isset($a['data-post-html'])) {
        $html = neutron_sanitize_html($post[$a['data-post-html']] ?? '');
        $node->children = $html === '' ? [] : neutron_parse_html($html)->children;
    }
    if (isset($a['data-post-link'])) {
        $node->attrs['href'] = neutron_post_href($post);
    }
    return true;
}

// Quita del árbol los elementos para los que $keep devuelve false (en cualquier nivel)
function neutron_fallback_filter(object $node, callable $keep): void {
    $kept = [];
    foreach ($node->children ?? [] as $child) {
        if (($child->type ?? null) === 'element') {
            if (!$keep($child)) continue;
            neutron_fallback_filter($child, $keep);
        }
        $kept[] = $child;
    }
    if (isset($node->children)) $node->children = $kept;
}

// --- árbol ---

function neutron_parse_html(string $html): object {
    $tokens = neutron_tokenize($html);
    $root = (object)['type' => 'root', 'children' => []];
    $stack = [$root];

    foreach ($tokens as $tok) {
        $top = end($stack);
        if ($tok['type'] === 'text') {
            // El texto de la plantilla viene con entidades (&amp;, &copy;…): se guarda decodificado porque
            // neutron_serialize() lo vuelve a escapar (si no, salía &amp;copy;)
            $top->children[] = (object)['type' => 'text', 'text' => html_entity_decode($tok['text'], ENT_QUOTES | ENT_HTML5, 'UTF-8')];
            continue;
        }
        if ($tok['type'] === 'other' || $tok['type'] === 'rawtext') {
            $top->children[] = (object)['type' => $tok['type'], 'text' => $tok['text']];
            continue;
        }
        if ($tok['type'] === 'open') {
            $node = (object)[
                'type' => 'element',
                'tag' => $tok['tag'],
                'attrs' => $tok['attrs'],
                'children' => [],
            ];
            $top->children[] = $node;
            if (!$tok['selfClose']) {
                $stack[] = $node;
            }
            continue;
        }
        if ($tok['type'] === 'close') {
            for ($i = count($stack) - 1; $i > 0; $i--) {
                if (($stack[$i]->tag ?? null) === $tok['tag']) {
                    array_splice($stack, $i);
                    break;
                }
            }
            continue;
        }
    }

    return $root;
}

function neutron_find_all(object $node, callable $predicate): array {
    $out = [];
    neutron_find_all_walk($node, $predicate, $out);
    return $out;
}

function neutron_find_all_walk(object $node, callable $predicate, array &$out): void {
    if (($node->type ?? null) === 'element' && $predicate($node)) {
        $out[] = $node;
    }
    foreach ($node->children ?? [] as $child) {
        neutron_find_all_walk($child, $predicate, $out);
    }
}

function neutron_find_first(object $node, callable $predicate): ?object {
    if (($node->type ?? null) === 'element' && $predicate($node)) return $node;
    foreach ($node->children ?? [] as $child) {
        $found = neutron_find_first($child, $predicate);
        if ($found) return $found;
    }
    return null;
}

function neutron_clone_node(object $node): object {
    $clone = clone $node;
    if (isset($node->children) && is_array($node->children)) {
        $clone->children = array_map('neutron_clone_node', $node->children);
    }
    return $clone;
}

function neutron_set_text(object $node, string $text): void {
    $node->children = [(object)['type' => 'text', 'text' => $text]];
}

function neutron_serialize(object $node): string {
    $type = $node->type ?? 'text';
    if ($type === 'text') {
        return htmlspecialchars($node->text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($type === 'rawtext' || $type === 'other') {
        return $node->text;
    }
    $attrsStr = '';
    foreach (($node->attrs ?? []) as $k => $v) {
        $attrsStr .= ' ' . $k . '="' . htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8') . '"';
    }
    if (neutron_is_void_element($node->tag)) {
        return "<{$node->tag}{$attrsStr}>";
    }
    $inner = '';
    foreach (($node->children ?? []) as $child) {
        $inner .= neutron_serialize($child);
    }
    return "<{$node->tag}{$attrsStr}>{$inner}</{$node->tag}>";
}

// --- tokenizer ---

function neutron_tokenize(string $html): array {
    $tokens = [];
    $len = strlen($html);
    $pos = 0;

    while ($pos < $len) {
        $lt = strpos($html, '<', $pos);
        if ($lt === false) {
            $tokens[] = ['type' => 'text', 'text' => substr($html, $pos)];
            break;
        }
        if ($lt > $pos) {
            $tokens[] = ['type' => 'text', 'text' => substr($html, $pos, $lt - $pos)];
        }

        if (substr($html, $lt, 4) === '<!--') {
            $end = strpos($html, '-->', $lt);
            $end = $end === false ? $len : $end + 3;
            $tokens[] = ['type' => 'other', 'text' => substr($html, $lt, $end - $lt)];
            $pos = $end;
            continue;
        }

        // Un «<» que no abre una etiqueta («< Anterior», «a < b») es texto, como en el navegador y en DOMDocument
        if (!preg_match('/^<[a-zA-Z\/!?]/', substr($html, $lt, 2))) {
            $tokens[] = ['type' => 'text', 'text' => '<'];
            $pos = $lt + 1;
            continue;
        }

        $gt = strpos($html, '>', $lt);
        if ($gt === false) {
            $tokens[] = ['type' => 'text', 'text' => substr($html, $lt)];
            break;
        }
        $tagStr = substr($html, $lt, $gt - $lt + 1);
        $pos = $gt + 1;

        if (preg_match('/^<[!?]/', $tagStr)) {
            $tokens[] = ['type' => 'other', 'text' => $tagStr];
            continue;
        }

        if (preg_match('/^<\/\s*([a-zA-Z][a-zA-Z0-9:-]*)\s*>$/', $tagStr, $m)) {
            $tokens[] = ['type' => 'close', 'tag' => strtolower($m[1])];
            continue;
        }

        if (preg_match('/^<([a-zA-Z][a-zA-Z0-9:-]*)((?:\s[^<>]*?)?)\s*(\/?)>$/s', $tagStr, $m)) {
            $tag = strtolower($m[1]);
            $attrs = neutron_parse_attrs($m[2]);
            $selfClose = ($m[3] === '/') || neutron_is_void_element($tag);
            $tokens[] = ['type' => 'open', 'tag' => $tag, 'attrs' => $attrs, 'selfClose' => $selfClose];

            if (($tag === 'script' || $tag === 'style') && !$selfClose) {
                $closePos = stripos($html, '</' . $tag, $pos);
                $rawEnd = $closePos === false ? $len : $closePos;
                if ($rawEnd > $pos) {
                    $tokens[] = ['type' => 'rawtext', 'text' => substr($html, $pos, $rawEnd - $pos)];
                }
                $pos = $rawEnd;
                if ($closePos !== false) {
                    $closeGt = strpos($html, '>', $closePos);
                    $pos = $closeGt === false ? $len : $closeGt + 1;
                    $tokens[] = ['type' => 'close', 'tag' => $tag];
                }
            }
            continue;
        }

        $tokens[] = ['type' => 'text', 'text' => $tagStr];
    }

    return $tokens;
}

function neutron_parse_attrs(string $attrsRaw): array {
    $attrs = [];
    if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)(?:\s*=\s*("([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?/', $attrsRaw, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $name = strtolower($m[1]);
            if (isset($m[3]) && $m[3] !== '') { $val = $m[3]; }
            elseif (isset($m[4]) && $m[4] !== '') { $val = $m[4]; }
            elseif (isset($m[5])) { $val = $m[5]; }
            else { $val = ''; }
            $attrs[$name] = html_entity_decode($val, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }
    return $attrs;
}

// Como neutron_dom_apply_menu()
function neutron_fallback_apply_menu(object $root, array $items): void {
    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-menu'])) as $nav) {
        $entries = [];
        foreach ($nav->children ?? [] as $c) {
            if (($c->type ?? '') !== 'element') continue;
            if ($c->tag === 'a' && isset($c->attrs['href'])) $entries[] = [$c, $c];
            elseif ($c->tag === 'li' && ($a = neutron_find_first($c, fn($n) => ($n->tag ?? '') === 'a' && isset($n->attrs['href'])))) $entries[] = [$c, $a];
        }
        if (!$entries) continue;
        $mold = $btn = null;
        foreach ($entries as $e) {
            if (neutron_menu_is_button((string)($e[1]->attrs['class'] ?? ''))) $btn ??= $e; else $mold ??= $e;
        }
        $mold ??= $entries[0];
        $new = [];
        foreach ($items as $it) {
            if ($it['hidden']) continue;
            [$node, $link] = $it['button'] && $btn ? $btn : $mold;
            $attrs = $link->attrs;
            unset($attrs['data-edit'], $attrs['aria-current']);
            $attrs['href'] = $it['href'];
            if (neutron_menu_external($it['href'])) { $attrs['target'] = '_blank'; $attrs['rel'] = 'noopener'; }
            $a = (object)['type' => 'element', 'tag' => 'a', 'attrs' => $attrs, 'children' => [(object)['type' => 'text', 'text' => neutron_menu_label($it)]]];
            if ($node !== $link) $a = (object)['type' => 'element', 'tag' => $node->tag, 'attrs' => $node->attrs, 'children' => [$a]];
            $new[] = $a;
        }
        $old = array_map(fn($e) => $e[0], $entries);
        $out = [];
        $placed = false;
        foreach ($nav->children as $c) {
            if (in_array($c, $old, true)) {
                if (!$placed) { array_push($out, ...$new); $placed = true; }
                continue;
            }
            $out[] = $c;
        }
        $nav->children = $out;
    }
}
