#!/usr/bin/env python3
# Comprobación de una web Neutron antes de entregarla o subirla:
#
#   python3 tools/check.py ~/Projects/neutron-cliente [--guia --nom "Nombre" --url https://www.web.com]
#
# Revisa la instalación (núcleo sin tocar, configuración, sitio) y, sobre una copia temporal servida con php -S, todas
# las páginas de todos los idiomas: que respondan, título y descripción, favicon, enlaces internos, redirecciones, URLs
# de la web antigua (_migracio), terceros sin consentimiento y que los dos motores de render den lo mismo.
# ✗ hay que arreglarlo · ! conviene revisarlo · ✓ bien. Sale con código 1 si hay algún ✗.
# Con --guia, si no hay ningún ✗, genera la guía del cliente (docs/guia-edicio/generar.py).
import argparse, hashlib, html, io, json, os, re, shutil, socket, subprocess, sys, tarfile, tempfile, time
import urllib.error, urllib.parse, urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
GOOGLE_TEST_KEY = '6LeIxAcTAAAAAJcZVRqyHh71UMIEGNQ_MXjiZKhI'
SOCIAL = r'instagram\.com|facebook\.com|twitter\.com|x\.com/|youtube\.com|tiktok\.com|linkedin\.com|pinterest\.|tripadvisor\.'
PENDING = re.compile(r'\[(PENDENT|PENDIENTE|PENDING|EN ATTENTE|TODO)[^\]]*\]|lorem ipsum', re.I)

ap = argparse.ArgumentParser(description='Comprueba una web Neutron antes de entregarla')
ap.add_argument('web', help='carpeta de la instalación')
ap.add_argument('--guia', action='store_true', help='si todo está bien, genera la guía del cliente')
ap.add_argument('--nom', help='nombre de la web (para la guía)')
ap.add_argument('--url', help='dirección pública (para la guía)')
ap.add_argument('--rapido', action='store_true', help='sin comprobar los enlaces internos uno a uno')
a = ap.parse_args()
web = Path(a.web).expanduser().resolve()
site = web / 'site'
if not (web / 'index.php').exists() or not site.is_dir():
    sys.exit(f'{web} no parece una instalación de Neutron (falta index.php o site/).')

res = {'✗': [], '!': [], '✓': []}
section = ['']
def report(level, msg):
    res[level].append(f'{section[0]}: {msg}')
    print(f'  {level} {msg}', flush=True)
def head(t):
    section[0] = t
    print(f'\n{t}')

# ---------------------------------------------------------------- núcleo
head('Núcleo')
version = (web / 'VERSION').read_text().strip()
tags = subprocess.run(['git', '-C', str(REPO), 'tag', '--sort=-v:refname', '-l', 'v*'], capture_output=True, text=True).stdout.split()
latest = tags[0].lstrip('v') if tags else version
report('✓' if version == latest else '!', f'versión {version}' + ('' if version == latest else f' (la última es {latest}: actualizar)'))
core = json.loads((web / 'core.json').read_text())['core']
if f'v{version}' in tags:
    arch = subprocess.run(['git', '-C', str(REPO), 'archive', f'v{version}'] + [c.rstrip('/*').rstrip('/') for c in core],
                          capture_output=True, check=True).stdout
    changed, missing = [], []
    with tarfile.open(fileobj=io.BytesIO(arch)) as tf:
        for m in tf.getmembers():
            if not m.isfile(): continue
            f = web / m.name
            if not f.exists(): missing.append(m.name)
            elif hashlib.sha1(f.read_bytes()).digest() != hashlib.sha1(tf.extractfile(m).read()).digest(): changed.append(m.name)
    if changed: report('✗', f'ficheros del núcleo modificados en la instalación (no se podrá actualizar desde Cosmo): {", ".join(changed[:8])}')
    if missing: report('✗', f'faltan ficheros del núcleo: {", ".join(missing[:8])}')
    if not changed and not missing: report('✓', 'el núcleo coincide con la etiqueta')
else:
    report('!', f'no hay etiqueta v{version} en el repo: no se puede comprobar si el núcleo está tocado')

# ---------------------------------------------------------------- configuración
head('Configuración')
cfg = {}
if (web / 'config.local.php').exists():
    out = subprocess.run(['php', '-r', 'define("NEUTRON_APP", 1); echo json_encode(require $argv[1]);', str(web / 'config.local.php')],
                         capture_output=True, text=True)
    cfg = json.loads(out.stdout or '{}')
else:
    report('✗', 'falta config.local.php (tools/new-site.py o php bin/install.php)')
