#!/usr/bin/env python3
# Monta el site/ de una web con los bloques de blocks/ a partir de su receta (site/compose.json):
#
#   python3 tools/compose.py ~/Projects/neutron-cliente [--force]
#
# Escribe la plantilla, las páginas, los parciales (head, cabecera, pie), assets/site.css (tema + bloques), assets/site.js,
# las tipografías (descargadas de Google Fonts), cookies.json y contact.json en cada idioma, y añade a content*.json los
# textos por defecto que falten, sin pisar los que ya hay. Una plantilla generada que se ha tocado a mano no se vuelve a
# escribir (salvo --force): el estado va en site/.compose.json. Formato de la receta y de los bloques: blocks/README.md.
import argparse, hashlib, html, json, re, sys, urllib.parse, urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
BLOCKS = REPO / 'blocks'
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
NOMS = {'ca': 'Català', 'es': 'Español', 'en': 'English', 'fr': 'Français', 'de': 'Deutsch', 'it': 'Italiano', 'pt': 'Português'}
ICON = {'instagram': ('instagram', 'Instagram'), 'facebook': ('facebook-f', 'Facebook'), 'x': ('x-twitter', 'X'), 'twitter': ('x-twitter', 'X'),
        'youtube': ('youtube', 'YouTube'), 'tiktok': ('tiktok', 'TikTok'), 'linkedin': ('linkedin-in', 'LinkedIn'), 'pinterest': ('pinterest-p', 'Pinterest'),
        'whatsapp': ('whatsapp', 'WhatsApp')}
LEGAL = [('avis-legal', 'legal_notice'), ('politica-de-privacitat', 'legal_privacy'), ('politica-de-cookies', 'legal_cookies')]
PLACEHOLDER = 'site/assets/img/placeholder.svg'
COOKIE_CATS = {
    'maps': {'ca': ('Mapes (Google Maps)', 'Mostren el mapa de com arribar. Google pot desar cookies quan es carrega.'),
             'es': ('Mapas (Google Maps)', 'Muestran el mapa de cómo llegar. Google puede guardar cookies cuando se carga.'),
             'en': ('Maps (Google Maps)', 'Show the map with directions. Google may store cookies when it loads.')},
    'videos': {'ca': ('Vídeos (YouTube)', 'Permeten veure els vídeos incrustats. YouTube pot desar cookies.'),
               'es': ('Vídeos (YouTube)', 'Permiten ver los vídeos incrustados. YouTube puede guardar cookies.'),
               'en': ('Videos (YouTube)', 'Let you watch embedded videos. YouTube may store cookies.')},
}
FORM_MSGS = {
    'ca': {'sending': 'Enviant...', 'success': 'Gràcies! Hem rebut el teu missatge i et respondrem aviat.', 'invalid': 'Omple els camps obligatoris (*), amb un correu vàlid, i accepta la política de privacitat.',
           'network': 'Error de xarxa. Torna-ho a provar.', 'admin': 'El formulari està desactivat en mode edició.', 'captcha_load': "No s'ha pogut carregar la verificació antispam. Recarrega la pàgina o truca'ns.",
           'required': 'Omple tots els camps obligatoris i accepta la política de privacitat.', 'email': 'El correu electrònic no és vàlid.', 'rate_limited': 'Has enviat massa missatges. Torna-ho a provar més tard.',
           'captcha': "No s'ha pogut verificar que no ets un robot. Torna-ho a provar.", 'not_configured': 'El formulari no està configurat correctament.',
           'send_failed': "No s'ha pogut enviar el missatge. Escriu-nos a {to}.", 'method': 'Mètode no permès.'},
    'es': {'sending': 'Enviando...', 'success': '¡Gracias! Hemos recibido tu mensaje y te responderemos pronto.', 'invalid': 'Rellena los campos obligatorios (*), con un correo válido, y acepta la política de privacidad.',
           'network': 'Error de red. Inténtalo de nuevo.', 'admin': 'El formulario está desactivado en el modo edición.', 'captcha_load': 'No se ha podido cargar la verificación antispam. Recarga la página o llámanos.',
           'required': 'Rellena todos los campos obligatorios y acepta la política de privacidad.', 'email': 'El correo electrónico no es válido.', 'rate_limited': 'Has enviado demasiados mensajes. Inténtalo más tarde.',
           'captcha': 'No se ha podido verificar que no eres un robot. Inténtalo de nuevo.', 'not_configured': 'El formulario no está configurado correctamente.',
           'send_failed': 'No se ha podido enviar el mensaje. Escríbenos a {to}.', 'method': 'Método no permitido.'},
    'en': {'sending': 'Sending...', 'success': 'Thank you! We have received your message and will reply soon.', 'invalid': 'Fill in the required fields (*) with a valid email and accept the privacy policy.',
           'network': 'Network error. Please try again.', 'admin': 'The form is disabled in edit mode.', 'captcha_load': 'The anti-spam check could not be loaded. Reload the page or call us.',
           'required': 'Fill in all the required fields and accept the privacy policy.', 'email': 'The email address is not valid.', 'rate_limited': 'You have sent too many messages. Please try again later.',
           'captcha': 'We could not verify that you are not a robot. Please try again.', 'not_configured': 'The form is not configured correctly.',
           'send_failed': 'The message could not be sent. Write to us at {to}.', 'method': 'Method not allowed.'},
}
NOTFOUND = {'ca': ('Error 404', 'Aquesta pàgina no existeix', 'Potser l’adreça ha canviat.', 'Tornar a la portada'),
            'es': ('Error 404', 'Esta página no existe', 'Quizá la dirección ha cambiado.', 'Volver a la portada'),
            'en': ('Error 404', 'This page does not exist', 'The address may have changed.', 'Back to the home page')}

