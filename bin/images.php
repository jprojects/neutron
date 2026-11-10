<?php
// Versiones de las imágenes del sitio para el móvil (lib/images.php): las que falten.
//   php bin/images.php [--segundos=300]
// También las hace la migración de cada actualización desde Cosmo (unos segundos cada vez) y la subida desde el editor.
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
define('NEUTRON_APP', true);
require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/images.php';
require_once __DIR__ . '/../lib/cache.php';

$seconds = (int)(getopt('', ['segundos:'])['segundos'] ?? 300);
if (!neutron_image_can_resize()) { fwrite(STDERR, "Falta la extensión GD de PHP\n"); exit(1); }
$r = neutron_images_generate_all($seconds);
neutron_cache_clear();
echo "Versiones hechas: {$r['made']} (de {$r['images']} imágenes)" . ($r['pending'] ? '. Faltan: vuelve a lanzarlo.' : '.') . "\n";