report('✓' if cfg.get('admin_password_hash') else '✗', 'contraseña del editor' + ('' if cfg.get('admin_password_hash') else ': falta admin_password_hash'))
token = (cfg.get('remote') or {}).get('token', '')
report('✓' if len(token) >= 32 else '!', 'token de Cosmo' + ('' if len(token) >= 32 else ': sin token (no se podrá gestionar desde Cosmo)'))
pul = cfg.get('pulsar') or {}
report('✓' if pul.get('url') and pul.get('token') else '!', 'SEO del panel (Pulsar)' + ('' if pul.get('url') and pul.get('token') else ': sin pulsar.url y pulsar.token, el panel no tendrá la sección SEO'))
cred = REPO / 'credencials.local.md'
if cred.exists() and (web.name in cred.read_text() or str(web).replace(str(Path.home()), '~') in cred.read_text()):
    report('✓', 'contraseña apuntada en credencials.local.md')
else:
    report('!', 'la contraseña no está en credencials.local.md')

# ---------------------------------------------------------------- sitio (ficheros)
head('Sitio')
htmls = [site / 'template.html'] + sorted((site / 'pages').glob('*.html')) + sorted((site / 'partials').glob('*.html')) + sorted(site.glob('*/_item.html'))
src_all = '\n'.join(p.read_text(errors='replace') for p in htmls if p.exists())
jsons = sorted(site.glob('*.json')) + sorted(site.glob('*/*.json'))
has_form = 'data-neutron-form' in src_all
langs = json.loads((site / 'languages.json').read_text()) if (site / 'languages.json').exists() else None
codes = list(langs['languages']) if langs else []

rc = cfg.get('recaptcha') or {}
if has_form:
    if rc.get('site_key') == GOOGLE_TEST_KEY: report('✗', 'reCAPTCHA con las claves de prueba de Google (aprueban siempre)')
    elif rc.get('site_key') and rc.get('secret_key'): report('✓', 'formulario con reCAPTCHA')
    else: report('✗', 'el formulario no tiene claves de reCAPTCHA en config.local.php')
    for need, what in [('name="website"', 'el honeypot «website»'), ('data-form-status', '[data-form-status]'), ('action="contact.php"', 'action="contact.php"')]:
        if need not in src_all: report('✗', f'al formulario le falta {what}')
    if not (site / 'contact.json').exists(): report('!', 'formulario sin site/contact.json (campos, obligatorios y textos)')
    for l in codes[1:]:
        if not (site / f'contact.{l}.json').exists(): report('!', f'sin contact.{l}.json: el formulario responderá en el idioma principal')

for f, link in [('favicon.ico', 'rel="icon"'), ('apple-touch-icon.png', 'rel="apple-touch-icon"')]:
    report('✓' if (site / f).exists() else '✗', f'site/{f}' + ('' if (site / f).exists() else ': falta'))
if not (site / 'cookies.json').exists(): report('✗', 'falta site/cookies.json (aunque no haya terceros: categories vacío)')
for l in codes[1:]:
    if not (site / f'cookies.{l}.json').exists(): report('!', f'sin cookies.{l}.json: el faldón saldrá en el idioma principal')
names = ' '.join(p.stem for p in (site / 'pages').glob('*.html'))
for word, what in [('legal|aviso|avis', 'aviso legal'), ('privac', 'política de privacidad'), ('cookie', 'política de cookies')]:
    if not re.search(word, names): report('✗', f'no hay página de {what}')
pend = sorted({m.group(0) for f in htmls + jsons if f.exists() for m in PENDING.finditer(f.read_text(errors='replace'))})
if pend: report('✗', f'textos pendientes: {", ".join(pend[:6])}')
else: report('✓', 'sin textos pendientes ([PENDENT]…)')
# Textos de ejemplo de los bloques (blocks/*/content.*.json) que se han quedado en la web
examples = set()
for f in (REPO / 'blocks').glob('*/content*.json'):
    try: data = json.loads(f.read_text())
    except Exception: continue
    for v in (data.values() if isinstance(data, dict) else []):
        if isinstance(v, str) and len(v) >= 25 and not v.startswith(('site/', 'http')): examples.add(v)
left = sorted({v for f in jsons if f.exists() for v in examples if v in f.read_text(errors='replace')})
if left: report('✗', f'textos de ejemplo de los bloques sin cambiar: {"; ".join(t[:50] for t in left[:4])}')