ap = argparse.ArgumentParser(description='Monta el site/ con los bloques a partir de site/compose.json')
ap.add_argument('web', help='carpeta de la instalación')
ap.add_argument('--force', action='store_true', help='reescribir también las plantillas tocadas a mano')
a = ap.parse_args()
web = Path(a.web).expanduser().resolve()
site = web / 'site'
recipe = json.loads((site / 'compose.json').read_text())
langs = recipe.get('langs') or ['ca']
main = langs[0]
state_file = site / '.compose.json'
state = json.loads(state_file.read_text()) if state_file.exists() else {}
written, skipped = [], []
esc = lambda s: html.escape(str(s), quote=False)
attr = lambda s: html.escape(str(s), quote=True)

def write(rel, text):
    """Escribe un fichero generado, salvo que se haya tocado a mano desde la última vez."""
    f = site / rel
    if f.exists() and not a.force:
        cur = hashlib.sha1(f.read_bytes()).hexdigest()
        if state.get(rel) and state[rel] != cur:
            skipped.append(rel); return
    f.parent.mkdir(parents=True, exist_ok=True)
    f.write_text(text)
    state[rel] = hashlib.sha1(text.encode()).hexdigest()
    written.append(rel)

def load_block(name):
    d = BLOCKS / name
    if not d.is_dir(): sys.exit(f'No existe el bloque «{name}» (blocks/{name}/).')
    meta = json.loads((d / 'block.json').read_text())
    content = {}
    for l in langs:
        f = d / f'content.{l}.json'
        content[l] = json.loads(f.read_text()) if f.exists() else None
    meta['content'] = content
    meta['html'] = (d / 'block.html').read_text()
    meta['css'] = (d / 'block.css').read_text() if (d / 'block.css').exists() else ''
    meta['js'] = (d / 'block.js').read_text() if (d / 'block.js').exists() else ''
    meta['item'] = (d / 'item.html').read_text() if (d / 'item.html').exists() else ''
    return meta

# ---------------------------------------------------------------- contenido existente
def load_json(p): return json.loads(p.read_text()) if p.exists() else {}
multi = len(langs) > 1
content_files = {l: site / (f'content.{l}.json' if multi else 'content.json') for l in langs}
content = {l: load_json(f) for l, f in content_files.items()}
shared = load_json(site / 'content.json') if multi else content[main]

def put(l, key, value, image=False):
    """Añade un texto por defecto si aún no está (en un idioma secundario, solo si es distinto del principal)."""
    target = shared if image else content[l]
    if key in target: return
    if not image and l != main and content[main].get(key) == value: return
    target[key] = value

# ---------------------------------------------------------------- páginas y enlaces
pages = recipe['pages']
# Colecciones de los bloques (documentos, álbumes, vídeos, noticias): nombre en el idioma principal o el de la receta
def collection_of(spec):
    if isinstance(spec, str): spec = {'block': spec}
    c = load_block(spec['block']).get('collection')
    if not c: return (None, None)
    if 'item_page' in spec: c = {**c, 'item_page': bool(spec['item_page'])}   # la receta manda: ficha para cualquier colección
    return (spec.get('collection') or c['names'].get(main) or c['names']['en'], c)
for p in pages:
    p['slug'] = 'home' if p['file'] == 'template' else re.sub(r'[^a-z0-9]+', '_', p['file'])
    p['href'] = './' if p['file'] == 'template' else p['file'] + '.html'
    # La página con el listado de una colección con página propia (noticias) se abre en /<colección>/
    for spec in p['blocks']:
        name, c = collection_of(spec)
        if c and c.get('item_page') and name == p['file']: p['href'] = p['file'] + '/'
collections_out = {}
cta_page = next((p for p in pages if p['file'] == recipe.get('cta')), None) or \
           next((p for p in pages if any((b if isinstance(b, str) else b['block']) in ('contact', 'form') for b in p['blocks'])), None)
