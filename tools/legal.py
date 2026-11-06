#!/usr/bin/env python3
# Páginas legales de una web Neutron en todos sus idiomas:
#
#   python3 tools/legal.py ~/Projects/neutron-cliente [--pulsar ~/Projects/pulsar] [--force]
#
# - Política de privacidad y de cookies: las genera Pulsar (inc/LegalGenerator.php), con lo que tiene la web
#   (formulario → contacto y reCAPTCHA; cookies.json → mapas, vídeos, analítica; redes sociales).
# - Aviso legal (LSSI-CE): lo hace esta herramienta con los mismos datos.
# - Datos del titular: site/legal.json (no se sirve: el .htaccess bloquea site/*.json). Si no existe, se crea con lo que
#   ya se sabe (nombre comercial, dirección, correo, teléfono) y lo que falta queda como «[PENDENT: …]», que
#   tools/check.py no deja entregar. Al tener los datos: rellenar legal.json y volver a lanzar.
# Las páginas son site/pages/{avis-legal,politica-de-privacitat,politica-de-cookies}.html con un campo data-edit-html por
# idioma (legal_*_html en content.<idioma>.json). Un texto que se ha editado a mano no se vuelve a escribir (salvo --force).
import argparse, hashlib, html, json, re, subprocess, sys, tempfile
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
PAGES = [('avis-legal', 'legal_notice'), ('politica-de-privacitat', 'legal_privacy'), ('politica-de-cookies', 'legal_cookies')]
TITLES = {'legal_notice': {'ca': 'Avís legal', 'es': 'Aviso legal', 'en': 'Legal notice', 'fr': 'Mentions légales'},
          'legal_privacy': {'ca': 'Política de privacitat', 'es': 'Política de privacidad', 'en': 'Privacy policy', 'fr': 'Politique de confidentialité'},
          'legal_cookies': {'ca': 'Política de cookies', 'es': 'Política de cookies', 'en': 'Cookie policy', 'fr': 'Politique de cookies'}}
PENDING = {'ca': '[PENDENT: {}]', 'es': '[PENDIENTE: {}]', 'en': '[PENDING: {}]', 'fr': '[EN ATTENTE : {}]'}
FIELD = {'name': {'ca': 'nom i cognoms o raó social', 'es': 'nombre y apellidos o razón social', 'en': 'full name or company name', 'fr': 'nom ou raison sociale'},
         'nif': {'ca': 'NIF', 'es': 'NIF', 'en': 'tax ID', 'fr': 'NIF'},
         'address': {'ca': 'domicili', 'es': 'domicilio', 'en': 'address', 'fr': 'adresse'},
         'email': {'ca': 'correu', 'es': 'correo', 'en': 'email', 'fr': 'e-mail'},
         'website': {'ca': 'adreça del web', 'es': 'dirección de la web', 'en': 'website address', 'fr': 'adresse du site'}}
ADMIN_COOKIE = {'ca': "neutron_admin_sid | | Sessió de l'editor de continguts (només l'administrador del web) | Sessió | Tècnica",
                'es': 'neutron_admin_sid | | Sesión del editor de contenidos (solo el administrador de la web) | Sesión | Técnica',
                'en': 'neutron_admin_sid | | Content editor session (website administrator only) | Session | Technical',
                'fr': "neutron_admin_sid | | Session de l'éditeur de contenu (administrateur du site uniquement) | Session | Technique"}
