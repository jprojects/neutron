<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

function neutron_render_dom(string $html, array $content, array $options = []): string {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    // Listas de primer nivel (las que no están dentro de otra); las anidadas las rellena su elemento
    foreach (iterator_to_array($xpath->query('//*[@data-edit-collection]')) as $container) {
        if (neutron_dom_owner_item($container) !== null) continue;
        $key = $container->getAttribute('data-edit-collection');
        if (isset($content[$key]) && is_array($content[$key])) {
            neutron_dom_fill_collection($container, $content[$key]);
        }
    }

    foreach (iterator_to_array($xpath->query('//*[@data-edit]')) as $node) {
        $key = $node->getAttribute('data-edit');
        if (!array_key_exists($key, $content)) continue;
        neutron_dom_set_text($node, (string)$content[$key]);
    }

    foreach (iterator_to_array($xpath->query('//*[@data-edit-img]')) as $node) {
        $key = $node->getAttribute('data-edit-img');
        if (!array_key_exists($key, $content)) continue;
        $node->setAttribute('src', (string)$content[$key]);
    }

    // <meta name="description" data-edit-content="clave">: el texto va al atributo content (traducible)
    foreach (iterator_to_array($xpath->query('//*[@data-edit-content]')) as $node) {
        $key = $node->getAttribute('data-edit-content');
        if (array_key_exists($key, $content) && is_string($content[$key])) $node->setAttribute('content', $content[$key]);
    }

    // Enlaces editables (redes sociales…): sin dirección, fuera
    foreach (iterator_to_array($xpath->query('//*[@data-edit-href]')) as $node) {
        [$href, $remove, $attrs] = neutron_edit_href($content, $node->getAttribute('data-edit-href'), $node->getAttribute('href'), !empty($options['editing']));
        if ($remove) { $node->parentNode->removeChild($node); continue; }
        $node->setAttribute('href', $href);
        foreach ($attrs as $name => $value) $node->setAttribute($name, $value);
    }

    // Colecciones (blog): listados data-posts y, en la página de una entrada, sus campos data-post*
    if (isset($options['posts'])) {
        neutron_dom_fill_posts($dom, $xpath, $options['posts']);
    }

    if (isset($options['current_href'])) {
        neutron_dom_mark_current($xpath, (array)$options['current_href']);
    }

    // Varios idiomas: lang, enlaces con el idioma delante, selector de idioma y hreflang
    if (!empty($options['lang'])) {
        neutron_dom_apply_lang($dom, $xpath, $options['lang']);
    }

    if (!empty($options['base'])) {
        $head = $dom->getElementsByTagName('head')->item(0);
        if ($head) {
            $base = $dom->createElement('base');
            $base->setAttribute('href', $options['base']);
            $head->insertBefore($base, $head->firstChild);
        }
    }

    if (!empty($options['form_bootstrap'])) {
        neutron_dom_inject_script($dom, 'neutron-form-config', $options['form_bootstrap'], 'assets/form.js');
    }

    if (!empty($options['cookies_bootstrap'])) {
        neutron_dom_inject_script($dom, 'neutron-cookies-config', $options['cookies_bootstrap'], 'assets/cookies.js', 'assets/cookies.css');
    }

    if (!empty($options['admin_bootstrap'])) {
        neutron_dom_inject_admin_bootstrap($dom, $options['admin_bootstrap']);
    }

    return $dom->saveHTML();
}

// Elemento de lista (data-edit-item) al que pertenece un nodo: el más cercano por encima de él.
function neutron_dom_owner_item(DOMNode $node): ?DOMElement {
    for ($p = $node->parentNode; $p instanceof DOMElement; $p = $p->parentNode) {
        if ($p->hasAttribute('data-edit-item')) return $p;
    }
    return null;
}

// Sustituye los hijos de la lista por una copia del primer data-edit-item (el molde) por elemento de $items.
function neutron_dom_fill_collection(DOMElement $container, array $items, int $depth = 0): void {
    $itemTemplate = null;
    foreach ($container->childNodes as $child) {
        if ($child instanceof DOMElement && $child->hasAttribute('data-edit-item')) {
            $itemTemplate = $child;
            break;
        }
    }
    if (!$itemTemplate) return;
    $templateClone = $itemTemplate->cloneNode(true);

    while ($container->firstChild) {
        $container->removeChild($container->firstChild);
    }

    $index = 0;
    foreach ($items as $itemData) {
        if (!is_array($itemData)) continue;
        $newItem = $templateClone->cloneNode(true);
        $newItem->setAttribute('data-edit-index', (string)$index);
        neutron_dom_fill_item($newItem, $itemData, $depth);
        $container->appendChild($newItem);
        $index++;
    }
}