cta_href = cta_page['href'] if cta_page else './'
address_q = recipe.get('map') or content[main].get('address') or ''
map_embed = 'https://www.google.com/maps?q=' + urllib.parse.quote(address_q) + '&z=16&output=embed'
map_link = 'https://www.google.com/maps/search/?api=1&query=' + urllib.parse.quote(address_q)

def nav_html(btn=True):
    out = []
    for p in pages:
        if not p.get('nav'): continue
        key = f"nav_{p['slug']}"
        label = p['nav'].get(main) if isinstance(p['nav'], dict) else p['nav']
        for l in langs:
            put(l, key, p['nav'].get(l, label) if isinstance(p['nav'], dict) else label)
        cls = ' class="btn"' if btn and cta_page is p and p['file'] != 'template' and not any(x.get('button') for x in recipe.get('nav_extra', [])) else ''
        out.append(f'<a{cls} href="{p["href"]}" data-edit="{key}">{esc(content[main].get(key, label))}</a>')
    # Enlaces a otras webs (la tienda, otra entidad del grupo…): "nav_extra": [{"nav": "Bugaderia", "url": "https://…", "button": true}]
    for i, x in enumerate(recipe.get('nav_extra', []), 1):
        key = f'nav_extra_{i}'
        label = x['nav'].get(main) if isinstance(x['nav'], dict) else x['nav']
        for l in langs: put(l, key, x['nav'].get(l, label) if isinstance(x['nav'], dict) else label)
        cls = ' class="btn"' if btn and x.get('button') else ''
        out.append(f'<a{cls} href="{attr(x["url"])}" target="_blank" rel="noopener" data-edit="{key}">{esc(content[main].get(key, label))}</a>')
    return '\n      '.join(out)

def langs_html():
    if not multi: return ''
    return '<div class="b-header__langs">' + ''.join(f'<a data-lang-link="{l}" title="{NOMS.get(l, l)}">{l.upper()}</a>' for l in langs) + '</div>'

social = recipe.get('social') or []
social = social if isinstance(social, dict) else {n: '' for n in social}
def social_html():
    if not social: return ''
    out = []
    for net, url in social.items():
        icon, label = ICON.get(net, (net, net.title()))
        f = BLOCKS / '_icons' / f'{icon}.svg'
        svg = re.sub(r'<!--.*?-->', '', f.read_text()).replace('<svg ', '<svg aria-hidden="true" ') if f.exists() else ''
        key = f'social_{net}'
        put(main, key, url)                       # en el idioma principal: los demás lo heredan
        out.append(f'<a href="{attr(content[main].get(key, url))}" data-edit-href="{key}" target="_blank" rel="noopener" aria-label="{label}">{svg}</a>')
    return '<div class="b-social">' + ''.join(out) + '</div>'

cookie_cats = set()
def legal_html():
    out = []
    if recipe.get('legal'):
        for file, key in LEGAL:
            out.append(f'<a href="{file}.html" data-edit="{key}">{esc(content[main].get(key, ""))}</a>')
    # Páginas de la receta con "legal_link": true (condiciones de venta, devoluciones…), junto a las legales
    for p in pages:
        if p.get('legal_link'):
            key = f"{p['slug']}_link"
            label = p['legal_link'] if isinstance(p['legal_link'], str) else (p.get('title') or p['file'])
            for l in langs: put(l, key, label)
            out.append(f'<a href="{p["href"]}" data-edit="{key}">{esc(content[main].get(key, label))}</a>')
    if cookie_cats:
        out.append(f'<button type="button" data-cookie-settings data-edit="cookie_settings">{esc(content[main].get("cookie_settings", ""))}</button>')
    return ''.join(out)

# ---------------------------------------------------------------- un bloque → HTML
used = {}
def has_categories(col):
    f = site / col / '_categories.json'
    try: return bool(json.loads(f.read_text())) if f.exists() else False
    except Exception: return False
def if_blocks(h, col):
    """<!--if:categories-->…<!--/if:categories--> (y su contrario, if:!categories): solo si la colección tiene categorías
    (site/<colección>/_categories.json, que deja tools/migrate.py o se crea en el panel)."""
    on = has_categories(col)
    h = re.sub(r'<!--if:categories-->(.*?)<!--/if:categories-->\n?', (lambda m: m.group(1)) if on else '', h, flags=re.S)
    return re.sub(r'<!--if:!categories-->(.*?)<!--/if:!categories-->\n?', '' if on else (lambda m: m.group(1)), h, flags=re.S)
