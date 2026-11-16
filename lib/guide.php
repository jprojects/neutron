<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Guía de edición del cliente: el PDF que genera docs/guia-edicio/generar.py, guardado en site/guia-edicio.pdf (es de la
// web, no del núcleo). No se sirve tal cual (.htaccess y router.php lo prohíben): solo desde el panel, con la sesión
// abierta (admin/guide.php), con un enlace junto a la versión en el inicio del panel.

function neutron_guide_path(): string {
    return NEUTRON_SITE . '/guia-edicio.pdf';
}

/** null si la web no tiene guía; si no, su tamaño y la fecha del fichero */
function neutron_guide_info(): ?array {
    $file = neutron_guide_path();
    if (!is_file($file)) return null;
    return ['size' => (int)filesize($file), 'modified' => date(DATE_ATOM, (int)filemtime($file))];
}
