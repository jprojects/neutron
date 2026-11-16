<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Guía de edición del cliente: el PDF que genera docs/guia-edicio/generar.py, guardado en site/guia-edicio.pdf (es de la
// web, no del núcleo). No se sirve tal cual (.htaccess y router.php lo prohíben): solo desde el panel, con la sesión
// abierta (admin/guide.php), con un enlace junto a la versión en el inicio del panel.
//
// Junto al PDF, site/guia-edicio.json (lo escribe generar.py): {"nom", "url", "generated", "fingerprint"}. Con él, el
// estado remoto dice a Cosmo si la guía se ha quedado vieja: si la estructura de la web ha cambiado desde entonces
// (neutron_guide_fingerprint) y cuántos campos se han editado después. Cosmo la regenera (MCP cosmo_guia) y la sube
// con la acción remota guide-set.

const NEUTRON_GUIDE_MAX_BYTES = 30 * 1024 * 1024;

// Edición de la guía: se sube cuando un cambio del editor o del panel deja desfasadas las guías hechas antes (un apartado
// nuevo, un botón que cambia). generar.py la apunta en site/guia-edicio.json (la lee de este fichero del repositorio), y
// una web con una guía de una edición anterior lo dice en su estado (outdated), para que Cosmo la regenere.
//   1: la edición en la propia página (hasta la 1.29.0) · 2: el panel (/admin/) y los botones en el idioma del panel (1.30.0)
const NEUTRON_GUIDE_EDITION = 2;

function neutron_guide_path(): string {
    return NEUTRON_SITE . '/guia-edicio.pdf';
}

function neutron_guide_meta_path(): string {
    return NEUTRON_SITE . '/guia-edicio.json';
}

/** Los datos con los que se generó la guía (site/guia-edicio.json), o [] */
function neutron_guide_meta(): array {
    $data = is_file(neutron_guide_meta_path()) ? json_decode((string)file_get_contents(neutron_guide_meta_path()), true) : null;
    return is_array($data) ? $data : [];
}

/**
 * Huella de la estructura de la web, lo que cambia lo que sale en la guía (apartados y capturas): la plantilla, las
 * páginas, los parciales, la plantilla de cada colección y los idiomas. Los textos no cuentan (ver edits_since).
 * La misma cuenta en docs/guia-edicio/generar.py (huella()): sha1 de «ruta\0sha1(contenido)\n» por fichero, por orden
 * de ruta (relativa a site/).
 */
function neutron_guide_fingerprint(): string {
    $files = array_merge(
        [NEUTRON_SITE . '/template.html', NEUTRON_SITE . '/languages.json'],
        glob(NEUTRON_SITE . '/pages/*.html') ?: [],
        glob(NEUTRON_SITE . '/partials/*.html') ?: [],
        glob(NEUTRON_SITE . '/*/_item.html') ?: []
    );
    $rows = [];
    foreach ($files as $f) {
        if (is_file($f)) $rows[substr($f, strlen(NEUTRON_SITE) + 1)] = sha1_file($f);
    }
    ksort($rows, SORT_STRING);
    $s = '';
    foreach ($rows as $path => $hash) $s .= $path . "\0" . $hash . "\n";
    return sha1($s);
}

/**
 * null si la web no tiene guía; si no, su tamaño y fecha y, si se sabe con qué se generó: nombre y dirección de la
 * portada, cuándo, si la estructura ha cambiado desde entonces y cuántos campos se han editado después.
 */
function neutron_guide_info(): ?array {
    $file = neutron_guide_path();
    if (!is_file($file)) return null;
    $meta = neutron_guide_meta();
    $generated = isset($meta['generated']) ? strtotime((string)$meta['generated']) : false;
    $since = $generated ?: (int)filemtime($file);
    $edits = 0;
    require_once NEUTRON_ROOT . '/lib/content.php';
    foreach (neutron_edit_times() as $keys) {
        foreach ((array)$keys as $t) if ((float)$t > $since) $edits++;
    }
    return [
        'size' => (int)filesize($file),
        'modified' => date(DATE_ATOM, (int)filemtime($file)),
        'nom' => isset($meta['nom']) ? (string)$meta['nom'] : null,
        'url' => isset($meta['url']) ? (string)$meta['url'] : null,
        'generated' => $generated ? date(DATE_ATOM, $generated) : null,
        // null: guía sin huella (hecha a mano o antes de la 1.29.0), no se sabe
        'structure_changed' => isset($meta['fingerprint']) ? !hash_equals((string)$meta['fingerprint'], neutron_guide_fingerprint()) : null,
        'edits_since' => $edits,
        // Hecha con una edición anterior de la guía (sin edición apuntada: la 1)
        'edition' => (int)($meta['edition'] ?? 1),
        'outdated' => (int)($meta['edition'] ?? 1) < NEUTRON_GUIDE_EDITION,
    ];
}

/**
 * Pone la guía: $tmp es el PDF subido (se mueve), $meta lo que dio generar.py (se guarda en site/guia-edicio.json).
 * @throws RuntimeException si no es un PDF o no se puede guardar
 */
function neutron_guide_save(string $tmp, array $meta): void {
    $head = (string)@file_get_contents($tmp, false, null, 0, 5);
    if ($head !== '%PDF-') throw new RuntimeException('El fichero no es un PDF');
    if (filesize($tmp) > NEUTRON_GUIDE_MAX_BYTES) throw new RuntimeException('El PDF pasa de 30 MB');
    $part = neutron_guide_path() . '.part';
    if (!@copy($tmp, $part) || !@rename($part, neutron_guide_path())) {
        @unlink($part);
        throw new RuntimeException('No se puede escribir en site/');
    }
    $keep = array_intersect_key($meta, array_flip(['nom', 'url', 'generated', 'fingerprint', 'edition']));
    $keep = array_map('strval', array_filter($keep, 'is_scalar'));
    require_once NEUTRON_ROOT . '/lib/remote/state.php';
    neutron_write_json(neutron_guide_meta_path(), $keep + ['generated' => date(DATE_ATOM)]);
}

function neutron_guide_delete(): void {
    @unlink(neutron_guide_path());
    @unlink(neutron_guide_meta_path());
}
