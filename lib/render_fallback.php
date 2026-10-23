<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Parser HTML mínimo (sin ext-dom) para hostings que no traen esa extensión.
// Tokeniza y arma un árbol de stdClass; no es un parser HTML completo,
// pero cubre lo que las plantillas de Neutron necesitan.

function neutron_render_fallback(string $html, array $content, array $options = []): string {
    $root = neutron_parse_html($html);

    foreach (neutron_find_all($root, fn($n) => isset($n->attrs['data-edit-collection'])) as $container) {
        $key = $container->attrs['data-edit-collection'];
        $items = isset($content[$key]) && is_array($content[$key]) ? $content[$key] : [];

        $itemTemplate = null;
        foreach ($container->children as $child) {
            if (($child->type ?? null) === 'element' && isset($child->attrs['data-edit-item'])) {
                $itemTemplate = $child;
                break;
            }
        }
        if (!$itemTemplate) continue;

        $newChildren = [];
        $index = 0;
        foreach ($items as $itemData) {
            if (!is_array($itemData)) continue;
            $clone = neutron_clone_node($itemTemplate);
            $clone->attrs['data-edit-index'] = (string)$index;
            neutron_fallback_fill_item_fields($clone, $itemData);
            $newChildren[] = $clone;
            $index++;
        }
        $container->children = $newChildren;
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

    if (!empty($options['form_bootstrap'])) {
        neutron_fallback_inject_script($root, 'neutron-form-config', $options['form_bootstrap'], 'assets/form.js');
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

function neutron_fallback_fill_item_fields(object $itemNode, array $data): void {
    foreach (neutron_find_all($itemNode, fn($n) => isset($n->attrs['data-edit-field'])) as $field) {
        $fkey = $field->attrs['data-edit-field'];
        if (!array_key_exists($fkey, $data)) continue;
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

function neutron_fallback_inject_script(object $root, string $id, array $config, string $src): void {
    $head = neutron_find_first($root, fn($n) => $n->tag === 'head');
    $body = neutron_find_first($root, fn($n) => $n->tag === 'body');

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

// --- árbol ---

function neutron_parse_html(string $html): object {
    $tokens = neutron_tokenize($html);
    $root = (object)['type' => 'root', 'children' => []];
    $stack = [$root];

    foreach ($tokens as $tok) {
        $top = end($stack);
        if ($tok['type'] === 'text' || $tok['type'] === 'other' || $tok['type'] === 'rawtext') {
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

        if (preg_match('/^<\/\s*([a-zA-Z][a-zA-Z0-9-]*)\s*>$/', $tagStr, $m)) {
            $tokens[] = ['type' => 'close', 'tag' => strtolower($m[1])];
            continue;
        }

        if (preg_match('/^<([a-zA-Z][a-zA-Z0-9-]*)((?:\s[^<>]*?)?)\s*(\/?)>$/s', $tagStr, $m)) {
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