def news_side(col):
    """La barra lateral de un blog con categorías: la lista de categorías y las últimas cuatro."""
    return (f'<aside class="b-news__side">\n'
            f'      <div><h2 data-edit="news_categories">{esc(content[main].get("news_categories", ""))}</h2>\n'
            f'      <ul class="b-news__cats" data-post-categories="{col}"><li data-post-category><a data-post-category-link href="#"><span data-post-category-title>Categoria</span> <small data-post-category-count>0</small></a></li></ul></div>\n'
            f'      <div><h2 data-edit="news_latest">{esc(content[main].get("news_latest", ""))}</h2>\n'
            f'      <ul class="b-news__latest" data-posts="{col}" data-posts-limit="4"><li data-post-item><a data-post-link href="#"><img data-post-img="image" src="" alt="" loading="lazy"></a>'
            f'<div><a data-post-link href="#" data-post="title">Notícia</a><time data-post="date"></time></div></li></ul></div>\n'
            f'    </aside>')
def render_block(spec, page, n):
    if isinstance(spec, str): spec = {'block': spec}
    b = used.setdefault(spec['block'], load_block(spec['block']))
    variant = spec.get('variant') or (b.get('variants') or ['default'])[0]
    # Cabecera sin logo (solo la imagen neutra): el nombre en texto
    if spec['block'] == 'header' and not spec.get('variant') and shared.get('logo', PLACEHOLDER) == PLACEHOLDER: variant = 'text'
    prefix = spec.get('prefix') or (page['slug'] if spec['block'] in ('header', 'footer') else f"{page['slug']}_{spec['block'].replace('-', '_')}")
    if n > 1 and not spec.get('prefix'): prefix += f'_{n}'
    cookie_cats.update(b.get('cookies', []) if variant != 'nomap' else [])
    # Textos por defecto: clave → {p}_clave; @clave → clave
    images = set(b.get('images', []))
    for l in langs:
        c = b['content'].get(l) or b['content'].get(main) or {}
        for k, v in c.items():
            key = k[1:] if k.startswith('@') else f'{prefix}_{k}'
            if isinstance(v, list):
                v = [{fk: (PLACEHOLDER if fv == 'IMG' else fv) for fk, fv in it.items()} for it in v]
                only_images = all(set(it) <= {'image'} for it in v)
                if only_images and l != main: continue            # galerías: solo en el idioma principal (los demás la heredan)
                put(l, key, v)
            else:
                put(l, key, v, image=k in images)
    h = b['html']
    for v in b.get('variants', []):
        h = re.sub(rf'<!--variant:{v}-->(.*?)<!--/variant:{v}-->\n?', (lambda m: m.group(1)) if v == variant else '', h, flags=re.S)
    col_name, col_def = collection_of(spec)
    if col_def:
        collections_out[col_name] = (col_def, b, page)
        h = if_blocks(h, col_name)
        h = h.replace('{collection}', col_name)
    h = h.replace('{variant}', variant).replace('{p}', prefix)
    specials = {'news_side': lambda: news_side(col_name),
                'nav': lambda: nav_html(), 'nav_plain': lambda: nav_html(False), 'langs': langs_html, 'social': social_html,
                'cta_href': lambda: cta_href, 'privacy_href': lambda: 'politica-de-privacitat.html', 'legal_links': lambda: '{{#legal_links}}',
                'map_embed': lambda: attr(map_embed), 'map_link': lambda: attr(map_link)}
    h = re.sub(r'\{\{#(\w+)\}\}', lambda m: specials[m.group(1)]() if m.group(1) in specials else m.group(0), h)
    def img(m):
        k = m.group(1); key = k[1:] if k.startswith('@') else f'{prefix}_{k}'
        if key not in shared: shared[key] = PLACEHOLDER
        return attr(shared[key])
    h = re.sub(r'\{\{img:(@?[\w]+)\}\}', img, h)
    def text(m):
        path = m.group(1)
        mm = re.fullmatch(r'(@?\w+)(?:\[(\d+)\]\.(\w+))?', path)
        k, idx, field = mm.group(1), mm.group(2), mm.group(3)
        key = k[1:] if k.startswith('@') else f'{prefix}_{k}'
        v = content[main].get(key, shared.get(key, ''))
        if idx is not None:
            v = (v[int(idx)] if isinstance(v, list) and len(v) > int(idx) else {}).get(field, '')
        if m.string[max(0, m.start() - 5):m.start()].endswith('="'): return attr(v)
        # Dentro de un data-edit-html el texto por defecto ya es HTML (limpio, de la migración o del bloque)
        if re.search(r'data-edit-html="[^"]*">\s*$', m.string[max(0, m.start() - 200):m.start()]): return str(v)
        return esc(v)
    h = re.sub(r'\{\{(@?\w+(?:\[\d+\]\.\w+)?)\}\}', text, h)
    return h

