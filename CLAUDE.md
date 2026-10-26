# Neutron

CMS mínimo sin base de datos para webs de una página creadas con IA: `site/template.html` con atributos
`data-edit*`, contenido en `site/content.json`, edición en la propia página con `?admin`. PHP 8.1+ procedural
(funciones `neutron_*`), sin dependencias. Se gestiona desde Cosmo (`~/Projects/cosmo`) igual que Quasar
(`~/Projects/quasar`), cuya API remota replica. El README es la referencia de uso: léelo antes de cambiar algo y
actualízalo con cada función nueva.

## Núcleo y sitio

- **Núcleo**: lo que lista `core.json`. Es lo único que sustituye una actualización desde Cosmo. Si añades un
  fichero o carpeta del núcleo fuera de los patrones actuales, añádelo a `core.json` o no llegará a las instalaciones.
- **Sitio**: `site/` (plantilla, contenido, `contact.json`, `redirects.json`, `pages/`, `partials/`, `assets/`, `uploads/`). **Nada del sitio en el
  núcleo**: ni textos de una web concreta en `lib/` o `assets/`, ni rutas fijas a sus ficheros. Los recursos del sitio
  se enlazan como `site/assets/…` (la página se sirve desde la raíz).
- **Configuración**: `config.php` (valores por defecto, núcleo) + `config.local.php` (secretos de la instalación, no
  versionado). Claves nuevas: con su valor por defecto y comentario en `config.php`, y en el README.
- `.htaccess`: el núcleo solo es dueño del bloque `# BEGIN Neutron` … `# END Neutron` (ver `lib/remote/htaccess.php`).
  Si cambias el `.htaccess`, cambia solo dentro del bloque; el fichero tiene que seguir siendo exactamente el bloque.
- Un cambio en el actualizador solo actúa en las actualizaciones **desde** la versión que lo trae: la que instala
  esa versión la ejecuta el código de la anterior. Lo que haya que arreglar al llegar, en `migrate`.
- `data/` es estado interno (log, copias, `remote/`, throttle). `site/`, `config.local.php` y `data/` no están en git.
- El directorio de trabajo es a la vez el repo del núcleo y una instalación real (la web de Assaice, en catalán,
  en `site/`). `examples/` contiene sitios de ejemplo con la misma estructura que `site/`.

## Sitios generados con IA

- Todo formulario de contacto lleva `data-neutron-form`, `action="contact.php"`, el honeypot `website` y
  `[data-form-status]`; **siempre con reCAPTCHA invisible** (lo pone el núcleo: no escribas JS de envío propio ni
  cargues el script de Google a mano). Hay que pedir/poner las claves en `config.local.php` → `recaptcha` y
  mencionar Google reCAPTCHA en la política de cookies del sitio.
- Campos, obligatorios y textos del formulario en `site/contact.json`, en el idioma de la web.
- Servicios de terceros (vídeos, mapas, analítica): bloqueados hasta el consentimiento del faldón de cookies del
  sitio (`site/assets/cookies.js`, iframes con `data-consent-src`) y descritos en `site/pages/politica-cookies.html`;
  si cambian las categorías, subir `VERSION` en `cookies.js`.
- Solo texto plano en `data-edit`; no se pueden editar `href`. Las listas se pueden anidar (hasta 3 niveles):
  cartas y menús como secciones con sus platos, no como bloques de texto.
- Webs de varias páginas: cabecera, menú y pie en `site/partials/` con `data-include`; claves de cada página con
  prefijo; resaltar el menú con `[aria-current="page"]`; `site/redirects.json` con todas las URLs de la web anterior.
- **Favicon siempre**: al migrar, cogerlo de la web original (`rel="icon"`/`shortcut icon` en el HTML y
  `/favicon.ico`); si no tiene, hacerlo a partir del logo. Va en `site/favicon.ico` (16/32/48 px) y
  `site/apple-touch-icon.png` (180×180), con `<link rel="icon" href="favicon.ico">` y
  `<link rel="apple-touch-icon" href="apple-touch-icon.png">` en el `<head>` de todas las páginas.
- Cada web nueva es una instalación aparte en `~/Projects/<nombre>` (núcleo con `git archive` + su `site/` +
  `config.local.php` con `bin/install.php`), no en `site/` de este repo, que es Assaice.

## Convenciones

- Comentarios, mensajes de error para el desarrollador, README y CHANGELOG **en castellano**. Los textos que ve el
  visitante salen del sitio (`contact.json`, plantilla), con valores por defecto en castellano en el núcleo.
- Cada fichero PHP que no es punto de entrada empieza con el guard `if (!defined('NEUTRON_APP'))…`; los puntos de
  entrada definen `NEUTRON_APP` y cargan `lib/bootstrap.php`.
- Rutas con `NEUTRON_ROOT`, `NEUTRON_SITE` y `NEUTRON_DATA`. Escrituras de JSON de estado: `neutron_write_json`
  (atómica).
- Lo importante va al log (`neutron_log($nivel, 'Mensaje {clave}', $contexto)`), nunca secretos en claro.
- El JSON que se inyecta en la página va sin `JSON_UNESCAPED_UNICODE` (DOMDocument convierte los acentos en
  entidades dentro de `<script>`).
- Cualquier cambio en el render se hace en los dos motores: `lib/render_dom.php` y `lib/render_fallback.php`
  (comprobar que generan lo mismo). Lo que se puede hacer sobre el texto antes del render (parciales) va en
  `lib/render.php`. Un campo o sublista pertenece al `data-edit-item` más cercano.
- API remota: mismas acciones, nombres de campo y respuestas que la de Quasar (`src/Controllers/RemoteController.php`
  allí); `app` es `"neutron"`. Si Cosmo necesita algo nuevo, mantener la compatibilidad (`api` sube si cambia).

## Versiones y publicación

- Semver en `VERSION`, con su entrada en `CHANGELOG.md` (una función nueva sube la versión MENOR).
- Publicar: commit, `git tag -a vX.Y.Z -m "Neutron X.Y.Z"` y push de la rama y de las etiquetas (Cosmo instala
  desde la etiqueta). Repositorio: `github.com/jprojects/neutron` (por confirmar).

## Probar

- Sintaxis: `for f in $(find . -name '*.php' -not -path './.git/*'); do php -l $f; done` y `node --check assets/*.js`.
- Local: `php -S 127.0.0.1:8000 router.php` (con `PHP_CLI_SERVER_WORKERS=4` para probar la autoprueba de una
  actualización). Editor en `/?admin` (contraseña de la instalación local: ver `config.local.php`).
- API remota: poner un token temporal en `config.local.php` y `curl -H "X-Quasar-Token: …" "…/index.php?r=remote&a=status"`.
  Las actualizaciones se prueban en una copia de la carpeta, nunca sobre la instalación de trabajo.
- reCAPTCHA: claves de prueba de Google (sitio `6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI`, secreta
  `6LeIxAcTAAAAAGG-vFI1TnRWxMZNFuojJ4WifJWe`), que aprueban siempre. No dejarlas en `config.local.php`.
- Editor en un navegador real: Chromium headless con el protocolo de DevTools desde Node 22 (WebSocket nativo,
  sin dependencias); el perfil y las capturas fuera de /tmp (`~/…`), porque Chromium es un snap.
- Antes de dar algo por terminado, pruébalo en el navegador (también en modo `?admin`). Limpia después lo que
  generen las pruebas en `data/`.
