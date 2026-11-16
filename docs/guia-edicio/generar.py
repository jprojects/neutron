#!/usr/bin/env python3
# Guía de edición en PDF para un cliente, con capturas de su propia web (el texto de la guía es en catalán).
#
#   python3 docs/guia-edicio/generar.py ~/Projects/neutron-pepgallifa --nom "Pep Gallifa" --url https://www.pepgallifa.com
#
# Trabaja sobre una copia temporal de la instalación (con una contraseña de prueba): la web de verdad no se toca.
# Los apartados que la web no tiene (blog, idiomas, formulario, varias páginas, redes sociales) no salen en la guía.
# El PDF se guarda en la carpeta de la web, con la fecha en el nombre (guia-edicio-AAAA-MM-DD.pdf): así queda con la web y no
# se pierde la anterior, y como site/guia-edicio.pdf, que el panel de la web enlaza junto a la versión (solo con la sesión
# abierta) y se publica con la web. Lleva la cabecera, el pie y la portada de AFI (docs/marca-afi).
# Al lado de cada PDF para el panel se escribe su .json (nombre, dirección, fecha y huella de la estructura de la web):
# con él la web dice a Cosmo si la guía se ha quedado vieja (lib/guide.php), y el MCP de Cosmo (cosmo_guia) la regenera.
# Hace falta Node 22, Chromium (tools/cdp.mjs), PIL y pdfunite (poppler-utils).
import argparse, datetime, hashlib, html, json, os, re, shutil, socket, subprocess, sys, tempfile, time
from pathlib import Path
from PIL import Image

AQUI = Path(__file__).resolve().parent
REPO = AQUI.parent.parent
CDP = REPO / 'tools' / 'cdp.mjs'
sys.path.insert(0, str(AQUI.parent / 'marca-afi'))
import marca                                               # cabecera, pie y portada con la marca de AFI
IDIOMES = {'ca': 'català', 'es': 'castellà', 'en': 'anglès', 'fr': 'francès', 'de': 'alemany', 'it': 'italià', 'pt': 'portuguès'}
MESOS = ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre']
PROVA = 'guia-prova-1234'

ap = argparse.ArgumentParser(description='Guía de edición en PDF de una web Neutron')
ap.add_argument('web', help='carpeta de la instalación (p. ej. ~/Projects/neutron-pepgallifa)')
ap.add_argument('--nom', required=True, help='nombre de la web para la portada')
ap.add_argument('--url', required=True, help='adreça pública, p. ex. https://www.pepgallifa.com')
ap.add_argument('--sortida', help='PDF de salida (por defecto <web>/guia-edicio-<fecha>.pdf)')
# Lo que depende de la configuración del servidor (config.local.php): por defecto, el de la carpeta; el MCP de Cosmo lo pasa
# con lo que dice el estado de la web, porque la copia de site/ no lo lleva
ap.add_argument('--seo', choices=['si', 'no'], help='la web tiene el análisis SEO de Pulsar en el panel')
ap.add_argument('--ia', choices=['si', 'no'], help='la web tiene la IA del panel activada')
a = ap.parse_args()
web = Path(a.web).expanduser().resolve()
slug = web.name
sortida = Path(a.sortida).expanduser() if a.sortida else web / f'guia-edicio-{datetime.date.today().isoformat()}.pdf'
treball = Path.home() / 'cdp-tmp' / f'guia-{slug}'          # Chromium es un snap: solo ve la carpeta personal
shutil.rmtree(treball, ignore_errors=True); treball.mkdir(parents=True)

# ---------------------------------------------------------------- qué tiene la web
site = web / 'site'

def huella(site):
    # La misma cuenta que neutron_guide_fingerprint() (lib/guide.php): la estructura de la web, no los textos
    fs = [site / 'template.html', site / 'languages.json'] + list((site / 'pages').glob('*.html')) + list((site / 'partials').glob('*.html')) \
        + list(site.glob('*/_item.html'))
    rows = sorted((str(f.relative_to(site)), hashlib.sha1(f.read_bytes()).hexdigest()) for f in fs if f.is_file())
    return hashlib.sha1(''.join(f'{p}\0{h}\n' for p, h in rows).encode()).hexdigest()