// Rellena un elemento: primero sus sublistas (data-edit-collection propias) y después sus campos.
function neutron_dom_fill_item(DOMElement $itemNode, array $data, int $depth = 0): void {
    $xpath = new DOMXPath($itemNode->ownerDocument);
    if ($depth < NEUTRON_MAX_NESTING) {
        foreach (iterator_to_array($xpath->query('.//*[@data-edit-collection]', $itemNode)) as $sub) {
            if (neutron_dom_owner_item($sub) !== $itemNode) continue;
            $key = $sub->getAttribute('data-edit-collection');
            if (isset($data[$key]) && is_array($data[$key])) {
                neutron_dom_fill_collection($sub, $data[$key], $depth + 1);
            }
        }
    }
    foreach (iterator_to_array($xpath->query('.//*[@data-edit-field]', $itemNode)) as $field) {
        if (neutron_dom_owner_item($field) !== $itemNode) continue;
        $fkey = $field->getAttribute('data-edit-field');
        if (!array_key_exists($fkey, $data) || is_array($data[$fkey])) continue;
        $val = (string)$data[$fkey];
        if (strtolower($field->tagName) === 'img') {
            $field->setAttribute('src', $val);
        } else {
            neutron_dom_set_text($field, $val);
        }
    }
}

// --- idiomas (ver lib/i18n.php) ---

function neutron_dom_apply_lang(DOMDocument $dom, DOMXPath $xpath, array $l): void {
    $html = $dom->documentElement;
    if ($html) $html->setAttribute('lang', $l['code']);
    // Selector: <a data-lang-link="es">Español</a> → la misma página en ese idioma
    foreach (iterator_to_array($xpath->query('//*[@data-lang-link]')) as $a) {
        $code = $a->getAttribute('data-lang-link');
        if (!isset($l['alternates'][$code])) continue;
        $a->setAttribute('href', $l['alternates'][$code]);
        $a->setAttribute('hreflang', $code);
        if ($code === $l['code']) $a->setAttribute('aria-current', 'true');
    }
    foreach (iterator_to_array($xpath->query('//*[@data-lang-current]')) as $el) {
        neutron_dom_set_text($el, $l['names'][$l['code']] ?? $l['code']);
    }
    // Enlaces internos de la plantilla → con el idioma delante
    foreach (iterator_to_array($xpath->query('//a[@href and not(@data-lang-link)] | //link[@rel="alternate" and @type="application/rss+xml"]')) as $a) {
        $href = neutron_lang_href($a->getAttribute('href'), $l['prefix'], $l['self']);
        if ($href !== null) $a->setAttribute('href', $href);
    }
    $head = $dom->getElementsByTagName('head')->item(0);
    if ($head) {
        foreach (neutron_lang_head_links($l) as [$hreflang, $url]) {
            $link = $dom->createElement('link');
            $link->setAttribute('rel', 'alternate');
            $link->setAttribute('hreflang', $hreflang);
            $link->setAttribute('href', $url);
            $head->appendChild($link);
        }
    }
}

// --- colecciones (ver lib/posts.php) ---

function neutron_dom_fill_posts(DOMDocument $dom, DOMXPath $xpath, array $opt): void {
    foreach (iterator_to_array($xpath->query('//*[@data-posts]')) as $list) {
        $collection = $list->getAttribute('data-posts');
        $view = neutron_posts_view($collection, $opt, (int)$list->getAttribute('data-posts-limit'), (int)$list->getAttribute('data-posts-per-page'));

        $tpl = null;
        foreach ($list->childNodes as $child) {
            if ($child instanceof DOMElement && $child->hasAttribute('data-post-item')) { $tpl = $child; break; }
        }
        if ($tpl) {
            $mold = $tpl->cloneNode(true);
            while ($list->firstChild) $list->removeChild($list->firstChild);
            foreach ($view['posts'] as $post) {
                $item = $mold->cloneNode(true);
                $item->setAttribute('data-post-slug', $post['slug']);
                if ($post['status'] !== 'published') $item->setAttribute('class', trim($item->getAttribute('class') . ' is-draft'));
                neutron_dom_fill_post($xpath, $item, $post, $opt, false);
                $list->appendChild($item);
            }
        }
        // Aviso de lista vacía y paginación (data-posts-empty / -prev / -next con el nombre de la colección)
        foreach (['empty', 'prev', 'next'] as $kind) {
            foreach (iterator_to_array($xpath->query('//*[@data-posts-' . $kind . '="' . $collection . '"]')) as $el) {
                $href = $view[$kind];
                if ($href === null) {
                    $el->parentNode->removeChild($el);
                } elseif ($kind !== 'empty') {
                    $el->setAttribute('href', $href);
                }
            }
        }
    }
    if (!empty($opt['post'])) {
        neutron_dom_fill_post($xpath, $dom->documentElement, $opt['post'], $opt, true);
        neutron_dom_post_meta($dom, $xpath, $opt['post'], $opt);
    }
}

