# Cambios

Formato: una sección por versión, la más reciente arriba. Cada versión se etiqueta en git como `vX.Y.Z`.

## 1.24.0 — 2026-11-10

- **Cabeceras de seguridad** en todas las respuestas (`lib/security.php`, desde `bootstrap.php`, antes de servir la
  caché): `nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy` y HSTS por HTTPS (también
  detrás del proxy del hosting); fuera `X-Powered-By`. Configurable en `security` (`hsts_subdomains`, `headers`, `hsts`).
- **`/.well-known/security.txt`**: el de `site/` o generado con `security.contact` (y `security.languages`).

## 1.23.0 — 2026-11-10

- **SEO y GEO en el núcleo** (`lib/meta.php`), en todas las páginas y en los dos motores, sin tocar las plantillas: URL
  canónica, Open Graph y Twitter (con la imagen para compartir: `site/og.jpg`, la de la página o el logo), y datos
  estructurados JSON-LD (el titular de la web en la portada, con `site/legal.json` y `site/business.json`; la ruta en
  las demás páginas; el elemento en las fichas, `"schema"` en `_collection.json`).
- **`/robots.txt`** y **`/llms.txt`** generados (o los de `site/`, si los hay).
- Herramientas: `compose.py` saca la descripción de cada página de su primer párrafo (antes ponía el texto de ejemplo del
  pie en todas); `check.py` avisa de textos de ejemplo de los bloques que se han quedado, descripciones repetidas, la
  imagen para compartir, los datos de contacto de `legal.json` y si `robots.txt` y `llms.txt` responden.

## 1.22.1 — 2026-11-10

- Menú editable: también menús en lista (`<ul data-menu>` con `<li><a>`), y los enlaces destacados se reconocen por
  `btn`, `button` o `cta` en la clase (`nav-cta`…). El texto de un enlace con icono ya no lleva el del SVG.

## 1.22.0 — 2026-11-10

- **Menú editable** desde el panel: si la plantilla marca su menú con `data-menu`, *Panel → Menú* permite añadir, quitar,
  ordenar, ocultar y destacar enlaces (internos o a otras webs), con el texto en cada idioma. Se guarda en
  `site/menu.json` y lo pintan los dos motores; sin ese fichero, el menú es el de la plantilla. Los bloques `header` y
  `footer` llevan `data-menu`.
- **Copias de las colecciones**: guardar o borrar un elemento (o las categorías) deja copia en *Copias*, con lo que ha
  cambiado y la opción de volver a como estaba (también si se había borrado).
- **Visitas** en el inicio del panel, sin cookies: páginas vistas y visitantes de los últimos 30 días y las páginas más
  vistas (`data/stats/`, config `stats`). El inicio cuenta los elementos de cada colección.
- **Panel y barra del editor en catalán** cuando la web es en catalán (o con `panel.lang`): traducciones en
  `assets/i18n/ca.json`.

## 1.21.0 — 2026-11-10

- **Fichas de cualquier colección**: la descripción y la imagen para buscadores y redes salen de sus campos aunque no se
  llamen `summary` ni `image`. `compose.py` genera la ficha a partir de los campos (`"item_page": true`, en el bloque o
  en la receta) y hay un bloque nuevo `catalog` (catálogo sin venta, con ficha).
- **Buscador** opcional: un formulario `data-search` en la plantilla, el índice en `/search.json` (páginas y colecciones,
  en caché) y la búsqueda en el navegador (`assets/search.js`), configurable con `site/search.json`. Bloque `search`.

## 1.20.0 — 2026-11-10

- **Caché de páginas** para los visitantes (`data/cache/`): se renueva sola cuando cambia cualquier cosa del sitio (por
  el panel, el editor, FTP o una actualización) y caduca a la hora. Config `cache` (`enabled`, `ttl`).
- **Imágenes para el móvil**: versiones de 640, 1024 y 1600 px de las imágenes grandes y `srcset` en las páginas (en los
  dos motores). Se hacen al subir, al migrar después de cada actualización y con `php bin/images.php`.

## 1.19.0 — 2026-11-10

- La limpieza del texto con formato codifica los espacios de los enlaces (`%20`): los dos motores dan el mismo HTML con
  enlaces a ficheros con espacios en el nombre.
