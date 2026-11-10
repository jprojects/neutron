# Neutron

CMS mínimo sin base de datos para webs de una página creadas con IA. El diseño es un `template.html` normal
con unos atributos `data-edit*`; el contenido vive en `content.json` y se edita en la propia página
(`?admin`). Una o varias páginas, con cabecera y pie compartidos y listas anidadas (secciones de una carta con sus
platos). PHP 8.1+, sin dependencias (ni Composer ni npm).

Se gestiona desde el panel central **Cosmo** igual que Quasar: estado, copias de seguridad, log,
mantenimiento y actualizaciones desde las etiquetas de GitHub.

## Núcleo y sitio

Cada instalación tiene dos partes:

| | Qué es | Lo toca una actualización |
|---|---|---|
| **Núcleo** | lo que lista `core.json`: `index.php`, `contact.php`, `admin/`, `lib/`, `assets/`, `bin/`… | sí |
| **Sitio** | `site/`: plantilla, contenido, páginas, imágenes y subidas | nunca |
| **Configuración** | `config.local.php`: contraseña, reCAPTCHA, token de Cosmo | nunca |
| **Datos internos** | `data/`: log, copias, estado de la API remota, límites de intentos | nunca |

```
index.php            página (y API remota con ?r=remote)
contact.php          recibe los formularios data-neutron-form
download.php         descargas de los documentos de las colecciones (las cuenta)
config.php           valores por defecto (núcleo)  ·  config.local.php: los de esta instalación
admin/               login, guardar, subir imagen (editor)
lib/                 render, contenido, auth, log, formulario, reCAPTCHA
lib/remote/          API para Cosmo: estado, copias, log, integridad, actualización
assets/              editor.js/css y form.js del núcleo (los del sitio van en site/assets/)
bin/install.php      prepara una instalación
router.php           router para php -S (el servidor de desarrollo no lee .htaccess)
core.json            qué ficheros son del núcleo
examples/            sitios de ejemplo (se copian a site/)

site/
  template.html      la página
  content.json       el contenido editable (en una web de varios idiomas, lo común: imágenes)
  content.ca.json    textos de cada idioma (solo webs de varios idiomas, ver «Varios idiomas»)
  languages.json     idiomas de la web y el principal (opcional; sin él, la web es de un idioma)
  contact.json       campos y textos del formulario de contacto (opcional)
  cookies.json       faldón de cookies: categorías que piden consentimiento y textos (opcional)
  redirects.json     URLs de la web anterior → páginas nuevas (opcional)
  pages/*.html       otras páginas: site/pages/carta.html → /carta.html (404.html, la de error)
  partials/*.html    trozos compartidos (cabecera, pie): <div data-include="header"></div>
  blog/              una colección (blog, noticias…): _item.html (la entrada) y una .json por entrada
  favicon.ico        icono de la web, servido también como /favicon.ico
  apple-touch-icon.png  icono para iPhone/iPad (180×180), servido también como /apple-touch-icon.png
  assets/            imágenes, CSS y JS propios del sitio (cookies.js…)
  uploads/           imágenes subidas desde el editor
```

`site/` y `config.local.php` no están en el repositorio (`.gitignore`): cada web tiene los suyos.

## Instalación

```bash
php bin/install.php                          # entorno, config.local.php (contraseña, reCAPTCHA, token) y carpetas
php bin/install.php --example=espacio-bella  # además copia un ejemplo a site/ si no existe
php -S localhost:8000 router.php             # desarrollo
```

Otras opciones: `--password` (cambiar la contraseña del editor) y `--token` (token nuevo para Cosmo).

En producción, Apache con `mod_rewrite` (el `.htaccess` incluido prohíbe `lib/`, `data/`, `bin/`, la config y los
JSON y la plantilla de `site/`, y sirve las páginas de `site/pages/`).

**`.htaccess`**: el núcleo solo es dueño del bloque `# BEGIN Neutron` … `# END Neutron`. Las reglas propias de la
instalación (redirección a HTTPS, la versión de PHP que añade cPanel…) van fuera, mejor **encima** del bloque (se
evalúan antes). Las actualizaciones sustituyen el bloque y conservan el resto, y la integridad solo mira el bloque. PHP necesita escribir en `site/` (editor),
`data/` (log, copias) y, para las actualizaciones desde Cosmo, en el núcleo.

Con nginx, el equivalente:

```nginx
location ~ ^/(lib|data|bin|examples|tools|\.git)(/|$) { deny all; }
location ~ ^/(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php)$ { deny all; }
location ~ ^/site/(template\.html|.+\.json$|pages/|partials/|[^/]+/_item\.html) { deny all; }
location ~ ^/([a-z0-9][a-z0-9-]*)\.html$ { try_files $uri /index.php?page=$1&$args; }
location / { try_files $uri $uri/ /index.php$is_args$args; }
```

Extensiones: `mbstring` y `fileinfo`; `zip` para copias y actualizaciones; `curl` (o `allow_url_fopen`) para el
reCAPTCHA y la autoprueba; `dom` es opcional (sin ella se usa un parser HTML propio).

## Crear un sitio

`site/template.html` es HTML normal. Lo que se puede editar se marca así:

| Atributo | Qué hace | En `content.json` |
|---|---|---|
| `data-edit="clave"` | texto editable (solo texto plano) | `"clave": "texto"` |
| `data-edit-img="clave"` | imagen sustituible (cambia `src`) | `"clave": "site/uploads/…"` |
| `data-edit-collection="clave"` | lista: el primer hijo con `data-edit-item` es el molde | `"clave": [ {…}, {…} ]` |
| `data-edit-field="campo"` | campo de cada elemento de la lista (texto, o `src` si es `<img>`) | `{"campo": "…"}` |
| `data-edit-add="Afegir plat"` | texto del botón para añadir elementos a esa lista (opcional) | — |
| `data-edit-content="clave"` | pone el atributo `content` (`<meta name="description">`); no se edita en la página | `"clave": "texto"` |
| `data-edit-html="clave"` | texto con formato (títulos, listas, enlaces, tablas), limpio con la lista blanca de `lib/sanitize.php`; se edita con la barra de formato | `"clave": "<p>…</p>"` |
| `data-edit-href="clave"` | dirección de un `<a>` editable (redes sociales); vacía, el enlace no sale | `"clave": "https://…"` |

El valor que hay en la plantilla solo se ve si `content.json` no tiene esa clave (también en las listas: sin la
clave se ve el molde tal cual).

**Enlaces editables** (`data-edit-href`): pensado para las redes sociales y enlaces parecidos. La dirección sale del
contenido; si no está, de la plantilla. Solo `http(s)`, `mailto:`, `tel:` y enlaces de la propia web (lo demás, como
`javascript:`, se descarta). **Vacía, el enlace no sale en la página**: así el cliente puede quitar una red, y la
plantilla puede traer redes sin dirección (`href=""`) que no se ven hasta que se les pone una. En el editor, los enlaces
sin dirección se ven atenuados; un clic en un enlace sin texto editable (un icono) abre la ventana para cambiar la
dirección, y si el enlace tiene texto editable sale un botón 🔗 a su lado. El resto de enlaces de la plantilla no se
pueden editar: solo su texto.