# La edición de la guía (NEUTRON_GUIDE_EDITION en lib/guide.php de este repositorio): la web avisa si su guía es de una anterior
edicio = int(re.search(r'NEUTRON_GUIDE_EDITION\s*=\s*(\d+)', (REPO / 'lib' / 'guide.php').read_text()).group(1))
meta = {'nom': a.nom, 'url': a.url, 'generated': datetime.datetime.now().astimezone().isoformat(timespec='seconds'), 'fingerprint': huella(site),
        'edition': edicio}
htmls = [site / 'template.html'] + sorted((site / 'pages').glob('*.html')) + sorted((site / 'partials').glob('*.html'))
tot = '\n'.join(p.read_text(errors='replace') for p in htmls if p.exists())
legals = {'404', 'avis-legal', 'politica-de-privacitat', 'politica-de-cookies', 'aviso-legal', 'politica-de-privacidad'}
colleccions = [d.name for d in site.iterdir() if d.is_dir() and (d / '_item.html').exists()]
pagines = [p.stem for p in (site / 'pages').glob('*.html') if p.stem not in legals and p.stem not in colleccions]
langs = json.loads((site / 'languages.json').read_text()) if (site / 'languages.json').exists() else None
te = {
    'blog': bool(colleccions),
    'langs': bool(langs and len(langs.get('languages', {})) > 1),
    'form': 'data-neutron-form' in tot,
    'pages': len(pagines) >= 1,
    'social': 'data-edit-href' in tot,
}
# Configuración de la instalación (si la carpeta la tiene): SEO, IA e idioma del panel
cfg_local = {}
if (web / 'config.local.php').exists():
    r = subprocess.run(['php', '-r', 'define("NEUTRON_APP", 1); echo json_encode(require $argv[1]);', str(web / 'config.local.php')], capture_output=True, text=True)
    try: cfg_local = json.loads(r.stdout) or {}
    except ValueError: cfg_local = {}
pulsar, ai = cfg_local.get('pulsar') or {}, cfg_local.get('ai') or {}
te['seo'] = a.seo == 'si' if a.seo else bool(str(pulsar.get('url', '')).strip() and str(pulsar.get('token', '')).strip())
te['ai'] = a.ia == 'si' if a.ia else bool(ai.get('enabled') and str(ai.get('api_key', '')).strip())
te['menu'] = 'data-menu' in tot
# Idioma del panel y de la barra del editor, como neutron_panel_lang() (lib/panel_lang.php): los botones de la guía, en ese idioma
plang = str((cfg_local.get('panel') or {}).get('lang') or (langs or {}).get('default') or '')
if not plang:
    m = re.search(r'<html[^>]*\blang="([A-Za-z]{2})', (site / 'template.html').read_text(errors='replace')[:4096])
    plang = m.group(1) if m else ''
plang = plang[:2].lower() if plang[:2].lower() in ('es', 'ca') else 'es'
print('La web tiene:', ', '.join(k for k, v in te.items() if v) or 'solo lo básico', '· panel en', plang)

# ---------------------------------------------------------------- copia temporal y servidor
tmp = Path(tempfile.mkdtemp(prefix='neutron-guia-'))
copia = tmp / 'web'
shutil.copytree(web, copia, ignore=shutil.ignore_patterns('data', '.git'))
(copia / 'data').mkdir()
# El núcleo de este repositorio (core.json) encima del de la web: la guía explica el editor y el panel de la versión actual
for pat in json.loads((REPO / 'core.json').read_text())['core']:
    src = REPO / pat.rstrip('/*')
    if pat.startswith('data/'): continue
    if pat.endswith('/**') and src.is_dir(): shutil.copytree(src, copia / pat.rstrip('/*'), dirs_exist_ok=True)
    elif src.is_file(): shutil.copy(src, copia / pat)
if (web / 'data' / '.htaccess').exists(): shutil.copy(web / 'data' / '.htaccess', copia / 'data' / '.htaccess')
hash_ = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', PROVA], capture_output=True, text=True, check=True).stdout
# Con SEO o IA, una configuración de mentira para que el panel enseñe sus apartados (no se llama a Pulsar ni a la IA)
extra = ("'pulsar' => ['url' => 'https://pulsar.invalid', 'token' => 'guia'], " if te['seo'] else '') + \
        ("'ai' => ['enabled' => true, 'api_key' => 'guia'], " if te['ai'] else '')