# ---------------------------------------------------------------- páginas
def page_html(page, body, title_key, desc_key, noindex=False):
    meta = '<meta name="robots" content="noindex">' if noindex else \
        f'<meta name="description" data-edit-content="{desc_key}" content="{attr(content[main].get(desc_key, ''))}">'
    return f'''<!DOCTYPE html>
<html lang="{main}">
<head>
<template data-include="head"></template>
<title data-edit="{title_key}">{esc(content[main].get(title_key, ''))}</title>
{meta}
</head>
<body>

<div data-include="header"></div>

<main>
{body}
</main>

<div data-include="footer"></div>
<script src="site/assets/site.js" defer></script>
</body>
</html>
'''

site_name = recipe.get('name', '')
for l in langs:
    put(l, 'site_name', site_name)
header_html = render_block(recipe.get('header', 'header'), {'slug': 'site'}, 1)
# Textos de cada página (título y descripción para buscadores)
for p in pages:
    for l in langs:
        label = p['nav'].get(l) if isinstance(p.get('nav'), dict) else p.get('nav')
        put(l, f"{p['slug']}_seo", (site_name if p['file'] == 'template' else f'{label} · {site_name}') if label else site_name)
bodies = {}
for p in pages:
    counts = {}
    parts = []
    for spec in p['blocks']:
        name = spec if isinstance(spec, str) else spec['block']
        if isinstance(spec, str) or not spec.get('prefix'): counts[name] = counts.get(name, 0) + 1   # los de prefijo propio no cuentan
        parts.append(render_block(spec, p, counts.get(name, 1)))
    bodies[p['file']] = '\n\n'.join(parts)

# Descripción para buscadores de cada página: la de la receta («desc», texto o {idioma: texto}) o, si no, el primer
# párrafo de la página con algo de texto (en cada idioma, el de su clave); nunca un texto de ejemplo de los bloques.
def short(t, n=158):
    t = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', str(t or '')))).strip()
    return t if len(t) <= n else t[:n - 1].rsplit(' ', 1)[0].rstrip(',;:') + '…'
def first_text_key(body):
    for m in re.finditer(r'<(?:p|div)\b[^>]*\bdata-edit(?:-html)?="([^"]+)"', body):
        if re.search(r'consent|cookie|empty|accept|_prev$|_next$', m.group(1)): continue   # avisos, no contenido
        if len(short(content[main].get(m.group(1), ''), 1000)) >= 40: return m.group(1)
    return None
examples = {v for b in ['footer'] if (blk := load_block(b)) for t in blk['content'].values() for v in (t or {}).values() if isinstance(v, str)}
for p in pages:
    key = first_text_key(bodies[p['file']])
    for l in langs:
        d = p.get('desc')
        d = d.get(l, d.get(main, '')) if isinstance(d, dict) else d
        if not d and key: d = content[l].get(key) or content[main].get(key, '')
        if not d:                                                    # el nombre de la página y el lema de la web
            tag = content[l].get('tagline') or content[main].get('tagline', '')
            label = p['nav'].get(l, p['nav'].get(main)) if isinstance(p.get('nav'), dict) else p.get('nav')
            d = f'{label}. {tag}' if tag and label and p['file'] != 'template' and tag not in examples else tag
        put(l, f"{p['slug']}_desc", '' if d in examples else short(d))
footer_html = render_block(recipe.get('footer', 'footer'), {'slug': 'site'}, 1).replace('{{#legal_links}}', legal_html())

for p in pages:
    rel = 'template.html' if p['file'] == 'template' else f"pages/{p['file']}.html"
    write(rel, page_html(p, bodies[p['file']], f"{p['slug']}_seo", f"{p['slug']}_desc"))
# 404
for l in langs:
    t = NOTFOUND.get(l, NOTFOUND['en'])
    for k, v in zip(('notfound_eyebrow', 'notfound_title', 'notfound_text', 'notfound_button'), t): put(l, k, v)
    put(l, 'notfound_seo', f'{t[1]} · {site_name}')
write('pages/404.html', page_html(None, f'''<section class="section"><div class="narrow" style="text-align:center;padding-top:calc(var(--header-h) + 40px)">
  <span class="eyebrow" data-edit="notfound_eyebrow">{esc(content[main]["notfound_eyebrow"])}</span>
  <h1 data-edit="notfound_title">{esc(content[main]["notfound_title"])}</h1>
  <p data-edit="notfound_text">{esc(content[main]["notfound_text"])}</p>
  <p><a class="btn" href="./" data-edit="notfound_button">{esc(content[main]["notfound_button"])}</a></p>
</div></section>''', 'notfound_seo', 'notfound_seo', noindex=True))
# Las páginas legales las hace tools/legal.py (con Pulsar); aquí solo las que falten, para que el menú no dé 404
if recipe.get('legal'):
    for file, key in LEGAL:
        if not (site / 'pages' / f'{file}.html').exists():
            write(f'pages/{file}.html', page_html(None, f'''<section class="section"><div class="narrow" style="padding-top:calc(var(--header-h) + 40px)">
  <h1 data-edit="{key}">{esc(content[main].get(key, ''))}</h1>
  <p data-edit="{file.replace('-', '_')}_pending">[PENDENT: text legal (tools/legal.py)]</p>
</div></section>''', f'{key}_seo', f'{key}_seo'))
        for l in langs: put(l, f'{key}_seo', f"{content[l].get(key) or content[main].get(key, '')} · {site_name}")

