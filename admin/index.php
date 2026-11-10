<?php
// Panel de la web (/admin/): inicio, contenido en formularios y acceso a la edición en la propia página (?admin).
// La interfaz es assets/panel.js; los datos salen de admin/api.php y se guardan con admin/save.php.
define('NEUTRON_APP', true);

require_once __DIR__ . '/../lib/bootstrap.php';
require_once __DIR__ . '/../lib/auth.php';

neutron_block_if_maintenance();
neutron_start_session();
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: same-origin');

$langs = neutron_languages();
$config = [
    'loggedIn' => neutron_is_logged_in(),
    'csrf' => $_SESSION['csrf'],
    'languages' => $langs,
];
$version = rawurlencode(trim((string)@file_get_contents(NEUTRON_ROOT . '/VERSION')));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Panel</title>
<link rel="icon" href="../favicon.ico">
<link rel="stylesheet" href="../assets/panel.css?v=<?= $version ?>">
</head>
<body>
<div id="panel" class="np-loading">Cargando…</div>
<script type="application/json" id="neutron-panel-config"><?= json_encode($config) ?></script>
<script src="../assets/panel.js?v=<?= $version ?>"></script>
</body>
</html>