```html
<a href="https://www.instagram.com/…" data-edit-href="social_instagram" target="_blank" rel="noopener" aria-label="Instagram">…</a>
<a href="" data-edit-href="social_youtube" target="_blank" rel="noopener" aria-label="YouTube">…</a>   <!-- no sale hasta tener dirección -->
```

### Listas dentro de listas

Un `data-edit-collection` dentro de un `data-edit-item` es una sublista de ese elemento (hasta 3 niveles). Cada
campo y cada sublista pertenecen al `data-edit-item` más cercano, así que el mismo nombre de campo puede usarse en
los dos niveles. El campo va siempre **dentro** del elemento, no en el mismo nodo
(`<li data-edit-item><span data-edit-field="name">`, no `<li data-edit-item data-edit-field="name">`). Ejemplo, una carta con secciones y platos:

```html
<div data-edit-collection="carta" data-edit-add="Afegir secció">
  <section data-edit-item>
    <h2 data-edit-field="title">Primers</h2>
    <ul data-edit-collection="plats" data-edit-add="Afegir plat">
      <li data-edit-item><span data-edit-field="name">Sopa</span> <b data-edit-field="price">8 €</b></li>
    </ul>
  </section>
</div>
```

```json
"carta": [
    { "title": "Primers", "plats": [ { "name": "Sopa", "price": "8 €" }, { "name": "Amanida", "price": "9 €" } ] },
    { "title": "Postres", "plats": [ { "name": "Crema", "price": "5 €" } ] }
]
```

En el editor, cada elemento tiene ↑ (subir) y ✕ (quitar; siempre queda uno) y cada lista su botón de añadir, que
copia el último elemento con los textos vacíos (y sus sublistas con un solo elemento).

### Varias páginas

- Cada `site/pages/nombre.html` es una página completa (con su `<head>`) que se abre como `/nombre.html`.
  Todas usan el mismo `content.json`: una clave que sale en varias páginas (teléfono, dirección) se edita una vez.
  Para lo que es propio de una página, claves con prefijo (`carta_intro`, `menjador_title`…).
- **Parciales**: `<div data-include="header"></div>` se sustituye por `site/partials/header.html` (vale cualquier
  etiqueta vacía con `data-include`). Así la cabecera, el menú y el pie se escriben una sola vez. Pueden llevar
  `data-edit` y otros parciales.
- **Página actual**: los enlaces a la página que se está viendo reciben `aria-current="page"` (para resaltarla
  en el menú con CSS). La portada es `./`; los enlaces con `#` no cuentan.
- Si la misma clave sale varias veces en una página (teléfono en la cabecera y en el pie), al editar una se
  actualizan las demás.
- **404**: si existe `site/pages/404.html`, es la página de error.

### Cookies

El núcleo trae el faldón de consentimiento (RGPD + art. 22.2 LSSI). Se activa con `site/cookies.json`; el sitio no
lleva JavaScript propio de cookies:

```json
{
    "policy": "politica-de-cookies.html",
    "accent": "#9b1b30",
    "categories": [
        { "id": "media", "name": "Mapes (Google Maps)", "description": "Permeten mostrar el mapa interactiu…" }
    ],
    "texts": { "title": "Aquest lloc web utilitza cookies", "text": "Fem servir cookies tècniques… a la {policy}.", "accept": "Acceptar totes" }
}
```

- `categories`: solo las que necesitan consentimiento (vídeos, mapas, analítica…). **Sin categorías no hay faldón**,
  porque las cookies técnicas no lo requieren, y los botones «Configurar cookies» se ocultan solos.
- `texts`: en el idioma de la web (`title`, `text` con `{policy}` donde va el enlace, `policy`, `accept`, `reject`,
  `configure`, `save`, `technical`, `technical_description`, `label`); los que falten salen en castellano.
- `accent`: color de los botones (también `--nc-accent`, `--nc-bg`, `--nc-text`, `--nc-font` en el CSS del sitio).
- `cookie` (por defecto `neutron_consent`) y `version`: subir la versión vuelve a preguntar a todo el mundo (cuando
  cambian las categorías). La elección se guarda 12 meses.

En la página:

```html
<iframe data-consent="media" data-consent-src="https://www.youtube-nocookie.com/embed/…"></iframe>  <!-- al aceptar -->
<script type="text/plain" data-consent="analytics" src="https://…"></script>                   <!-- se ejecuta al aceptar -->
<button data-cookie-accept="media">Mostrar el vídeo</button>      <!-- acepta esa categoría -->
<button data-cookie-settings>Configurar cookies</button>          <!-- vuelve a abrir las opciones -->
```

Sin `data-consent`, un iframe con `data-consent-src` es de la primera categoría. Al cargarse, su contenedor recibe la
clase `is-loaded` (para ocultar el aviso que lo tapaba). Rechazar es tan fácil como aceptar; si se retira un
consentimiento ya dado, la página se recarga. En JS: `neutronCookies.has('media')`, `neutronCookies.open()` y el
evento `neutron:consent` en `document`. En el modo edición el faldón no sale.

La política de cookies del sitio (`site/pages/…`) tiene que describir las cookies técnicas, cada categoría y los
servicios que se cargan sin cookies propias (reCAPTCHA del formulario, tipografías de Google…).

### Favicon

Cada sitio tiene su `site/favicon.ico` (16, 32 y 48 px) y su `site/apple-touch-icon.png` (180×180). Neutron los
sirve también en la raíz (`/favicon.ico`, `/apple-touch-icon.png`), que es donde los piden muchos navegadores y
lectores aunque la página diga otra cosa; sin el fichero responden 404 vacío. En el `<head>` de cada página:

```html
<link rel="icon" href="favicon.ico">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
```

Al migrar una web, el favicon se coge de la original (las etiquetas `rel="icon"`/`shortcut icon` y `/favicon.ico`);
si no tiene, se hace a partir del logo.

### Colecciones: blog, documentos, galería, vídeos…

Una colección es una carpeta del sitio con elementos en JSON. El **blog** es la de siempre (página propia por entrada);
desde la 1.18 una colección puede tener **su propia definición** de campos, con o sin página propia, y el panel genera
su gestor (`/admin/` → una sección por colección).

```
site/documentacio/_collection.json   definición (opcional; sin ella, la de un blog)
site/documentacio/_categories.json   categorías (opcional): [{"id", "title", "description", "i18n": {"es": {…}}}]
site/documentacio/_item.html         página de cada elemento (opcional) → /documentacio/mi-documento
site/documentacio/mi-documento.json  un elemento: sus campos, "status" (draft | published), "order" y, traducidos, "i18n"
```

