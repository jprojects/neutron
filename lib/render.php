<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

require_once __DIR__ . '/render_dom.php';
require_once __DIR__ . '/render_fallback.php';

function neutron_render(string $html, array $content, array $options = []): string {
    if (class_exists('DOMDocument')) {
        return neutron_render_dom($html, $content, $options);
    }
    return neutron_render_fallback($html, $content, $options);
}

function neutron_is_void_element(string $tag): bool {
    static $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];
    return in_array($tag, $void, true);
}