# Imágenes: las que faltan y las que pesan demasiado
refs = set(re.findall(r'site/(?:assets|uploads)/[A-Za-z0-9_./%-]+\.(?:jpe?g|png|webp|gif|svg|avif|ico)', src_all + ''.join(f.read_text(errors='replace') for f in jsons)))
missing = sorted(r for r in refs if not (web / urllib.parse.unquote(r)).exists())
if missing: report('✗', f'imágenes que no existen: {", ".join(missing[:6])}')
try:
    from PIL import Image
    big = []
    for f in list((site / 'assets').rglob('*')) + list((site / 'uploads').rglob('*')):
        if f.suffix.lower() in ('.jpg', '.jpeg', '.png', '.webp') and f.is_file():
            kb = f.stat().st_size // 1024
            with Image.open(f) as im: side = max(im.size)
            if kb > 600 or side > 2400: big.append(f'{f.relative_to(site)} ({kb} KB, {side} px)')
    if big: report('!', f'imágenes pesadas (más de 600 KB o 2400 px): {", ".join(big[:6])}')
    else: report('✓', 'imágenes de tamaño razonable')
except ImportError:
    pass

# Varios idiomas: claves del idioma principal que no están traducidas
if langs:
    def load(p): return json.loads(p.read_text()) if p.exists() else {}
    main = load(site / f"content.{langs['default']}.json")
    for l in codes:
        if l == langs['default']: continue
        tr = load(site / f'content.{l}.json')
        def needs(k, v):
            if k in tr or k.startswith('social_'): return False
            if isinstance(v, list):                                   # galerías: solo imágenes, comunes
                return any(isinstance(x, str) and not x.startswith('site/') for it in v if isinstance(it, dict) for x in it.values())
            if not isinstance(v, str) or v.startswith(('site/', 'http')) or not re.search(r'[^\W\d_]', v): return False
            return len(v.split()) > 3 or v != main.get('site_name')   # etiquetas cortas iguales en los idiomas («Menú», «Enviar»)
        todo = [k for k, v in main.items() if needs(k, v) and not (isinstance(v, str) and len(v.split()) <= 3)]
        if todo: report('!', f'{l}: {len(todo)} textos sin traducir (se ve el {langs["default"]}): {", ".join(todo[:6])}')
        else: report('✓', f'{l}: todo traducido')

for extra in ['_migracio'] + [p.name for p in web.glob('*.pdf')]:
    if (web / extra).exists(): report('!', f'{extra} en la carpeta de la web: no subirlo al servidor')

# ---------------------------------------------------------------- páginas servidas (copia temporal)
def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); return s.getsockname()[1]
def copy_site(dest, fallback=False):
    # Fuera: data/, la migración y la guía del cliente (los PDF de la raíz); los documentos de site/uploads se quedan
    top = str(web)
    shutil.copytree(web, dest, ignore=lambda d, names: [n for n in names if n in ('data', '_migracio', '.git') or (d == top and n.endswith('.pdf'))])
    (dest / 'data').mkdir()
    if fallback:
        r = dest / 'lib' / 'render.php'
        r.write_text(r.read_text().replace("if (class_exists('DOMDocument')) {", 'if (false) {'))
    port = free_port()
    proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', 'router.php'], cwd=dest, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    return proc, f'http://127.0.0.1:{port}'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *args, **kw): return None
opener = urllib.request.build_opener(NoRedirect)
def get(url, follow=True):
    try:
        with (urllib.request.urlopen if follow else opener.open)(urllib.request.Request(url, headers={'Accept-Language': 'ca'}), timeout=20) as r:
            return r.status, r.headers.get('Location', ''), r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e:
        return e.code, e.headers.get('Location', ''), e.read().decode('utf-8', 'replace') if e.fp else ''
    except Exception as e:
        return 0, '', str(e)