Hace falta `_item.html` (con página propia: listado en `/coleccion/`, RSS y sitemap) o `_collection.json` (sin página
propia: documentos, álbumes, vídeos, que solo salen en los listados).

**Definición** (`_collection.json`):

```json
{
  "label": "Documentació", "item": "Document", "help": "Texto de ayuda en el panel",
  "sort": "-date",
  "fields": [
    {"name": "title", "type": "text", "label": "Títol"},
    {"name": "category", "type": "category", "label": "Categoria"},
    {"name": "date", "type": "date", "label": "Publicat"},
    {"name": "file", "type": "file", "label": "Fitxer"},
    {"name": "summary", "type": "textarea", "label": "Descripció"}
  ],
  "translated": ["title", "summary"]
}
```

- Tipos de campo: `text`, `textarea`, `html` (texto con formato, por la lista blanca), `date`, `image`, `images` (un
  álbum), `file` (un documento: PDF, Word, Excel, PowerPoint, LibreOffice, texto, CSV o ZIP, en `site/uploads/docs/`),
  `category` (de `_categories.json`), `url` y `video` (YouTube o Vimeo). `title` siempre está.
- `sort`: `-date` (por defecto, el más nuevo primero), `date`, `title`, `-title` u `order` (orden manual, con flechas
  en el panel). `translated`: los campos que se traducen en una web de varios idiomas (por defecto, `title`).
- Sin `_collection.json`, la de un blog: `title`, `date`, `image`, `summary`, `body` (html), con `title`, `image`,
  `summary` y `body` traducibles.

**Listados** (en cualquier página; en la de un elemento, también):

```html
<div data-posts="blog" data-posts-per-page="9">        <!-- o data-posts-limit="3" -->
  <article data-post-item>                              <!-- molde de cada elemento -->
    <a data-post-link href="#"><img data-post-img="image" src="site/assets/img/ejemplo.jpg" alt=""></a>
    <time data-post="date"></time>
    <h2><a data-post-link href="#" data-post="title">…</a></h2>
    <p data-post="summary"></p>
  </article>
</div>
<p data-posts-empty="blog">Aún no hay entradas.</p>   <!-- solo si no hay ninguno -->
<a data-posts-prev="blog" href="#">← Más nuevas</a> <a data-posts-next="blog" href="#">Anteriores →</a>
```

| Atributo | Qué hace |
|---|---|
| `data-post="campo"` | el texto del campo; la fecha en el idioma de la web («2 de novembre de 2026»; en un `<time>`, también `datetime`); una categoría, su nombre. Con punto, un dato: `category.description`, `file.size` («1,9 MB»), `file.ext` («PDF»), `file.name`, `file.downloads`, `images.count` |
| `data-post-img="campo"` | `src` de una imagen (sin imagen, se quita) |
| `data-post-html="campo"` | texto con formato |
| `data-post-link` | `href` a la página del elemento (colecciones con página propia) |
| `data-post-href="campo"` | `href` de un fichero (por `download.php`, que cuenta las descargas), un enlace o un vídeo; vacío = se quita |
| `data-post-src="campo"` | `src` de una imagen o de un vídeo para insertar (`youtube-nocookie.com/embed/…`); en un iframe con `data-consent`, va a `data-consent-src`. `video.thumb`: la miniatura de YouTube |
| `data-post-images="campo"` | un álbum: su primer hijo es el molde de cada foto, con `data-post-image` (`src`) y `data-post-image-link` (`href`); sin fotos, se quita |
| `data-posts-filter="category:marc-legal"` | solo los que cumplen (`campo:valor`, varios con comas) |
| `data-posts-group="category"` | por categorías, en el orden de `_categories.json`: molde `data-post-group` con `data-post-group-title`, `-description`, `-count` y los elementos dentro de `data-post-group-items`; los que no tienen categoría, al final con el título de `data-posts-group-other` |

Un documento por categorías:

```html
<div data-posts="documentacio" data-posts-group="category" data-posts-group-other="Altres documents">
  <section data-post-group>
    <h2 data-post-group-title>Categoria</h2> <p data-post-group-description></p>
    <ul data-post-group-items>
      <li data-post-item><a data-post-href="file" href="#" download data-post="title">…</a>
        <span data-post="file.ext"></span> · <span data-post="file.size"></span></li>
    </ul>
  </section>
</div>
```

Una galería por álbumes y vídeos con consentimiento:

```html
<div data-posts="imatges"><section data-post-item><h2 data-post="title"></h2>
  <div data-post-images="images"><a data-post-image-link href="#"><img data-post-image src="" alt="" loading="lazy"></a></div>
</section></div>
<div data-posts="videos"><article data-post-item><h3 data-post="title"></h3>
  <iframe data-consent="videos" data-consent-src="" data-post-src="video" title="Vídeo" allowfullscreen></iframe>
</article></div>
```

- **Descargas**: `download.php?c=coleccion&i=elemento&f=campo` suma una (los robots no cuentan) en `data/downloads.json`
  y lleva al fichero. Solo de elementos publicados y de `site/uploads/` o `site/assets/`.
- **Panel**: una sección por colección con buscador, filtro por categoría, idioma, «+ Nuevo», la ficha con un control por
  campo (subir documentos, varias fotos a la vez, vista previa del vídeo…), publicado o borrador, borrar y, con
  `sort: "order"`, flechas para ordenar. **Categorías…** edita las de la colección (en otro idioma, su traducción).
  Un elemento nuevo empieza como borrador si la colección tiene página propia, y publicado si no.
- **Páginas de un blog** (`_item.html`): `data-post="title"`, `data-post="summary"`, `data-post="date"`,
  `data-post-img="image"`, `data-post-html="body"`; en `<title data-post="title">Entrada · Mi web</title>` se conserva lo
  de después de ` · `. El núcleo añade la descripción, la URL canónica y las etiquetas `og:`.
- **Editor en la página**: en las páginas con un listado de una colección con página propia, **«Nueva entrada»** pide
  el título, crea un borrador y lo abre. En la entrada se editan en la página el título, el resumen, la imagen y el
  cuerpo (con barra de formato); en la barra, la **fecha**, **Publicar / Pasar a borrador** y **Borrar entrada** (va a
  `data/deleted/`). Los borradores solo los ve quien ha entrado al editor; en los listados llevan la etiqueta «Borrador».
- **Texto con formato seguro**: se limpia en el servidor con una lista blanca (`lib/sanitize.php`) al guardar y al
  pintar: `p`, `h2`, `h3`, `strong`, `em`, `a` (http, https, mailto, tel o rutas relativas), `ul`, `ol`, `li`,
  `blockquote`, `br`, tablas, `code` e `img` solo de `site/uploads/` o `site/assets/`. Ningún script, estilo, `on…`,
  iframe ni enlace `javascript:`. Lo que se pega (Word, otra web) se limpia ya en el navegador.
- La dirección de un elemento sale del título al crearlo y no cambia aunque se edite el título.