# Definición de cada colección (en el idioma principal) y, si tiene página propia, la página de cada elemento
pick = lambda v: v.get(main) or v.get('en') or next(iter(v.values()), '') if isinstance(v, dict) else (v or '')
ITEM_TEXTS = {'item_back': {'ca': '← Tornar', 'es': '← Volver', 'en': '← Back'},
              'item_download': {'ca': 'Descarregar', 'es': 'Descargar', 'en': 'Download'},
              'item_link': {'ca': 'Més informació', 'es': 'Más información', 'en': 'More information'}}

def generic_item(col_name, c, listing):
    """Ficha de un elemento a partir de los campos de su colección (sin item.html propio en el bloque)."""
    for k, t in ITEM_TEXTS.items():
        for l in langs: put(l, k, t.get(l, t['en']))
    fields = {n: (t, pick(lab)) for n, t, lab in c['fields']}
    by = lambda typ: [n for n, (t, _) in fields.items() if t == typ]
    lead = next(iter(by('textarea')), None)
    out = [f'<article class="section b-item b-item--{col_name}">', '  <div class="narrow">',
           f'    <p class="b-item__back"><a href="{listing}" data-edit="item_back">{esc(content[main]["item_back"])}</a></p>',
           '    <p class="b-item__meta">' + ' '.join([f'<span class="b-item__cat" data-post="{n}"></span>' for n in by('category')]
                                                  + [f'<time data-post="{n}"></time>' for n in by('date')]) + '</p>',
           '    <h1 data-post="title">' + esc(pick(c.get('item', {}))) + '</h1>']
    if lead: out.append(f'    <p class="b-item__lead" data-post="{lead}"></p>')
    for n in by('image'): out.append(f'    <img class="b-item__img" data-post-img="{n}" src="" alt="">')
    data = [n for n, (t, _) in fields.items() if t == 'text' and n != 'title'] + [n for n in by('textarea') if n != lead]
    if data:
        out.append('    <dl class="b-item__data">' + ''.join(f'<div><dt>{esc(fields[n][1])}</dt><dd data-post="{n}"></dd></div>' for n in data) + '</dl>')
    for n in by('html'): out.append(f'    <div class="b-item__body" data-post-html="{n}"></div>')
    for n in by('images'):
        out.append(f'    <div class="b-item__gallery b-albums__grid" data-post-images="{n}"><a data-post-image-link href="#"><img data-post-image src="" alt="" loading="lazy"></a></div>')
        used.setdefault('albums', load_block('albums'))         # el visor de fotos
    for n in by('file'):
        out.append(f'    <p class="actions"><a class="btn" data-post-href="{n}" href="#" target="_blank" rel="noopener"><span data-edit="item_download">{esc(content[main]["item_download"])}</span> '
                   f'<small><span data-post="{n}.ext"></span> · <span data-post="{n}.size"></span></small></a></p>')
    for n in by('video'):
        out.append(f'    <div class="b-videos__frame"><img class="b-videos__thumb" data-post-src="{n}.thumb" src="" alt="">'
                   f'<iframe data-consent="videos" data-consent-src="" data-post-src="{n}" title="Vídeo" allowfullscreen></iframe>'
                   f'<div class="b-videos__consent"><p data-edit="videos_consent">{esc(content[main].get("videos_consent", ""))}</p>'
                   f'<button class="btn btn--light" type="button" data-cookie-accept="videos" data-edit="videos_accept">{esc(content[main].get("videos_accept", ""))}</button></div></div>')
        vb = used.setdefault('videos', load_block('videos'))
        for l in langs:
            for k, v in (vb['content'].get(l) or vb['content'].get(main) or {}).items():
                if k.startswith('@'): put(l, k[1:], v)
        cookie_cats.add('videos')
    for n in by('url'):
        out.append(f'    <p class="actions"><a class="btn btn--ghost" data-post-href="{n}" href="#" target="_blank" rel="noopener" data-edit="item_link">{esc(content[main]["item_link"])}</a></p>')
    out += ['  </div>', '</article>', '']
    return '\n'.join(out)

