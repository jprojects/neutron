# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 1.0.0 — 2026-10-23

Primera versión publicada. Núcleo separado del sitio y gestión desde Cosmo.

- **Núcleo y sitio**: todo lo de cada web va en `site/` (plantilla, `content.json`, `contact.json`, páginas en
  `site/pages/`, recursos en `site/assets/`, subidas en `site/uploads/`) y la configuración en `config.local.php`.
  `core.json` lista los ficheros del núcleo, los únicos que sustituye una actualización.
- **Páginas del sitio**: `site/pages/nombre.html` se sirve como `/nombre.html` (pasando por el render).
- **Formulario de contacto genérico** con `data-neutron-form`: el núcleo inyecta `assets/form.js` (envío por fetch y
  mensajes) y `contact.php` envía los campos definidos en `site/contact.json` (o todos, sin él).
- **reCAPTCHA v2 invisible** en todos los formularios de contacto si `config.local.php` tiene las claves. El script
  de Google se carga solo al empezar a usar el formulario. Además, honeypot y límite de envíos por IP.
- **API remota para Cosmo** (`index.php?r=remote&a=…`, compatible con la de Quasar): estado, log, integridad,
  copias (crear, listar, descargar, borrar), actualización con copia previa, mantenimiento, autoprueba y vuelta
  atrás, `migrate` y modo mantenimiento.
- **Log** en `data/logs/` (accesos al editor, guardados, subidas, formulario, acciones remotas, errores de PHP).
- `config.php` con valores por defecto + `config.local.php`; `bin/install.php` lo crea (contraseña, reCAPTCHA, token).
- `router.php` para `php -S` y `.htaccess` que protege `lib/`, `data/`, `bin/`, la configuración y los JSON del sitio.
- Corregido: el JSON que se inyecta en la página (config del editor y del formulario) se escapa en ASCII; DOMDocument
  convertía los acentos en entidades HTML, que dentro de `<script>` no se decodifican.