(copia / 'config.local.php').write_text("<?php\nif (!defined('NEUTRON_APP')) { http_response_code(403); exit; }\n"
                                        f"return ['admin_password_hash' => '{hash_}', 'panel' => ['lang' => '{plang}'], {extra}];\n")
# Para las capturas del panel: una guía (el enlace del inicio) y, si hay formulario, un mensaje de ejemplo
if not (copia / 'site' / 'guia-edicio.pdf').exists(): (copia / 'site' / 'guia-edicio.pdf').write_bytes(b'%PDF-1.4\n')
if te['form']:
    (copia / 'data' / 'messages').mkdir(parents=True)
    ara = datetime.datetime.now().astimezone()
    (copia / 'data' / 'messages' / (ara.strftime('%Y%m%d-%H%M%S') + '-000001.json')).write_text(json.dumps({
        'id': ara.strftime('%Y%m%d-%H%M%S') + '-000001', 'at': ara.isoformat(timespec='seconds'), 'lang': None, 'page': '',
        'fields': [{'name': 'name', 'label': 'Nom', 'value': 'Marta Puig', 'type': 'text'},
                   {'name': 'email', 'label': 'Correu', 'value': 'marta@exemple.cat', 'type': 'email'},
                   {'name': 'message', 'label': 'Missatge', 'value': 'Hola! Voldria més informació. Gràcies!', 'type': 'textarea'}],
        'read': False, 'mailed': True}, ensure_ascii=False))
with socket.socket() as s:
    s.bind(('127.0.0.1', 0)); port = s.getsockname()[1]
base = f'http://127.0.0.1:{port}'
srv = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', 'router.php'], cwd=copia, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
time.sleep(1)

