<?php
if (!defined('NEUTRON_APP')) { http_response_code(403); exit; }

// Configuración base de Neutron (núcleo: la sustituye cada actualización).
// Lo propio de cada instalación va en config.local.php, que devuelve solo las claves a cambiar:
//
//   <?php
//   return [
//       'admin_password_hash' => '$2y$10$…',   // php bin/install.php o password_hash()
//       'recaptcha' => ['site_key' => '…', 'secret_key' => '…'],
//       'remote' => ['token' => '…64 caracteres…'],
//   ];
//
// php bin/install.php lo crea.

function neutron_config_defaults(): array {
    return [
        'name' => '',                   // nombre de la web (Cosmo); vacío = el dominio
        'timezone' => 'Europe/Madrid',
        'debug' => false,               // true = muestra los errores de PHP (solo en desarrollo)

        // Hash de la contraseña del editor (?admin):
        //   php -r "echo password_hash('tu_password', PASSWORD_DEFAULT), PHP_EOL;"
        'admin_password_hash' => '',

        // reCAPTCHA v2 invisible para los formularios con data-neutron-form.
        // Claves en https://www.google.com/recaptcha/admin (tipo "Invisible reCAPTCHA badge").
        'recaptcha' => [
            'site_key' => '',
            'secret_key' => '',
            'badge' => 'inline',        // inline | bottomright | bottomleft
        ],

        // Formulario de contacto
        'mail' => [
            'to' => '',                 // vacío = el campo de content.json que diga site/contact.json ("email")
            'from' => '',               // vacío = no-reply@dominio
        ],
        'contact' => [
            'max_per_window' => 5,      // mensajes por IP…
            'window' => 3600,           // …en estos segundos
        ],

        // API remota para Cosmo. Desactivada mientras el token tenga menos de 32 caracteres.
        //   php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
        'remote' => [
            'token' => '',
            'allow_ips' => [],          // opcional: solo la IP del servidor de Cosmo
            'backups_keep' => 5,
            'self_test' => true,        // tras actualizar, se pide el estado a sí misma y deshace si falla
        ],

        'log' => [
            'level' => 'info',          // debug | info | notice | warning | error
            'keep_days' => 30,
        ],
    ];
}

function neutron_config(): array {
    static $config = null;
    if ($config !== null) return $config;

    $config = neutron_config_defaults();
    $local = NEUTRON_ROOT . '/config.local.php';
    if (is_file($local)) {
        $override = require $local;
        if (is_array($override)) {
            $config = array_replace_recursive($config, $override);
        }
    }
    // Instalaciones anteriores a la 1.0 definían la contraseña como constante
    if ($config['admin_password_hash'] === '' && defined('NEUTRON_ADMIN_PASSWORD_HASH')) {
        $config['admin_password_hash'] = NEUTRON_ADMIN_PASSWORD_HASH;
    }
    // allow_ips es una lista: array_replace_recursive mezclaría la de serie con la local
    if (isset($override['remote']['allow_ips'])) {
        $config['remote']['allow_ips'] = array_values((array)$override['remote']['allow_ips']);
    }
    return $config;
}
