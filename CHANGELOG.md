# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 1.3.0 — 2026-10-26

Favicon de cada sitio.

- `site/favicon.ico` y `site/apple-touch-icon.png` se sirven también en la raíz (`/favicon.ico`,
  `/apple-touch-icon.png`, `/apple-touch-icon-precomposed.png`), con caché de una semana y aunque la web esté en
  mantenimiento. Antes esas peticiones acababan en la página 404 completa.
- El estado para Cosmo dice si el sitio tiene favicon (`site.favicon`).

## 1.2.0 — 2026-10-26

Las actualizaciones conservan las reglas propias del `.htaccess`.

- El `.htaccess` del núcleo va entre `# BEGIN Neutron` y `# END Neutron`. Al actualizar se sustituye solo ese bloque
  y se conserva lo que la instalación tenga fuera (HTTPS, la versión de PHP de cPanel…); la integridad y el
  manifiesto comparan solo el bloque, así que esas reglas ya no cuentan como «modificado a mano».
- Al llegar desde la 1.0.0/1.1.0 (cuyo actualizador sobrescribe el fichero entero), `migrate` recupera las líneas
  propias del `.htaccess` desde la copia completa previa a la actualización y avisa si no la encuentra.

## 1.1.0 — 2026-10-26

Webs de varias páginas. Las instalaciones 1.0.0 siguen funcionando igual (mismo HTML).

- **Listas dentro de listas**: un `data-edit-collection` dentro de un `data-edit-item` es una sublista (hasta 3
  niveles), p. ej. secciones de una carta con sus platos. En los dos motores de render, el editor y el guardado.
- **Parciales**: `<div data-include="header"></div>` inserta `site/partials/header.html` (cabecera, menú y pie una
  sola vez para todas las páginas).
- **Página actual**: los enlaces a la página que se ve reciben `aria-current="page"`.
- **Redirecciones** de la web anterior con `site/redirects.json` (301), incluidas rutas tipo `index.php/serveis/carta`
  y consultas `index.php?option=…`. Las rutas que no existen pasan por `index.php` (`.htaccess` y `router.php`).
- **404** propia con `site/pages/404.html`; antes una ruta desconocida como `/index.php/loquesea` mostraba la portada.
- Editor: botón ↑ para reordenar, texto del botón de añadir con `data-edit-add`, al añadir se conservan las imágenes
  del elemento copiado (antes se quedaba sin `src` y no se podía pulsar), y una clave que sale varias veces en la
  página se mantiene igual en todas al editarla.
- Una lista sin su clave en `content.json` muestra el molde de la plantilla (antes se vaciaba), igual que los textos.
- `site/partials/` no se sirve directamente.

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
