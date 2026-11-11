#!/usr/bin/env python3
# Migración de una web antigua a Neutron: hace lo mecánico para que solo quede el diseño.
#
#   python3 tools/migrate.py https://www.web-antigua.com ~/Projects/neutron-cliente [--render] [--max 150]
#
# Deja en <instalación>/_migracio/ (no se sube al servidor):
#   contenido.json   cada página: URL, idioma, título, descripción y bloques (títulos, párrafos, listas, imágenes)
#   comun.json       lo que se repite en todas las páginas (menú, pie): se quita del contenido de cada una
#   informe.md       CMS, idiomas, menú, contacto, redes, terceros (→ cookies), formularios, tipografías, colores, blog…
#   redirects.json   propuesta de site/redirects.json (URL antigua → página nueva) para revisar
#   paginas/         el HTML original de cada página
# Y en site/: las imágenes optimizadas (assets/img/*.webp, 2000 px como mucho), favicon.ico y apple-touch-icon.png.
# --render descarga las páginas con Chromium (tools/cdp.mjs) para las webs que montan el contenido con JavaScript
# (Duda, Wix…); sin él lo detecta y lo avisa en el informe. Solo usa la biblioteca estándar y PIL.
import argparse, collections, hashlib, html, io, json, os, re, subprocess, sys, time, unicodedata
import urllib.error, urllib.parse, urllib.request
from html.parser import HTMLParser
from pathlib import Path
from PIL import Image