prefix = (langs['default'] + '/') if te['langs'] else ''
cols = json.dumps(colleccions)
rects = treball / 'rects.json'
pasos = treball / 'pasos.mjs'
pasos.write_text('''
import { writeFileSync } from 'node:fs';
const B = %(base)s, P = %(prefix)s, COLS = %(cols)s, PW = %(pw)s, OUT = %(out)s;
const R = {};
// Rectángulo de un elemento en la pantalla (coordenadas del viewport) en el momento de la captura
const rect = sel => `(() => { const e = ${sel}; if (!e) return null; const r = e.getBoundingClientRect(); return [r.x, r.y, r.width, r.height]; })()`;
const quiet = "document.documentElement.style.scrollBehavior='auto'; document.querySelectorAll('.nc-banner, [class*=neutron-cookie]').forEach(e => e.remove())";
const NAV = labels => `[...document.querySelectorAll('.np-nav-item')].find(b => ${labels}.includes(b.firstChild.textContent))`;
async function snap(c, name, sel) { await c.sleep(400); R[name] = await c.evalJs(rect(sel)); await c.shot('guia/' + name); }
export default [
  ['login', async c => { await c.go(B + '/' + P + '?admin'); await c.evalJs(quiet); await snap(c, 'login', "document.querySelector('.neutron-login-box')"); return R.login; }],
  ['entrar', async c => {
    await c.evalJs(`(() => { const f = document.querySelector('.neutron-login-box'); f.password.value = '${PW}'; f.requestSubmit(); })()`); await c.sleep(2500);
    await c.evalJs(quiet); await snap(c, 'portada', "document.body"); return !!(await c.evalJs("!!document.querySelector('.neutron-bar')")); }],
  ['text', async c => {
    const sel = "(document.querySelector('h1[data-edit]') || [...document.querySelectorAll('[data-edit]')].find(e => e.getBoundingClientRect().top > 70 && e.offsetHeight > 20))";
    await c.evalJs(`(() => { const el = ${sel}; el.scrollIntoView({block: 'center'}); el.focus(); const r = document.createRange(); r.selectNodeContents(el); r.collapse(false);
      const s = getSelection(); s.removeAllRanges(); s.addRange(r); el.dispatchEvent(new Event('input', {bubbles: true})); })()`);
    await snap(c, 'text', `(${sel}).closest('section, header, .hero') || ${sel}`); return R.text; }],
  ['galeria', async c => {
    const sel = "[...document.querySelectorAll('[data-edit-collection]')].find(e => e.querySelector('[data-edit-item] img'))";
    if (await c.evalJs(`!!${sel}`)) { R.galeria_llista = true; await c.evalJs(`(${sel}).scrollIntoView({block: 'center'})`); await snap(c, 'galeria', sel); return R.galeria; }
    // Sin ninguna lista con fotos: el grupo de imágenes sueltas (data-edit-img) más abajo de la cabecera
    const img = "[...document.querySelectorAll('[data-edit-img]')].find(e => e.getBoundingClientRect().top + scrollY > innerHeight * .8)";
    if (!await c.evalJs(`!!${img}`)) return null;
    await c.evalJs(`(${img}).scrollIntoView({block: 'center'})`);
    await snap(c, 'galeria', `(() => { const e = ${img}; const p = e.closest('ul, .gallery, figure, section'); return p || e; })()`); return R.galeria; }],
  ['llista', async c => {
    const sel = "[...document.querySelectorAll('[data-edit-collection]')].find(e => !e.querySelector('img') && e.querySelectorAll('[data-edit-item]').length > 1 && !e.closest('[data-edit-item]'))";
    if (!await c.evalJs(`!!${sel}`)) return null;
    await c.evalJs(`(${sel}).scrollIntoView({block: 'center'})`); await snap(c, 'llista', `(() => { const e = ${sel}; return e.nextElementSibling && e.nextElementSibling.classList.contains('neutron-add-item') ? e.parentElement : e; })()`); return R.llista; }],
  ['xarxes', async c => {
    const sel = "document.querySelector('[data-edit-href]')";
    if (!await c.evalJs(`!!${sel}`)) return null;
    await c.evalJs(`(${sel}).scrollIntoView({block: 'center'})`);
    await snap(c, 'xarxes', `(${sel}).parentElement.parentElement`);
    R.buides = await c.evalJs("document.querySelectorAll('.neutron-href-empty').length");
    await c.evalJs(`(${sel}).click()`); await snap(c, 'enllac', "document.querySelector('.neutron-dialog')");
    await c.evalJs("document.querySelector('[data-dialog=cancel]').click()"); return R.xarxes; }],
  ['desar', async c => { await c.evalJs("document.querySelector('[data-action=save]').click()"); await c.sleep(1500); await c.evalJs('window.scrollTo(0, 0)');
    await snap(c, 'guardat', "document.querySelector('.neutron-status')"); return c.evalJs("document.querySelector('.neutron-status').textContent"); }],
  ['blog', async c => {
    if (!COLS.length) return null;
    await c.go(B + '/' + P + COLS[0] + '/?admin'); await c.evalJs(quiet);
    await c.evalJs("document.querySelector('.neutron-new-post').click()"); await c.sleep(300);
    await c.evalJs("document.querySelector('.neutron-dialog input').value = 'La nostra primera entrada'");
    await snap(c, 'nova', "document.querySelector('.neutron-dialog')");
    const link = await c.evalJs("(document.querySelector('[data-post-link]') || {}).href || ''");
    if (!link) return 'sense entrades';
    await c.go(link + '?admin'); await c.evalJs(quiet); await snap(c, 'entrada', 'document.body');
    if (await c.evalJs("!!document.querySelector('.neutron-rich-tools')")) {
      await c.evalJs("document.querySelector('.neutron-rich-tools').scrollIntoView({block: 'center'})");
      await snap(c, 'cos', "document.querySelector('.neutron-rich-tools')");
    }
    return link; }],
  // El panel (/admin/), con la misma sesión. Los apartados se buscan por su texto en castellano o en catalán
  ['tauler', async c => {
    await c.go(B + '/admin/'); if (!await c.waitFor('.np-version a', 10000)) return null;
    await c.sleep(600); await snap(c, 'tauler', "document.querySelector('.np-app')");
    R.nav = await c.evalJs("[...document.querySelectorAll('.np-nav-item')].map(b => b.firstChild.textContent)");
    R.guia = await c.evalJs("document.querySelector('.np-version a').textContent"); return R.nav; }],
  ['contingut', async c => {
    if (!R.tauler) return null;
    await c.evalJs(NAV("['Contenido', 'Contingut']") + '.click()'); if (!await c.waitFor('.np-main .np-field', 10000)) return null;
    await c.sleep(500);
    // La primera secció amb uns quants camps (no «Dades de la pàgina»)
    const sec = "[...document.querySelectorAll('.np-main .np-card')].find(s => s.querySelectorAll('.np-field').length >= 3) || document.querySelector('.np-main .np-card')";
    await c.evalJs(`(${sec}).scrollIntoView({block: 'start'}); window.scrollBy(0, -20)`); await c.sleep(300);
    await snap(c, 'contingut', sec);
    const ai = `((${sec}).querySelector('.np-ai') || document.querySelector('.np-main .np-ai'))`;
    if (await c.evalJs(`!!${ai}`)) {
      await c.evalJs(`(${ai}).scrollIntoView({block: 'center'}); (${ai}).click()`); await c.sleep(400);
      await snap(c, 'ia', `(${ai}).closest('.np-field')`);
    }
    return R.contingut; }],
  ['missatges', async c => {
    if (!R.tauler || !await c.evalJs(`!!${NAV("['Mensajes', 'Missatges']")}`)) return null;
    await c.evalJs(NAV("['Mensajes', 'Missatges']") + '.click()'); if (!await c.waitFor('.np-msg', 8000)) return null;
    await c.evalJs("document.querySelector('.np-msg').click()"); await c.sleep(800);
    await snap(c, 'missatges', "document.querySelector('.np-msg-wrap') || document.querySelector('.np-main')"); return R.missatges; }],
  ['fi', async () => { writeFileSync(OUT, JSON.stringify(R)); return Object.keys(R).length; }],
];
''' % {'base': json.dumps(base), 'prefix': json.dumps(prefix), 'cols': cols, 'pw': json.dumps(PROVA), 'out': json.dumps(str(rects))})
(Path.home() / 'cdp-tmp' / 'guia').mkdir(parents=True, exist_ok=True)
try:
    r = subprocess.run(['node', str(CDP), base + '/', str(pasos)], env={**os.environ, 'CDP_SIZE': '1280,800'}, capture_output=True, text=True, timeout=300)
    print(r.stdout.strip()); r.returncode and print(r.stderr[-2000:], file=sys.stderr)
