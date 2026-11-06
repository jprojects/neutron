# Bloques reutilizables

Piezas de web ya probadas (HTML + CSS + textos en ca/es/en) para montar el `site/` de una web nueva con
`tools/compose.py`. No son del núcleo: se **copian** al `site/` de cada web, así que cambiar un bloque aquí no toca las
webs ya entregadas. El diseño propio de cada web va encima (tema + `site/assets/custom.css`).

## Receta de una web: `site/compose.json`

```json
{
  "name": "Pep Gallifa",
  "langs": ["ca", "es", "en"],
  "theme": { "primary": "#8a6c3e", "dark": "#161514", "soft": "#f7f3ee", "font_head": "Cormorant Garamond", "font_body": "Raleway" },
  "social": ["instagram", "facebook", "youtube"],
  "pages": [
    { "file": "template", "nav": {"ca": "Inici", "es": "Inicio", "en": "Home"},
      "blocks": ["hero", "gallery", {"block": "text-image", "prefix": "about"}, "cards", "cta"] },
    { "file": "contacte", "nav": {"ca": "Contacte", "es": "Contacto", "en": "Contact"}, "blocks": ["page-head", "contact", "form"] }
  ],
  "legal": true
}
```

- `file`: `template` es la portada; las demás van a `site/pages/<file>.html` (mismo nombre en todos los idiomas).
- Cada bloque: un nombre o `{"block": …, "variant": …, "prefix": …}`. El prefijo (por defecto `<página>_<bloque>`) va
  delante de sus claves de contenido, así el mismo bloque puede salir varias veces.
- `theme`: lo que cambia el aspecto (ver `_theme/theme.css`). Las tipografías se descargan de Google Fonts y se alojan
  en la web.
- `legal`: aviso legal, privacidad y cookies (con Pulsar, ver `tools/legal.py`).

`compose.py` escribe la plantilla, las páginas, los parciales, `assets/site.css` (tema + bloques), `assets/site.js` y los
textos por defecto en `content.<idioma>.json` **sin pisar** los que ya haya (los de la migración o los editados). Si una
plantilla generada se ha tocado a mano, no la vuelve a escribir (salvo `--force`).

## Formato de un bloque: `blocks/<nombre>/`

| Fichero | Qué es |
|---|---|
| `block.html` | El HTML. `{p}` es el prefijo de la instancia. `{{clave}}` es el texto por defecto (escapado) en el idioma principal. |
| `content.ca.json`, `.es`, `.en` | Textos por defecto. `clave` → `{p}_clave`; `@clave` → `clave` (global: teléfono, correo, menú…). |
| `block.css` | Estilos, **solo con variables del tema** (`var(--c-primary)`…), con la clase `.b-<nombre>` delante. |
| `block.js` | Opcional. Se junta en `site/assets/site.js`. |
| `block.json` | `name`, `description`, `variants` (la primera es la de por defecto), `images` (claves que son imágenes: van a `content.json`, comunes a los idiomas), `cookies` (categorías que necesita: `maps`, `videos`…), `form` (si lleva el formulario). |

Marcas especiales en `block.html`:

- `<!--variant:x-->…<!--/variant:x-->`: solo se queda la variante elegida.
- `{{#nav}}`: enlaces del menú (de las páginas de la receta); `{{#langs}}`: selector de idioma (si hay más de uno);
  `{{#social}}`: iconos de las redes de la receta, con `data-edit-href="social_<red>"` (vacías si aún no hay dirección).
- `{{img:clave}}`: la imagen por defecto de esa clave (una imagen neutra si no hay ninguna).

Reglas: todo lo que ve el visitante es editable (`data-edit*`) salvo los enlaces; nada de JS de formularios ni de
cookies propio (es del núcleo); los bloques se prueban con los dos motores, en el editor y en el móvil
(`tools/check.py` sobre una web de prueba compuesta con todos).