### Varios idiomas

Con `site/languages.json` la web tiene varios idiomas, cada uno con su carpeta en la URL y las mismas páginas:

```json
{ "default": "ca", "languages": { "ca": "Català", "es": "Español", "en": "English", "fr": "Français" } }
```

- **Rutas**: `/ca/`, `/es/servicios.html`… el mismo nombre de página en todos los idiomas (`/ca/biomassa.html` y
  `/es/biomassa.html`), también en las colecciones (`/es/noticies/mi-entrada`, `/es/noticies/feed.xml`). La raíz `/`
  va al idioma del navegador (`Accept-Language`) o, si no es uno de la web, al principal; `/pagina.html` sin idioma
  redirige al principal. `/sitemap.xml` lleva todos los idiomas.
- **Textos**: cada idioma en `site/content.<idioma>.json`; `site/content.json` queda para lo común (las imágenes,
  que se cambian una vez para todos). Lo que falta en un idioma se ve en el principal, y si tampoco está, el texto de
  la plantilla. La plantilla y las páginas son las mismas para todos los idiomas.
- **Formulario y cookies**: `site/contact.<idioma>.json` y `site/cookies.<idioma>.json` si existen; si no,
  `contact.json` y `cookies.json`. El formulario manda el idioma de la página y `contact.php` responde en él.
- **Enlaces**: en la plantilla se escriben sin idioma (`servicios.html`, `./#contacte`, `noticies/`) y el núcleo les
  pone delante el de la página. No se tocan los que empiezan por `/`, `site/`, `assets/`, `contact.php`, los
  externos ni los que ya llevan idioma. Las páginas llevan `<base>` y `<html lang>` con el idioma.
- **Selector de idioma**: `<a data-lang-link="es">ES</a>` enlaza la misma página en ese idioma (con `hreflang`; el
  del idioma actual lleva `aria-current="true"`), y `<span data-lang-current></span>` muestra el nombre del actual.
  El núcleo añade en el `<head>` los `<link rel="alternate" hreflang>` de todos los idiomas y `x-default`.
- **Entradas de una colección**: fecha y estado son comunes; título, resumen, cuerpo e imagen de cada idioma van
  en la misma entrada, en `"i18n": {"es": {"title": "…", "summary": "…", "body": "…", "image": "…"}}`. Lo que no
  está traducido se ve en el idioma principal (la imagen solo hace falta si cambia: un cartel con texto). «Nueva
  entrada» la crea en el idioma principal, desde cualquier idioma. `/noticies/…` sin idioma va al principal; si la
  entrada no existe, se busca en `redirects.json` (URLs antiguas con el mismo nombre de carpeta).
- **Editor**: la barra tiene un selector **Idioma** (la misma página en otro idioma, en modo edición). Lo que se
  guarda va al idioma de la página. Un texto o una lista que aún se ven en el idioma principal y se guardan sin
  cambiarlos no se copian a la traducción (siguen los del principal). En una entrada sin traducir, el editor lo avisa.

### Redirecciones de la web anterior

`site/redirects.json` convierte las URLs viejas en redirecciones 301 (para no perder el posicionamiento). Rutas
relativas a la carpeta de la instalación; `*` al final, cualquier ruta que empiece así; con `?…`, la consulta exacta:

```json
{
    "index.php/serveis/carta": "carta.html",
    "index.php/avis-legal": "avis-legal.html",
    "index.php?option=com_content&view=article&id=2": "politica-de-cookies.html",
    "component/*": "./"
}
```

Solo se consulta cuando la URL no es una página existente (o es la portada con parámetros que no son de Neutron).
El destino puede ser una URL completa.

Rutas: la página se sirve desde la raíz, así que los recursos del sitio se enlazan como `site/assets/…`.
Las páginas de `site/pages/` se abren como `/nombre.html` (o `index.php?page=nombre` si no hay `mod_rewrite`).

### Formulario de contacto

```html
<form action="contact.php" method="post" novalidate data-neutron-form>
  <input name="name" required> <input type="email" name="email" required> <textarea name="message" required></textarea>
  <label class="hp" aria-hidden="true">Web<input name="website" tabindex="-1" autocomplete="off"></label>  <!-- honeypot, oculto con CSS -->
  <button type="submit">Enviar</button>
  <p data-form-status role="status"></p>     <!-- mensajes; .is-error si falla -->
  <div data-recaptcha></div>                 <!-- opcional: dónde va el distintivo de reCAPTCHA -->
</form>
```

Con `data-neutron-form` el núcleo añade solo `assets/form.js`, que envía por `fetch`, enseña los mensajes y, si
hay claves, pasa el **reCAPTCHA v2 invisible**. El sitio no necesita JavaScript propio para el formulario.

- **reCAPTCHA**: claves del tipo *Invisible reCAPTCHA badge* en <https://www.google.com/recaptcha/admin> (con el
  dominio de la web), en `config.local.php` → `recaptcha.site_key` / `secret_key`. El script de Google solo se carga
  cuando el visitante empieza a usar el formulario. Sin claves el formulario funciona, pero Cosmo lo marca
  (`recaptcha.missing` en el estado). La política de cookies del sitio tiene que mencionar Google reCAPTCHA.
- **Protecciones**: honeypot `website`, reCAPTCHA y `contact.max_per_window` envíos por IP cada `contact.window` s.
- **Destinatario**: `mail.to` de `config.local.php` o, si está vacío, la clave `email` de `content.json` (la que diga
  `recipient` en `contact.json`). Se envía con `mail()` y `Reply-To` al correo del visitante.
- **`site/contact.json`** (opcional): orden, etiqueta, longitud máxima y obligatorios de cada campo, asunto
  (`{campo}` = valor enviado) y los textos que ve el visitante, en el idioma de la web. Sin él se envían todos los
  campos y los mensajes salen en castellano. Ejemplo:

```json
{
    "recipient": "email",
    "subject": "Contacto web: {name}",
    "fields": [
        { "name": "name", "label": "Nombre", "required": true, "max": 200 },
        { "name": "email", "label": "Correo", "required": true, "type": "email" },
        { "name": "message", "label": "Mensaje", "required": true, "max": 5000 }
    ],
    "messages": { "success": "¡Gracias! Te responderemos pronto." }
}
```

Mensajes disponibles: `sending`, `success`, `invalid`, `network`, `admin`, `captcha_load` (navegador) y
`required`, `email`, `rate_limited`, `captcha`, `not_configured`, `send_failed` (`{to}` = destinatario), `method`.

## Editor

`/?admin` (o `/pagina.html?admin`) pide la contraseña y vuelve editables los textos, imágenes y listas.