// Campos de una entrada dentro de $root. En la página de la entrada ($skipLists) no se tocan los listados.
function neutron_dom_fill_post(DOMXPath $xpath, DOMElement $root, array $post, array $opt, bool $skipLists): void {
    $nodes = $xpath->query('descendant-or-self::*[@data-post or @data-post-img or @data-post-html or @data-post-link]', $root);
    foreach (iterator_to_array($nodes) as $node) {
        if ($skipLists && $xpath->query('ancestor::*[@data-posts]', $node)->length) continue;
        if ($node->hasAttribute('data-post')) {
            $field = $node->getAttribute('data-post');
            $text = neutron_post_text($post, $field, $opt['lang'] ?? 'ca');
            if (strtolower($node->tagName) === 'title') $text = neutron_post_title_text($node->textContent, $text);
            neutron_dom_set_text($node, $text);
            if ($field === 'date' && strtolower($node->tagName) === 'time') $node->setAttribute('datetime', $post['date']);
        }
        if ($node->hasAttribute('data-post-img')) {
            $src = $post[$node->getAttribute('data-post-img')] ?? '';
            if ($src !== '') {
                $node->setAttribute('src', $src);
                if ($node->getAttribute('alt') === '') $node->setAttribute('alt', $post['title']);
            } elseif (empty($opt['admin']) && $node->parentNode) {
                $node->parentNode->removeChild($node); // sin imagen: fuera (en el editor se deja el molde para poner una)
                continue;
            } else {
                $node->setAttribute('data-post-empty', ''); // el editor no la guardará como imagen de la entrada
            }
        }
        if ($node->hasAttribute('data-post-html')) {
            while ($node->firstChild) $node->removeChild($node->firstChild);
            $html = neutron_sanitize_html($post[$node->getAttribute('data-post-html')] ?? '');
            if ($html !== '') {
                $tmp = new DOMDocument();
                libxml_use_internal_errors(true);
                $tmp->loadHTML(mb_convert_encoding('<div>' . $html . '</div>', 'HTML-ENTITIES', 'UTF-8'));
                libxml_clear_errors();
                $wrap = $tmp->getElementsByTagName('div')->item(0);
                foreach (iterator_to_array($wrap->childNodes) as $child) {
                    $node->appendChild($node->ownerDocument->importNode($child, true));
                }
            }
        }
        if ($node->hasAttribute('data-post-link')) {
            $node->setAttribute('href', neutron_post_href($post));
        }
    }
}

// Descripción, URL canónica y etiquetas og: de la entrada
function neutron_dom_post_meta(DOMDocument $dom, DOMXPath $xpath, array $post, array $opt): void {
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) return;
    foreach (neutron_post_meta_tags($post) as [$tag, $key, $name, $value]) {
        $found = $xpath->query('//head/' . $tag . '[@' . $key . '="' . $name . '"]')->item(0);
        $el = $found ?: $head->appendChild($dom->createElement($tag));
        $el->setAttribute($key, $name);
        $el->setAttribute($tag === 'link' ? 'href' : 'content', $value);
    }
}

// aria-current="page" en los enlaces a la página que se está viendo (menús compartidos).
function neutron_dom_mark_current(DOMXPath $xpath, array $hrefs): void {
    foreach (iterator_to_array($xpath->query('//a[@href]')) as $a) {
        if (in_array(neutron_normalize_href($a->getAttribute('href')), $hrefs, true)) {
            $a->setAttribute('aria-current', 'page');
        }
    }
}

function neutron_dom_set_text(DOMElement $node, string $text): void {
    while ($node->firstChild) {
        $node->removeChild($node->firstChild);
    }
    $node->appendChild($node->ownerDocument->createTextNode($text));
}

function neutron_dom_inject_admin_bootstrap(DOMDocument $dom, array $config): void {
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) return;

    $css = $dom->createElement('link');
    $css->setAttribute('rel', 'stylesheet');
    $css->setAttribute('href', 'assets/editor.css');
    $head->appendChild($css);

    neutron_dom_inject_script($dom, 'neutron-config', $config, 'assets/editor.js');
}

// Config en <script type="application/json" id="…"> dentro de <head> y el script al final de <body>.
function neutron_dom_inject_script(DOMDocument $dom, string $id, array $config, string $src, ?string $css = null): void {
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) return;

    if ($css !== null) {
        $link = $dom->createElement('link');
        $link->setAttribute('rel', 'stylesheet');
        $link->setAttribute('href', $css);
        $head->appendChild($link);
    }

    // Sin JSON_UNESCAPED_UNICODE: DOMDocument pasaría los acentos a entidades, que dentro de <script> no se decodifican
    $json = json_encode($config);
    $json = str_replace('</script', '<\/script', $json);

    $configScript = $dom->createElement('script');
    $configScript->setAttribute('type', 'application/json');
    $configScript->setAttribute('id', $id);
    $configScript->appendChild($dom->createTextNode($json));
    $head->appendChild($configScript);

    $body = $dom->getElementsByTagName('body')->item(0);
    if ($body) {
        $js = $dom->createElement('script');
        $js->setAttribute('src', $src);
        $js->setAttribute('defer', 'defer');
        $body->appendChild($js);
    }
}