# Aviso legal (LSSI-CE): {owner} = bloque con los datos del titular, {site} = dominio
NOTICE = {
 'ca': [('h1', 'Avís legal'), ('h2', '1. Identificació del titular'), ('p', "En compliment de l'article 10 de la Llei 34/2002, d'11 de juliol, de serveis de la societat de la informació i de comerç electrònic (LSSI-CE), s'informa que el titular del lloc web {site} és:"), ('owner', ''),
        ('h2', '2. Objecte'), ('p', "Aquest lloc web presenta l'activitat i els serveis del titular i permet contactar-hi. L'accés al web atribueix la condició d'usuari i implica l'acceptació d'aquest avís legal."),
        ('h2', "3. Condicions d'ús"), ('p', "L'usuari es compromet a fer un ús adequat dels continguts del web i a no emprar-los per a activitats il·lícites o contràries a la bona fe i a l'ordre públic."),
        ('h2', '4. Propietat intel·lectual i industrial'), ('p', "Els textos, les imatges, el logotip i el disseny del web són propietat del titular o disposa de llicència per utilitzar-los. Queda prohibida la reproducció total o parcial dels continguts sense autorització expressa."),
        ('h2', '5. Responsabilitat'), ('p', "El titular no es fa responsable dels danys que es puguin derivar d'interrupcions del servei, errors tècnics o de l'ús que es faci de la informació publicada, i es reserva el dret de modificar els continguts del web sense avís previ. Els enllaços a webs de tercers es faciliten a títol informatiu i el titular no en controla el contingut."),
        ('h2', '6. Protecció de dades i cookies'), ('p', "El tractament de les dades personals es descriu a la {privacy}, i l'ús de cookies a la {cookies}."),
        ('h2', '7. Legislació aplicable'), ('p', "Aquest avís legal es regeix per la legislació espanyola.")],
 'es': [('h1', 'Aviso legal'), ('h2', '1. Identificación del titular'), ('p', 'En cumplimiento del artículo 10 de la Ley 34/2002, de 11 de julio, de servicios de la sociedad de la información y de comercio electrónico (LSSI-CE), se informa de que el titular del sitio web {site} es:'), ('owner', ''),
        ('h2', '2. Objeto'), ('p', 'Este sitio web presenta la actividad y los servicios del titular y permite contactar con él. El acceso al sitio web atribuye la condición de usuario e implica la aceptación de este aviso legal.'),
        ('h2', '3. Condiciones de uso'), ('p', 'El usuario se compromete a hacer un uso adecuado de los contenidos del sitio web y a no emplearlos para actividades ilícitas o contrarias a la buena fe y al orden público.'),
        ('h2', '4. Propiedad intelectual e industrial'), ('p', 'Los textos, las imágenes, el logotipo y el diseño del sitio web son propiedad del titular o dispone de licencia para utilizarlos. Queda prohibida la reproducción total o parcial de los contenidos sin autorización expresa.'),
        ('h2', '5. Responsabilidad'), ('p', 'El titular no se hace responsable de los daños que puedan derivarse de interrupciones del servicio, errores técnicos o del uso que se haga de la información publicada, y se reserva el derecho de modificar los contenidos del sitio web sin previo aviso. Los enlaces a sitios de terceros se facilitan a título informativo y el titular no controla su contenido.'),
        ('h2', '6. Protección de datos y cookies'), ('p', 'El tratamiento de los datos personales se describe en la {privacy}, y el uso de cookies en la {cookies}.'),
        ('h2', '7. Legislación aplicable'), ('p', 'Este aviso legal se rige por la legislación española.')],
 'en': [('h1', 'Legal notice'), ('h2', '1. Website owner'), ('p', 'In accordance with article 10 of Spanish Law 34/2002, of 11 July, on information society services and electronic commerce (LSSI-CE), the owner of the website {site} is:'), ('owner', ''),
        ('h2', '2. Purpose'), ('p', "This website presents the owner's business and services and lets you get in touch. Accessing the website makes you a user and implies acceptance of this legal notice."),
        ('h2', '3. Terms of use'), ('p', 'Users undertake to make appropriate use of the website content and not to use it for unlawful activities or activities contrary to good faith and public order.'),
        ('h2', '4. Intellectual and industrial property'), ('p', 'The texts, images, logo and design of the website belong to the owner or are used under licence. Total or partial reproduction of the content without express permission is prohibited.'),
        ('h2', '5. Liability'), ('p', 'The owner is not liable for damages arising from service interruptions, technical errors or the use made of the published information, and reserves the right to change the content of the website without prior notice. Links to third-party websites are provided for information only and the owner does not control their content.'),
        ('h2', '6. Data protection and cookies'), ('p', 'The processing of personal data is described in the {privacy}, and the use of cookies in the {cookies}.'),
        ('h2', '7. Applicable law'), ('p', 'This legal notice is governed by Spanish law.')],
 'fr': [('h1', 'Mentions légales'), ('h2', '1. Identification du titulaire'), ('p', "Conformément à l'article 10 de la loi espagnole 34/2002, du 11 juillet, sur les services de la société de l'information et le commerce électronique (LSSI-CE), le titulaire du site {site} est :"), ('owner', ''),
        ('h2', '2. Objet'), ('p', "Ce site présente l'activité et les services du titulaire et permet de le contacter. L'accès au site confère la qualité d'utilisateur et implique l'acceptation des présentes mentions légales."),
        ('h2', "3. Conditions d'utilisation"), ('p', "L'utilisateur s'engage à faire un usage approprié des contenus du site et à ne pas les utiliser à des fins illicites ou contraires à la bonne foi et à l'ordre public."),
        ('h2', '4. Propriété intellectuelle et industrielle'), ('p', 'Les textes, les images, le logo et le design du site appartiennent au titulaire ou sont utilisés sous licence. Toute reproduction totale ou partielle sans autorisation expresse est interdite.'),
        ('h2', '5. Responsabilité'), ('p', "Le titulaire n'est pas responsable des dommages pouvant résulter d'interruptions du service, d'erreurs techniques ou de l'usage fait des informations publiées, et se réserve le droit de modifier les contenus du site sans préavis."),
        ('h2', '6. Protection des données et cookies'), ('p', 'Le traitement des données personnelles est décrit dans la {privacy}, et l’utilisation des cookies dans la {cookies}.'),
        ('h2', '7. Droit applicable'), ('p', 'Les présentes mentions légales sont régies par le droit espagnol.')]}
