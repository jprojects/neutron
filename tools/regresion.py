#!/usr/bin/env python3
# Regresión antes de publicar una versión (herramienta de desarrollo, no es del núcleo):
#
#   python3 tools/regresion.py [webs…] [--max 40] [--sin-motores] [--admin]
#
# 1. Núcleo anterior frente al nuevo: cada web local (las carpetas de ~/Projects que son instalaciones de Neutron, o
#    las que se pasen) se sirve tal cual y con el núcleo del repositorio (lo que dice core.json, en copias temporales:
#    las webs no se tocan) y se compara el HTML de las URLs de su sitemap (hasta --max).
# 2. Los dos motores de render: con el núcleo nuevo, DOMDocument frente al motor sin DOM (lib/render_fallback.php).
#
# El HTML se compara normalizado (entidades, atributos vacíos, espacios, el puerto del servidor de pruebas, el token
# CSRF y la hora del editor). Con --admin también las páginas en modo edición (sin sesión: el aviso de acceso).
# Sale con 1 si hay alguna diferencia y enseña dónde empieza.
import argparse, html, json, re, shutil, socket, subprocess, sys, tempfile, time, urllib.request
from pathlib import Path

REPO = Path(__file__).resolve().parent.parent
PROJECTS = Path.home() / 'Projects'

ap = argparse.ArgumentParser(description='Regresión de Neutron: núcleo anterior frente al nuevo y los dos motores')
ap.add_argument('webs', nargs='*', help='carpetas de instalaciones (por defecto, todas las de ~/Projects)')
ap.add_argument('--max', type=int, default=40, help='URLs por web como mucho (por defecto 40)')
ap.add_argument('--sin-motores', action='store_true', help='no comparar los dos motores')
ap.add_argument('--admin', action='store_true', help='también las páginas con ?admin')
a = ap.parse_args()

def is_neutron(d):
    return (d / 'lib' / 'render_fallback.php').is_file() and (d / 'site').is_dir() and (d / 'core.json').is_file() and d.resolve() != REPO

webs = [Path(w).expanduser().resolve() for w in a.webs] or sorted(d for d in PROJECTS.iterdir() if d.is_dir() and not d.name.startswith('.') and is_neutron(d))
if not webs: sys.exit('No hay ninguna instalación de Neutron.')
core = [p.split('/*')[0] for p in json.loads((REPO / 'core.json').read_text())['core']]

def free_port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0)); return s.getsockname()[1]

def serve(folder):
    port = free_port()
    proc = subprocess.Popen(['php', '-S', f'127.0.0.1:{port}', 'router.php'], cwd=folder, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    for _ in range(50):
        try:
            socket.create_connection(('127.0.0.1', port), timeout=.2).close(); break
        except OSError: time.sleep(.1)
    return proc, f'http://127.0.0.1:{port}'

def get(base, path):
    try:
        with urllib.request.urlopen(base + path, timeout=30) as r: return r.read().decode('utf-8', 'replace')
    except urllib.error.HTTPError as e: return f'HTTP {e.code}\n' + e.read().decode('utf-8', 'replace')
    except Exception as e: return f'ERROR {e}'

def norm(t):
    t = html.unescape(re.sub(r"(\s[\w:-]+)='([^']*)'", r'\1="\2"', t))   # DOMDocument pone '…' si el valor lleva comillas
    t = re.sub(r'127\.0\.0\.1:\d+', 'HOST', t)
    t = re.sub(r'"csrf":"[a-f0-9]+"|"base":[0-9.]+', '', t)
    t = re.sub(r'<!doctype html>\s*', '<!DOCTYPE html>', t, flags=re.I)
    t = re.sub(r'(\s[a-z-]+)=""', r'\1', t)
    return re.sub(r'\s+', ' ', t).strip()

def urls(base):
    sm = get(base, '/sitemap.xml')
    found = sorted({re.sub(r'^https?://[^/]+', '', u) or '/' for u in re.findall(r'<loc>([^<]+)</loc>', sm)})
    out = (found or ['/'])[:a.max] + ['/no-existe-' + str(int(time.time()))]          # y una 404
    if a.admin: out += [u + ('&' if '?' in u else '?') + 'admin' for u in out[:5]]
    return out

def where(x, y):
    i = next((i for i in range(min(len(x), len(y))) if x[i] != y[i]), min(len(x), len(y)))
    return f'      antes: …{x[max(0, i - 70):i + 90]}…\n      ahora: …{y[max(0, i - 70):i + 90]}…'

def copy_with_core(web, dest, dom=True):
    top = str(web)    # fuera la migración, data/ y la guía del cliente (los PDF de la raíz); los de site/uploads se quedan
    shutil.copytree(web, dest, ignore=lambda d, names: [n for n in names if n in ('_migracio', '.git', 'data') or (d == top and n.endswith('.pdf'))], symlinks=True)
    (dest / 'data').mkdir(exist_ok=True)
    for item in core:
        src = REPO / item
        if src.is_dir():
            shutil.rmtree(dest / item, ignore_errors=True); shutil.copytree(src, dest / item)
        elif src.is_file():
            (dest / item).parent.mkdir(parents=True, exist_ok=True); shutil.copy2(src, dest / item)
    if not dom:
        r = dest / 'lib' / 'render.php'
        r.write_text(r.read_text().replace("if (class_exists('DOMDocument')) {", "if (false) {", 1))

total = bad = 0
tmp = Path(tempfile.mkdtemp(prefix='neutron-regresion-'))
try:
    for web in webs:
        new, nodom = tmp / (web.name + '-nuevo'), tmp / (web.name + '-sindom')
        copy_with_core(web, new)
        if not a.sin_motores: copy_with_core(web, nodom, dom=False)
        procs = [serve(web), serve(new)] + ([serve(nodom)] if not a.sin_motores else [])
        try:
            (_, old_base), (_, new_base) = procs[0], procs[1]
            paths = urls(old_base)
            diffs = []
            for u in paths:
                x, y = norm(get(old_base, u)), norm(get(new_base, u))
                if x != y: diffs.append(('núcleo', u, where(x, y)))
                if not a.sin_motores:
                    z = norm(get(procs[2][1], u))
                    if y != z: diffs.append(('motores', u, where(y, z).replace('antes:', '  DOM:').replace('ahora:', 'sinDOM:')))
            total += len(paths); bad += len(diffs)
            mark = '✓' if not diffs else '✗'
            version = (web / 'VERSION').read_text().strip() if (web / 'VERSION').exists() else '?'
            print(f'{mark} {web.name} ({version}): {len(paths)} URLs' + (f', {len(diffs)} diferencias' if diffs else ''))
            for kind, u, w in diffs[:6]: print(f'    {kind}: {u}\n{w}')
        finally:
            for p, _ in procs: p.terminate()
finally:
    shutil.rmtree(tmp, ignore_errors=True)
nuevo = (REPO / 'VERSION').read_text().strip()
print(f'\n{len(webs)} webs, {total} URLs con el núcleo del repositorio ({nuevo})' + ('' if a.sin_motores else ' y los dos motores') + f': {bad} diferencias')
sys.exit(1 if bad else 0)
