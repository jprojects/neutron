#!/usr/bin/env python3
# Crea la instalación de una web nueva, lista para hacerle el site/:
#
#   python3 tools/new-site.py pepgallifa --nom "Pep Gallifa" [--idiomes ca,es,en] [--migrar https://web-antigua.com]
#
# - ~/Projects/neutron-<cliente> con el núcleo de la última etiqueta (git archive), como cualquier instalación
# - site/ con la estructura básica: carpetas, languages.json (si hay más de un idioma) y cookies.json en cada idioma
# - config.local.php con una contraseña aleatoria (su hash), el nombre, un token para Cosmo y Pulsar (SEO del panel) si
#   hay ~/.config/neutron/pulsar.json ({"url": "https://…", "token": "…"})
# - la contraseña, apuntada en credencials.local.md de este repo (en el .gitignore)
# - con --migrar, después tools/migrate.py sobre la web antigua
# No sobrescribe nada: si la carpeta ya existe, se detiene.
import argparse, datetime, json, os, secrets, string, subprocess, sys
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
# El núcleo de una etiqueta: lo que dice SU core.json (los patrones «carpeta/**» se copian enteros)
def core_paths(tag):
    data = json.loads(subprocess.run(['git', '-C', str(REPO), 'show', f'{tag}:core.json'], capture_output=True, text=True, check=True).stdout)
    return [x.split('/*')[0] for x in data['core']]
NOMS = {'ca': 'Català', 'es': 'Español', 'en': 'English', 'fr': 'Français', 'de': 'Deutsch', 'it': 'Italiano', 'pt': 'Português'}
# Textos del faldón de cookies en cada idioma (el núcleo solo los trae en castellano)
COOKIES = {
    'ca': {'title': 'Aquest lloc web utilitza cookies', 'text': "Fem servir cookies tècniques, necessàries perquè el web funcioni, i, només si ho acceptes, cookies de tercers. Més informació a la {policy}.",
           'policy': 'Política de cookies', 'accept': 'Acceptar totes', 'reject': 'Rebutjar', 'configure': 'Configurar', 'save': 'Desar la selecció',
           'technical': 'Tècniques (sempre actives)', 'technical_description': 'Imprescindibles perquè el web funcioni i per recordar la teva elecció sobre les cookies.', 'label': 'Avís de cookies'},
    'es': {'title': 'Este sitio web utiliza cookies', 'text': 'Usamos cookies técnicas, necesarias para que la web funcione, y, solo si lo aceptas, cookies de terceros. Más información en la {policy}.',
           'policy': 'Política de cookies', 'accept': 'Aceptar todas', 'reject': 'Rechazar', 'configure': 'Configurar', 'save': 'Guardar selección',
           'technical': 'Técnicas (siempre activas)', 'technical_description': 'Imprescindibles para que la web funcione y para recordar tu elección sobre las cookies.', 'label': 'Aviso de cookies'},
    'en': {'title': 'This website uses cookies', 'text': 'We use technical cookies, which the website needs to work, and, only if you accept, third-party cookies. More information in the {policy}.',
           'policy': 'Cookie policy', 'accept': 'Accept all', 'reject': 'Reject', 'configure': 'Settings', 'save': 'Save selection',
           'technical': 'Technical (always on)', 'technical_description': 'Essential for the website to work and to remember your cookie choice.', 'label': 'Cookie notice'},
}

ap = argparse.ArgumentParser(description='Crea la instalación de una web Neutron nueva')
ap.add_argument('client', help='nombre corto del cliente, sin espacios (p. ej. pepgallifa): la carpeta será ~/Projects/neutron-<cliente>')
ap.add_argument('--nom', required=True, help='nombre de la web (Cosmo, guía)')
ap.add_argument('--idiomes', default='ca', help='idiomas separados por comas; el primero es el principal (por defecto: ca)')
ap.add_argument('--etiqueta', help='versión del núcleo (por defecto, la última etiqueta vX.Y.Z)')
ap.add_argument('--migrar', metavar='URL', help='web antigua: al terminar, ejecuta tools/migrate.py')
a = ap.parse_args()

client = a.client.strip().lower()
if not client.replace('-', '').replace('.', '').isalnum():
    sys.exit('El nombre del cliente solo puede tener letras, cifras, guiones y puntos.')