En webs de varias páginas, la barra del editor tiene un selector **Página** con la portada y todas las de
`site/pages/` (menos la 404), en el orden del menú y con el título de su `<title>` (lo que va antes de ` · `).
Dentro de la página, un clic en un texto editable es para editarlo (también en los enlaces del menú);
**Ctrl/Cmd + clic** en un enlace, o un clic en un enlace sin texto editable, lleva a esa página sin salir del modo
edición. Si hay cambios sin guardar, avisa antes de cambiar de página, salir o cerrar la pestaña.
**+ Nueva entrada** (blog) y los enlaces editables piden los datos en una ventana del propio editor.
En las webs de varios idiomas, otro selector **Idioma** abre la misma página en otro idioma (ver «Varios idiomas»).
La sesión solo arranca con `?admin`: los visitantes no reciben la cookie `neutron_admin_sid`.
5 intentos fallidos por IP bloquean 15 minutos. Imágenes JPG, PNG, GIF o WebP de hasta 5 MB (SVG no, por XSS).

**Guardar solo lo que cambia, sin pisar otra ventana**: el editor recuerda lo que había al abrir la página y al guardar
manda solo los campos, imágenes y listas que se han cambiado, con la hora en que se abrió la página (`base`).
`admin/save.php` apunta en `data/edits.json` cuándo se guardó cada campo (por idioma; `_` = `content.json`). Si un campo
que se manda lo ha guardado otra ventana después de `base` (la misma página en otra pestaña, otra persona) con otro
valor, no lo guarda y responde 409 con la lista (`conflict`); el editor pregunta si **guardar lo suyo** (`force`) o
**recargar la página**. Lectura, comprobación y escritura van bajo un bloqueo (`data/save.lock`). Un guardado sin `base`
(un editor anterior a la 1.12 abierto durante la actualización) se guarda como antes.

**Imágenes optimizadas al subirlas**: el editor reduce la imagen en el navegador a 2000 px de lado como mucho y la pasa
a WebP (calidad 82; JPG, o PNG si tiene transparencia, en los navegadores que no saben hacer WebP), con la orientación
de la foto ya aplicada y sin los datos EXIF (ubicación incluida). Una foto del móvil de 3–8 MB queda en unos 200–500 KB,
así que el cliente la puede subir tal cual, aunque el hosting limite las subidas a 2 MB (`upload_max_filesize`). Los GIF
se suben sin tocar (pueden ser animados). Si llega una imagen grande sin pasar por ahí (un navegador antiguo),
`admin/upload.php` hace lo mismo con GD, si hay memoria; si no, la guarda tal cual. El lado máximo es
`NEUTRON_UPLOAD_MAX_SIDE` en `admin/upload.php` y `MAX_SIDE` en `assets/editor.js`.

## Panel (`/admin/`)

El cliente entra en `/admin/` con la misma contraseña que el editor (la sesión es la misma) y ve:

- **Inicio**: nombre de la web, páginas, idiomas y elementos de cada colección; las **visitas** de los últimos 30 días
  (por día, y las páginas más vistas; ver «Visitas» más abajo); los **últimos cambios** (de `data/edits.json`, con la
  página y la sección de cada campo) y los **avisos de AFI**: las campañas que envía Cosmo (*Campañas*) con la
  acción remota `announcements-set`. Se guardan en `data/announcements.json`
  (`{"items": [{"id", "title", "text", "url", "button", "until": "AAAA-MM-DD"}]}`; los caducados no salen, el enlace
  solo puede ser http(s) y sin enlace no hay botón). Cada aviso cuenta sus vistas (una por sesión del panel) y los
  clics del botón en `data/announcements-stats.json`, que Cosmo lee al enviar (`lib/announcements.php`).
- **Contenido**: todo lo editable de la web en formularios, por página y sección, en cada idioma. «Común a todas las
  páginas» son los parciales (`site/partials/`: cabecera, pie…). Textos (una línea o área de texto según la longitud),
  texto con formato (`data-edit-html`, con barra), imágenes (se optimizan en el navegador antes de subirlas, como en el
  editor), enlaces editables, la descripción para Google (`data-edit-content`) y listas con sus sublistas (añadir,
  quitar y mover). En otro idioma, lo que aún no está traducido sale marcado y con el texto del idioma principal.
  «Ver en la web» abre esa página en modo edición, en la sección (si tiene `id`).
- **Mensajes**: los del formulario de contacto, guardados en `data/messages/` (uno por fichero, sin la IP) antes de
  enviar el correo, así que no se pierden si el correo falla o el destinatario está mal configurado: en ese caso el
  visitante recibe «enviado», el log anota el error y el mensaje sale marcado en el panel. Lista con los no leídos (y su
  número en el menú y en el inicio), detalle con **Responder** (correo al remitente), marcar como no leído y borrar. Se
  borran solos a los `contact.keep_days` días (365; 0 = no se guardan). La política de privacidad del sitio debe decir
  que los mensajes se conservan ese tiempo.
- **Copias**: antes de cada guardado (en la web, en el panel o una traducción con IA) se copia cómo estaban los ficheros
  de textos que cambian, con los campos afectados (`data/history/`, las últimas 300; `lib/history.php`). Por cada copia
  se ve el antes y el ahora de cada campo, con **Recuperar lo de antes** (un campo) y **Volver a esta versión** (todos
  los de esa copia; deja a su vez una copia de lo que había, así que se puede deshacer). Los **elementos de las
  colecciones** (entradas, documentos, álbumes…) y sus categorías también: antes de guardarlos o borrarlos se copia su
  fichero (`data/history/<id>/item/<colección>/`), y desde la copia se ve qué ha cambiado y se vuelve a como estaba
  (también un elemento borrado). Ordenar con las flechas no deja copias. Las copias completas de la web las hace Cosmo.
- **Menú** (si la plantilla marca su menú con `data-menu`): los enlaces del menú en una lista para añadir, quitar,
  ordenar, ocultar o marcar como botón, con su texto en cada idioma; ver «Menú editable» más abajo.
- **SEO** (si está configurado `pulsar`): análisis **SEO** (Google) y **GEO** (asistentes de IA) de cualquier página con
  la API de Pulsar: nota, puntuación por categoría y lo que se puede mejorar, con la solución (los textos son de Pulsar,
  en catalán). Los problemas del título y de la descripción tienen **Corregir**, que abre ese campo en *Contenido*. Se
  guarda el último informe de cada página y tipo y el historial en `data/seo/` (`lib/seo.php`). Límite de análisis al
  día para toda la web (`pulsar.daily_limit`, SEO y GEO juntos); solo cuentan los que Pulsar llega a hacer, y no puede
  haber dos a la vez. En una web de varios idiomas se analiza la página en el idioma principal.
- **IA** (si está activada `ai`): botón **✨ IA** en cada texto de *Contenido* para **mejorarlo**, **corregir la
  ortografía** o **traducirlo a los otros idiomas** de la web, y en el título y la descripción para Google, **escribirlos a
  partir del texto de la página**. La IA solo propone: la propuesta sale debajo del campo, se puede retocar y se usa o se
  descarta (las traducciones se guardan en cada idioma al pulsar «Guardar las traducciones»). `lib/ai.php` y
  `admin/ai.php`; ver «IA» en Configuración.
