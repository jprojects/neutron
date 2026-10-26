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
  content.json       el contenido editable
  contact.json       campos y textos del formulario de contacto (opcional)
  redirects.json     URLs de la web anterior → páginas nuevas (opcional)
  pages/*.html       otras páginas: site/pages/carta.html → /carta.html (404.html, la de error)
  partials/*.html    trozos compartidos (cabecera, pie): <div data-include="header"></div>
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
location ~ ^/(lib|data|bin|examples|\.git)(/|$) { deny all; }
location ~ ^/(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php)$ { deny all; }
location ~ ^/site/(template\.html|[^/]+\.json|pages/|partials/) { deny all; }
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

El valor que hay en la plantilla solo se ve si `content.json` no tiene esa clave (también en las listas: sin la
clave se ve el molde tal cual). No se pueden editar enlaces (`href`).

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
La sesión solo arranca con `?admin`: los visitantes no reciben la cookie `neutron_admin_sid`.
5 intentos fallidos por IP bloquean 15 minutos. Imágenes JPG, PNG, GIF o WebP de hasta 5 MB (SVG no, por XSS).

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
| `status` (también `?r=status`) | GET | `app: "neutron"`, versión, PHP, disco, extensiones, sitio, reCAPTCHA, errores del log en 24 h, última copia, integridad, mantenimiento |
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
- Solo texto plano en el contenido; subidas validadas por tipo real (`finfo`), sin SVG.
- `lib/`, `data/`, `bin/`, la configuración, `site/*.json`, la plantilla, `site/pages/` y `site/partials/` no se
  sirven directamente.
- API remota: token de 32+ caracteres comparado en tiempo constante, IP opcional, 404 si falla.