tmp = Path(tempfile.mkdtemp(prefix='neutron-check-'))
procs = []
try:
    p1, B = copy_site(tmp / 'dom'); procs.append(p1)
    p2, F = copy_site(tmp / 'fallback', fallback=True); procs.append(p2)
    time.sleep(1)

    head('Páginas')
    st, _, sm = get(B + '/sitemap.xml')
    urls = [urllib.parse.urlsplit(u).path + ('?' + urllib.parse.urlsplit(u).query if urllib.parse.urlsplit(u).query else '')
            for u in re.findall(r'<loc>([^<]+)</loc>', sm)] if st == 200 else []
    if not urls: report('✗', 'el sitemap.xml no responde o está vacío'); urls = ['/']
    # Páginas que no salen en el sitemap (las legales y las que no tienen enlace)
    for p in (site / 'pages').glob('*.html'):
        if p.stem == '404': continue
        for l in (codes or ['']):
            u = f"/{l + '/' if l else ''}{p.stem}.html"
            if u not in urls and not (p.stem in [c.name for c in site.iterdir() if (c / '_item.html').exists()]): urls.append(u)
    report('✓', f'{len(urls)} páginas')
    pages = {}
    for u in urls:
        st, loc, body = get(B + u)
        if st != 200: report('✗', f'{u} responde {st}'); continue
        pages[u] = body
    st, _, _ = get(B + '/no-existe-' + str(os.getpid()) + '.html')
    report('✓' if st == 404 else '✗', f'una página que no existe responde {st}')

    problems = {'title': [], 'desc': [], 'h1': [], 'icon': [], 'lang': []}
    for u, body in pages.items():
        t = re.search(r'<title[^>]*>(.*?)</title>', body, re.S)
        if not t or not t.group(1).strip(): problems['title'].append(u)
        d = re.search(r'<meta[^>]+name="description"[^>]*content="([^"]*)"', body)
        if (not d or len(d.group(1).strip()) < 30) and not re.search(r'legal|privac|cookie', u): problems['desc'].append(u)
        h1 = len(re.findall(r'<h1\b', body))
        if h1 != 1: problems['h1'].append(f'{u} ({h1})')
        if 'rel="icon"' not in body or 'rel="apple-touch-icon"' not in body: problems['icon'].append(u)
        if not re.search(r'<html[^>]+lang="[a-z]{2}', body): problems['lang'].append(u)
    descs = {}
    for u, body in pages.items():
        d = re.search(r'<meta[^>]+name="description"[^>]*content="([^"]*)"', body)
        if d and d.group(1).strip(): descs.setdefault(html.unescape(d.group(1).strip()), []).append(u)
    # Lo que pone el núcleo para buscadores, redes e IA (lib/meta.php)
    home = next((b for u, b in pages.items() if u.rstrip('/').count('/') <= 1 and not u.endswith('.html')), '')
    ogi = re.search(r'<meta property="og:image" content="([^"]*)"', home)
    logo = re.search(r'<header\b.*?<img\b[^>]*\bsrc="([^"]+)"', home, re.S)
    if not ogi: report('!', 'la portada no tiene imagen para compartir (og:image): poner site/og.jpg (1200×630)')
    elif logo and ogi.group(1).endswith(logo.group(1)): report('!', 'al compartir la portada sale el logo: poner site/og.jpg (1200×630) o una imagen en la portada')
    if 'application/ld+json' not in home: report('!', 'la portada no lleva datos estructurados (JSON-LD)')
    legal_data = json.loads((site / 'legal.json').read_text()) if (site / 'legal.json').exists() else {}
    if not any(legal_data.get(k) for k in ('phone', 'email', 'address')):
        report('!', 'sin teléfono, correo ni dirección en site/legal.json: los datos estructurados y llms.txt saldrán sin contacto (tools/legal.py)')
    for path in ('/robots.txt', '/llms.txt'):
        st, _, body = get(B + path)
        if st != 200 or not body.strip(): report('✗', f'{path} no responde')
    dup = [us for us in descs.values() if len(us) > 1]
    if dup: report('!', f'{sum(len(us) for us in dup)} páginas comparten descripción (Google las ve como repetidas): {", ".join(dup[0][:5])}')
    for k, msg, lvl in [('title', 'sin <title>', '✗'), ('desc', 'sin descripción (o de menos de 30 letras)', '!'), ('h1', 'sin un <h1> o con varios', '!'),
                        ('icon', 'sin los <link> del favicon', '✗'), ('lang', 'sin <html lang>', '✗')]:
        if problems[k]: report(lvl, f'{len(problems[k])} páginas {msg}: {", ".join(problems[k][:5])}')
    if not any(problems.values()): report('✓', 'títulos, descripciones, h1, favicon e idioma en todas')

    # Terceros sin consentimiento y redes sin data-edit-href
    unconsented, socials = set(), set()
    for u, body in pages.items():
        for tag in re.findall(r'<(?:iframe|script)\b[^>]*>', body):
            src = re.search(r'\bsrc="(https?:)?//([^/"]+)', tag)
            if src and 'data-consent' not in tag and '127.0.0.1' not in src.group(2) and 'recaptcha' not in tag:
                unconsented.add(src.group(2))
        for tag in re.findall(r'<a\b[^>]*href="https?://[^"]*(?:' + SOCIAL + r')[^"]*"[^>]*>', body):
            if 'data-edit-href' not in tag and not re.search(r'share|sharer|intent', tag): socials.add(re.search(r'href="([^"]+)"', tag).group(1))
    if unconsented: report('✗', f'servicios de terceros que se cargan sin consentimiento: {", ".join(sorted(unconsented))}')
    else: report('✓', 'ningún tercero se carga sin consentimiento')
    if socials: report('!', f'redes sociales sin data-edit-href (el cliente no las podrá cambiar): {", ".join(sorted(socials)[:4])}')

    # Enlaces internos
    if not a.rapido:
        head('Enlaces')
        seen, broken = {}, []
        for u, body in pages.items():
            base_m = re.search(r'<base href="([^"]+)"', body)
            base = B + (base_m.group(1) if base_m else u)
            for href in set(re.findall(r'<a\b[^>]*?(?<![\w-])href="([^"#][^"]*)"', body)):
                full = urllib.parse.urljoin(base, html.unescape(href))
                if not full.startswith(B): continue
                full = full.split('#')[0]
                if full not in seen: seen[full] = get(full)[0]
                if seen[full] != 200: broken.append(f'{u} → {full[len(B):]} ({seen[full]})')
        if broken: report('✗', f'{len(broken)} enlaces internos rotos: {"; ".join(broken[:6])}')
        else: report('✓', f'{len(seen)} enlaces internos, todos responden')

    head('Redirecciones')
    red = json.loads((site / 'redirects.json').read_text()) if (site / 'redirects.json').exists() else {}
    bad = []
    for old in red:
        if '*' in old: continue
        st, loc, _ = get(B + '/' + old, follow=False)
        if st not in (301, 302): bad.append(f'/{old} ({st})'); continue
        st2, _, _ = get(urllib.parse.urljoin(B + '/', loc))
        if st2 != 200: bad.append(f'/{old} → {loc} ({st2})')
    if bad: report('✗', f'{len(bad)} redirecciones que no llevan a una página: {"; ".join(bad[:6])}')
    elif red: report('✓', f'{len(red)} redirecciones, todas llevan a una página')
    mig = web / '_migracio' / 'contenido.json'
    if mig.exists():
        old_urls = set()
        for p in json.loads(mig.read_text()):
            for u in [p['url']] + p.get('alias', []):
                s = urllib.parse.urlsplit(u)
                old_urls.add((s.path or '/') + ('?' + s.query if s.query else ''))
        lost = [u for u in sorted(old_urls) if get(B + u)[0] == 404]
        if lost: report('✗', f'{len(lost)} URLs de la web antigua dan 404 (añadirlas a redirects.json): {", ".join(lost[:6])}')
        else: report('✓', f'las {len(old_urls)} URLs de la web antigua llevan a una página')
    elif not red:
        report('!', 'sin site/redirects.json: si es una migración, faltan las URLs de la web antigua')

    head('Motores de render')
    def normalise(s):
        s = html.unescape(s)
        s = re.sub(r'"csrf":"\w+"', '', s); s = re.sub(r'127\.0\.0\.1:\d+', 'HOST', s)
        s = re.sub(r'( [\w-]+)=""', r'\1', s); s = re.sub(r'\s*/?>', '>', s); s = re.sub(r'<!doctype html>', '<!DOCTYPE html>', s, flags=re.I)
        return re.sub(r'\s+', ' ', s)
    diff = [u for u in pages if normalise(pages[u]) != normalise(get(F + u)[2])]
    if diff: report('✗', f'el motor sin DOM da otro HTML en {len(diff)} páginas: {", ".join(diff[:5])} (revisar la plantilla)')
    else: report('✓', f'los dos motores dan lo mismo en las {len(pages)} páginas')
    st, _, body = get(B + '/' + (codes[0] + '/' if codes else '') + '?admin')
    report('✓' if 'neutron-config' in body else '✗', 'el editor responde en ?admin' if 'neutron-config' in body else 'el editor no responde en ?admin')
finally:
    for p in procs: p.terminate()
    shutil.rmtree(tmp, ignore_errors=True)

print(f"\nResumen: {len(res['✗'])} por arreglar · {len(res['!'])} por revisar · {len(res['✓'])} bien")
if res['✗'] and a.guia: print('No se genera la guía: primero hay que arreglar lo marcado con ✗.')
elif a.guia:
    if not (a.nom and a.url): sys.exit('Para la guía hacen falta --nom y --url.')
    subprocess.run([sys.executable, str(REPO / 'docs' / 'guia-edicio' / 'generar.py'), str(web), '--nom', a.nom, '--url', a.url])
sys.exit(1 if res['✗'] else 0)