finally:
    srv.terminate(); srv.wait()
    shutil.rmtree(tmp, ignore_errors=True)
R = json.loads(rects.read_text())

# ---------------------------------------------------------------- recortes (las capturas son a 2x)
cap = Path.home() / 'cdp-tmp' / 'guia'
def retall(nom, sortida, marge=24, minw=0, maxh=None, sencer=False, alt=None, dreta=None):
    if not R.get(nom) and not sencer: return False
    im = Image.open(cap / f'{nom}.png').convert('RGB')
    W, H = im.width // 2, im.height // 2
    if sencer:
        x, y, w, h = 0, 0, W, (maxh or H)
    else:
        x, y, w, h = R[nom]
        if alt: h = alt                      # desde arriba del elemento, esta altura (la barra de formato y el texto de debajo)
        if dreta: x, y, w = W - dreta, 0, dreta    # el trozo de la derecha de la barra (los avisos)
        if w < minw: x -= (minw - w) / 2; w = minw
        x, y, w, h = x - marge, y - marge, w + 2 * marge, h + 2 * marge
        if y < 0: h += y; y = 0              # un elemento más alto que la pantalla (una lista larga): desde arriba de lo que se ve
        if maxh and h > maxh: h = maxh
    x0, y0 = max(0, int(x)), max(0, int(y)); x1, y1 = min(W, int(x + w)), min(H, int(y + h))
    if x1 <= x0 or y1 <= y0: return False
    out = im.crop((x0 * 2, y0 * 2, x1 * 2, y1 * 2))
    if out.width > 1800: out = out.resize((1800, round(out.height * 1800 / out.width)), Image.LANCZOS)
    out.save(treball / sortida, quality=86, optimize=True)
    return True
