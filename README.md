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

### Blog (colecciones con página propia)

Para un blog, noticias o cualquier lista de artículos con su propia página. Una colección es una carpeta del sitio
con la plantilla de la entrada; cada entrada es un JSON:

```
site/blog/_item.html          página de una entrada            → /blog/mi-entrada
site/blog/mi-entrada.json     título, fecha, imagen, resumen, cuerpo (HTML limpio) y estado (draft | published)
site/pages/blog.html          el listado                        → /blog/  (/blog.html redirige aquí)
```

- **Rutas**: `/blog/` (listado; `?p=2` para la página 2), `/blog/mi-entrada`, `/blog/feed.xml` (RSS de las 20
  últimas) y, para todo el sitio, `/sitemap.xml` (portada, páginas y entradas publicadas). Las páginas de una
  colección llevan `<base>` para que las rutas relativas (`site/assets/…`, `blog/…`, `./#contacte`) sigan valiendo.
- **En la plantilla de la entrada**: `data-post="title"`, `data-post="summary"`, `data-post="date"` (la fecha en el
  idioma de `<html lang>`, catalán, castellano, francés o inglés: «2 de novembre de 2026»; en un `<time>`, también `datetime`), `data-post-img="image"` y
  `data-post-html="body"`. En `<title data-post="title">Entrada · Mi web</title>` se conserva lo de después de ` · `.
  El núcleo añade la descripción, la URL canónica y las etiquetas `og:` de la entrada.
- **Listados** (en cualquier página, también en la portada o en la propia entrada):

```html
<div data-posts="blog" data-posts-per-page="9">        <!-- o data-posts-limit="3" -->
  <article data-post-item>                              <!-- molde de cada entrada -->
    <a data-post-link href="#"><img data-post-img="image" src="site/assets/img/ejemplo.jpg" alt=""></a>
    <time data-post="date"></time>
    <h2><a data-post-link href="#" data-post="title">…</a></h2>
    <p data-post="summary"></p>
  </article>
</div>
<p data-posts-empty="blog">Aún no hay entradas.</p>   <!-- solo si no hay ninguna -->
<a data-posts-prev="blog" href="#">← Más nuevas</a> <a data-posts-next="blog" href="#">Anteriores →</a>
```

  De la más nueva a la más antigua (por fecha). En la página de una entrada, sus listados no la repiten. Sin imagen,
  el `<img data-post-img>` se quita (en el editor se deja el de la plantilla para poder poner una).
- **Editor**: en las páginas con un listado, **«Nueva entrada»** pide el título, crea un borrador
  (`/blog/titulo-de-la-entrada`) y lo abre. En la entrada se editan en la página el título, el resumen y la imagen,
  y el cuerpo con una barra de formato (negrita, cursiva, subtítulos, listas, cita, enlace e imagen); en la barra
  del editor, la **fecha**, **Publicar / Pasar a borrador** y **Borrar entrada** (va a `data/deleted/`, se puede
  recuperar a mano). Los borradores solo los ve quien ha entrado al editor; en los listados llevan la etiqueta
  «Borrador».
- **Texto con formato seguro**: el cuerpo se limpia en el servidor con una lista blanca (`lib/sanitize.php`) al
  guardar y al pintar: `p`, `h2`, `h3`, `strong`, `em`, `a` (http, https, mailto, tel o rutas relativas), `ul`,
  `ol`, `li`, `blockquote`, `br` e `img` solo de `site/uploads/` o `site/assets/`. Ningún script, estilo, `on…`,
  iframe ni enlace `javascript:`. Lo que se pega (Word, otra web) se limpia ya en el navegador.
- La dirección de la entrada sale del título al crearla y no cambia aunque se edite el título (los enlaces que ya
  circulan siguen funcionando).

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

**Imágenes optimizadas al subirlas**: el editor reduce la imagen en el navegador a 2000 px de lado como mucho y la pasa
a WebP (calidad 82; JPG, o PNG si tiene transparencia, en los navegadores que no saben hacer WebP), con la orientación
de la foto ya aplicada y sin los datos EXIF (ubicación incluida). Una foto del móvil de 3–8 MB queda en unos 200–500 KB,
así que el cliente la puede subir tal cual, aunque el hosting limite las subidas a 2 MB (`upload_max_filesize`). Los GIF
se suben sin tocar (pueden ser animados). Si llega una imagen grande sin pasar por ahí (un navegador antiguo),
`admin/upload.php` hace lo mismo con GD, si hay memoria; si no, la guarda tal cual. El lado máximo es
`NEUTRON_UPLOAD_MAX_SIDE` en `admin/upload.php` y `MAX_SIDE` en `assets/editor.js`.

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
];
```

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
| `python3 tools/migrate.py URL <instalación> [--render] [--max 150]` | Rastrea la web antigua y deja en `_migracio/` el contenido por páginas e idiomas (`contenido.json`, sin el menú ni el pie), una propuesta de `redirects.json` y un `informe.md` (CMS, idiomas, menú, contacto, redes, terceros → cookies, formularios, tipografías, colores, logo, blog). Descarga las imágenes en WebP (2000 px como mucho) a `site/assets/img/` y hace el favicon. `--render` usa Chromium para las webs que montan el contenido con JavaScript. |
| `python3 tools/check.py <instalación> [--guia --nom "…" --url …]` | Antes de entregar: núcleo sin tocar, contraseña, token, reCAPTCHA, favicon, cookies, páginas legales, textos `[PENDENT]`, imágenes, traducciones y, sobre una copia servida con `php -S`, todas las páginas (título, descripción, h1), enlaces internos, redirecciones, URLs de la web antigua, terceros sin consentimiento y los dos motores. Con `--guia`, si no hay errores, genera la guía. |
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