- **Editar en la web**: la edición sobre la propia página (`?admin`), que tiene un botón **Panel** para volver.

No hay que preparar nada en cada web: `lib/schema.php` saca los campos de las plantillas (sin DOMDocument). Las
secciones son los `<section>`, `<header>`, `<footer>`, `<nav>`, `<aside>`, `<article>` y `<form>` con campos; un
bloque que contiene `<section>` (un `<main>`) se recorre por dentro. El nombre de cada sección es su primer título
(h1–h3), si no su `id`, si no el principio de su primer texto. Lo que está fuera de cualquier sección (el `<title>`, la
descripción) va en «Datos de la página». Una clave que sale en varios sitios (el teléfono en la cabecera y en el pie)
se edita una sola vez, en el primero. El botón de añadir de una lista usa su `data-edit-add`.

El panel lee con `admin/api.php` (`?a=home`, `?a=content&lang=…`, `?a=seo`, `?a=messages`, `?a=history`, `?a=menu`…; ver la cabecera del fichero) y guarda con `admin/save.php`, igual que el editor:
solo lo cambiado y con los mismos avisos si otra ventana ha cambiado lo mismo.

### Menú editable

Si un elemento de la plantilla (o de un parcial) tiene `data-menu` —el `<nav>` de la cabecera, el del pie—, sus enlaces
(los `<a>` que son hijos directos) se pueden cambiar desde *Panel → Menú*. Mientras no se guarde, el menú es el de la
plantilla, tal cual; al guardar se crea `site/menu.json`, y a partir de entonces los enlaces de **todos** los `data-menu`
salen de ahí (en los dos motores, antes de marcar la página actual y de poner el idioma a los enlaces):

```json
{"items": [{"href": "la-festa.html", "label": {"ca": "La Festa", "es": "La Fiesta"}, "button": false, "hidden": false},
           {"href": "https://botiga.example.com", "label": {"ca": "Botiga"}, "button": true, "hidden": false}]}
```

- Cada enlace se hace copiando el primer enlace del elemento (clases y atributos, sin su `data-edit`); los marcados como
  **botón**, el primero con la clase `btn` (si no hay, como los demás). Van donde estaba el primer enlace; lo demás que
  haya dentro (el selector de idioma) se queda igual.
- Los internos se escriben sin idioma (`la-festa.html`, `noticies/`); los externos (`https://…`) se abren en otra
  pestaña. Solo `http(s)`, `mailto:`, `tel:` y páginas de la web. El texto, en el idioma que se ve, o en el principal.
- **Sin submenús**: si el menú tiene un desplegable (un `<div>` con más enlaces dentro), no se le pone `data-menu`.
- «Volver al menú de la plantilla» borra `site/menu.json`. Los bloques `header` y `footer` ya llevan `data-menu`.

### Visitas

Sin cookies ni servicios externos (no piden consentimiento): cada página que sirve Neutron con un 200 a una persona (no
a robots, al editor con sesión ni a las precargas del navegador) es una línea en `data/stats/AAAA-MM-DD.log` con la
página y una huella del visitante de ese día (`sha1` de una sal al azar del día + IP + navegador; la IP no se guarda y
la sal se borra al terminar el día, así que no se puede seguir a nadie de un día a otro). Al día siguiente se resume en
`data/stats/days.json` (vistas, visitantes, páginas más vistas; 400 días) y el registro se borra. Cuenta también las
páginas servidas desde la caché. Config `stats.enabled` (true). Son cifras orientativas: no ven las visitas que se
quedan en la caché de un proxy ni distinguen dos personas detrás de la misma IP con el mismo navegador.

### Idioma del panel

El panel y la barra del editor están en castellano o en catalán: en el idioma principal de la web si es uno de los dos
(si no, en castellano), o el que diga `panel.lang` (`'es'` o `'ca'`). Los textos se escriben en castellano en
`assets/panel.js` y `assets/editor.js`, y `assets/i18n/ca.json` los traduce (`lib/panel_lang.php`): la clave es el texto
en castellano y `{}` vale para las partes que cambian (`"hace {} min": "fa {} min"`). Al añadir un texto al panel, se
añade también su traducción. Los textos del contenido (páginas, colecciones) son los del sitio y no se traducen.

### Fichas de cualquier colección

Cualquier colección puede tener una página por elemento con `site/<colección>/_item.html` (los mismos `data-post*` que en
un listado). `tools/compose.py` la genera a partir de los campos si el bloque la pide (`"item_page": true` en su
`collection`, como `news` y `catalog`) o la receta (`{"block": "documents", "item_page": true}`): categoría y fecha,
título, entradilla (el primer `textarea`), imagen, los demás datos en una lista (los vacíos no se ven), el texto con
formato, la galería con visor, el botón de descarga, el vídeo con consentimiento y el enlace. La descripción y la imagen
para buscadores y redes salen de `summary` e `image` o, si la colección no los tiene, de su primer texto y su primera
imagen. Bloque `catalog`: catálogo sin venta (productos, inmuebles, proyectos) con rejilla y ficha.

### Buscador

Opcional: solo en las páginas con un formulario `data-search` (en una plantilla o un parcial, como la cabecera):

```html
<form data-search role="search" data-search-empty="No hi ha res per «{q}»">
  <input type="search" name="q" placeholder="Cerca…" aria-label="Cerca">
  <div data-search-results hidden></div>
</form>
```

El índice es `/search.json` (`/ca/search.json`… en varios idiomas; en caché como las páginas): los textos de cada página
(sus campos editables) y los elementos publicados de las colecciones, cada uno con su enlace (la ficha, la descarga del
documento, el vídeo o el enlace, o la página de su listado). `assets/search.js` lo baja al empezar a escribir y busca en
el navegador, sin acentos ni mayúsculas, con todas las palabras y más peso en el título; con `?q=` busca al abrir.
`site/search.json` (opcional): `{"pages": true, "collections": ["noticies"] | "all", "exclude": ["avis-legal"]}`; por
defecto, todas las páginas menos las legales y la 404, y todas las colecciones. Bloque `search` para una página «Cerca».

## Velocidad: caché de páginas e imágenes para el móvil

**Caché de páginas** (`lib/cache.php`, config `cache`): la primera visita a una página la monta y la guarda en
`data/cache/`; las siguientes la sirven tal cual (cabecera `X-Neutron-Cache: HIT`). No hay que vaciarla: cada página
guarda la «huella» del sitio (fechas de plantillas, parciales, contenidos, elementos de colección, carpetas,
`config.local.php` y `VERSION`) y, si algo ha cambiado (desde el panel, el editor, por FTP o al actualizar), se vuelve a
montar. Además caduca a los `cache.ttl` segundos (3600), por lo que cambia solo (el número de descargas). Solo para
visitantes: no con `?admin` ni con la sesión del editor, ni con más parámetros que `?p=`; solo respuestas 200. Las
copias de seguridad no la incluyen. `'cache' => ['enabled' => false]` la apaga.