fets = {
    'portada': retall('portada', 'c-portada.jpg', sencer=True, maxh=650),
    'login': retall('login', 'c-login.jpg', marge=110),
    'barra': retall('portada', 'c-barra.jpg', sencer=True, maxh=120),
    'text': retall('text', 'c-text.jpg', marge=10, maxh=560),
    'shot-galeria': retall('galeria', 'c-galeria.jpg', marge=16, maxh=520),
    'shot-llista': retall('llista', 'c-llista.jpg', marge=16, maxh=560),
    'xarxes': retall('xarxes', 'c-xarxes.jpg', marge=30, minw=520),
    'enllac': retall('enllac', 'c-enllac.jpg', marge=30),
    'guardat': retall('guardat', 'c-guardat.jpg', marge=0, dreta=620, alt=51) if R.get('guardat') else False,
    'nova': retall('nova', 'c-nova.jpg', marge=30),
    'entrada': retall('entrada', 'c-entrada.jpg', sencer=True, maxh=760) if R.get('entrada') else False,
    'shot-cos': retall('cos', 'c-cos.jpg', marge=16, alt=300),
    'shot-tauler': retall('tauler', 'c-tauler.jpg', marge=0, maxh=760),
    'shot-contingut': retall('contingut', 'c-contingut.jpg', marge=12, maxh=640),
    'shot-ia': retall('ia', 'c-ia.jpg', marge=14, alt=150),   # el campo y el menú que se abre debajo
    'shot-missatges': retall('missatges', 'c-missatges.jpg', marge=0, maxh=560),
}

# ---------------------------------------------------------------- plantilla de la guía
t = (AQUI / 'guia.template.html').read_text()
cond = {**te, 'blog': te['blog'] and fets['nova'], 'social': te['social'] and fets['xarxes'], 'shot-galeria': fets['shot-galeria'],
        'shot-llista': fets['shot-llista'], 'shot-cos': fets['shot-cos'], 'nomenu': not te['menu'], 'panel-es': plang == 'es',
        'shot-tauler': fets['shot-tauler'], 'shot-contingut': fets['shot-contingut'], 'shot-ia': te['ai'] and fets['shot-ia'],
        'shot-missatges': te['form'] and fets['shot-missatges']}
if not R.get('tauler'): print('Aviso: no se ha podido capturar el panel (/admin/)', file=sys.stderr)
for k, v in cond.items():
    t = re.sub(rf'<!--if:{re.escape(k)}-->(.*?)<!--endif:{re.escape(k)}-->', (lambda m: m.group(1)) if v else '', t, flags=re.S)
if not fets['entrada']:
    t = re.sub(r'\s*<h3>Escriure-la</h3>\s*<figure class="mid"><img src="c-entrada.jpg".*?</figure>', '', t, flags=re.S)
assert '<!--if:' not in t, re.findall(r'<!--if:[^>]*>', t)
noms = [IDIOMES.get(c, c) for c in (langs or {}).get('languages', {})]
idiomes = ', '.join(noms[:-1]) + ' i ' + noms[-1] if len(noms) > 1 else ''
avui = datetime.date.today()
t = (t.replace('{{NOM}}', html.escape(a.nom)).replace('{{ADMIN_URL}}', html.escape(a.url.rstrip('/') + '/?admin'))
      .replace('{{URL_CURTA}}', html.escape(re.sub(r'^https?://(www\.)?', '', a.url).rstrip('/')))
      .replace('{{DATA}}', f'{MESOS[avui.month - 1]} de {avui.year}')
      .replace('{{IDIOMES}}', idiomes).replace('{{IDIOMA_PRINCIPAL}}', IDIOMES.get((langs or {}).get('default', ''), ''))
      .replace('{{CAPCIO_GALERIA}}', 'Una llista amb fotos: cada foto es canvia fent-hi clic, i la llista té els botons ↑ i ✕.' if R.get('galeria_llista')
               else 'Les fotos que es poden canviar tenen una vora de punts: feu-hi clic i trieu-ne una altra.')
      .replace('{{CAPCIO_XARXES}}', 'Les xarxes del peu de la web. Les atenuades no hi surten fins que no tenen adreça.' if R.get('buides')
               else 'Les xarxes del peu de la web: feu clic en una icona per canviar-ne l\'adreça.'))