REPO = Path(__file__).resolve().parent.parent
UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36'
VOID = {'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'}
SKIP = {'script', 'style', 'noscript', 'svg', 'template', 'iframe', 'canvas', 'select', 'button', 'form'}
BLOCK = {'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'blockquote', 'figcaption', 'td', 'th', 'dt', 'dd', 'address'}
FILES = re.compile(r'\.(pdf|jpe?g|png|gif|webp|svg|zip|rar|docx?|xlsx?|pptx?|mp4|mp3|webm|ico|css|js|xml|txt|exe)$', re.I)
JUNK_Q = re.compile(r'(^|&)(format=feed|type=rss|print=1|tmpl=component|share=|replytocom=|utm_|fbclid=|gclid=|limitstart=0)', re.I)
# Páginas de cuenta, carrito y acciones (no son contenido): no se rastrean
NOT_CONTENT = re.compile(r'(^|/)(component/users|wp-login\.php|wp-admin|my-account|mi-cuenta|cart|carrito|checkout)(/|$|\?)'
                         r'|[?&](view=(login|logout|register|registration|reset|remind|checkout|cart|history|favorites)|task=)', re.I)
SOCIAL = {'instagram.com': 'Instagram', 'facebook.com': 'Facebook', 'fb.com': 'Facebook', 'twitter.com': 'X (Twitter)', 'x.com': 'X (Twitter)',
          'youtube.com': 'YouTube', 'youtu.be': 'YouTube', 'tiktok.com': 'TikTok', 'linkedin.com': 'LinkedIn', 'pinterest.': 'Pinterest',
          'wa.me': 'WhatsApp', 'api.whatsapp.com': 'WhatsApp', 'vimeo.com': 'Vimeo', 'tripadvisor.': 'TripAdvisor', 'g.page': 'Google (ficha)'}
THIRD = [  # (patrón en src/href, servicio, categoría de cookies propuesta)
    (r'youtube\.com/embed|youtube-nocookie|youtu\.be', 'YouTube (vídeos)', 'videos'), (r'player\.vimeo\.com', 'Vimeo (vídeos)', 'videos'),
    (r'google\.[a-z.]+/maps|maps\.google|maps\.googleapis', 'Google Maps', 'maps'), (r'api\.mapbox\.com|mapbox', 'Mapbox (mapas)', 'maps'),
    (r'openstreetmap\.org', 'OpenStreetMap', 'maps'), (r'googletagmanager\.com|google-analytics\.com|gtag\(', 'Google Analytics / Tag Manager', 'analytics'),
    (r'connect\.facebook\.net|fbq\(', 'Píxel de Facebook', 'marketing'), (r'hotjar\.com', 'Hotjar', 'analytics'), (r'clarity\.ms', 'Microsoft Clarity', 'analytics'),
    (r'instagram\.com/embed|cdninstagram|elfsight', 'Widget de Instagram', 'social'), (r'recaptcha', 'Google reCAPTCHA (formulario)', 'necesaria'),
    (r'calendly\.com', 'Calendly (reservas)', 'reservas'), (r'booksy|treatwell|covermanager|thefork', 'Reservas externas', 'reservas'),
]
CMS = [(r'cdn-website\.com|multiscreensite', 'Duda'), (r'wp-content|wp-includes', 'WordPress'), (r'/media/jui/|joomla', 'Joomla'),
       (r'wixstatic\.com|_wix', 'Wix'), (r'squarespace', 'Squarespace'), (r'shopify', 'Shopify'), (r'webflow', 'Webflow'),
       (r'jimdo', 'Jimdo'), (r'prestashop', 'PrestaShop'), (r'drupal', 'Drupal')]
# Textos de relleno de los constructores de webs
JUNK_TEXT = {'Button', 'Botón', 'Botó', 'Share by:', 'Compartir con:', 'Nuevo párrafo', 'New paragraph', 'Click here', 'Haz clic aquí',
             'Read more', 'Leer más', 'Llegir més', 'Learn more', 'Más información', 'Skip to main content', 'Saltar al contenido'}
BLOGWORDS = r'(blog|noticies|noticias|news|actualitat|actualidad|nouvelles|articles|articulos|entrades|posts?)'

ap = argparse.ArgumentParser(description='Migra el contenido de una web antigua a una instalación Neutron')
ap.add_argument('url', help='portada de la web antigua')
ap.add_argument('install', help='carpeta de la instalación (tools/new-site.py)')
ap.add_argument('--max', type=int, default=150, help='páginas como mucho (por defecto 150)')
ap.add_argument('--render', action='store_true', help='descargar con Chromium (webs que montan el contenido con JavaScript)')
ap.add_argument('--sin-imagenes', action='store_true', help='no descargar las imágenes')
ap.add_argument('--excluir', action='append', default=[], metavar='REGEX',
                help='no rastrear las URLs (ruta y consulta) que coinciden, p. ej. una tienda que no se migra: '
                     "--excluir '^productes' --excluir 'option=com_botiga' (se puede repetir)")
# Sin idioma preferido: cada URL se descarga en el suyo (con «ca» primero, un Joomla con filtro de idioma da el catalán
# también en la portada del castellano y se pierde ese idioma)
ap.add_argument('--idioma-navegador', default='', help='Accept-Language que se envía (por defecto, ninguno)')
a = ap.parse_args()

root = urllib.parse.urlsplit(a.url if '://' in a.url else 'https://' + a.url)
HOST = root.netloc.lower().removeprefix('www.')
inst = Path(a.install).expanduser().resolve()
out = inst / '_migracio'
(out / 'paginas').mkdir(parents=True, exist_ok=True)
img_dir = inst / 'site' / 'assets' / 'img'
img_dir.mkdir(parents=True, exist_ok=True)
log = lambda *x: print(*x, flush=True)

# ---------------------------------------------------------------- descarga
def fetch(url, binary=False, timeout=30):
    req = urllib.request.Request(url, headers={'User-Agent': UA, **({'Accept-Language': a.idioma_navegador} if a.idioma_navegador else {})})
    try:
        with urllib.request.urlopen(req, timeout=timeout) as r:
            data = r.read()
            ctype = r.headers.get('Content-Type', '')
            return r.geturl(), r.status, ctype, (data if binary else data.decode(r.headers.get_content_charset() or 'utf-8', 'replace'))
    except urllib.error.HTTPError as e:
        return url, e.code, '', b'' if binary else ''
    except Exception as e:
        return url, 0, '', b'' if binary else ''

def render(urls):
    """HTML ya montado por el navegador (para Duda, Wix…), con tools/cdp.mjs."""
    tmp = Path.home() / 'cdp-tmp' / 'migrate'
    tmp.mkdir(parents=True, exist_ok=True)
    steps = tmp / 'pasos.mjs'
    steps.write_text('''import { writeFileSync } from 'node:fs';
const URLS = %s, OUT = %s;
export default URLS.map((u, i) => [String(i), async ({ go, evalJs, sleep }) => {
  await go(u); await sleep(1200);
  await evalJs('window.scrollTo(0, document.body.scrollHeight)'); await sleep(800);
  writeFileSync(OUT + '/' + i + '.html', await evalJs('document.documentElement.outerHTML')); return u; }]);
''' % (json.dumps(urls), json.dumps(str(tmp))))
    subprocess.run(['node', str(REPO / 'tools' / 'cdp.mjs'), 'about:blank', str(steps)], capture_output=True, timeout=60 + 15 * len(urls))
    res = {}
    for i, u in enumerate(urls):
        f = tmp / f'{i}.html'
        if f.exists(): res[u] = f.read_text(errors='replace'); f.unlink()
    return res

# ---------------------------------------------------------------- árbol HTML tolerante
class Node:
    __slots__ = ('tag', 'attrs', 'children', 'parent', 'text')
    def __init__(s, tag, attrs=None, parent=None, text=None):
        s.tag, s.attrs, s.children, s.parent, s.text = tag, dict(attrs or {}), [], parent, text
    def get(s, k, d=''): return s.attrs.get(k) or d
    def iter(s):
        yield s
        for c in s.children:
            if c.tag: yield from c.iter()
    def find_all(s, *tags): return [n for n in s.iter() if n.tag in tags]
    def text_content(s):
        out = []
        def walk(n):
            if n.tag is None: out.append(n.text)
            elif n.tag == 'br': out.append('\n')
            elif n.tag not in SKIP:
                for c in n.children: walk(c)
        walk(s); return ''.join(out)
    def classes(s): return (s.get('class') + ' ' + s.get('id')).lower()

class Builder(HTMLParser):
    def __init__(s):
        super().__init__(convert_charrefs=True); s.root = Node('#root'); s.cur = s.root
    def handle_starttag(s, tag, attrs):
        n = Node(tag, attrs, s.cur); s.cur.children.append(n)
        if tag not in VOID: s.cur = n
    def handle_startendtag(s, tag, attrs):
        s.cur.children.append(Node(tag, attrs, s.cur))
    def handle_endtag(s, tag):
        n = s.cur
        while n is not None and n.tag != tag: n = n.parent
        if n is not None and n.parent is not None: s.cur = n.parent
    def handle_data(s, d):
        s.cur.children.append(Node(None, parent=s.cur, text=d))

def parse(src):
    b = Builder()
    try: b.feed(src)
    except Exception: pass
    return b.root

def clean_text(t):
    # Caracteres de control C1 (0x80–0x9F): casi siempre Windows-1252 mal declarado ("\x80" era «€»)
    t = re.sub('[\x80-\x9f]', lambda m: bytes([ord(m.group(0))]).decode('cp1252', 'replace'), t)
    t = t.replace(' ', ' ').replace('﻿', '').replace('​', '')
    t = re.sub(r'[ \t\r\f\v]+', ' ', t)
    t = re.sub(r' *\n *', '\n', t).strip()
    return re.sub(r'\s+([.,;:!?])(?=\s|$)', r'\1', t)

# ---------------------------------------------------------------- normalización de URLs
def norm(url, base):
    if not url or url.startswith(('mailto:', 'tel:', 'javascript:', 'data:', '#', 'whatsapp:')): return None
    u = urllib.parse.urlsplit(urllib.parse.urljoin(base, html.unescape(url.strip())))
    if u.scheme not in ('http', 'https') or u.netloc.lower().removeprefix('www.') != HOST: return None
    path = re.sub(r'/{2,}', '/', u.path or '/')
    if FILES.search(path): return None
    q = '&'.join(p for p in u.query.split('&') if p and not JUNK_Q.search(p))
    return urllib.parse.urlunsplit((root.scheme, root.netloc, path, q, ''))

def rel(url):
    u = urllib.parse.urlsplit(url)
    return (u.path.lstrip('/') or '') + ('?' + u.query if u.query else '')

# ---------------------------------------------------------------- rastreo
def sitemap_urls():
    found, todo, seen = [], [f'{root.scheme}://{root.netloc}/sitemap.xml', f'{root.scheme}://{root.netloc}/sitemap_index.xml'], set()
    while todo and len(found) < a.max * 2:
        sm = todo.pop(0)
        if sm in seen: continue
        seen.add(sm)
        _, st, _, body = fetch(sm)
        if st != 200 or '<' not in body: continue
        for loc in re.findall(r'<loc>\s*([^<\s]+)\s*</loc>', body):
            loc = html.unescape(loc)
            if loc.endswith('.xml'): todo.append(loc)
            elif (n := norm(loc, a.url)): found.append(n)
    return found

log(f'Rastreando {a.url} …')
start = norm(a.url, a.url) or a.url
queue = collections.deque([start] + sitemap_urls())
pages, seen_urls, final_seen = {}, set(), {}
EXCLUDE = [re.compile(x, re.I) for x in a.excluir]
excluded = collections.Counter()
def skipped(url):
    r = rel(url)
    for x in EXCLUDE:
        if x.search(r): excluded[x.pattern] += 1; return True
    if NOT_CONTENT.search(r): excluded['(cuenta, carrito y acciones)'] += 1; return True
    return False
while queue and len(pages) < a.max:
    url = queue.popleft()
    if url in seen_urls: continue
    seen_urls.add(url)
    if url != start and skipped(url): continue
    final, st, ctype, body = fetch(url)
    if st != 200 or 'html' not in ctype.lower(): continue
    final_n = norm(final, final) or final
    if final_n in final_seen and final_n != url:      # redirección a una página que ya tenemos
        final_seen[final_n].setdefault('alias', []).append(url); continue
    tree = parse(body)
    page = {'url': url, 'final': final_n, 'html': body, 'tree': tree}
    pages[url] = page; final_seen[final_n] = page
    for n in tree.find_all('a'):
        if (h := norm(n.get('href'), final)) and h not in seen_urls: queue.append(h)
    for n in tree.find_all('link'):
        if 'alternate' in n.get('rel') and n.get('hreflang') and (h := norm(n.get('href'), final)) and h not in seen_urls: queue.append(h)
    if len(pages) % 10 == 0: log(f'  {len(pages)} páginas…')
log(f'{len(pages)} páginas descargadas.')

# Webs que montan el contenido con JavaScript: poco texto en el HTML
def visible_len(tree):
    body = next(iter(tree.find_all('body')), tree)
    return len(re.sub(r'\s+', ' ', body.text_content()))
js_pages = [u for u, p in pages.items() if visible_len(p['tree']) < 400]
if a.render and pages:
    log('Descargando con Chromium …')
    for u, src in render(list(pages)).items():
        pages[u]['html'] = src; pages[u]['tree'] = parse(src)
    js_pages = []

# ---------------------------------------------------------------- nombres
def slugify(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    return re.sub(r'[^a-z0-9]+', '-', s).strip('-')[:50] or 'imatge'

def image_name(src):
    base = urllib.parse.unquote(os.path.basename(urllib.parse.urlsplit(src).path))
    base = re.sub(r'\.(jpe?g|png|gif|webp|avif|svg)$', '', base, flags=re.I)
    base = re.sub(r'-\d{2,4}(w|x\d{2,4})$', '', base)                       # -1920w, -300x200
    base = re.sub(r'(-[0-9a-f]{8})+$', '', base)                            # sufijos de hash (Duda)
    return slugify(base)

# ---------------------------------------------------------------- análisis de cada página
def lang_of(p):
    h = next(iter(p['tree'].find_all('html')), None)
    l = (h.get('lang') if h else '')[:2].lower()
    m = re.match(r'/([a-z]{2})(/|$)', urllib.parse.urlsplit(p['final']).path)
    q = re.search(r'(?:^|&)lang=([a-z]{2})', urllib.parse.urlsplit(p['final']).query)
    return (m.group(1) if m else None) or (q.group(1) if q else None) or l or '?'

def meta(tree, name):
    for n in tree.find_all('meta'):
        if n.get('name').lower() == name or n.get('property').lower() == name: return clean_text(html.unescape(n.get('content')))
    return ''

def best_src(n, base):
    cands = []
    for attr in ('srcset', 'data-srcset'):
        for part in n.get(attr).split(','):
            bits = part.strip().split()
            if bits:
                w = re.match(r'(\d+)w', bits[1]) if len(bits) > 1 else None
                cands.append((int(w.group(1)) if w else 1, bits[0]))
    for attr in ('data-src', 'data-lazy-src', 'data-original', 'data-image-url', 'src'):
        if n.get(attr) and not n.get(attr).startswith('data:'): cands.append((0, n.get(attr)))
    if not cands: return ''
    return urllib.parse.urljoin(base, html.unescape(max(cands, key=lambda c: c[0])[1]))

def main_container(tree):
    for sel in ('main', 'article'):
        found = [n for n in tree.find_all(sel) if len(n.text_content().strip()) > 200]
        if found: return max(found, key=lambda n: len(n.text_content()))
    for n in tree.iter():
        if n.get('role') == 'main' or re.search(r'\b(main|content|contingut|contenido)\b', n.get('id').lower()):
            if len(n.text_content().strip()) > 200: return n
    return next(iter(tree.find_all('body')), tree)

CHROME = re.compile(r'\b(header|footer|nav|menu|cookie|breadcrumb|sidebar|share|social|lang|skip)\b')
def blocks_of(node, base):
    out = []
    def flush_text(parts, tag):
        t = clean_text(''.join(parts))
        for piece in [x.strip() for x in t.split('\n') if x.strip()]:
            if piece not in JUNK_TEXT and not re.fullmatch(r'[-–—·•|]+', piece): out.append({'t': tag, 'text': piece})
    def walk(n, in_list=None):
        if n.tag is None: return
        if n.tag in SKIP or n.tag in ('header', 'footer', 'nav'): return
        if n.tag == 'img':
            src = best_src(n, base)
            if src: out.append({'t': 'img', 'src': src, 'alt': clean_text(n.get('alt'))})
            return
        st = n.get('style')
        if 'background' in st and (m := re.search(r'url\([\'"]?([^\'")]+)', st)):
            out.append({'t': 'img', 'src': urllib.parse.urljoin(base, html.unescape(m.group(1))), 'alt': '', 'fondo': True})
        if n.tag in BLOCK:
            # Un bloque puede llevar imágenes dentro (p > img): primero el texto, después las imágenes
            tag = 'li' if n.tag == 'li' else ('h' + n.tag[1] if re.match(r'h\d', n.tag) else 'p')
            flush_text([n.text_content()], tag)
            for img in n.find_all('img'):
                if (src := best_src(img, base)): out.append({'t': 'img', 'src': src, 'alt': clean_text(img.get('alt'))})
            return
        if n.get('data-image-url'):                                         # Duda: la imagen original, grande
            out.append({'t': 'img', 'src': urllib.parse.urljoin(base, html.unescape(n.get('data-image-url'))), 'alt': ''})
            return
        # Texto suelto dentro de un div (los constructores de webs no usan <p>)
        loose = []
        for c in n.children:
            inline = c.tag in ('span', 'strong', 'b', 'em', 'i', 'a', 'br', 'font', 'small', 'u', 'sup', 'sub')
            if inline and (c.find_all('img') or 'url(' in c.get('style') or c.get('data-image-url')): inline = False
            if c.tag is None or inline:
                loose.append(c.text if c.tag is None else ('\n' if c.tag == 'br' else c.text_content()))
            else:
                if ''.join(loose).strip(): flush_text(loose, 'p')
                loose = []
                walk(c)
        if ''.join(loose).strip(): flush_text(loose, 'p')
    walk(node)
    # La misma imagen dos veces seguidas (el enlace a la grande y la miniatura de dentro)
    return [b for i, b in enumerate(out) if not (b['t'] == 'img' and i and out[i - 1]['t'] == 'img' and image_name(out[i - 1]['src']) == image_name(b['src']))]

def analyse(p):
    t, base = p['tree'], p['final']
    title = clean_text(html.unescape(next((n.text_content() for n in t.find_all('title')), '')))
    body = next(iter(t.find_all('body')), t)
    p.update(lang=lang_of(p), title=title, description=meta(t, 'description'), og_image=meta(t, 'og:image'),
             blocks=blocks_of(main_container(t), base))
    p['date'] = next((n.get('datetime')[:10] for n in t.find_all('time') if n.get('datetime')), '') or meta(t, 'article:published_time')[:10]
    if not p['date']:                                       # «Publicat el 08-10-2026» (blogs de componentes de Joomla)
        m = re.search(r'\b(?:publicat|publicado|publié|published|posted|data de publicació|fecha de publicación)\b[^\d]{0,20}'
                      r'(\d{1,2}[-/.]\d{1,2}[-/.]\d{4}|\d{4}-\d{2}-\d{2}|\d{1,2}\s+(?:de\s+|d\')?[a-zçéûô]{3,10}\.?\s+(?:de\s+)?\d{4})',
                      re.sub(r'\s+', ' ', body.text_content()), re.I)
        if m:
            sys.path.insert(0, str(Path(__file__).resolve().parent))
            import migrate_collections
            p['date'] = migrate_collections.parse_date(m.group(1))
    p['links'] = [(clean_text(n.text_content()), urllib.parse.urljoin(base, html.unescape(n.get('href')))) for n in body.find_all('a') if n.get('href')]
for p in pages.values(): analyse(p)
uniq = {}
for url in sorted(pages, key=lambda u: (len(u), u)):          # se queda la URL más corta
    p = pages[url]
    key = (p['lang'], p['title'], hashlib.md5(json.dumps(p['blocks'], sort_keys=True).encode()).hexdigest())
    if key in uniq: uniq[key].setdefault('alias', []).extend([url] + p.get('alias', [])); del pages[url]
    else: uniq[key] = p

# Lo común (menú, pie, avisos): bloques de texto que salen en más de la mitad de las páginas del mismo idioma
by_lang = collections.defaultdict(list)
for p in pages.values(): by_lang[p['lang']].append(p)
common = {}
for l, ps in by_lang.items():
    cnt = collections.Counter()
    for p in ps: cnt.update({b['text'] for b in p['blocks'] if b['t'] != 'img'})
    limit = max(2, len(ps) * 0.5) if len(ps) > 2 else 99
    common[l] = [t for t, c in cnt.most_common() if c >= limit]
    for p in ps:
        p['blocks'] = [b for b in p['blocks'] if b['t'] == 'img' or b['text'] not in common[l]]

# ---------------------------------------------------------------- imágenes
images, used_names, img_report, by_name = {}, set(), [], {}
def save_image(src):
    if src in images: return images[src]
    images[src] = None
    if a.sin_imagenes: return None
    _, st, ctype, data = fetch(src, binary=True)
    if st != 200 or not data: return None
    name = image_name(src)
    if name in by_name: images[src] = by_name[name]; return by_name[name]     # la misma imagen en otro tamaño
    if src.lower().split('?')[0].endswith('.svg') or 'svg' in ctype:
        fn = name + '.svg'; (img_dir / fn).write_bytes(data)          # logos en SVG: tal cual (revisar a mano)
        images[src] = 'site/assets/img/' + fn; return images[src]
    try:
        im = Image.open(io.BytesIO(data)); im.load()
    except Exception:
        return None
    if min(im.size) < 40: return None                                   # iconos y píxeles de seguimiento
    if getattr(im, 'is_animated', False):
        fn = name + '.gif'; (img_dir / fn).write_bytes(data)
    else:
        im = im.convert('RGBA') if im.mode in ('P', 'LA', 'RGBA') or 'transparency' in im.info else im.convert('RGB')
        if max(im.size) > 2000:
            k = 2000 / max(im.size); im = im.resize((round(im.width * k), round(im.height * k)), Image.LANCZOS)
        fn = name + '.webp'
        n = 2
        while fn in used_names: fn = f'{name}-{n}.webp'; n += 1
        im.save(img_dir / fn, 'WEBP', quality=82, method=6)
    used_names.add(fn)
    img_report.append((fn, im.size, len(data) // 1024, (img_dir / fn).stat().st_size // 1024))
    images[src] = by_name[name] = 'site/assets/img/' + fn
    return images[src]

if not a.sin_imagenes:
    log('Descargando y optimizando imágenes …')
    for p in pages.values():
        for b in p['blocks']:
            if b['t'] == 'img': b['local'] = save_image(b['src'])
        if p['og_image']: p['og_local'] = save_image(urllib.parse.urljoin(p['final'], p['og_image']))

# Logo: la primera imagen de la cabecera con «logo» en el nombre, la clase o el alt
logo = None
home = pages.get(start) or next(iter(pages.values()), None)
if home:
    for n in home['tree'].find_all('img'):
        anc, txt = n, (n.get('src') + n.get('alt') + n.classes()).lower()
        while anc is not None and not logo:
            if 'logo' in txt or 'logo' in anc.classes() or anc.tag == 'header':
                if 'logo' in txt or 'logo' in anc.classes():
                    logo = best_src(n, home['final'])
                break
            anc = anc.parent
        if logo: break
logo_local = save_image(logo) if logo else None

# Favicon: apple-touch-icon > icono PNG grande > favicon.ico; si no hay (o es el genérico del constructor), desde el logo
fav_note = ''
icons = []
if home:
    for n in home['tree'].find_all('link'):
        rel_ = n.get('rel').lower()
        if 'icon' in rel_ and n.get('href'):
            size = max([int(x) for x in re.findall(r'(\d+)x\d+', n.get('sizes'))] or [180 if 'apple' in rel_ else 32])
            icons.append((size, urllib.parse.urljoin(home['final'], n.get('href'))))
if not any(re.search(r'cdn-website\.com/runtime|wixstatic.*favicon|squarespace.*favicon', u) for _, u in icons):
    icons.append((16, f'{root.scheme}://{root.netloc}/favicon.ico'))
fav_img = None
for size, url in sorted(icons, key=lambda x: -x[0]):
    if re.search(r'cdn-website\.com/runtime|wixstatic.*favicon|squarespace.*favicon', url):
        fav_note = f'El favicon de la web antigua es el genérico del constructor ({url}): se ha hecho desde el logo.'; continue
    _, st, _, data = fetch(url, binary=True)
    if st == 200 and data:
        try:
            im = Image.open(io.BytesIO(data)); im.load(); fav_img = im.convert('RGBA'); fav_src = url; break
        except Exception: continue
if fav_img is None and logo_local and not logo_local.endswith('.svg'):
    lg = Image.open(inst / logo_local).convert('RGBA')
    side = max(lg.size); sq = Image.new('RGBA', (side, side), (255, 255, 255, 0)); sq.paste(lg, ((side - lg.width) // 2, (side - lg.height) // 2))
    fav_img, fav_src = sq, 'logo'
    fav_note = fav_note or 'La web antigua no tiene favicon: se ha hecho desde el logo (revisar: un logo ancho queda pequeño).'
if fav_img is not None:
    site = inst / 'site'
    fav_img.save(site / 'favicon.ico', sizes=[(16, 16), (32, 32), (48, 48)])
    t = Image.new('RGBA', (180, 180), (255, 255, 255, 255)); ic = fav_img.copy(); ic.thumbnail((180, 180), Image.LANCZOS)
    t.alpha_composite(ic, ((180 - ic.width) // 2, (180 - ic.height) // 2)); t.convert('RGB').save(site / 'apple-touch-icon.png')
    if fav_img.width < 64 and not fav_note: fav_note = f'El favicon original es pequeño ({fav_img.width} px): el apple-touch-icon puede verse borroso.'

# ---------------------------------------------------------------- informe
all_html = '\n'.join(p['html'] for p in pages.values())
cms = next((name for pat, name in CMS if re.search(pat, all_html, re.I)), 'desconocido')
gen = meta(home['tree'], 'generator') if home else ''
langs = collections.Counter(p['lang'] for p in pages.values())

text_all = '\n'.join(re.sub(r'<[^>]+>', ' ', html.unescape(p['html'])) for p in pages.values())
emails = collections.Counter(e.lower() for e in re.findall(r'(?<![\w@])[\w.+-]+@[A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)*\.[A-Za-z]{2,}(?![\w.-]*\w)',
                                                           ' '.join(html.unescape(p['html']) for p in pages.values()))
                             if not re.search(r'\.(png|jpe?g|webp|gif|svg|css|js)$|example|sentry|wixpress|domain\.|serviciodecorreo|@2x|wght@', e, re.I))
for e in list(emails):                                   # «info@web.c» cortado al final de una línea
    if any(o != e and o.startswith(e) for o in emails): del emails[e]
# Teléfonos: los enlaces tel: y, en el texto, números españoles escritos con separadores (938 890 233, 93.889.02.33)
phones = collections.Counter()
for p in pages.values():
    for n in p['tree'].find_all('a'):
        if n.get('href').startswith('tel:'):
            d = re.sub(r'\D', '', n.get('href'))[-9:]
            if len(d) == 9: phones[f'{d[:3]} {d[3:6]} {d[6:]}'] += 1
for m in re.findall(r'(?<![\w/])(?:\+34[\s.]?)?[6789]\d{1,2}(?:[\s.]\d{2,3}){2,3}(?![\w/])', text_all):
    d = re.sub(r'\D', '', m)[-9:]
    if len(d) == 9 and len(set(d)) > 3: phones[f'{d[:3]} {d[3:6]} {d[6:]}'] += 1
for m in re.findall(r'(?:tel[eèé]?f?o?n?o?s?|tlf|phone|m[oò]bil|m[oó]vil|whatsapp|truca|llama)[^\d]{0,30}?((?:\+34\s?)?[6789]\d{8})(?!\d)', text_all, re.I):
    d = re.sub(r'\D', '', m)[-9:]
    phones[f'{d[:3]} {d[3:6]} {d[6:]}'] += 1
cps = collections.Counter(re.sub(r'\s+', ' ', m.strip()) for m in re.findall(r'\b[0-5]\d{4}\s*[-–]?\s*[A-ZÀ-Ú][\wÀ-ú\'.]+(?: [\wÀ-ú\'.()]+){0,3}', text_all))
social = collections.OrderedDict()
for p in pages.values():
    for n in p['tree'].find_all('a'):
        h = n.get('href')
        for host, name in SOCIAL.items():
            if host in h and not re.search(r'sharer|share\?|intent/tweet|/share|dialog/|send\?text=', h) and urllib.parse.urlsplit(h).path.strip('/'):
                social.setdefault(name, collections.Counter())[h] += 1
third = collections.OrderedDict()
for pat, name, cat in THIRD:
    hits = sorted({rel(p['url']) for p in pages.values() if re.search(pat, p['html'], re.I)})
    if hits: third[name] = (cat, hits)

forms = []
for p in pages.values():
    for f in p['tree'].find_all('form'):
        fields = []
        for n in f.find_all('input', 'textarea', 'select'):
            typ = n.get('type', n.tag).lower()
            if typ in ('hidden', 'submit', 'button', 'image', 'reset'): continue
            lab = n.get('placeholder') or n.get('aria-label') or n.get('name')
            if n.get('id'):
                lab = next((clean_text(l.text_content()) for l in p['tree'].find_all('label') if l.get('for') == n.get('id')), lab)
            fields.append(f"{clean_text(lab) or '?'} ({typ}{', obligatorio' if 'required' in n.attrs else ''})")
        real = [n for n in f.find_all('input', 'textarea') if n.get('type', 'text').lower() in ('text', 'email', 'tel', 'textarea', '') or n.tag == 'textarea']
        if fields and real and not re.search(r'search|cerca|busca|lang', f.classes() + f.get('action') + f.get('name'), re.I):
            forms.append((rel(p['url']) or '/', f.get('action') or '(misma página)', fields))

css_texts = re.findall(r'<style[^>]*>(.*?)</style>', home['html'] if home else '', re.S)
for n in (home['tree'].find_all('link') if home else [])[:40]:
    if 'stylesheet' in n.get('rel') and n.get('href'):
        _, st, _, body = fetch(urllib.parse.urljoin(home['final'], n.get('href')))
        if st == 200: css_texts.append(body)
css = '\n'.join(css_texts)
gfonts = sorted({urllib.parse.unquote(f).split(':')[0].replace('+', ' ') for f in re.findall(r'fonts\.googleapis\.com/css2?\?family=([^"\'&)]+)', all_html + css)
                 for f in [f] for f in f.split('|')})
families = collections.Counter(f for decl in re.findall(r'font-family\s*:\s*([^;}{]+)', css)
                               for f in [decl.split(',')[0].replace('!important', '').strip().strip('\'"')]
                               if f and not re.search(r'inherit|initial|icon|awesome|dm-font|glyph|material|dashicons', f, re.I))
colors = collections.Counter(c.lower() if len(c) == 7 else '#' + ''.join(ch * 2 for ch in c[1:]).lower()
                             for c in re.findall(r'#[0-9a-fA-F]{6}\b|#[0-9a-fA-F]{3}\b', css))

menu = []
if home:
    navs = home['tree'].find_all('nav') or [n for n in home['tree'].iter() if re.search(r'\b(menu|nav)\b', n.classes())]
    seen_m = set()
    for nav in navs[:3]:
        for n in nav.find_all('a'):
            txt, href = clean_text(n.text_content()), n.get('href')
            if txt and (txt, href) not in seen_m and len(txt) < 60: seen_m.add((txt, href)); menu.append((txt, href))

css_bgs = []
if not a.sin_imagenes and home:
    for u in dict.fromkeys(re.findall(r'url\(\s*[\'"]?([^\'")]+\.(?:jpe?g|png|webp)(?:\?[^\'")]*)?)', css + home['html'], re.I)):
        full = urllib.parse.urljoin(home['final'], html.unescape(u))
        if full in images: continue
        local = save_image(full)
        if not local or not img_report or img_report[-1][0] != os.path.basename(local): continue
        if max(img_report[-1][1]) >= 600: css_bgs.append(local)
        else:                                      # iconos y sprites de la interfaz de la web antigua: fuera
            (inst / local).unlink(missing_ok=True); img_report.pop(); images[full] = None

def is_post(p):
    path = urllib.parse.urlsplit(p['final']).path
    q = urllib.parse.urlsplit(p['final']).query
    if re.search(r'view=article&id=\d+:[^&]+&catid=', q): return True   # Joomla
    # Componentes de blog de Joomla (com_blogger, com_k2, com_easyblog…): la ficha de un elemento
    if re.search(r'(?:^|&)option=com_\w+', q) and re.search(r'(?:^|&)view=(?:item|entry|post)(?:&|$)', q) and re.search(r'(?:^|&)id=\d+', q): return True
    return bool(re.search(rf'/{BLOGWORDS}/[^/]+', path, re.I) or re.search(r'/\d{4}/\d{2}/', path) or
                (p['date'] and next(iter(p['tree'].find_all('article')), None) is not None))
# Las páginas legales de un Joomla son artículos, pero no entradas del blog
LEGAL_RE = r'legal|privac|cookies|aviso|avís|mentions|cancel|devoluci|desistim|condicions|condiciones|terms|términos|termes'
posts = [p for p in pages.values() if is_post(p) and not re.search(LEGAL_RE, p['url'] + ' ' + p['title'], re.I)]
# Las páginas del menú (de cualquier idioma) no son entradas
menu_urls = set()
for p in pages.values():
    for nav in p['tree'].find_all('nav') + [n for n in p['tree'].iter() if re.search(r'\b(menu|nav|navigation)\b', n.classes())]:
        menu_urls.update(norm(n.get('href'), p['final']) for n in nav.find_all('a'))
listings = [p for p in pages.values() if re.search(rf'/{BLOGWORDS}/?$', urllib.parse.urlsplit(p['final']).path, re.I)
            or re.search(r'layout=blog|view=category|view=items?list|view=items(?:&|$)', urllib.parse.urlsplit(p['final']).query)]
for lp in listings:
    for _, href in lp['links']:
        q = pages.get(norm(href, lp['final']) or '')
        if q and q not in posts and q not in listings and q['url'] not in menu_urls and q['lang'] == lp['lang'] \
           and not re.fullmatch(r'/[a-z]{2}/?', urllib.parse.urlsplit(q['final']).path) and q is not home \
           and not re.search(LEGAL_RE, q['url'] + ' ' + q['title'], re.I):
            posts.append(q)

# Datos del titular para las páginas legales (tools/legal.py): del aviso legal de la web antigua, si lo tiene
legal_pages = [p for p in pages.values() if re.search(r'legal|privac|aviso|avis|mentions|imprint|cookies', p['url'] + ' ' + p['title'], re.I)]
legal_txt = '\n'.join(re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', p['html']))) for p in legal_pages)
NIF_RE = r'\b(?:[ABCDEFGHJNPQRSUVW]-?\d{7}-?[0-9A-J]|\d{8}-?[A-Z]|[XYZ]-?\d{7}-?[A-Z])\b'
nifs = collections.Counter(re.sub(r'-', '', m) for m in re.findall(NIF_RE, legal_txt))
names = collections.Counter(re.sub(r'\s+', ' ', m).strip(' ,') for m in re.findall(
    r'([A-ZÀ-Ú0-9][\wÀ-ú&\'.-]*(?:\s+[\wÀ-ú&\'.-]+){0,5}?,?\s+S\.?\s?(?:L\.?\s?U?|A|C\.?P|COOP|Coop)\.?)(?=[\s,;)(]|$)', legal_txt))
legal_found = {'name': names.most_common(1)[0][0] if names else '', 'nif': nifs.most_common(1)[0][0] if nifs else '',
               'email': emails.most_common(1)[0][0] if emails else '', 'phone': phones.most_common(1)[0][0] if phones else '',
               'website': f'{root.scheme}://{root.netloc}'}
if cps: legal_found['address_hint'] = cps.most_common(1)[0][0]
(out / 'legal.json').write_text(json.dumps(legal_found, ensure_ascii=False, indent=4) + '\n')

# Propuesta de redirecciones: la última parte de la ruta, sin extensión, como nombre de página nueva
multi = len([l for l in langs if l != '?']) > 1
main_lang = langs.most_common(1)[0][0] if langs else 'ca'
redir = {}
# El nombre de una página, el de su enlace en el menú (en Joomla, varias páginas comparten título y solo cambia Itemid)
menu_label = {}
for p in pages.values():
    for nav in p['tree'].find_all('nav') + [n for n in p['tree'].iter() if re.search(r'\b(menu|nav|navigation)\b', n.classes())]:
        for n in nav.find_all('a'):
            u, t = norm(n.get('href'), p['final']), clean_text(n.text_content())
            if u and t and len(t) < 40: menu_label.setdefault(u, t)
taken_names = collections.Counter()
for p in pages.values():
    for old in [p['url']] + p.get('alias', []):
        r = rel(old)
        if not r or r in ('index.php', 'index.html'): continue
        u = urllib.parse.urlsplit(old)
        segs = [s for s in u.path.split('/') if s and not re.fullmatch(r'[a-z]{2}', s)]
        name = slugify(re.sub(r'\.(php|html?|aspx?)$', '', segs[-1])) if segs else ''
        if (name in ('index', '') and u.query) or re.fullmatch(r'\d+-.*', name or ''):
            label = menu_label.get(p['url']) or next((menu_label[x] for x in p.get('alias', []) if x in menu_label), '')
            name = slugify(label) if label else (slugify(re.sub(r'\s*[|·–-].*$', '', p['title'])) if p['title'] else name)
        pre = (p['lang'] + '/') if multi else ''
        if p in posts:
            col = next((m.group(1) for m in [re.search(rf'/{BLOGWORDS}/', u.path, re.I)] if m), 'blog').lower()
            dest = f'{pre}{col}/{re.sub(r"^\\d+-", "", name)}'
        elif not name or name in ('index', 'home', 'inici', 'inicio'):
            dest = pre or './'
        else:
            dest = f'{pre}{name}.html'
        redir[r] = dest
# Las URLs de noticias con identificador (Joomla SEF «noticies/77-titol»), a la entrada con ese id aunque el servidor
# antiguo las sirviera mal
by_id = {}
for p in posts:
    if (m := re.search(r'(?:^|&)id=(\d+)[:&]', urllib.parse.urlsplit(p['url']).query + '&')): by_id[m.group(1)] = redir.get(rel(p['url']))
for old in list(redir):
    m = re.match(rf'(?:[a-z]{{2}}/)?{BLOGWORDS}/(\d+)-[^/]+$', old, re.I)
    if m and by_id.get(m.group(2)): redir[old] = by_id[m.group(2)]
# Colecciones: noticias, documentos, galería y vídeos (tools/migrate_collections.py)
sys.path.insert(0, str(Path(__file__).resolve().parent))
import migrate_collections
cols = migrate_collections.run({'pages': pages, 'posts': posts, 'main_lang': main_lang, 'inst': inst, 'fetch': fetch, 'slugify': slugify,
                                'clean_text': clean_text, 'save_image': save_image, 'sin_imagenes': a.sin_imagenes,
                                'main_container': main_container})
# Las entradas y las categorías del blog, a sus direcciones nuevas (con el nombre que les ha dado la migración)
pre = lambda p: (p['lang'] + '/') if multi and p.get('lang') else ''
for url, (col, slug) in cols.pop('news_urls', {}).items():
    q = pages.get(url)
    for old in [url] + (q.get('alias', []) if q else []):
        if rel(old): redir[rel(old)] = f"{pre(q) if q else ''}{col}/{slug}"
# El listado del blog (sin categoría), a la página de la colección
if cols['created'].get('news'):
    for lp in listings:
        if rel(lp['url']) not in redir or '/categoria/' in redir[rel(lp['url'])]: continue
        for old in [lp['url']] + lp.get('alias', []):
            if rel(old) and '/categoria/' not in redir.get(rel(old), ''): redir[rel(old)] = f"{pre(lp)}{cols['names']['news']}/"
for url, (col, path) in cols.pop('news_category_urls', {}).items():
    q = pages.get(url)
    for old in [url] + (q.get('alias', []) if q else []):
        if rel(old): redir[rel(old)] = f"{pre(q) if q else ''}{col}/{path}"
(out / 'redirects.json').write_text(json.dumps(redir, ensure_ascii=False, indent=4) + '\n')

# Los enlaces de los textos a la propia web, a sus direcciones nuevas (los de una página que no se ha migrado se quedan)
def relink(h):
    def one(m):
        u = norm(html.unescape(m.group(1)), a.url)
        if u is None: return m.group(0)
        r = rel(u)
        if r.startswith('?'): r = 'index.php' + r                     # /?option=… es lo mismo que /index.php?option=…
        dest = redir.get(r) if r not in ('', 'index.php', 'index.html') else './'
        return f'href="{html.escape(dest, quote=True)}"' if dest else m.group(0)
    return re.sub(r'href="([^"]+)"', one, h or '')
for f in (inst / 'site' / cols['names']['news']).glob('[a-z0-9]*.json') if (inst / 'site' / cols['names']['news']).is_dir() else []:
    d = json.loads(f.read_text())
    if isinstance(d.get('body'), str) and (nb := relink(d['body'])) != d['body']:
        d['body'] = nb; f.write_text(json.dumps(d, ensure_ascii=False, indent=4) + '\n')

# contenido.json y páginas originales
pages_out = []
for i, p in enumerate(sorted(pages.values(), key=lambda p: (p['lang'], len(p['url']), p['url']))):
    fn = f"{i:03d}-{slugify(rel(p['url']) or 'portada')[:40]}.html"
    (out / 'paginas' / fn).write_text(p['html'])
    pages_out.append({'url': p['url'], 'final': p['final'], 'alias': p.get('alias', []), 'lang': p['lang'], 'title': p['title'],
                      'description': p['description'], 'date': p['date'], 'post': p in posts, 'imagen_og': p.get('og_local'),
                      'html': 'paginas/' + fn, 'blocks': p['blocks'],
                      # El cuerpo limpio (para un bloque richtext): sin el h1, con las imágenes ya descargadas
                      'body_html': relink(migrate_collections.clean_body(mc := main_container(p['tree']), p['final'], save_image,
                                                                         skip={next(iter(mc.find_all('h1')), None)}))})
(out / 'contenido.json').write_text(json.dumps(pages_out, ensure_ascii=False, indent=1) + '\n')
(out / 'comun.json').write_text(json.dumps(common, ensure_ascii=False, indent=1) + '\n')

L = []
w = L.append
w(f'# Migración de {a.url}\n')
w(f"Fecha: {time.strftime('%Y-%m-%d %H:%M')} · CMS: **{cms}**{' (' + gen + ')' if gen else ''} · {len(pages)} páginas · {len(images)} imágenes\n")
if excluded:
    w('URLs no rastreadas: ' + ', '.join(f'{n} por `{pat}`' for pat, n in excluded.most_common()) + ' (`--excluir`).\n')
if js_pages:
    w(f'> **Atención**: {len(js_pages)} páginas tienen muy poco texto en el HTML (el contenido se monta con JavaScript). '
      f'Vuelve a lanzar con `--render`. Ejemplos: ' + ', '.join(rel(u) or '/' for u in js_pages[:5]) + '\n')
w('## Idiomas\n')
for l, c in langs.most_common(): w(f'- `{l}`: {c} páginas')
w(f"\nPrincipal (el más frecuente): `{main_lang}`. {'Web multilingüe: `site/languages.json`.' if multi else 'Un solo idioma.'}\n")
w('## Páginas\n')
w('| Idioma | URL | Título | Bloques | Imágenes |\n|---|---|---|---|---|')
for p in pages_out:
    w(f"| {p['lang']} | `/{rel(p['url'])}`{' (entrada)' if p['post'] else ''} | {p['title'][:60]} | {sum(b['t'] != 'img' for b in p['blocks'])} | {sum(b['t'] == 'img' for b in p['blocks'])} |")
w('\n## Menú de la portada\n')
for t, h in menu[:30]: w(f'- {t} → `{h}`')
w('\n## Contacto\n')
w('- Correos: ' + (', '.join(e for e, _ in emails.most_common(5)) or '—'))
w('- Teléfonos: ' + (', '.join(t for t, _ in phones.most_common(5)) or '—'))
w('- Códigos postales y población: ' + (', '.join(c for c, _ in cps.most_common(5)) or '—'))
w('\n## Redes sociales (→ `data-edit-href="social_<red>"`)\n')
for name, c in social.items(): w(f'- {name}: ' + ', '.join(f'`{h}`' for h, _ in c.most_common(2)))
if not social: w('- Ninguna enlazada.')
w('\n## Servicios de terceros (→ categorías de `cookies.json`)\n')
for name, (cat, hits) in third.items(): w(f"- **{name}** → categoría `{cat}` · en {len(hits)} páginas: " + ', '.join(f'`/{h}`' for h in hits[:4]))
if not third: w('- Ninguno: `categories` vacío (sin faldón).')
w('\n## Formularios (→ `contact.json`, siempre con reCAPTCHA)\n')
for where, action, fields in forms[:10]: w(f'- `/{where}` → `{action}`: ' + '; '.join(fields))
if not forms: w('- Ninguno.')
w('\n## Datos legales (→ `_migracio/legal.json`, los usa `tools/legal.py`)\n')
w(f"- Titular: {legal_found['name'] or 'no encontrado'} · NIF/CIF: {legal_found['nif'] or 'no encontrado'}"
  + (f" (de {len(legal_pages)} páginas legales de la web antigua; verificarlos)" if legal_pages else ' (la web antigua no tiene páginas legales: pedírselos al cliente)'))
w('\n## Diseño\n')
w('- Google Fonts: ' + (', '.join(gfonts) or '—') + ' (alojarlas en `site/assets/fonts/`)')
w('- Tipografías en el CSS: ' + (', '.join(f'{f} ({c})' for f, c in families.most_common(6)) or '—'))
w('- Colores más usados: ' + (' '.join(f'`{c}` ({n})' for c, n in colors.most_common(10)) or '—'))
w(f"- Logo: {('`' + logo + '` → `' + logo_local + '`') if logo_local else 'no encontrado (buscarlo a mano)'}")
w(f"- Favicon: {'`site/favicon.ico` y `site/apple-touch-icon.png` desde `' + fav_src + '`' if fav_img is not None else 'no encontrado: hacerlo desde el logo'}" + (f'. {fav_note}' if fav_note else ''))
w(f'\n## Blog ({len(posts)} entradas)\n')
for p in sorted(posts, key=lambda p: p['date'], reverse=True)[:40]: w(f"- {p['date'] or 'sin fecha'} · [{p['lang']}] {p['title'][:70]} · `/{rel(p['url'])}`")
if not posts: w('- No se han detectado entradas.')
w('\n## Colecciones (→ `site/<colección>/`, con los bloques `news`, `documents`, `albums` y `videos` de compose.py)\n')
cr, cn = cols['report'], cols['names']
if 'news' in cr: w(f"- **Noticias**: {cr['news']['count']} en `site/{cn['news']}/` (cuerpo, fecha y resumen; revisar el formato)")
if cr.get('news_categories'):
    w(f"- **Categorías del blog** (`site/{cn['news']}/_categories.json`, páginas `{cn['news']}/categoria/<id>`): "
      + ', '.join(f'{n} ({c})' for n, c in cr['news_categories'].items()) + '. Las vacías no salen en la lista hasta que tengan entradas.')
if 'docs' in cr: w(f"- **Documentos**: {cr['docs']['count']} en `site/{cn['docs']}/`, ficheros en `site/uploads/docs/`"
                   + (f"; categorías: {', '.join(cr['docs']['categories'])}" if cr['docs']['categories'] else '')
                   + (f"; {cr['docs']['downloads']} descargas de la web antigua en `data/downloads.json`" if cr['docs']['downloads'] else ''))
if 'gallery' in cr: w(f"- **Galería**: {cr['gallery']['albums']} álbumes con {cr['gallery']['images']} fotos en `site/{cn['gallery']}/`")
if 'videos' in cr: w(f"- **Vídeos**: {cr['videos']['count']} en `site/{cn['videos']}/` (YouTube o Vimeo, con consentimiento)")
if 'own_videos' in cr: w('- **Vídeos propios** (en el hosting): ' + ', '.join(f'`{v}`' for v in cr['own_videos'][:5])
                        + ' → subirlos a YouTube o Vimeo y añadirlos en el panel (en el hosting pesan y no se ven bien en el móvil)')
if not cr: w('- Ninguna.')
w('\n## Imágenes\n')
if img_report:
    before, after = sum(r[2] for r in img_report), sum(r[3] for r in img_report)
    kb = lambda k: f'{k / 1024:.1f} MB' if k >= 1024 else f'{k} KB'
    w(f'{len(img_report)} imágenes en `site/assets/img/`: de {kb(before)} a {kb(after)} ({after * 100 // max(before, 1)} %).\n')
    if css_bgs: w('Imágenes de fondo del CSS (cabeceras, banners): ' + ', '.join(f'`{x}`' for x in css_bgs) + '\n')
    small = [r for r in img_report if max(r[1]) < 900 and not r[0].endswith('.svg')]
    if small: w('Pequeñas (menos de 900 px: se verán borrosas a pantalla completa): ' + ', '.join(f'`{r[0]}` {r[1][0]}×{r[1][1]}' for r in small[:15]))
w('\n## Redirecciones\n')
w(f'Propuesta en `_migracio/redirects.json` ({len(redir)} URLs antiguas). Revisar los nombres de página antes de copiarla a `site/redirects.json`.')
if multi: w('En una web multilingüe cada página tiene el **mismo nombre en todos los idiomas** (`/ca/biomassa.html`, `/es/biomassa.html`): '
            'la propuesta usa el título de cada idioma, hay que unificarlos con los del idioma principal.')
w('\n## Pendiente siempre\n')
w('- Diseño, plantilla y textos en `content*.json` (desde `contenido.json`).')
w('- Datos legales del titular (nombre o razón social, NIF) y páginas legales.')
w('- Claves de reCAPTCHA si hay formulario; token de Cosmo; `python3 tools/check.py` antes de entregar.')
(out / 'informe.md').write_text('\n'.join(L) + '\n')
log(f"Hecho: {out / 'informe.md'}")