- Herramientas (no son del núcleo), para migrar webs con componentes:
  - `migrate.py` crea las **colecciones**: noticias (el blog de categoría de Joomla y los artículos), **documentos** (los
    descarga con su categoría, fecha y descargas), **galería** por álbumes y **vídeos**; guarda el cuerpo limpio de cada
    página para el bloque `richtext`, y avisa de los vídeos propios del hosting.
  - Bloques nuevos: `documents`, `albums` (con visor de fotos), `videos`, `news` y `richtext`; `compose.py` escribe la
    definición de sus colecciones y la página de cada noticia.
  - `new-site.py` copia el núcleo según el `core.json` de la etiqueta (antes faltaba `download.php`) y `check.py` ya no
    deja fuera los documentos de `site/uploads` al probar.

## 1.18.0 — 2026-11-10

- **Colecciones con su propia definición** (`site/<colección>/_collection.json`): campos de texto, texto con formato,
  fecha, imagen, **álbum de fotos**, **documento**, **categoría**, enlace y **vídeo**, orden y qué se traduce; con o
  sin página propia. Categorías en `_categories.json`. Las colecciones de antes (blog) siguen igual.
- **Gestor en el panel** para cada colección, generado a partir de su definición: buscador, filtro por categoría,
  idiomas, ficha con un control por campo (subir documentos y varias fotos a la vez), publicar, borrar, orden manual y
  categorías. El blog también se gestiona desde el panel.
- Listados: `data-post-href` (descarga de un documento, enlace o vídeo), `data-post-src` (vídeo para insertar, con
  consentimiento, o su miniatura), `data-post-images` (álbum), `data-posts-filter` y `data-posts-group` (por
  categorías), y datos como `file.size` o `file.downloads`. En los dos motores.
- `download.php` (nuevo en el núcleo): cuenta las descargas y lleva al fichero. `admin/upload.php` acepta documentos
  (PDF, Office, LibreOffice, texto, CSV, ZIP) hasta `uploads.max_doc_mb` (20).

## 1.17.0 — 2026-11-10

- **Mensajes en el panel**: los del formulario de contacto se guardan en la web antes de enviar el correo, así que no se
  pierden si el correo falla (el visitante recibe «enviado» y el mensaje queda marcado). Lista con los no leídos,
  responder, marcar como no leído y borrar; se borran solos a los `contact.keep_days` días (365).
- **Copias del contenido**: antes de cada guardado queda copia de cómo estaba. En el panel, el antes y el ahora de cada
  cambio, **Recuperar lo de antes** de un campo y **Volver a esta versión** (que también se puede deshacer).

## 1.16.0 — 2026-11-10

- **IA en el panel**: botón ✨ IA en cada texto para mejorarlo, corregir la ortografía o traducirlo a los otros idiomas,
  y para escribir el título y la descripción para Google a partir de la página. La IA solo propone; el cliente usa,
  retoca o descarta. Configuración nueva `ai` (mismas claves que Quasar: clave de Anthropic por cliente, modelo, límite
  mensual en euros). El consumo del mes va en `data/ai/usage.json` y en el estado remoto, para Cosmo.

## 1.15.0 — 2026-11-10

- **SEO y GEO en el panel** con Pulsar: se elige la página y se analiza; sale la nota, la puntuación por categoría y lo
  que se puede mejorar con la solución. El título y la descripción tienen **Corregir**, que lleva a su campo. Límite de
  análisis al día (`pulsar.daily_limit`, 3 por defecto), último informe de cada página e historial en `data/seo/`.
  Configuración nueva `pulsar` (`url`, `token`, `daily_limit`): sin ella la sección no sale.
- Herramientas: `new-site.py` configura Pulsar desde `~/.config/neutron/pulsar.json` y `check.py` avisa si falta.

## 1.14.0 — 2026-11-10

- **Campañas de AFI en el panel**: acciones remotas `announcements` y `announcements-set` para que Cosmo (0.5.0+) envíe
  los avisos del inicio del panel (`/admin/`). Cada aviso cuenta sus vistas (una por sesión) y los clics del botón,
  que Cosmo recoge. Los enlaces solo pueden ser http(s).

## 1.13.0 — 2026-11-10

- **Panel de la web** en `/admin/`, con la contraseña del editor: inicio (datos de la web, últimos cambios y avisos de
  AFI desde `data/announcements.json`) y todo el contenido en formularios por página y sección, en cada idioma (textos,
  texto con formato, imágenes, enlaces, descripción para Google y listas con sublistas). Funciona con cualquier web ya
  hecha: los campos salen de las plantillas (`lib/schema.php`). Guarda con `admin/save.php`, con los avisos de
  conflicto de la 1.12. La barra del editor tiene un botón **Panel**.
- `router.php` (servidor de desarrollo) sirve el `index.php` de una carpeta (`/admin/`) como el `DirectoryIndex` de
  Apache.

## 1.12.0 — 2026-11-10

