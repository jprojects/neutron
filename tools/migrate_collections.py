# Colecciones de la web antigua para tools/migrate.py (no es del núcleo): noticias, documentos, galería y vídeos.
# Detección genérica (no de una web concreta): el blog de categoría de Joomla (itemprop="blogPost") y los artículos con
# página propia; fichas con un enlace de descarga (ficheros o componentes de descargas) y líneas «Etiqueta: valor»
# (categoría, publicado, descargas); enlaces de lightbox a imágenes agrupados por título o carpeta; iframes y enlaces de
# YouTube y Vimeo. Escribe los elementos en site/<colección>/<elemento>.json (sin pisar los que ya haya), los ficheros
# en site/uploads/docs/ y las fotos en site/assets/img/; las definiciones (_collection.json) las ponen los bloques de
# tools/compose.py.
import collections, html, json, mimetypes, re, urllib.parse
from pathlib import Path

NAMES = {
    'news': {'ca': 'noticies', 'es': 'noticias', 'fr': 'actualites', 'en': 'news'},
    'docs': {'ca': 'documentacio', 'es': 'documentacion', 'fr': 'documents', 'en': 'documents'},
    'gallery': {'ca': 'galeria', 'es': 'galeria', 'fr': 'galerie', 'en': 'gallery'},
    'videos': {'ca': 'videos', 'es': 'videos', 'fr': 'videos', 'en': 'videos'},
    'directory': {'ca': 'establiments', 'es': 'establecimientos', 'fr': 'etablissements', 'en': 'directory'},
}
# Directorio (comercios, socios, establecimientos): las fichas, sus etiquetas y las características con icono
ADDRESS_LABEL = r'^(adreça|adreca|dirección|direccion|address|adresse|domicili|ubicació|ubicación)$'
HOURS_LABEL = r'^(horari|horario|hours|opening hours|horaires)$'
SOCIAL = {'facebook': r'facebook\.com', 'instagram': r'instagram\.com', 'twitter': r'(twitter|x)\.com', 'linkedin': r'linkedin\.com',
          'youtube': r'youtube\.com', 'tiktok': r'tiktok\.com'}
# Iconos de Font Awesome de las características → nombre del icono (blocks/_icons/features/<nombre>.svg)
FEATURE_ICONS = {'wheelchair': 'accessible', 'credit-card': 'card', 'car': 'parking', 'truck': 'delivery', 'paw': 'pets',
                 'users': 'loyalty', 'language': 'language', 'wifi': 'wifi', 'leaf': 'eco', 'shopping-bag': 'bag', 'gift': 'gift',
                 'child': 'kids', 'cutlery': 'food', 'coffee': 'coffee', 'bicycle': 'bike', 'motorcycle': 'bike'}
MONTHS = {}
for i, names in enumerate([('jan', 'gen', 'ene', 'janv', 'january', 'gener', 'enero', 'janvier'), ('feb', 'febr', 'fev', 'févr', 'february', 'febrer', 'febrero', 'février'),
                           ('mar', 'març', 'mars', 'march', 'marzo'), ('apr', 'abr', 'avr', 'april', 'abril', 'avril'), ('may', 'maig', 'mai', 'mayo'),
                           ('jun', 'juny', 'juin', 'june', 'junio'), ('jul', 'juil', 'july', 'juliol', 'julio', 'juillet'), ('aug', 'ag', 'ago', 'août', 'august', 'agost', 'agosto'),
                           ('sep', 'set', 'sept', 'september', 'setembre', 'septiembre', 'septembre'), ('oct', 'october', 'octubre', 'octobre'),
                           ('nov', 'november', 'novembre', 'noviembre'), ('dec', 'des', 'dic', 'déc', 'december', 'desembre', 'diciembre', 'décembre')], 1):
    for n in names: MONTHS[n] = i