OWNER_LABELS = {'ca': ('NIF', 'Domicili', 'Telèfon', 'Correu electrònic', 'Inscripció'), 'es': ('NIF', 'Domicilio', 'Teléfono', 'Correo electrónico', 'Inscripción'),
                'en': ('Tax ID', 'Address', 'Phone', 'Email', 'Registration'), 'fr': ('NIF', 'Adresse', 'Téléphone', 'E-mail', 'Inscription')}

ap = argparse.ArgumentParser(description='Páginas legales (aviso legal, privacidad, cookies) de una web Neutron')
ap.add_argument('web', help='carpeta de la instalación')
ap.add_argument('--pulsar', default=str(Path.home() / 'Projects' / 'pulsar'), help='carpeta de Pulsar (por defecto ~/Projects/pulsar)')
ap.add_argument('--url', help='dirección pública de la web (si no está en legal.json)')
ap.add_argument('--force', action='store_true', help='reescribir también los textos editados a mano')
a = ap.parse_args()
web = Path(a.web).expanduser().resolve()
site = web / 'site'
pulsar = Path(a.pulsar).expanduser()
if not (pulsar / 'inc' / 'LegalGenerator.php').exists():
    sys.exit(f'No encuentro Pulsar en {pulsar} (inc/LegalGenerator.php): usa --pulsar.')
esc = lambda s: html.escape(str(s), quote=False)
load = lambda p: json.loads(p.read_text()) if p.exists() else {}

langs_json = load(site / 'languages.json')
langs = list(langs_json.get('languages', {})) or ['ca']
main = langs_json.get('default', langs[0])
multi = len(langs) > 1
cfile = lambda l: site / (f'content.{l}.json' if multi else 'content.json')
content = {l: load(cfile(l)) for l in langs}
recipe = load(site / 'compose.json')

# ---------------------------------------------------------------- datos del titular
lf = site / 'legal.json'
data = load(lf)
if not data:
    c = content[main]
    data = {'owner_type': 'empresa', 'name': '', 'trade_name': c.get('site_name') or recipe.get('name', ''), 'nif': '',
            'address': c.get('address', ''), 'email': c.get('email', ''), 'phone': c.get('phone', ''), 'website': a.url or '',
            'registry': '', 'dpo': '', 'hosting': '', 'email_tool': ''}
    mig = web / '_migracio' / 'legal.json'                                 # lo que encontró tools/migrate.py
    if mig.exists():
        for k, v in load(mig).items():
            if v and not data.get(k): data[k] = v
    lf.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n')
    print(f'Creado {lf.relative_to(web)}: revisa los datos del titular.')