- El editor solo manda lo que se ha cambiado (campos, imágenes y listas), no toda la página. Si otra ventana (otra pestaña
  o otra persona) ha guardado el mismo campo después de abrir la página, avisa y pregunta si guardar lo suyo o recargar,
  en vez de pisarlo sin decir nada. Hora del último guardado de cada campo en `data/edits.json`; los guardados se hacen
  bajo un bloqueo. Compatible con un editor de antes abierto durante la actualización.
- Guardar sin cambios ya no escribe nada («Sin cambios»). El log apunta qué campos se han guardado.

## 1.11.0 — 2026-11-06

- `data-edit-html="clave"`: texto con formato por idioma (títulos, listas, enlaces, tablas), limpio con la lista blanca
  y editable con la barra de formato, en los dos motores. La lista blanca admite ahora tablas y `code`, y un `<div>` que
  envuelve bloques se quita en lugar de convertirse en párrafo.
- Herramientas (no son del núcleo): bloques reutilizables en `blocks/` (cabecera, portada, cabecera de página, texto con
  imagen, galería, tarjetas, llamada a la acción, contacto con mapa, formulario y pie, con textos en ca/es/en) y
  `tools/compose.py` para montar el `site/` con ellos; `tools/legal.py` hace las páginas legales con Pulsar;
  `tools/migrate.py` saca de la web antigua los datos del titular (razón social, NIF).

## 1.10.1 — 2026-11-06

- Corregido: el motor sin DOM trataba un «<» suelto («< Anterior», «3 < 5») como el principio de una etiqueta y
  descuadraba lo que venía detrás; ahora es texto, como en DOMDocument y en el navegador.
- Herramientas para hacer webs (en `tools/`, no son del núcleo): `new-site.py` crea la instalación, `migrate.py` migra el
  contenido de la web antigua (textos, imágenes en WebP, favicon, redirecciones e informe) y `check.py` lo comprueba todo
  antes de entregar. Ver «Herramientas» en el README.

## 1.10.0 — 2026-11-06

- Imágenes optimizadas al subirlas desde el editor: se reducen a 2000 px de lado como mucho y se pasan a WebP en el
  navegador (JPG o PNG si no sabe hacer WebP), con la orientación de la foto aplicada y sin datos EXIF. Las fotos del
  móvil se pueden subir tal cual (de 3–8 MB a unos 200–500 KB), aunque el hosting limite las subidas a 2 MB. Los GIF no se
  tocan. Si llega una imagen grande sin optimizar, `admin/upload.php` la reduce con GD.
- Guía de edición para los clientes: `docs/guia-edicio/generar.py` la hace con capturas de la web de cada cliente y solo
  con los apartados que tiene; se guarda en la carpeta de la web.

## 1.9.1 — 2026-11-06

- Corregido: en una web de varios idiomas, guardar desde un idioma que no es el principal copiaba a ese idioma las
  listas que aún no estaban traducidas, y después ya no seguían los cambios del idioma principal. Ahora las listas
  hacen como los textos: solo se guardan en el idioma si son distintas de las del principal.

## 1.9.0 — 2026-11-06

- Enlaces editables: `<a data-edit-href="clave">` (redes sociales…). El cliente puede cambiar la dirección o vaciarla,
  y entonces el enlace no sale en la página; la plantilla puede traer redes sin dirección para añadirlas después. Solo
  `http(s)`, `mailto:`, `tel:` y enlaces de la web, comprobado al pintar (los dos motores) y en el editor.
- Editor: «Nueva entrada» y la dirección de los enlaces se piden en una ventana del propio editor, en lugar del
  `prompt()` del navegador; los errores salen en la misma ventana.
- `tools/cdp.mjs`: `shot()` para varias capturas en una misma ejecución y `CDP_SIZE` para probar con pantalla de móvil.

## 1.8.0 — 2026-11-03

- Entradas de una colección: la imagen también se puede traducir (`i18n.<idioma>.image`), para carteles con texto.
  Desde el editor, cambiarla en un idioma que no es el principal solo la cambia en ese idioma.
- Fechas de las entradas en francés («22 mai 2026», «1er mai»).
- Corregido: en una web de varios idiomas, `/noticies/…` sin idioma iba a la portada; ahora va a la misma ruta en
  el idioma principal, y si la entrada no existe se consulta `redirects.json`.

## 1.7.0 — 2026-11-03

Webs de varios idiomas.

- `site/languages.json` (idiomas y el principal): rutas `/ca/…`, `/es/…` con el mismo nombre de página, raíz al
  idioma del navegador, `/pagina.html` sin idioma al principal. También colecciones, RSS por idioma y un sitemap con
  todos.