FILE_EXT = r'\.(pdf|docx?|xlsx?|pptx?|odt|ods|odp|rtf|zip|csv|txt)(\?|#|$)'
DOWNLOAD_HINT = r'(task=[\w.]*download|com_descargas|com_phocadownload|com_jdownloads|com_rsfiles|com_docman|[?&]download=|/download/)'
GENERIC_LINK = r'^(descarrega|descargar?|download|baixa|veure|ver|more|més|mas|más|llegir|leer|read|pdf|\s*)$'
ALLOWED = {'p', 'h2', 'h3', 'h4', 'strong', 'b', 'em', 'i', 'a', 'ul', 'ol', 'li', 'blockquote', 'br', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td'}

def parse_date(t):
    t = html.unescape(t or '').replace('\xa0', ' ').lower()
    if m := re.search(r'\b(\d{4})-(\d{2})-(\d{2})\b', t): return f'{m[1]}-{m[2]}-{m[3]}'
    if m := re.search(r'\b(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})\b', t): return f'{m[3]}-{int(m[2]):02d}-{int(m[1]):02d}'
    if m := re.search(r"\b(\d{1,2})\s*(?:de\s+|d')?([a-zçéûô]{3,10})\.?\s*(?:de\s+)?(\d{4})\b", t):
        mo = MONTHS.get(m[2]) or MONTHS.get(m[2][:3])
        if mo: return f'{m[3]}-{mo:02d}-{int(m[1]):02d}'
    return ''

def wrap_loose(h):
    """HTML bien formado para los dos motores: texto suelto dentro de <p>, ningún bloque dentro de un <p> y cada
    etiqueta de bloque cerrada (los dos motores arreglan distinto el HTML mal formado)."""
    BLOCK = ('p', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'blockquote', 'table', 'thead', 'tbody', 'tr', 'th', 'td')
    parts = re.split(r'(</?(?:' + '|'.join(BLOCK) + r')\b[^>]*>|<img\b[^>]*>)', h)
    out, stack, buf = [], [], ''
    def flush():
        nonlocal buf
        if buf.strip(): out.append('<p>' + buf.strip() + '</p>')
        buf = ''
    for part in parts:
        if not part: continue
        m = re.match(r'<(/?)([a-z0-9]+)', part)
        tag = m.group(2) if m else None
        if tag in BLOCK:
            if not m.group(1):                                          # apertura
                if stack and stack[-1] == 'p':                          # un bloque dentro de un <p>: se cierra el <p>
                    out.append('</p>'); stack.pop()
                if not stack: flush()
                if tag == 'p' and stack and stack[-1] not in ('blockquote',): continue   # <p> dentro de li, td…: fuera
                stack.append(tag); out.append(part)
            else:                                                       # cierre
                if tag not in stack: continue
                while stack:
                    t = stack.pop(); out.append(f'</{t}>')
                    if t == tag: break
        elif tag == 'img' and not stack:
            flush(); out.append(part)
        elif not stack:
            buf += part
        else:
            out.append(part)
    flush()
    while stack: out.append(f'</{stack.pop()}>')
    return ''.join(out)

BLOCKS_IN_LINK = {'p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'table', 'section', 'article', 'figure'}

def clean_body(node, base, save_image, save_doc=None, skip=()):
    """HTML limpio (lista blanca de lib/sanitize.php) de un nodo: imágenes descargadas, PDF del visor de Google como enlace."""
    out = []
    def walk(n):
        if n.tag is None: out.append(html.escape(n.text, quote=False)); return
        if n in skip or n.tag in ('script', 'style', 'noscript', 'svg', 'form', 'button', 'nav', 'audio', 'video', 'object'): return
        if re.search(r'\b(icons|btn-group|dropdown|breadcrumb|pagination|pager|share|social|print)\b|-social\b', n.classes()): return
        # «Tornar amunt» y el copyright del pie, que algunas plantillas meten dentro del contenido
        if re.search(r'\b(cd-top|back-to-top|backtotop|totop|scroll-top|copyright)\b', n.classes()): return
        if n.tag == 'a' and re.fullmatch(r'\s*(back to top|tornar amunt|volver arriba|haut de page)\s*', n.text_content(), re.I): return
        if n.tag == 'iframe':                       # un PDF incrustado con el visor de Google: un enlace al PDF
            src = n.get('src')
            q = urllib.parse.parse_qs(urllib.parse.urlsplit(src).query)
            if 'docs.google.com/gview' in src and q.get('url'):
                local = save_doc(q['url'][0], 'document') if save_doc else None
                if local: out.append(f'<p><a href="{local}">{html.escape(Path(local).name)}</a></p>')
            return
        tag = {'b': 'strong', 'i': 'em', 'h1': 'h2', 'h5': 'h3', 'h6': 'h3'}.get(n.tag, n.tag)
        if tag == 'img':
            src = save_image(urllib.parse.urljoin(base, n.get('src'))) if n.get('src') else None
            if src: out.append(f'<img src="{src}" alt="{html.escape(n.get("alt"), quote=True)}">')
            return
        # Un enlace que envuelve bloques (una tarjeta: logotipo y título): un párrafo con el enlace y lo de dentro en línea
        if tag == 'a' and any(c.tag in BLOCKS_IN_LINK or c.tag == 'img' for c in n.iter() if c is not n):
            inner = [f'<img src="{src}" alt="{html.escape(c.get("alt"), quote=True)}">' for c in n.iter()
                     if c.tag == 'img' and c.get('src') and (src := save_image(urllib.parse.urljoin(base, c.get('src'))))]
            label = re.sub(r'\s+', ' ', n.text_content()).strip()
            if label: inner.append(html.escape(label, quote=False))
            href = n.get('href')
            if inner and href and not href.startswith('javascript:'):
                out.append(f'<p><a href="{html.escape(urllib.parse.urljoin(base, href), quote=True)}">{" ".join(inner)}</a></p>')
            return
        keep = tag in ALLOWED and tag != 'img'
        if keep:
            attrs = ''
            if tag == 'a' and n.get('href') and not n.get('href').startswith('javascript:'):
                attrs = f' href="{html.escape(urllib.parse.urljoin(base, n.get("href")), quote=True)}"'
            out.append(f'<{tag}{attrs}>' + ('' if tag == 'br' else ''))
        for c in n.children: walk(c)
        if keep and tag != 'br': out.append(f'</{tag}>')
    for c in node.children: walk(c)
    h = wrap_loose(''.join(out))
    h = re.sub(r'<(p|li|h[234]|strong|em)>\s*</\1>', '', h)
    return re.sub(r'\s+', ' ', h).strip()

def shouting(t):
    """«CALENDARI ASSOCIACIó» (mayúsculas con algún acento en minúscula, como en muchos Joomla) → «Calendari associació»."""
    letters = [c for c in t if c.isalpha()]
    if len(letters) < 4 or sum(c.isupper() for c in letters) / len(letters) < .6: return t
    t = t.lower()
    return t[:1].upper() + t[1:]

def run(ctx):
    """ctx: pages, posts, main_lang, inst, fetch, parse, slugify, clean_text, save_image, sin_imagenes, log. Devuelve el informe."""
    pages, inst, fetch, slugify = ctx['pages'], ctx['inst'], ctx['fetch'], ctx['slugify']
    name_of = lambda kind: NAMES[kind].get(ctx['main_lang'], NAMES[kind]['en'])
    site = inst / 'site'
    report, created = {}, collections.defaultdict(list)
    taken = collections.defaultdict(set)

    def write_item(kind, title, data):
        col = name_of(kind)
        d = site / col
        d.mkdir(parents=True, exist_ok=True)
        base = slugify(title)[:70].strip('-') or 'element'
        slug, n = base, 2
        while slug in taken[col] or slug == 'feed': slug = f'{base}-{n}'; n += 1
        taken[col].add(slug)
        f = d / f'{slug}.json'
        if not f.exists():
            f.write_text(json.dumps({'title': title, **data, 'status': 'published'}, ensure_ascii=False, indent=4) + '\n')
        created[kind].append((slug, title))
        return col, slug

    def text_of(n): return ctx['clean_text'](n.text_content()) if n else ''

    def body_html(node, base, skip=()):
        return clean_body(node, base, ctx['save_image'], save_doc, skip)

    doc_cache = {}
    def save_doc(url, title):
        if url in doc_cache: return doc_cache[url]
        doc_cache[url] = None
        final, st, ctype, data = fetch(url, binary=True)
        if st != 200 or not data or 'html' in (ctype or '').lower(): return None
        ext = ''
        if m := re.search(FILE_EXT, urllib.parse.urlsplit(final).path.lower() + '$'): ext = m[1]
        if not ext: ext = (mimetypes.guess_extension((ctype or '').split(';')[0].strip()) or '.pdf').lstrip('.')
        if ext not in ('pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp', 'rtf', 'zip', 'csv', 'txt'): ext = 'pdf'
        d = site / 'uploads' / 'docs'
        d.mkdir(parents=True, exist_ok=True)
        base = slugify(title)[:60].strip('-') or 'document'
        fn, n = f'{base}.{ext}', 2
        while (d / fn).exists(): fn = f'{base}-{n}.{ext}'; n += 1
        (d / fn).write_bytes(data)
        doc_cache[url] = f'site/uploads/docs/{fn}'
        return doc_cache[url]

    # ------------------------------------------------ noticias
    seen_titles, dup_url = set(), {}
    news = []
    for p in pages.values():
        for item in [n for n in p['tree'].iter() if n.get('itemprop') == 'blogPost' or re.search(r'\bblog-item\b', n.classes())]:
            head = next((h for h in item.find_all('h2', 'h3', 'h1') if text_of(h)), None)
            title = text_of(head)
            if not title or title.lower() in seen_titles: continue
            date_node = next((n for n in item.iter() if n.tag == 'time' or re.search(r'\b(create|published|date|fecha|data)\b', n.classes())), None)
            date = parse_date(date_node.get('datetime') if date_node is not None and date_node.get('datetime') else text_of(date_node))
            skip = {head} | ({date_node} if date_node is not None else set())
            # La fila del título (con la fecha al lado) no es cuerpo
            row = head.parent if head.parent is not None and date_node is not None and date_node in list(head.parent.iter()) else None
            if row is None and head.parent is not None and head.parent.parent is not None and date_node is not None and date_node in list(head.parent.parent.iter()):
                row = head.parent.parent
            if row is not None and row is not item: skip.add(row)
            seen_titles.add(title.lower())
            news.append((title, date, body_html(item, p['final'], skip), None))

    # Categorías de los blogs de componentes (?catid=N): el nombre, del enlace que lleva a su listado (la barra lateral), y
    # sus entradas, las tarjetas del listado (enlaces con un título dentro; los «últimos» de la barra lateral no lo llevan)
    # Las direcciones se comparan por sus parámetros (con o sin index.php, con o sin Itemid…)
    def ukey(u):
        sp = urllib.parse.urlsplit(html.unescape(u))
        q = tuple(sorted((k, v) for k, v in urllib.parse.parse_qsl(sp.query) if k in ('option', 'view', 'id', 'catid', 'task')))
        return q if q else re.sub(r'/(index\.php)?$', '', sp.path) or '/'
    post_urls = {ukey(q['final']): q for q in ctx['posts']} | {ukey(q['url']): q for q in ctx['posts']}
    cat_listings = {}
    # Solo los listados del mismo componente que las entradas (un artículo de Joomla también lleva catid)
    blog_options = {o for q in ctx['posts'] for o in urllib.parse.parse_qs(urllib.parse.urlsplit(q['final']).query).get('option', [])}
    for p in pages.values():
        q = urllib.parse.parse_qs(urllib.parse.urlsplit(p['final']).query)
        if not blog_options or (q.get('option') or [''])[0] not in blog_options: continue
        cid = (q.get('catid') or [''])[0]
        if cid.isdigit() and cid != '0' and not re.search(r'view=(?:item|article|entry|post)(?:&|$)', urllib.parse.urlsplit(p['final']).query):
            cat_listings[p['final']] = p
        # Dos listados vacíos son la misma página para migrate.py (uno queda como alias del otro): cada uno es una categoría
        for al in p.get('alias', []):
            aq = urllib.parse.parse_qs(urllib.parse.urlsplit(al).query)
            acid = (aq.get('catid') or [''])[0]
            if (aq.get('option') or [''])[0] in blog_options and acid.isdigit() and acid != '0' and acid != cid: cat_listings[al] = {**p, 'final': al, 'url': al, 'alias': []}
    by_cid = {}
    for lurl, lp in cat_listings.items():
        cid = (urllib.parse.parse_qs(urllib.parse.urlsplit(lurl).query).get('catid') or [''])[0]
        by_cid.setdefault(cid, []).append((lurl, lp))
    cat_names, cat_of, cat_order = {}, {}, []
    for lurl, lp in [x for xs in by_cid.values() for x in xs]:
        keys = {ukey(lurl), ukey(lp['url'])}
        names = collections.Counter(t for p in pages.values() for t, h in p['links'] if t and ukey(h) in keys)
        name = names.most_common(1)[0][0] if names else ''
        if not name: continue
        cat_names[lurl] = name
        if name not in cat_order: cat_order.append(name)
        for a_ in lp['tree'].find_all('a'):
            href = a_.get('href')
            if not href or not any(h for h in a_.iter() if h.tag in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6')): continue
            k = ukey(urllib.parse.urljoin(lp['final'], html.unescape(href)))
            if k in post_urls: cat_of.setdefault(post_urls[k]['final'], name)
    # La imagen de cada entrada en su tarjeta (un fondo CSS, si en la ficha no hay ninguna)
    card_img = {}
    for lp in [p for p in pages.values()]:
        for a_ in lp['tree'].find_all('a'):
            href = a_.get('href')
            if not href: continue
            k = ukey(urllib.parse.urljoin(lp['final'], html.unescape(href)))
            if k not in post_urls: continue
            for n in a_.iter():
                st = n.get('style') or ''
                if (m := re.search(r'url\([\'"]?([^\'")]+)', st)) and post_urls[k]['final'] not in card_img:
                    card_img[post_urls[k]['final']] = urllib.parse.urljoin(lp['final'], html.unescape(m.group(1)))

    for p in ctx['posts']:
        title = p['title'].split(' - ')[0].split(' | ')[0].strip()
        container = ctx['main_container'](p['tree'])
        h1 = next(iter(container.find_all('h1')), None)
        if h1 is not None and text_of(h1): title = text_of(h1)      # el <title> de algunos componentes es el del menú
        if title and title.lower() in seen_titles: dup_url.setdefault(title.lower(), p['url'])   # ya está (del listado): su dirección
        if not title or title.lower() in seen_titles or re.search(r'legal|privac|cookies|aviso|avís|cancel|devoluci|desistim|condicions|condiciones|terms', p['url'] + title, re.I): continue
        seen_titles.add(title.lower())
        # El artículo: el bloque del título, si tiene el texto (así no entra la barra lateral)
        if h1 is not None and h1.parent is not None and len(text_of(h1.parent)) > len(title) + 80: container = h1.parent
        skip = {h1} if h1 is not None else set()
        skip |= {n for n in container.iter() if re.search(r'\b(meta|date|published|created)\b', n.classes())
                 and re.search(r'\d{4}', text_of(n)) and len(text_of(n)) < 60}
        news.append((title, p['date'] or '', body_html(container, p['final'], skip), p))
    news_urls = {}
    for title, date, body, p in news:
        data = {'date': date}
        # La primera imagen del cuerpo, si va al principio, es la de la entrada
        if (m := re.match(r'\s*(?:<p>\s*)?<img\b[^>]*\bsrc="([^"]+)"[^>]*>(?:\s*</p>)?', body)):
            data['image'] = html.unescape(m.group(1))
            body = body[m.end():]
        elif p is not None and p['final'] in card_img and not ctx['sin_imagenes']:
            local = ctx['save_image'](card_img[p['final']])
            if local: data['image'] = local
        summary = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', ' ', body))).strip()
        if len(summary) > 220: summary = summary[:217].rsplit(' ', 1)[0] + '…'
        if p is not None and p['final'] in cat_of: data['category'] = slugify(cat_of[p['final']])
        col, slug = write_item('news', title, {**data, 'summary': summary, 'body': body or '<p></p>'})
        if p is not None: news_urls[p['url']] = (col, slug)
        elif title.lower() in dup_url: news_urls[dup_url[title.lower()]] = (col, slug)   # del listado, con su página aparte
    # En el orden de la lista de categorías de la web antigua (la página que enlaza más de ellas)
    if cat_order:
        cat_keys = {ukey(u) for u in cat_listings} | {ukey(lp['url']) for lp in cat_listings.values()}
        link_pos = lambda p: {t: i for i, (t, h) in reversed(list(enumerate(p['links']))) if ukey(h) in cat_keys}
        best = max(pages.values(), key=lambda p: sum(1 for n in cat_order if n in link_pos(p)))
        pos = link_pos(best)
        cat_order.sort(key=lambda n: pos.get(n, 10**6))
    news_category_urls = {}
    if cat_order:
        col = name_of('news')
        (site / col).mkdir(parents=True, exist_ok=True)
        cf = site / col / '_categories.json'
        if not cf.exists():
            cf.write_text(json.dumps([{'id': slugify(n), 'title': n, 'description': ''} for n in cat_order], ensure_ascii=False, indent=4) + '\n')
        for lurl, name in cat_names.items():
            news_category_urls[cat_listings[lurl]['url']] = (col, 'categoria/' + slugify(name))
        report['news_categories'] = {n: sum(1 for c in cat_of.values() if c == n) for n in cat_order}

    # ------------------------------------------------ directorio (fichas de establecimientos)
    # Una ficha: una página «detail» de un componente (o con dirección y teléfono) que no es una entrada del blog
    def directory_card(p):
        q = urllib.parse.urlsplit(p['final']).query
        if not re.search(r'(?:^|&)view=detail(?:&|$)', q): return None
        root = next((n for n in p['tree'].iter() if re.search(r'\b(establiments?|establecimientos?|directory|listing|detail|fitxa|ficha)\b', n.classes())), None)
        return root or ctx['main_container'](p['tree'])
    features, dir_items, dir_cats, dir_cat_ids = collections.OrderedDict(), [], collections.OrderedDict(), {}
    for p in sorted(pages.values(), key=lambda p: p['url']):
        root = directory_card(p)
        if root is None: continue
        head = next((n for n in root.iter() if n.tag in ('h1', 'h2', 'h3', 'h4') or 'h4' in n.classes() or (n.tag == 'strong' and len(text_of(n)) > 2)), None)
        title = text_of(head)
        if not title: continue
        data = {}
        # Categoría: el enlace a un listado con catid
        for a_ in root.find_all('a'):
            qs = urllib.parse.parse_qs(urllib.parse.urlsplit(html.unescape(a_.get('href'))).query)
            if (qs.get('catid') or [''])[0].isdigit() and text_of(a_):
                data['category'] = slugify(text_of(a_)); dir_cats.setdefault(data['category'], text_of(a_))
                dir_cat_ids[qs['catid'][0]] = data['category']; break
        # Características: iconos con un title (o un tooltip) que dicen qué ofrecen
        feats = []
        for n in root.iter():
            t = n.get('title') or n.get('data-original-title') or n.get('aria-label')
            if not t or not re.search(r'\b(hastip|tip|tooltip|feature|servei|servicio)\b', (n.classes() + ' ' + n.get('data-toggle')).lower()): continue
            fid = slugify(t)[:40]
            icon = next((FEATURE_ICONS[c[3:]] for i in n.iter() if i.tag == 'i' for c in i.classes().split() if c.startswith('fa-') and c[3:] in FEATURE_ICONS), '')
            features.setdefault(fid, {'id': fid, 'label': ctx['clean_text'](t), 'icon': icon})
            if fid not in feats: feats.append(fid)
        if feats: data['features'] = feats
        # Etiquetas «Adreça: …» y «Horari: …»
        for n in root.iter():
            if n.tag != 'strong': continue
            lab = text_of(n).rstrip(':').strip().lower()
            box = n.parent
            if box is None: continue
            rest = ctx['clean_text'](box.text_content()).replace(text_of(n), '', 1).strip(' :')
            if re.match(ADDRESS_LABEL, lab) and rest and 'address' not in data: data['address'] = rest[:200]
            if re.match(HOURS_LABEL, lab) and 'hours' not in data:
                paras = [text_of(x) for x in box.find_all('p') if text_of(x)]
                data['hours'] = '\n'.join(paras) if paras else rest
        # Enlaces: teléfono, WhatsApp, correo, web y redes
        for a_ in root.find_all('a'):
            href = html.unescape(a_.get('href')).strip()
            if re.match(r'^/?www\.', href): href = 'https://' + href.lstrip('/')     # «/www.botiga.com»: la web antigua el enlace mal
            icons = ' '.join(i.classes() for i in a_.iter())
            if href.startswith('tel:'):
                num = re.sub(r'[^0-9+ ]', '', href[4:]).strip()
                if 'whatsapp' in icons and 'whatsapp' not in data: data['whatsapp'] = 'https://wa.me/' + ('' if num.startswith('+') or num.startswith('34') else '34') + re.sub(r'\D', '', num)
                elif 'phone' not in data: data['phone'] = num
            elif re.search(r'(wa\.me|api\.whatsapp\.com|whatsapp\.com/send)', href) and 'whatsapp' not in data:
                num = re.sub(r'\D', '', (urllib.parse.parse_qs(urllib.parse.urlsplit(href).query).get('phone') or [href.rsplit('/', 1)[-1]])[0])
                data['whatsapp'] = 'https://wa.me/' + ('' if num.startswith('34') or len(num) > 9 else '34') + num if num else href
            elif href.startswith('mailto:') and 'email' not in data: data['email'] = href[7:].split('?')[0]
            elif re.match(r'https?://', href):
                net = next((k for k, rx in SOCIAL.items() if re.search(rx, href)), None)
                if net and 'share' not in href and 'sharer' not in href: data.setdefault(net, href)
                elif re.search(r'google\.[a-z.]+/maps|maps\.google|goo\.gl/maps', href): data.setdefault('map', href.replace(' ', '%20'))
                elif not net and 'globe' in icons or (not net and 'web' not in data and text_of(a_).lower().startswith('www')): data.setdefault('web', href)
        # Imágenes: la primera es el logotipo; las demás (un carrusel), las fotos
        imgs = [urllib.parse.urljoin(p['final'], i.get('src')) for i in root.find_all('img') if i.get('src')]
        if imgs and not ctx['sin_imagenes']:
            logo = ctx['save_image'](imgs[0])
            if logo: data['logo'] = logo
            photos = [x for x in (ctx['save_image'](u) for u in imgs[1:]) if x]
            if photos: data['images'] = photos
        # Vídeo (YouTube o Vimeo)
        for f_ in root.find_all('iframe'):
            src = f_.get('src')
            if re.search(r'(youtube|youtu\.be|vimeo)', src): data['video'] = re.sub(r'\?.*$', '', src) if 'vimeo' in src else src; break
        if 'video' in data and (m := re.search(r'player\.vimeo\.com/video/(\d+)', data['video'])): data['video'] = f'https://vimeo.com/{m[1]}'
        # Etiquetas (palabras clave) y descripción: el texto que queda después de la línea
        tags = [text_of(n) for n in root.iter() if re.search(r'\b(label|tag|badge|chip)\b', n.classes()) and text_of(n) and len(text_of(n)) < 40]
        if tags: data['tags'] = ', '.join(dict.fromkeys(t.strip() for t in tags))
        hrs = [n for n in root.iter() if n.tag == 'hr']
        desc = ''
        if hrs:
            after = False
            for n in root.iter():
                if n is hrs[min(1, len(hrs) - 1)]: after = True; continue
                if after and n.tag == 'p' and text_of(n) and not re.search(r'mapa|promoci|clic', text_of(n), re.I):
                    desc += f'<p>{html.escape(text_of(n), quote=False)}</p>'
                if after and n.tag in ('h4', 'h3') and re.search(r'promoci', text_of(n), re.I): break
        if desc: data['body'] = desc
        data['summary'] = re.sub(r'<[^>]+>', ' ', desc).strip()[:200] if desc else ''
        dir_items.append((title, data, p))
    dir_urls = {}
    if dir_items:
        for title, data, p in dir_items:
            nice = title if not title.isupper() else ' '.join(w.capitalize() if len(w) > 2 else w.lower() for w in title.lower().split())
            col, slug = write_item('directory', nice, data)
            dir_urls[p['url']] = (col, slug)
        col = name_of('directory')
        if dir_cats and not (site / col / '_categories.json').exists():
            (site / col / '_categories.json').write_text(json.dumps([{'id': k, 'title': v, 'description': ''} for k, v in sorted(dir_cats.items(), key=lambda kv: kv[1])],
                                                                    ensure_ascii=False, indent=4) + '\n')
        if features:
            (site / col / '_features.json').write_text(json.dumps(list(features.values()), ensure_ascii=False, indent=4) + '\n')
        report['directory'] = {'count': len(dir_items), 'categories': len(dir_cats), 'features': [f['label'] for f in features.values()]}

    # ------------------------------------------------ documentos
    docs, cats, counts, seen_docs = [], collections.OrderedDict(), {}, set()
    for p in pages.values():
        for a_ in p['tree'].find_all('a'):
            href = html.unescape(a_.get('href'))
            if not href or not (re.search(FILE_EXT, href.lower()) or re.search(DOWNLOAD_HINT, href, re.I)): continue
            full = urllib.parse.urljoin(p['final'], href)
            if full in seen_docs: continue
            # La ficha: el antepasado más cercano que tiene título o texto además del enlace
            card, n = None, a_.parent
            while n is not None and n.tag not in ('body', '#root'):
                if len([x for x in n.find_all('a') if re.search(FILE_EXT, x.get('href').lower()) or re.search(DOWNLOAD_HINT, x.get('href'), re.I)]) > 1: break
                if any(h.tag in ('h2', 'h3', 'h4', 'h5', 'h6', 'b', 'strong') and text_of(h) for h in n.iter()): card = n; break
                n = n.parent
            title_node = next((h for h in (card.iter() if card else []) if h.tag in ('h2', 'h3', 'h4', 'h5', 'h6', 'b', 'strong') and text_of(h)), None)
            title = text_of(title_node) or text_of(a_)
            if re.match(GENERIC_LINK, title.lower()): title = Path(urllib.parse.urlsplit(full).path).stem.replace('-', ' ').replace('_', ' ')
            meta = {}
            for line in (text_of(card) if card else '').split('\n'):
                if m := re.match(r'\s*([^:\n]{3,25}):\s*(.+)$', line): meta[m[1].strip().lower()] = m[2].strip()
            cat = next((v for k, v in meta.items() if re.match(r'categor', k)), '')
            date = next((parse_date(v) for k, v in meta.items() if re.match(r'(publica|data|fecha|date)', k)), '')
            dl = next((int(re.sub(r'\D', '', v) or 0) for k, v in meta.items() if re.match(r'(desc[aà]rreg|descarg|download|hits)', k)), 0)
            seen_docs.add(full)
            docs.append((title.strip(), cat, date, full, dl))
    if len(docs) >= 2:
        for title, cat, date, url, dl in docs:
            local = None if ctx['sin_imagenes'] else save_doc(url, title)
            cid = ''
            if cat:
                cid = slugify(cat)[:60] or 'altres'
                cats.setdefault(cid, shouting(cat))
            col, slug = write_item('docs', shouting(title),
                                   {'category': cid, 'date': date, 'file': local or '', 'summary': ''})
            if dl: counts[f'{col}/{slug}/file'] = dl
        if cats:
            f = site / name_of('docs') / '_categories.json'
            if not f.exists(): f.write_text(json.dumps([{'id': k, 'title': v, 'description': ''} for k, v in cats.items()], ensure_ascii=False, indent=4) + '\n')
        if counts:
            df = inst / 'data' / 'downloads.json'
            df.parent.mkdir(parents=True, exist_ok=True)
            old = json.loads(df.read_text()) if df.exists() else {}
            df.write_text(json.dumps({**counts, **old}, ensure_ascii=False, indent=4) + '\n')
        report['docs'] = {'count': len(docs), 'categories': list(cats.values()), 'downloads': sum(counts.values())}

    # ------------------------------------------------ galería (álbumes)
    # Por carpeta (gallery/2014/…); el título del álbum, el más repetido de sus fotos (data-title) o el de la carpeta
    albums, titles = collections.OrderedDict(), collections.defaultdict(collections.Counter)
    seen_imgs = set()
    for p in pages.values():
        for a_ in p['tree'].find_all('a'):
            href = a_.get('href')
            lightbox = a_.get('data-lightbox') or a_.get('data-fancybox') or ('lightbox' in a_.get('rel')) or re.search(r'glightbox|fancybox|lightbox', a_.classes())
            if not href or not re.search(r'\.(jpe?g|png|webp|gif)(\?|$)', href.lower()) or not (lightbox or a_.find_all('img')): continue
            full = urllib.parse.urljoin(p['final'], href)
            if full in seen_imgs: continue
            seen_imgs.add(full)
            parts = [x for x in urllib.parse.urlsplit(full).path.split('/') if x]
            folder = urllib.parse.unquote(parts[-2]) if len(parts) > 1 else 'galeria'
            albums.setdefault(folder, []).append(full)
            t = a_.get('data-title') or a_.get('title')
            if t and len(t) <= 60: titles[folder][t.strip()] += 1
    def album_title(folder):
        t = titles[folder].most_common(1)[0][0] if titles[folder] else folder
        # Carpeta con el año (gallery/2015/): si el título más repetido no lo dice, es el pie de una foto: el año
        if re.fullmatch(r'(19|20)\d\d', folder) and folder not in t: return folder
        return t
    albums = collections.OrderedDict((album_title(f), imgs) for f, imgs in albums.items())
    if sum(len(v) for v in albums.values()) >= 6:
        for title, imgs in sorted(albums.items(), key=lambda kv: kv[0], reverse=True):
            local = [] if ctx['sin_imagenes'] else [x for x in (ctx['save_image'](u) for u in imgs) if x]
            write_item('gallery', title[:1].upper() + title[1:], {'images': local})
        report['gallery'] = {'albums': len(albums), 'images': sum(len(v) for v in albums.values())}

    # ------------------------------------------------ vídeos
    vids = collections.OrderedDict()
    dir_pages = {id(p) for _, _, p in dir_items}
    for p in pages.values():
        if id(p) in dir_pages: continue                  # el vídeo de una ficha del directorio es suyo, no de la galería
        for n in p['tree'].iter():
            url = n.get('src') or n.get('data-src') if n.tag == 'iframe' else (n.get('href') if n.tag == 'a' else '')
            m = re.search(r'(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?(?:.*&)?v=)|youtu\.be/)([\w-]{11})', url or '')
            vm = re.search(r'vimeo\.com/(?:video/)?(\d+)', url or '')
            if not m and not vm: continue
            key = m[1] if m else 'vimeo' + vm[1]
            if key in vids: continue
            title = n.get('title')
            if not title or re.search(r'player|youtube|vimeo|^v[ií]deo$', title, re.I):
                # El texto de al lado (otra celda de la fila, el párrafo de encima…), subiendo como mucho 7 niveles
                title, branch = '', n
                for _ in range(7):
                    parent = branch.parent
                    if parent is None or parent.tag in ('main', 'body', '#root'): break
                    for sib in parent.children:
                        if sib is branch or sib.tag is None: continue
                        t = text_of(sib)
                        if len(t) >= 8 and not sib.find_all('iframe'): title = t; break
                    if title: break
                    branch = parent
                title = re.split(r'(?<=[.!?])\s', title)[0][:110]
            vids[key] = (title or p['title'], f'https://www.youtube.com/watch?v={m[1]}' if m else f'https://vimeo.com/{vm[1]}')
    for title, url in vids.values():
        write_item('videos', title[:120], {'video': url, 'date': ''})
    if vids: report['videos'] = {'count': len(vids)}
    # Vídeos propios (<video>): mejor en YouTube o Vimeo que en el hosting; se apuntan para hacerlo a mano
    own = sorted({urllib.parse.urljoin(p['final'], s_.get('src') or n.get('src')) for p in pages.values() for n in p['tree'].find_all('video')
                  for s_ in (n.find_all('source') or [n]) if (s_.get('src') or n.get('src'))})
    if own: report['own_videos'] = own
    if news: report['news'] = {'count': len(news)}
    return {'report': report, 'created': created, 'names': {k: name_of(k) for k in NAMES},
            'news_urls': news_urls, 'news_category_urls': news_category_urls, 'directory_urls': dir_urls,
            'directory_category_ids': dir_cat_ids}