**Imágenes para el móvil** (`lib/images.php`): de cada imagen de `site/uploads/` y `site/assets/img/` más ancha de 640 px
se guardan versiones de 640, 1024 y 1600 px al lado («foto.w640.webp»), y los dos motores ponen en sus `<img>` un
`srcset` con las que haya (y `sizes="100vw"` si la plantilla no dice otro): el navegador se baja la que necesita. En el
editor no, para que al cambiar una foto se vea la nueva. Se hacen al subir una imagen, en la migración de cada
actualización desde Cosmo (unos segundos cada vez) y con `php bin/images.php`. Sin GD no se hace nada.

## Configuración

`config.php` tiene los valores por defecto comentados; `config.local.php` devuelve solo lo que cambia:

```php
return [
    'name' => 'Assaice',                                   // nombre en Cosmo (vacío = dominio)
    'admin_password_hash' => '$2y$10$…',
    'recaptcha' => ['site_key' => '…', 'secret_key' => '…', 'badge' => 'inline'],
    'mail' => ['to' => '', 'from' => ''],
    'remote' => ['token' => '…', 'allow_ips' => ['IP de Cosmo'], 'backups_keep' => 5],
    'log' => ['level' => 'info', 'keep_days' => 30],
    'panel' => ['lang' => null],                           // idioma del panel: 'es', 'ca' o null (el de la web)
    'stats' => ['enabled' => true],                        // visitas del inicio del panel
];
```

**Pulsar** (SEO del panel): en `config.local.php`, la dirección de Pulsar y un token que Pulsar acepte (sus
`api_tokens`, en el `config.local.php` de Pulsar; puede ser el mismo para todas las webs):

```php
'pulsar' => ['url' => 'https://pulsar.example.com', 'token' => '…', 'daily_limit' => 3],
```

`tools/new-site.py` lo pone solo si existe `~/.config/neutron/pulsar.json` (`{"url": "…", "token": "…"}`), y
`tools/check.py` avisa si falta.

**IA** (textos del panel): API de Anthropic, con las mismas claves que la IA de Quasar para que Cosmo muestre el gasto
del mes, el límite y los avisos igual. Una clave por cliente (un espacio de trabajo en la consola de Anthropic, con su
propio límite de gasto) sirve para controlar y facturar el consumo de cada web:

```php
'ai' => ['enabled' => true, 'api_key' => 'sk-ant-…', 'monthly_limit_eur' => 10],
// opcional: 'model' (claude-opus-5-5 por defecto; claude-haiku-5-5 es mucho más barato), 'effort' (low), 'eur_per_usd',
// 'prices' (dólares por millón de tokens de cada modelo), 'base_url' (un proxy), 'timeout'
```

Cada consulta va a `/v1/messages` con la respuesta en JSON con esquema fijo (`output_config.format`) y
`fallbacks: "default"` (si un filtro de seguridad la rechaza, la repite el modelo que recomienda Anthropic). El consumo
del mes (consultas, errores, tokens y coste en dólares) está en `data/ai/usage.json`; al llegar a `monthly_limit_eur`
la IA se para hasta el mes siguiente. El estado remoto (`status` → `ai`) da `month_cost_eur`, `monthly_limit_eur`,
`limit_state` (`ok`, `warn` desde `warn_at`, `blocked`), `queries`, `errors` y tokens.

## Log

`data/logs/neutron-AAAA-MM-DD.log`, con el mismo formato que Quasar:

```
[2026-10-23 11:30:12.123] WARNING [req:3fa9c1 ip:10.0.0.4] Formulario rechazado por reCAPTCHA: timeout-or-duplicate
```

Se registran accesos al editor (correctos, fallidos y bloqueados), guardados, subidas, envíos del formulario y sus
rechazos, acciones remotas, copias, actualizaciones y warnings/errores de PHP. Las claves que parecen secretas se
escriben como `***`. Se borran los ficheros de más de `log.keep_days` días.

## API remota (panel Cosmo)

Mismas acciones y respuestas que la de Quasar, así Cosmo trata las dos igual. Desactivada mientras no haya
token; actívala con `php bin/install.php --token` o en `config.local.php`:

```php
'remote' => ['token' => '…64 caracteres…', 'allow_ips' => ['203.0.113.10']],
```

Las llamadas llevan la cabecera `X-Quasar-Token` (la que envía Cosmo), `X-Neutron-Token` o
`Authorization: Bearer …`. Sin token o con uno incorrecto responden `404` y dejan un aviso en el log. No abren
sesión ni envían cookies. Úsala solo por HTTPS.

| Acción `index.php?r=remote&a=` | Método | Qué hace |
|---|---|---|
| `status` (también `?r=status`) | GET | `app: "neutron"`, versión, PHP, disco, extensiones, sitio (con `favicon`, `cookies`, `posts` y `languages`), reCAPTCHA, errores del log en 24 h, última copia, integridad, mantenimiento |
| `logs&level=warning&limit=100&days=7` | GET | Últimas entradas del log |
| `integrity` | GET | Ficheros del núcleo cambiados a mano e historial de actualizaciones |
| `backups` | GET | Copias de `data/backups` |
| `download&file=` | GET | Descarga una copia (cabecera `X-Backup-Sha256`) |
| `backup` (`type=full\|db\|files`) | POST | Crea una copia (ver abajo) |
| `backup-delete` (`file=`) | POST | Borra una copia |
| `update` (`package`, `sha256`, `version`, `backup`, `force`) | POST | Instala un paquete de versión |
| `migrate` | POST | Prepara las carpetas de datos y quita el mantenimiento (no hay BD) |
| `maintenance` (`on=1\|0`) | POST | Modo mantenimiento: la web, el formulario y el editor responden 503 |
| `announcements` | GET | Avisos de AFI del panel (`items`) y sus vistas y clics (`stats`: `{id: {views, clicks}}`) |
| `announcements-set` (`items` = JSON) | POST | Sustituye los avisos por la lista enviada (`[]` los quita); responde como `announcements` |

```bash
curl -H "X-Quasar-Token: el-token" "https://cliente.example.com/index.php?r=remote&a=status"
curl -H "X-Quasar-Token: el-token" -d type=full "https://cliente.example.com/index.php?r=remote&a=backup"
```

**Copias** (ZIP con `backup.json` y `files/…`): `full` y `files` son la instalación entera (núcleo, `site/`,
`config.local.php` y `data/` salvo log y copias); `db` es solo `site/` (el «contenido»: Neutron no tiene base de
datos y el tipo se llama así para que Cosmo lo use igual). Se conservan `remote.backups_keep`. Copias y
actualizaciones no se solapan: si ya hay una en marcha, la siguiente responde `409`.

### Cómo se actualiza una instalación