if a.url and not data.get('website'):
    data['website'] = a.url; lf.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n')
missing = [k for k in ('name', 'nif', 'address', 'email', 'website') if not data.get(k)]

# ---------------------------------------------------------------- qué tiene la web
src = '\n'.join(p.read_text(errors='replace') for p in [site / 'template.html', *sorted((site / 'pages').glob('*.html')), *sorted((site / 'partials').glob('*.html'))] if p.exists())
cats = {c['id'] for c in load(site / 'cookies.json').get('categories', [])}
has_form = 'data-neutron-form' in src
cookies = (['recaptcha'] if has_form else []) + [s for cat, s in [('maps', 'maps'), ('videos', 'youtube'), ('analytics', 'ga4')] if cat in cats]
purposes = (['contacto'] if has_form else []) + (['redes'] if 'data-edit-href="social_' in src else [])

runner = tempfile.NamedTemporaryFile('w', suffix='.php', delete=False)
runner.write('''<?php
// Puente con Pulsar: recibe los datos en JSON por la entrada estándar y devuelve los textos en JSON
if (!function_exists('e')) { function e(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); } }
require $argv[1] . '/inc/LegalGenerator.php';
$in = json_decode(stream_get_contents(STDIN), true);
$g = new LegalGenerator($in);
echo json_encode(['privacy' => $g->privacy(), 'cookies' => $g->cookies(), 'errors' => $g->errors(), 'lang' => $g->lang(),
    'slugs' => ['privacy' => $g->slug('privacy'), 'cookies' => $g->slug('cookies')]]);
''')
runner.close()

def pulsar_texts(l):
    pend = lambda k: data.get(k) or PENDING.get(l, PENDING['en']).format(FIELD[k].get(l, FIELD[k]['en']))
    inp = {**data, 'lang': l if l in ('ca', 'es', 'en', 'fr') else 'en', 'jurisdiction': 'es', 'cmp': 'otro',
           'name': pend('name'), 'nif': pend('nif'), 'address': pend('address'), 'website': data.get('website') or pend('website'),
           'email': data.get('email') or 'info@example.com', 'purposes': purposes, 'cookies': cookies,
           'custom_cookies': ADMIN_COOKIE.get(l, ADMIN_COOKIE['en'])}
    r = subprocess.run(['php', runner.name, str(pulsar)], input=json.dumps(inp), capture_output=True, text=True)
    if r.returncode or not r.stdout.startswith('{'):
        sys.exit(f'Pulsar ha fallado ({l}): {r.stderr or r.stdout[:500]}')
    out = json.loads(r.stdout)
    # La cookie de consentimiento de Neutron (mientras Pulsar no conozca Neutron como gestor de consentimiento)
    out['cookies'] = out['cookies'].replace('<code>cookie_consent</code>', '<code>neutron_consent</code>')
    # Los enlaces entre las dos políticas, con los nombres de página de Neutron (Pulsar usa los suyos)
    files = {'privacy': 'politica-de-privacitat.html', 'cookies': 'politica-de-cookies.html'}
    for doc in ('privacy', 'cookies'):
        for slug, file in files.items():
            s = re.escape(out['slugs'][slug].strip('/'))
            out[doc] = re.sub(rf'href="(?:https?://[^"/]*)?/?{s}/?"', f'href="{file}"', out[doc])
    return out