- Textos por idioma en `site/content.<idioma>.json` (con el principal y la plantilla como respaldo); las imágenes,
  comunes en `site/content.json`. `contact.<idioma>.json` y `cookies.<idioma>.json` opcionales.
- Enlaces internos con el idioma delante, `<html lang>`, selector `data-lang-link` / `data-lang-current` y
  `<link rel="alternate" hreflang>`. En los dos motores de render.
- Entradas traducidas en la misma entrada (`i18n`), con el texto del idioma principal mientras no se traducen.
- Editor: selector de idioma; guarda en el idioma de la página sin copiar a la traducción los textos que aún son
  del principal; aviso en las entradas sin traducir.
- `data-edit-content="clave"`: el atributo `content` de una etiqueta (`<meta name="description">`).
- El estado para Cosmo incluye los idiomas (`site.languages`).

## 1.6.0 — 2026-11-02

Blog: colecciones con página propia.

- `site/<colección>/_item.html` + una entrada por JSON: listado en `/blog/` (con paginación), entrada en
  `/blog/mi-entrada`, RSS en `/blog/feed.xml` y `/sitemap.xml` para todo el sitio. Fecha en el idioma de la web,
  descripción, URL canónica y etiquetas `og:` por entrada. En los dos motores de render.
- Listados en cualquier página (`data-posts`, `data-post-item`, `data-posts-per-page` / `-limit`, `data-posts-empty`,
  `data-posts-prev` / `-next`); en la página de una entrada no la repiten.
- Editor: «Nueva entrada», borradores, Publicar / Pasar a borrador, Borrar (a `data/deleted/`), fecha, imagen y
  cuerpo con barra de formato. Endpoint `admin/post.php` con sesión y CSRF.
- `lib/sanitize.php`: limpieza del HTML con lista blanca, al guardar y al pintar.
- Seguridad: no se sirve ningún `.json` de `site/` (antes solo los de la raíz de `site/`) ni las plantillas `_item.html`.
- Corregido: el motor sin DOM escapaba dos veces las entidades del texto de la plantilla (`&copy;` salía como
  `&amp;copy;`), y no reconocía etiquetas con espacio de nombres (`<o:p>` de Word).
- El estado para Cosmo cuenta las entradas (`site.posts`).

## 1.5.1 — 2026-11-02

- Corregido: con el servidor de desarrollo (`php -S router.php`), las redirecciones de `site/redirects.json` y la 404
  fallaban para rutas en subcarpetas que parecen ficheros (`/assets/images/logo.png`), porque PHP pone esa ruta en
  `SCRIPT_NAME`. La carpeta de la instalación se calcula ahora también desde el `DOCUMENT_ROOT`. Con Apache ya
  funcionaba.

## 1.5.0 — 2026-11-02

Faldón de cookies en el núcleo.

- Con `site/cookies.json` el núcleo inyecta el faldón de consentimiento (`assets/cookies.js` y `cookies.css`):
  categorías con su descripción, aceptar / rechazar / configurar, textos en el idioma del sitio, color propio.
  Sin categorías de terceros no se muestra (solo cookies técnicas). Antes cada sitio llevaba su copia.
- Bloqueo hasta el consentimiento de iframes (`data-consent-src`, con `data-consent="categoría"`) y de scripts
  (`<script type="text/plain" data-consent="…">`); `[data-cookie-accept="…"]` y `[data-cookie-settings]`;
  `neutronCookies.has()` y el evento `neutron:consent`.
- Lee las cookies de los faldones propios anteriores (`{ v, media: true }`): quien ya había elegido no tiene que
  volver a hacerlo si el sitio mantiene el nombre de su cookie (`cookie` en `cookies.json`).
- El estado para Cosmo dice si el sitio tiene faldón (`site.cookies`). `tools/` no se sirve.

## 1.4.0 — 2026-10-26

Editar webs de varias páginas sin salir del modo edición.

- Selector **Página** en la barra del editor: la portada y las de `site/pages/` (menos la 404), en el orden del menú
  y con el título de cada una; lleva a esa página ya en modo edición.
- En modo edición, Ctrl/Cmd + clic en un enlace interno (o un clic en un enlace sin texto editable) abre esa página
  manteniendo `?admin`; un clic normal en un texto editable sigue siendo para editarlo. Antes los enlaces del menú
  no llevaban a ningún sitio en modo edición y los demás sacaban del editor.
- Aviso de cambios sin guardar al cambiar de página, salir, cerrar sesión o cerrar la pestaña.
- Corregido: una imagen editable dentro de un enlace abría el selector de archivo y además seguía el enlace.

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