Cosmo descarga de GitHub el ZIP de la etiqueta `vX.Y.Z` y lo sube a la instalación, que:

1. Hace una copia completa (si `backup=1`) y se pone en mantenimiento.
2. Comprueba el sha256, las rutas del ZIP, su `VERSION` y que es un paquete de Neutron.
3. Si algún fichero del núcleo se cambió a mano desde la última actualización, se detiene (salvo `force=1`).
4. Copia **solo los ficheros del núcleo** que lista el `core.json` del paquete y borra los que la versión anterior
   instaló y la nueva ya no trae. `site/`, `config.local.php` y `data/` no se tocan nunca, aunque el paquete los traiga.
   Del `.htaccess` solo se sustituye el bloque `# BEGIN Neutron` … `# END Neutron`.
5. Se llama a sí misma (`a=status`) con el código nuevo: si no arranca, deshace todos los cambios.
6. Cosmo pide `migrate` y la web sale del mantenimiento.

**Desde la 1.0.0 o la 1.1.0**: esas versiones no conocían el bloque y su actualizador copia el `.htaccess` entero.
Por eso, al pasar a la 1.2.0, `migrate` (que ya corre con el código nuevo) busca la copia completa previa a la
actualización, saca del `.htaccess` antiguo las líneas que no eran de Neutron y las vuelve a poner encima del
bloque (paso «.htaccess: N líneas propias recuperadas»). Si la actualización se hizo sin copia previa (`backup=0`),
lo avisa y hay que revisarlo a mano.

El sha1 de cada fichero instalado queda en `data/remote/manifest.json` (integridad y borrados). La primera
instalación de la 1.0.0 se hace a mano (copiar el núcleo); a partir de ahí, desde Cosmo.

> La autoprueba necesita que el servidor atienda dos peticiones a la vez. Con `php -S` usa
> `PHP_CLI_SERVER_WORKERS=4`; si no, se da por «no alcanzable» y la actualización sigue sin ella.

## Herramientas para hacer webs (no son del núcleo)

En `tools/` y `docs/`, para el repo de Neutron (no llegan a las instalaciones). Python 3 con PIL, PHP y, para lo que usa
el navegador, Node 22 y Chromium.

| Comando | Qué hace |
|---|---|
| `python3 tools/new-site.py <cliente> --nom "…" [--idiomes ca,es,en] [--migrar URL]` | Crea `~/Projects/neutron-<cliente>`: núcleo de la última etiqueta, `site/` básico (carpetas, `languages.json`, `cookies.json` en cada idioma), `config.local.php` con contraseña aleatoria y token de Cosmo, y la contraseña en `credencials.local.md`. |
| `python3 tools/migrate.py URL <instalación> [--render] [--max 150]` | Rastrea la web antigua y deja en `_migracio/` el contenido por páginas e idiomas (`contenido.json`, sin el menú ni el pie), una propuesta de `redirects.json` y un `informe.md` (CMS, idiomas, menú, contacto, redes, terceros → cookies, formularios, tipografías, colores, logo, blog). Descarga las imágenes en WebP (2000 px como mucho) a `site/assets/img/` y hace el favicon. `--render` usa Chromium para las webs que montan el contenido con JavaScript. **Colecciones** (`tools/migrate_collections.py`): noticias (el blog de Joomla y los artículos), documentos (los descarga, con categoría, fecha y descargas), galería por álbumes y vídeos de YouTube o Vimeo, directamente en `site/<colección>/`, y el cuerpo limpio de cada página (`body_html`) para el bloque `richtext`. |
| `python3 tools/check.py <instalación> [--guia --nom "…" --url …]` | Antes de entregar: núcleo sin tocar, contraseña, token, reCAPTCHA, favicon, cookies, páginas legales, textos `[PENDENT]`, imágenes, traducciones y, sobre una copia servida con `php -S`, todas las páginas (título, descripción, h1), enlaces internos, redirecciones, URLs de la web antigua, terceros sin consentimiento y los dos motores. Con `--guia`, si no hay errores, genera la guía. |
| `python3 tools/regresion.py [webs…]` | Antes de publicar una versión: cada web local (las instalaciones de `~/Projects`) con su núcleo y con el del repositorio, y los dos motores de render, comparando el HTML de su sitemap. Las webs no se tocan (copias temporales). Sale con 1 si hay diferencias. |
| `python3 tools/compose.py <instalación>` | Monta el `site/` con los **bloques** de `blocks/` según la receta `site/compose.json`: plantilla, páginas, parciales, tema (colores y tipografías, que se alojan en la web), CSS y JS, cookies y formulario en cada idioma, y los textos por defecto que falten en `content*.json` (sin pisar los que hay). No reescribe una plantilla tocada a mano. Ver `blocks/README.md`. |
| `python3 tools/legal.py <instalación> [--nif NIF]` | Aviso legal, privacidad y cookies en todos los idiomas: privacidad y cookies con Pulsar (`~/Projects/pulsar`), según lo que tiene la web (formulario, mapas, vídeos, redes). Datos del titular en `site/legal.json`: los fiscales (nombre, NIF, domicilio) de la ficha del cliente en Asteroid, buscado por el NIF (token del MCP de Asteroid en `~/.config/asteroid-mcp/token`; `--sin-asteroid` para no consultarlo), y el resto de la web antigua si `migrate.py` los encontró; lo que falte sale como «[PENDENT: …]». Respeta los textos editados a mano. |
| `python3 docs/guia-edicio/generar.py <instalación> --nom "…" --url …` | Guía de edición en PDF (catalán) con capturas de la web del cliente, en su carpeta (`guia-edicio-AAAA-MM-DD.pdf`). |
| `node tools/cdp.mjs <url> <pasos.mjs>` | Chromium sin ventana por el protocolo de DevTools, para probar el editor y hacer capturas. |

## Versiones y publicación

Semver en `VERSION`, con su entrada en `CHANGELOG.md`. Cada versión se etiqueta en git como `vX.Y.Z`:

```bash
# 1. VERSION y CHANGELOG.md   2. core.json si hay ficheros o carpetas nuevos del núcleo
git commit -am "Neutron 1.1.0: …"
git tag -a v1.1.0 -m "Neutron 1.1.0"
git push && git push --tags
```

Lo que no esté en `core.json` no llega a las instalaciones al actualizar.

## Seguridad

- Contraseña del editor con `password_hash`, CSRF en todas las acciones del editor, límite de intentos por IP.
- Solo texto plano en el contenido (el cuerpo de las entradas, con lista blanca); los enlaces editables solo admiten
  `http(s)`, `mailto:`, `tel:` y rutas de la web, y se comprueban al pintar; subidas validadas por tipo real (`finfo`), sin SVG.
- `lib/`, `data/`, `bin/`, la configuración, `site/*.json`, la plantilla, `site/pages/` y `site/partials/` no se
  sirven directamente.
- API remota: token de 32+ caracteres comparado en tiempo constante, IP opcional, 404 si falla.