for col_name, (c, b, from_page) in collections_out.items():
    schema = {'label': pick(c.get('label', {})), 'item': pick(c.get('item', {})), 'help': pick(c.get('help', {})), 'sort': c.get('sort', '-date'),
              'fields': [{'name': n, 'type': t, 'label': pick(lab)} for n, t, lab in c['fields']], 'translated': c.get('translated', ['title'])}
    if has_categories(col_name) and not any(f['type'] == 'category' for f in schema['fields']):      # el blog migrado tiene categorías
        schema['fields'].insert(2, {'name': 'category', 'type': 'category', 'label': {'ca': 'Categoria', 'es': 'Categoría'}.get(main, 'Category')})
    write(f'{col_name}/_collection.json', json.dumps(schema, ensure_ascii=False, indent=2) + '\n')
    if c.get('item_page'):
        listing = next((p['href'] for p in pages if p['file'] == col_name), None) or from_page.get('href', './')
        if b.get('item'):
            for l in langs:
                for k, v in (b['content'].get(l) or b['content'].get(main) or {}).items():
                    if k.startswith('@'): put(l, k[1:], v)
            body = re.sub(r'\{\{@(\w+)\}\}', lambda m: esc(content[main].get(m.group(1), '')), if_blocks(b['item'], col_name).replace('{collection}', col_name))
            body = body.replace('{{#news_side}}', news_side(col_name))
        else:
            body = generic_item(col_name, c, listing)
        write(f'{col_name}/_item.html', f'''<!DOCTYPE html>
<html lang="{main}">
<head>
<template data-include="head"></template>
<title data-post="title">{esc(schema['item'])} · {esc(site_name)}</title>
</head>
<body>

<div data-include="header"></div>

<main>
{body}</main>

<div data-include="footer"></div>
<script src="site/assets/site.js" defer></script>
</body>
</html>
''')

write('partials/header.html', header_html + '\n')
write('partials/footer.html', footer_html + '\n')

# ---------------------------------------------------------------- tipografías, CSS y JS
theme = recipe.get('theme', {})
def google_fonts(families):
    css, out = [], site / 'assets' / 'fonts'
    out.mkdir(parents=True, exist_ok=True)
    for fam, axes in families:
        q = urllib.parse.quote(fam) + ':' + axes
        try:
            req = urllib.request.Request(f'https://fonts.googleapis.com/css2?family={q}&display=swap', headers={'User-Agent': UA})
            g = urllib.request.urlopen(req, timeout=20).read().decode()
        except Exception as e:
            print(f'  ! no se ha podido descargar {fam}: {e}'); continue
        for sub, body in re.findall(r'/\* ([a-z-]+) \*/\s*@font-face \{(.*?)\}', g, re.S):
            if sub not in ('latin', 'latin-ext'): continue
            st = re.search(r'font-style: (\w+)', body).group(1); w = re.search(r'font-weight: (\d+)', body).group(1)
            url = re.search(r'url\((https://[^)]+)\)', body).group(1); rng = re.search(r'unicode-range: ([^;]+);', body).group(1)
            name = f"{fam.lower().replace(' ', '-')}-{w}{'-italic' if st == 'italic' else ''}{'-ext' if sub == 'latin-ext' else ''}.woff2"
            if not (out / name).exists():
                (out / name).write_bytes(urllib.request.urlopen(urllib.request.Request(url, headers={'User-Agent': UA}), timeout=20).read())
            css.append(f"@font-face {{ font-family: '{fam}'; font-style: {st}; font-weight: {w}; font-display: swap; src: url({name}) format('woff2'); unicode-range: {rng}; }}")
    return css
fams = []
if theme.get('font_head'): fams.append((theme['font_head'], 'ital,wght@0,400;0,500;0,600;1,400'))
if theme.get('font_body') and theme.get('font_body') != theme.get('font_head'): fams.append((theme['font_body'], 'wght@400;500;600'))
font_css = google_fonts(fams) if fams else []
write('assets/fonts/fonts.css', '/* Tipografías alojadas en la web (Google Fonts, licencia OFL) */\n' + '\n'.join(font_css) + '\n')

VARS = {'bg': '--c-bg', 'soft': '--c-soft', 'text': '--c-text', 'muted': '--c-muted', 'head': '--c-head', 'primary': '--c-primary',
        'primary_ink': '--c-primary-ink', 'dark': '--c-dark', 'dark_text': '--c-dark-text', 'line': '--c-line', 'radius': '--radius',
        'head_weight': '--head-weight', 'container': '--container'}
decl = [f'  {VARS[k]}: {v};' for k, v in theme.items() if k in VARS]
if theme.get('font_head'): decl.append(f"  --font-head: '{theme['font_head']}', Georgia, serif;")
if theme.get('font_body'): decl.append(f"  --font-body: '{theme['font_body']}', system-ui, sans-serif;")
css = [(BLOCKS / '_theme' / 'theme.css').read_text(), '/* Tema de esta web (site/compose.json) */\n:root {\n' + '\n'.join(decl) + '\n}\n']
js = [(BLOCKS / '_theme' / 'theme.js').read_text()]
for name, b in used.items():
    if b['css']: css.append(f'/* Bloque: {name} */\n' + b['css'])
    if b['js']: js.append(f'/* Bloque: {name} */\n' + b['js'])
