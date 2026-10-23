<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

function neutron_render_dom(string $html, array $content, array $options = []): string {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'));
    libxml_clear_errors();
    $xpath = new DOMXPath($dom);

    foreach (iterator_to_array($xpath->query('//*[@data-edit-collection]')) as $container) {
        $key = $container->getAttribute('data-edit-collection');
        $items = isset($content[$key]) && is_array($content[$key]) ? $content[$key] : [];

        $itemTemplate = null;
        foreach ($container->childNodes as $child) {
            if ($child instanceof DOMElement && $child->hasAttribute('data-edit-item')) {
                $itemTemplate = $child;
                break;
            }
        }
        if (!$itemTemplate) continue;
        $templateClone = $itemTemplate->cloneNode(true);

        while ($container->firstChild) {
            $container->removeChild($container->firstChild);
        }

        $index = 0;
        foreach ($items as $itemData) {
            if (!is_array($itemData)) continue;
            $newItem = $templateClone->cloneNode(true);
            $newItem->setAttribute('data-edit-index', (string)$index);
            neutron_dom_fill_item_fields($newItem, $itemData);
            $container->appendChild($newItem);
            $index++;
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

    if (!empty($options['form_bootstrap'])) {
        neutron_dom_inject_script($dom, 'neutron-form-config', $options['form_bootstrap'], 'assets/form.js');
    }

    if (!empty($options['admin_bootstrap'])) {
        neutron_dom_inject_admin_bootstrap($dom, $options['admin_bootstrap']);
    }

    return $dom->saveHTML();
}

function neutron_dom_fill_item_fields(DOMElement $itemNode, array $data): void {
    $xpath = new DOMXPath($itemNode->ownerDocument);
    $fields = $xpath->query('.//*[@data-edit-field]', $itemNode);
    foreach (iterator_to_array($fields) as $field) {
        $fkey = $field->getAttribute('data-edit-field');
        if (!array_key_exists($fkey, $data)) continue;
        $val = (string)$data[$fkey];
        if (strtolower($field->tagName) === 'img') {
            $field->setAttribute('src', $val);
        } else {
            neutron_dom_set_text($field, $val);
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
function neutron_dom_inject_script(DOMDocument $dom, string $id, array $config, string $src): void {
    $head = $dom->getElementsByTagName('head')->item(0);
    if (!$head) return;

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
