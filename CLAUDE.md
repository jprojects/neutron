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
- **Cookies**: el faldón es del núcleo; cada sitio lleva `site/cookies.json` (textos en su idioma, `accent`, y una
  categoría por cada servicio de terceros que necesite consentimiento: vídeos, mapas, analítica). Nunca un
  `cookies.js` propio. Iframes con `data-consent="…" data-consent-src="…"`, scripts con
  `type="text/plain" data-consent="…"`, aviso con `[data-cookie-accept="…"]`, y `[data-cookie-settings]` en el pie.
  Si cambian las categorías, subir `version`. Todo descrito en la política de cookies del sitio. Sin terceros que
  pidan consentimiento, `categories` vacío (no sale faldón) pero `cookies.json` igualmente.
- Solo texto plano en `data-edit`. Los `href` no se editan, salvo los enlaces con `data-edit-href` (redes sociales:
  siempre con `data-edit-href="social_<red>"`; las redes que el cliente aún no usa, con `href=""`, para que pueda
  añadirlas). Las listas se pueden anidar (hasta 3 niveles):
  cartas y menús como secciones con sus platos, no como bloques de texto.
- **Webs nuevas, con las herramientas** (README → «Herramientas»): `tools/new-site.py <cliente>` (crea
  `~/Projects/neutron-<cliente>` y apunta la contraseña), `tools/migrate.py URL <carpeta>` (contenido, imágenes,
  favicon, datos legales, redirecciones e informe en `_migracio/`; leer el informe antes de diseñar),
  `tools/compose.py <carpeta>` con los bloques de `blocks/` (receta en `site/compose.json`; el diseño propio en
  `site/assets/custom.css`), `tools/legal.py <carpeta> --nif …` (las legales con Pulsar; los datos fiscales, de Asteroid), y al terminar
  `tools/check.py <carpeta> --guia` hasta que no quede ningún ✗. Si una herramienta falla o se queda corta con una web,
  se mejora la herramienta (en vez de hacerlo a mano), para la próxima.
- Webs de varias páginas: cabecera, menú y pie en `site/partials/` con `data-include`; claves de cada página con
  prefijo; resaltar el menú con `[aria-current="page"]`; `site/redirects.json` con todas las URLs de la web anterior.
- **Blog**: colección en `site/blog/` (`_item.html` + JSON por entrada) y listado en `site/pages/blog.html`; enlaces
  como `blog/` y `blog/<slug>` (nunca `blog.html`). El cuerpo es HTML limpio por `lib/sanitize.php`: si un sitio
  necesita otra etiqueta, se amplía la lista blanca en el núcleo (y su versión simple en `editor.js`), nunca se
  salta. La plantilla de la entrada lleva `data-post*` y su `<title data-post="title">… · Web</title>`.
- **Varios idiomas**: `site/languages.json` y los textos en `site/content.<idioma>.json` (en `content.json` solo
  imágenes y lo común); misma plantilla y mismos nombres de página para todos; enlaces internos sin idioma (los pone
  el núcleo); selector con `data-lang-link`; `contact.<idioma>.json` y `cookies.<idioma>.json`; entradas traducidas
  en `i18n`. Las URLs antiguas de cada idioma, en `redirects.json`.
- **Favicon siempre**: al migrar, cogerlo de la web original (`rel="icon"`/`shortcut icon` en el HTML y
  `/favicon.ico`); si no tiene, hacerlo a partir del logo. Va en `site/favicon.ico` (16/32/48 px) y
  `site/apple-touch-icon.png` (180×180), con `<link rel="icon" href="favicon.ico">` y
  `<link rel="apple-touch-icon" href="apple-touch-icon.png">` en el `<head>` de todas las páginas.
- Cada web nueva es una instalación aparte en `~/Projects/neutron-<cliente>` (con `tools/new-site.py`), no en `site/`
  de este repo, que es Assaice. Las anteriores a noviembre de 2026 están en `~/Projects/<nombre>`.
  La contraseña del editor de cada web (al crearla o al cambiarla) se apunta en `credencials.local.md` de este repo
  (en el `.gitignore`; nunca en el scratchpad, que se borra, ni en la memoria).
- **En una web (instalación) no se tocan los ficheros de `core.json`**, para que siempre se pueda actualizar desde
  Cosmo: todo lo de esa web va en su `site/` y su `config.local.php`. Si una web necesita algo del núcleo, se hace aquí
  de forma genérica (nada de una web concreta en `lib/` o `assets/`), se publica una versión y la web se actualiza.

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
- Editor y JS en un navegador real: `node tools/cdp.mjs <url> <pasos.mjs> [captura.png]` (Chromium headless con el
  protocolo de DevTools, sin dependencias; ver la cabecera del fichero). Perfil y capturas en `~/cdp-tmp`, porque
  Chromium es un snap y no ve /tmp.
- **Cada web nueva lleva su guía** (PDF en catalán, con capturas de su web y solo los apartados que tiene):
  `python3 docs/guia-edicio/generar.py ~/Projects/neutron-<cliente> --nom "…" --url https://…`. Se guarda en la carpeta de
  la web (`guia-edicio-AAAA-MM-DD.pdf`), no en este repo, para tenerla con la web y no perder la anterior. No es para subirla
  al servidor.
- Antes de dar algo por terminado, pruébalo en el navegador (también en modo `?admin`). Limpia después lo que
  generen las pruebas en `data/`.