write('assets/site.css', '/* Generado por tools/compose.py: los cambios propios de esta web van en custom.css */\n' + '\n'.join(css))
write('assets/site.js', '\n'.join(js))
if not (site / 'assets' / 'custom.css').exists():
    write('assets/custom.css', '/* Diseño propio de esta web, encima de los bloques (tools/compose.py no lo toca) */\n')
write('partials/head.html', '''<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="icon" href="favicon.ico">
<link rel="apple-touch-icon" href="apple-touch-icon.png">
<link rel="stylesheet" href="site/assets/fonts/fonts.css">
<link rel="stylesheet" href="site/assets/site.css">
<link rel="stylesheet" href="site/assets/custom.css">
''')
ph = site / 'assets' / 'img' / 'placeholder.svg'
if not ph.exists():
    ph.parent.mkdir(parents=True, exist_ok=True)
    ph.write_text('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 600"><rect width="800" height="600" fill="#e8e4de"/>'
                  '<path d="M300 380l90-110 70 80 50-55 90 85z" fill="#cfc8bd"/><circle cx="520" cy="230" r="34" fill="#cfc8bd"/></svg>\n')

# ---------------------------------------------------------------- cookies, formulario y contenido
for i, l in enumerate(langs):
    f = site / ('cookies.json' if i == 0 else f'cookies.{l}.json')
    c = load_json(f) or {'policy': 'politica-de-cookies.html', 'version': 1, 'categories': [], 'texts': {}}
    have = {x['id'] for x in c.get('categories', [])}
    new = [cat for cat in sorted(cookie_cats) if cat not in have and cat in COOKIE_CATS]
    for cat in new:
        name, desc = COOKIE_CATS[cat].get(l, COOKIE_CATS[cat]['en'])
        c['categories'].append({'id': cat, 'name': name, 'description': desc})
    if new and have: c['version'] = int(c.get('version', 1)) + 1       # categorías nuevas: se vuelve a preguntar
    c['accent'] = theme.get('primary', c.get('accent', '#333333'))
    f.write_text(json.dumps(c, ensure_ascii=False, indent=4) + '\n')
    if cookie_cats:
        put(l, 'cookie_settings', {'ca': 'Configurar cookies', 'es': 'Configurar cookies', 'en': 'Cookie settings'}.get(l, 'Cookies'))
if 'form' in used:
    for i, l in enumerate(langs):
        f = site / ('contact.json' if i == 0 else f'contact.{l}.json')
        if f.exists(): continue
        lab = lambda k: content[l].get(k) or content[main].get(k, k)
        f.write_text(json.dumps({'recipient': 'email', 'subject': f'Web {site_name}: missatge de {{name}}' if l == 'ca' else f'{site_name}: {{name}}',
            'fields': [{'name': 'name', 'label': lab('f_name'), 'required': True, 'max': 200}, {'name': 'phone', 'label': lab('f_phone'), 'max': 40},
                       {'name': 'email', 'label': lab('f_email'), 'required': True, 'type': 'email', 'max': 200},
                       {'name': 'message', 'label': lab('f_message'), 'required': True, 'max': 5000},
                       {'name': 'privacy', 'label': lab('f_privacy_link'), 'required': True, 'max': 10}],
            'messages': FORM_MSGS.get(l, FORM_MSGS['en'])}, ensure_ascii=False, indent=4) + '\n')
if multi and not (site / 'languages.json').exists():
    (site / 'languages.json').write_text(json.dumps({'default': main, 'languages': {l: NOMS.get(l, l) for l in langs}}, ensure_ascii=False, indent=4) + '\n')
for l, f in content_files.items():
    f.write_text(json.dumps(content[l], ensure_ascii=False, indent=4) + '\n')
if multi:
    (site / 'content.json').write_text(json.dumps(shared, ensure_ascii=False, indent=4) + '\n')
state_file.write_text(json.dumps(state, indent=1) + '\n')
if recipe.get('legal'):
    import subprocess
    subprocess.run([sys.executable, str(REPO / 'tools' / 'legal.py'), str(web)] + (['--url', recipe['url']] if recipe.get('url') else []))

print(f"Bloques: {', '.join(used)}")
print(f"Escritos: {len(written)} ficheros" + (f" · sin tocar (editados a mano; --force para reescribirlos): {', '.join(skipped)}" if skipped else ''))
if cookie_cats: print(f"Cookies: categorías {', '.join(sorted(cookie_cats))} (descríbelas en la política de cookies)")
if 'form' in used: print('Formulario: faltan las claves de reCAPTCHA en config.local.php si aún no están')