# Una fila por colección en la tabla del panel: los apartados del menú del panel que no son los de siempre
fixos = {'Inicio', 'Inici', 'Contenido', 'Contingut', 'Menú', 'Mensajes', 'Missatges', 'Copias', 'Còpies', 'Chat', 'Xat', 'SEO'}
files = ''.join(f'    <tr><td><span class="ui">{html.escape(n)}</span></td><td>Els elements de «{html.escape(n)}» en fitxes: crear-ne, editar-los amb tots els seus camps, '
                'ordenar-los i esborrar-los.</td></tr>\n' for n in (R.get('nav') or []) if n not in fixos)
t = t.replace('{{FILES_COLLECCIONS}}', files).replace('{{PANEL_URL}}', html.escape(a.url.rstrip('/') + '/admin/')) \
     .replace('{{GUIA_ENLLAC}}', html.escape(R.get('guia') or 'Guía de edición (PDF)'))
# Los botones y apartados, como los verá en la pantalla: en el idioma del panel (assets/i18n/<idioma>.json, el del núcleo)
if plang != 'es':
    tr = json.loads((REPO / 'assets' / 'i18n' / f'{plang}.json').read_text())
    def tradueix(m):
        s = html.unescape(m.group(2)); return m.group(1) + html.escape(tr.get(s, s), quote=False) + m.group(3)
    t = re.sub(r'(<span class="(?:btn[^"]*|ui|k)"[^>]*>)([^<]+)(</span>)', tradueix, t)
    t = re.sub(r'(«)([^»<]+)(»)', lambda m: m.group(1) + tr.get(m.group(2), m.group(2)) + m.group(3), t)
index = ''.join(f'\n    <li>{html.escape(re.sub("<[^>]+>", "", m))}</li>' for m in re.findall(r'<h2 class="num"[^>]*>(.*?)</h2>', t))
t = t.replace('{{INDEX}}', index + '\n  ')
t = t.replace('{{MARGE}}', marca.MARGE_CSS).replace('{{AFI_CSS}}', marca.PORTADA_CSS).replace('{{AFI_PORTADA}}', marca.portada_html('afi-logo.png'))
assert '{{' not in t, re.findall(r'\{\{\w+\}\}', t)
shutil.copy(marca.LOGO, treball / 'afi-logo.png')
(treball / 'guia.html').write_text(t)
for f in AQUI.glob('*.woff2'): shutil.copy(f, treball / f.name)

pdf = treball / 'pasos-pdf.mjs'
# La portada se imprime aparte, sin la cabecera ni el pie de AFI (Chromium los pintaría encima aunque tenga margen 0), y se une
# con el resto (pdfunite, de poppler-utils).
pdf.write_text('''import { writeFileSync } from 'node:fs';
const print = async (send, params, out) => {
  const r = await send('Page.printToPDF', { printBackground: true, preferCSSPageSize: true, ...params });
  if (!r.result) throw new Error(JSON.stringify(r)); writeFileSync(out, Buffer.from(r.result.data, 'base64')); };
export default [['pdf', async ({ send, sleep }) => { await sleep(1500);
  await print(send, { pageRanges: '1' }, %s);
  await print(send, { pageRanges: '2-', ...%s }, %s); return 'ok'; }]];
''' % (json.dumps(str(treball / 'portada.pdf')), json.dumps(marca.parametres_pdf()), json.dumps(str(treball / 'interior.pdf'))))
subprocess.run(['node', str(CDP), 'file://' + str(treball / 'guia.html'), str(pdf)], check=True, capture_output=True, timeout=120)
subprocess.run(['pdfunite', str(treball / 'portada.pdf'), str(treball / 'interior.pdf'), str(treball / 'guia.pdf')], check=True)
sortida.parent.mkdir(parents=True, exist_ok=True)
shutil.copy(treball / 'guia.pdf', sortida)
if not a.sortida:   # la que enlaza el panel de la web (solo con la sesión abierta; lib/guide.php): va con la web al publicarla
    shutil.copy(treball / 'guia.pdf', site / 'guia-edicio.pdf')
    (site / 'guia-edicio.json').write_text(json.dumps(meta, ensure_ascii=False, indent=2) + '\n')
    print('Panel:', site / 'guia-edicio.pdf')
else:               # p. ej. el MCP de Cosmo, que la sube con su .json
    sortida.with_suffix('.json').write_text(json.dumps(meta, ensure_ascii=False, indent=2) + '\n')
shutil.rmtree(cap, ignore_errors=True)
print('PDF:', sortida)