dest = Path.home() / 'Projects' / f'neutron-{client}'
if dest.exists():
    sys.exit(f'{dest} ya existe: no se toca nada.')
idiomes = [l.strip() for l in a.idiomes.split(',') if l.strip()]
etiqueta = a.etiqueta or subprocess.run(['git', '-C', str(REPO), 'tag', '--sort=-v:refname', '-l', 'v*'],
                                        capture_output=True, text=True, check=True).stdout.split()[0]

# Núcleo
dest.mkdir(parents=True)
arch = subprocess.Popen(['git', '-C', str(REPO), 'archive', etiqueta] + core_paths(etiqueta), stdout=subprocess.PIPE)
subprocess.run(['tar', '-x', '-C', str(dest)], stdin=arch.stdout, check=True)
arch.wait()

# site/
site = dest / 'site'
for d in ['assets/img', 'assets/fonts', 'pages', 'partials', 'uploads']:
    (site / d).mkdir(parents=True, exist_ok=True)
(site / 'uploads' / '.gitkeep').touch()
def dump(path, data): path.write_text(json.dumps(data, ensure_ascii=False, indent=4) + '\n')
if len(idiomes) > 1:
    dump(site / 'languages.json', {'default': idiomes[0], 'languages': {l: NOMS.get(l, l) for l in idiomes}})
for i, l in enumerate(idiomes):
    if l in COOKIES:
        dump(site / ('cookies.json' if i == 0 else f'cookies.{l}.json'),
             {'policy': 'politica-de-cookies.html', 'accent': '#333333', 'version': 1, 'categories': [], 'texts': COOKIES[l]})

# config.local.php
pw = ''.join(secrets.choice(string.ascii_letters + string.digits) for _ in range(16))
hash_ = subprocess.run(['php', '-r', 'echo password_hash($argv[1], PASSWORD_DEFAULT);', pw], capture_output=True, text=True, check=True).stdout
token = secrets.token_hex(32)
php = lambda s: "'" + s.replace('\\', '\\\\').replace("'", "\\'") + "'"
# Pulsar (SEO del panel): url y token comunes a todas las webs en ~/.config/neutron/pulsar.json, fuera de cualquier repo
pulsar_file = Path.home() / '.config' / 'neutron' / 'pulsar.json'
pulsar = json.loads(pulsar_file.read_text()) if pulsar_file.exists() else {}
pulsar_php = (f"""
    'pulsar' => [
        'url' => {php(str(pulsar.get('url', '')))},
        'token' => {php(str(pulsar.get('token', '')))},
    ],""" if pulsar.get('url') and pulsar.get('token') else '')
(dest / 'config.local.php').write_text(f"""<?php
if (!defined('NEUTRON_APP')) {{ http_response_code(403); exit; }}

// Configuración de esta instalación (no se versiona ni la toca una actualización). Ver config.php.
return [
    'admin_password_hash' => {php(hash_)},
    'name' => {php(a.nom)},
    'recaptcha' => [
        'site_key' => '',
        'secret_key' => '',
    ],
    'remote' => [
        'token' => {php(token)},
    ],{pulsar_php}
];
""")
os.chmod(dest / 'config.local.php', 0o640)
for d in ['logs', 'backups', 'remote']:
    (dest / 'data' / d).mkdir(parents=True, exist_ok=True)

# La contraseña, en credencials.local.md
cred = REPO / 'credencials.local.md'
if not cred.exists():
    cred.write_text("# Contraseñas del editor (?admin) de las webs Neutron\n\nSolo en local (en el .gitignore). Al servidor solo va el hash.\n\n"
                    "| Web | Carpeta | Contraseña | Fecha |\n|---|---|---|---|\n")
with cred.open('a') as f:
    f.write(f"| {a.nom} | ~/Projects/neutron-{client} | `{pw}` | {datetime.date.today().isoformat()} |\n")

print(f"""Hecho: {dest}
  Núcleo:       {etiqueta}
  Idiomas:      {', '.join(idiomes)} (principal: {idiomes[0]})
  Contraseña:   apuntada en credencials.local.md
  Token Cosmo:  {token}
  Pendiente:    claves de reCAPTCHA en config.local.php si la web tiene formulario
  Local:        cd {dest} && php -S 127.0.0.1:8000 router.php""")

if a.migrar:
    subprocess.run([sys.executable, str(REPO / 'tools' / 'migrate.py'), a.migrar, str(dest)], check=False)
