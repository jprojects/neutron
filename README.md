# Neutron

CMS mínimo sin base de datos para webs de una página creadas con IA. El diseño es un `template.html` normal
con unos atributos `data-edit*`; el contenido vive en `content.json` y se edita en la propia página
(`?admin`). PHP 8.1+, sin dependencias (ni Composer ni npm).

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
  pages/*.html       otras páginas: site/pages/avis-legal.html → /avis-legal.html
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
JSON y la plantilla de `site/`, y sirve las páginas de `site/pages/`). PHP necesita escribir en `site/` (editor),
`data/` (log, copias) y, para las actualizaciones desde Cosmo, en el núcleo.

Con nginx, el equivalente:

```nginx
location ~ ^/(lib|data|bin|examples|\.git)(/|$) { deny all; }
location ~ ^/(config(\.local)?\.php|core\.json|VERSION|CHANGELOG\.md|README\.md|CLAUDE\.md|router\.php)$ { deny all; }
location ~ ^/site/(template\.html|[^/]+\.json|pages/) { deny all; }
location ~ ^/([a-z0-9][a-z0-9-]*)\.html$ { try_files $uri /index.php?page=$1&$args; }
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

El valor que hay en la plantilla solo se ve si `content.json` no tiene esa clave. No se pueden editar enlaces
(`href`) ni anidar listas.

Rutas: la página se sirve desde la raíz, así que los recursos del sitio se enlazan como `site/assets/…`.
Las páginas de `site/pages/` se abren como `/nombre.html` (o `index.php?page=nombre` si no hay `mod_rewrite`) y
también pasan por el render, así que pueden usar las mismas claves de `content.json`.

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
5. Se llama a sí misma (`a=status`) con el código nuevo: si no arranca, deshace todos los cambios.
6. Cosmo pide `migrate` y la web sale del mantenimiento.

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
- `lib/`, `data/`, `bin/`, la configuración, `site/*.json`, la plantilla y `site/pages/` no se sirven directamente.
- API remota: token de 32+ caracteres comparado en tiempo constante, IP opcional, 404 si falla.