def notice(l):
    t = NOTICE.get(l, NOTICE['en'])
    lab = OWNER_LABELS.get(l, OWNER_LABELS['en'])
    pend = lambda k: data.get(k) or PENDING.get(l, PENDING['en']).format(FIELD[k].get(l, FIELD[k]['en']))
    name = pend('name') + (f" ({data['trade_name']})" if data.get('trade_name') and data.get('trade_name') != data.get('name') else '')
    owner = [f'<strong>{esc(name)}</strong>', f'{lab[0]}: {esc(pend("nif"))}', f'{lab[1]}: {esc(pend("address"))}']
    if data.get('phone'): owner.append(f'{lab[2]}: {esc(data["phone"])}')
    owner.append(f'{lab[3]}: <a href="mailto:{esc(data.get("email", ""))}">{esc(pend("email"))}</a>' if data.get('email') else f'{lab[3]}: {esc(pend("email"))}')
    if data.get('registry'): owner.append(f'{lab[4]}: {esc(data["registry"])}')
    site_name = re.sub(r'^https?://(www\.)?', '', data.get('website') or '').rstrip('/') or (content[main].get('site_name') or '')
    links = {'privacy': f'<a href="politica-de-privacitat.html">{TITLES["legal_privacy"].get(l, TITLES["legal_privacy"]["en"])}</a>',
             'cookies': f'<a href="politica-de-cookies.html">{TITLES["legal_cookies"].get(l, TITLES["legal_cookies"]["en"])}</a>'}
    out = []
    for kind, text in t:
        if kind == 'owner': out.append('<ul><li>' + '</li><li>'.join(owner) + '</li></ul>'); continue
        text = esc(text).replace('{site}', esc(site_name)).replace('{privacy}', links['privacy']).replace('{cookies}', links['cookies'])
        out.append(f'<{kind}>{text}</{kind}>')
    return ''.join(out)

# ---------------------------------------------------------------- textos y páginas
state_file = site / '.compose.json'
state = load(state_file)
h = lambda s: hashlib.sha1(s.encode()).hexdigest()
kept = []
for l in langs:
    texts = pulsar_texts(l)
    gen = {'legal_notice_html': notice(l), 'legal_privacy_html': texts['privacy'], 'legal_cookies_html': texts['cookies']}
    for key, value in gen.items():
        value = re.sub(r'<h1>.*?</h1>', '', value, count=1)            # el título ya lo pone la página
        cur = content[l].get(key)
        if cur is not None and not a.force and state.get(f'{l}:{key}') and h(cur) != state[f'{l}:{key}']:
            kept.append(f'{l}:{key}'); continue
        content[l][key] = value
        state[f'{l}:{key}'] = h(value)
    for _, tkey in PAGES:
        content[l].setdefault(tkey, TITLES[tkey].get(l, TITLES[tkey]['en']))
        content[l].setdefault(f'{tkey}_seo', f"{content[l][tkey]} · {content[main].get('site_name', '')}".rstrip(' ·'))
    cfile(l).write_text(json.dumps(content[l], ensure_ascii=False, indent=4) + '\n')
Path(runner.name).unlink()

for file, tkey in PAGES:
    rel = f'pages/{file}.html'
    f = site / rel
    body = f'''<!DOCTYPE html>
<html lang="{main}">
<head>
<template data-include="head"></template>
<title data-edit="{tkey}_seo">{esc(content[main][f"{tkey}_seo"])}</title>
<meta name="robots" content="noindex, follow">
</head>
<body>

<div data-include="header"></div>

<main class="section b-legal">
  <div class="narrow">
    <h1 data-edit="{tkey}">{esc(content[main][tkey])}</h1>
    <div class="b-legal__text" data-edit-html="{tkey}_html">{content[main][f"{tkey}_html"]}</div>
  </div>
</main>

<div data-include="footer"></div>
<script src="site/assets/site.js" defer></script>
</body>
</html>
'''
    # Una página tocada a mano (o la de una web hecha antes de las herramientas) no se reescribe sin --force
    if f.exists() and not a.force and state.get(rel) and h(f.read_text()) != state[rel]:
        kept.append(rel); continue
    if f.exists() and not a.force and not state.get(rel) and '[PENDENT: text legal' not in f.read_text():
        kept.append(rel); continue
    f.write_text(body)
    state[rel] = h(body)
state_file.write_text(json.dumps(state, indent=1) + '\n')

print(f"Páginas legales en {', '.join(langs)}: aviso legal, privacidad y cookies (Pulsar: {', '.join(cookies) or 'solo cookies técnicas'})")
if missing: print(f"Pendiente en site/legal.json: {', '.join(missing)} → al tenerlo, vuelve a lanzar tools/legal.py")
if kept: print(f"Sin tocar (editados a mano; --force para reescribirlos): {', '.join(kept)}")
