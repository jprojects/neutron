// Panel de la web (/admin/): inicio y contenido en formularios. Sin dependencias.
// Datos: admin/api.php (home, content). Guardar: admin/save.php, igual que la edición en la página (solo lo cambiado,
// con «base» para los avisos de conflicto). Las imágenes se optimizan en el navegador antes de subirlas (como editor.js).
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('neutron-panel-config').textContent || '{}');
  var app = document.getElementById('panel');
  var MAX_SIDE = 2000, QUALITY = 0.82;

  // Estado del contenido abierto
  var state = { view: 'home', lang: null, data: null, values: {}, original: {}, page: null, dirty: false, base: null };

  // ------------------------------------------------------------------ utilidades
  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = attrs[k];
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return el;
  }
  function api(action, params) {
    var q = Object.keys(params || {}).map(function (k) { return '&' + k + '=' + encodeURIComponent(params[k]); }).join('');
    return fetch('api.php?a=' + action + q, { credentials: 'same-origin' }).then(function (r) {
      if (r.status === 401) { location.reload(); throw new Error('sesión'); }
      return r.json();
    });
  }
  function post(url, body) {
    return fetch(url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
      body: JSON.stringify(body)
    }).then(function (r) { return r.json(); });
  }
  function clone(v) { return v === undefined ? undefined : JSON.parse(JSON.stringify(v)); }
  function same(a, b) { return JSON.stringify(a) === JSON.stringify(b); }
  // Las rutas de las imágenes son relativas a la raíz de la web; el panel está en /admin/
  function src(url) { return !url || /^(https?:|data:|\/)/.test(url) ? url : '../' + url; }
  function ago(t) {
    var s = Math.max(0, Date.now() / 1000 - t);
    if (s < 60) return 'ahora mismo';
    if (s < 3600) return 'hace ' + Math.round(s / 60) + ' min';
    if (s < 86400) return 'hace ' + Math.round(s / 3600) + ' h';
    var d = Math.round(s / 86400);
    return d === 1 ? 'ayer' : 'hace ' + d + ' días';
  }
  function siteHref(path) {
    var langs = cfg.languages;
    var prefix = langs ? (state.lang || langs.default) + '/' : '';
    return '../' + prefix + (path === './' ? '' : path);
  }

  // ------------------------------------------------------------------ acceso
  function showLogin() {
    app.className = 'np-login';
    var error = h('p', { class: 'np-error', hidden: true });
    var input = h('input', { type: 'password', name: 'password', placeholder: 'Contraseña', autocomplete: 'current-password', required: true });
    var form = h('form', { class: 'np-login-box', onsubmit: function (e) {
      e.preventDefault();
      post('login.php', { password: input.value }).then(function (d) {
        if (d.ok) location.reload();
        else { error.textContent = d.error || 'Error de acceso'; error.hidden = false; }
      }).catch(function () { error.textContent = 'Error de red'; error.hidden = false; });
    } }, [h('h1', { text: 'Panel de la web' }), input, h('button', { type: 'submit', text: 'Entrar' }), error]);
    app.innerHTML = '';
    app.appendChild(form);
    input.focus();
  }

  function logout() {
    if (!confirmLeave()) return;
    fetch('logout.php', { method: 'POST', headers: { 'X-CSRF-Token': cfg.csrf }, credentials: 'same-origin' })
      .then(function () { location.reload(); });
  }

  // ------------------------------------------------------------------ estructura
  var main, nav;
  function layout() {
    app.className = 'np-app';
    app.innerHTML = '';
    nav = h('nav', { class: 'np-nav' });
    main = h('main', { class: 'np-main' });
    app.appendChild(h('aside', { class: 'np-side' }, [
      h('div', { class: 'np-brand' }, [h('span', { class: 'np-site', text: '…' })]),
      nav,
      h('div', { class: 'np-side-foot' }, [
        h('a', { class: 'np-link', href: siteHref('./') + '?admin', text: 'Editar en la web ↗' }),
        h('button', { type: 'button', class: 'np-link', text: 'Cerrar sesión', onclick: logout })
      ])
    ]));
    app.appendChild(main);
    renderNav();
  }

  function renderNav() {
    nav.innerHTML = '';
    [['home', 'Inicio'], ['content', 'Contenido']].concat(cfg.seo ? [['seo', 'SEO']] : []).forEach(function (item) {
      nav.appendChild(h('button', { type: 'button', class: 'np-nav-item' + (state.view === item[0] ? ' is-active' : ''), text: item[1],
        onclick: function () { go(item[0]); } }));
    });
  }

  function go(view) {
    if (view === state.view) return;
    if (!confirmLeave()) return;
    state.dirty = false;
    state.view = view;
    renderNav();
    if (view === 'home') loadHome(); else if (view === 'seo') loadSeo(); else loadContent();
  }

  function confirmLeave() {
    return !state.dirty || confirm('Hay cambios sin guardar. ¿Salir sin guardarlos?');
  }
  window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ------------------------------------------------------------------ inicio
  function loadHome() {
    main.innerHTML = '<p class="np-muted">Cargando…</p>';
    Promise.all([api('home'), api('content')]).then(function (r) {
      var home = r[0], content = r[1];
      document.querySelector('.np-site').textContent = home.name;
      document.title = 'Panel · ' + home.name;
      var where = keyIndex(content.schema);
      main.innerHTML = '';
      main.appendChild(h('header', { class: 'np-head' }, [h('h1', { text: home.name }),
        h('a', { class: 'np-button np-secondary', href: siteHref('./'), target: '_blank', rel: 'noopener', text: 'Ver la web ↗' })]));
      (home.notices || []).forEach(function (n) {
        main.appendChild(h('section', { class: 'np-notice' }, [
          h('span', { class: 'np-eyebrow', text: 'AFI' }), h('h2', { text: n.title }), n.text ? h('p', { text: n.text }) : null,
          n.url ? h('a', { class: 'np-button', href: n.url, target: '_blank', rel: 'noopener', text: n.button || 'Más información', onclick: function () {
            fetch('api.php?a=notice-click&id=' + encodeURIComponent(n.id), { method: 'POST', keepalive: true, credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf } });
          } }) : null
        ]));
      });
      var langs = home.languages ? Object.keys(home.languages.languages).length : 1;
      main.appendChild(h('div', { class: 'np-stats' }, [
        stat(home.pages, home.pages === 1 ? 'página' : 'páginas'),
        home.posts ? stat(home.posts, home.posts === 1 ? 'entrada del blog' : 'entradas del blog') : null,
        stat(langs, langs === 1 ? 'idioma' : 'idiomas')
      ]));
      var list = h('ul', { class: 'np-recent' });
      (home.recent || []).forEach(function (c) {
        var w = where[c.key];
        list.appendChild(h('li', {}, [
          h('span', { text: w ? w.page + ' › ' + w.section : c.key }),
          c.lang ? h('span', { class: 'np-tag', text: c.lang.toUpperCase() }) : null,
          h('span', { class: 'np-muted', text: ago(c.time) })
        ]));
      });
      main.appendChild(h('section', { class: 'np-card' }, [h('h2', { text: 'Últimos cambios' }),
        home.recent && home.recent.length ? list : h('p', { class: 'np-muted', text: 'Todavía no hay cambios guardados desde el editor.' })]));
      main.appendChild(h('p', { class: 'np-version', text: 'Neutron ' + home.version }));
    }).catch(failed);
  }
  function stat(n, label) { return h('div', { class: 'np-stat' }, [h('strong', { text: String(n) }), h('span', { text: label })]); }
  // Clave → página y sección (para «Últimos cambios»)
  function keyIndex(schema) {
    var out = {};
    function add(pageTitle, sections) {
      sections.forEach(function (s) { s.fields.forEach(function (f) { out[f.key] = out[f.key] || { page: pageTitle, section: s.title }; }); });
    }
    add('Común', schema.common);
    schema.pages.forEach(function (p) { add(p.title, p.sections); });
    return out;
  }
  function failed() { main.innerHTML = '<p class="np-error">No se han podido cargar los datos. Recarga la página.</p>'; }

  // ------------------------------------------------------------------ contenido
  function loadContent(lang, keepPage) {
    main.innerHTML = '<p class="np-muted">Cargando…</p>';
    api('content', lang ? { lang: lang } : {}).then(function (d) {
      state.data = d;
      state.lang = d.lang;
      state.base = d.base;
      state.values = {};
      state.dirty = false;
      var pages = [{ slug: '__common', title: 'Común a todas las páginas', sections: d.schema.common }].concat(d.schema.pages)
        .filter(function (p) { return p.sections.length; });
      state.pages = pages;
      if (!keepPage || !pages.some(function (p) { return p.slug === state.page; })) state.page = pages.length > 1 ? pages[1].slug : pages[0] && pages[0].slug;
      renderContent();
      if (state.focusLabel) focusField(state.focusLabel);
    }).catch(failed);
  }

  // Valor que se ve: el del idioma; si no, el común (imágenes), el del idioma principal o el de la plantilla
  function valueOf(f) {
    var d = state.data;
    if (f.type === 'img' && d.shared && f.key in d.shared) return d.shared[f.key];
    if (f.key in d.values) return d.values[f.key];
    if (f.key in d.fallback) return d.fallback[f.key];
    return f.default;
  }
  function untranslated(f) {
    var d = state.data, langs = cfg.languages;
    return langs && d.lang !== langs.default && f.type !== 'img' && !(f.key in d.values);
  }

  function renderContent() {
    main.innerHTML = '';
    var page = state.pages.filter(function (p) { return p.slug === state.page; })[0];
    var status = h('span', { class: 'np-status' });
    var saveBtn = h('button', { type: 'button', class: 'np-button', text: 'Guardar cambios', disabled: true, onclick: function () { save(false); } });
    state.statusEl = status;
    state.saveBtn = saveBtn;

    var pageSelect = h('select', { class: 'np-select', 'aria-label': 'Página', onchange: function () {
      if (!confirmLeave()) { pageSelect.value = state.page; return; }
      state.page = pageSelect.value;
      loadContent(state.lang, true);
    } }, state.pages.map(function (p) { return h('option', { value: p.slug, selected: p.slug === state.page, text: p.title }); }));

    var tools = [pageSelect];
    if (cfg.languages) {
      var langSelect = h('select', { class: 'np-select', 'aria-label': 'Idioma', onchange: function () {
        if (!confirmLeave()) { langSelect.value = state.lang; return; }
        loadContent(langSelect.value, true);
      } }, Object.keys(cfg.languages.languages).map(function (code) {
        return h('option', { value: code, selected: code === state.lang, text: cfg.languages.languages[code] });
      }));
      tools.push(langSelect);
    }
    main.appendChild(h('header', { class: 'np-head np-sticky' }, [
      h('div', { class: 'np-tools' }, tools), h('div', { class: 'np-actions' }, [status, saveBtn])
    ]));
    if (cfg.languages && state.lang !== cfg.languages.default) {
      main.appendChild(h('p', { class: 'np-hint', text: 'Los textos marcados «Sin traducir» muestran ahora el del idioma principal. Escríbelos en este idioma para traducirlos.' }));
    }
    if (!page) { main.appendChild(h('p', { class: 'np-muted', text: 'Esta web no tiene contenido editable.' })); return; }

    page.sections.forEach(function (s) {
      var link = page.slug !== '__common' && page.href
        ? h('a', { class: 'np-link np-small', href: siteHref(page.href) + '?admin' + (s.id ? '#' + s.id : ''), target: '_blank', rel: 'noopener', text: 'Ver en la web ↗' })
        : null;
      var card = h('section', { class: 'np-card' }, [h('div', { class: 'np-card-head' }, [h('h2', { text: s.title }), link])]);
      s.fields.forEach(function (f) { card.appendChild(fieldRow(f, s, page)); });
      main.appendChild(card);
    });
  }

  // Lleva al campo con esa etiqueta (p. ej. «Descripción para Google») y lo resalta
  function focusField(label) {
    state.focusLabel = null;
    var row = Array.prototype.filter.call(main.querySelectorAll('.np-field'), function (r) {
      var l = r.querySelector('.np-label span');
      return l && l.textContent === label;
    })[0];
    if (!row) return;
    row.classList.add('np-highlight');
    row.scrollIntoView({ block: 'center' });
    var input = row.querySelector('input, textarea');
    if (input) input.focus();
  }

  // Un campo de primer nivel: su valor se guarda en state.values[key] cuando cambia
  function fieldRow(f, section, page) {
    var initial = clone(valueOf(f));
    state.original[f.key] = initial;
    var set = function (v) {
      state.values[f.key] = v;
      if (same(v, state.original[f.key])) delete state.values[f.key];
      markDirty();
    };
    var label = h('div', { class: 'np-label' }, [h('span', { text: f.label }),
      untranslated(f) ? h('span', { class: 'np-tag np-tag-warn', text: 'Sin traducir' }) : null]);
    var ctrl = control(f, initial, set);
    var row = h('div', { class: 'np-field' }, [label, ctrl]);
    if (cfg.ai && f.type === 'text' && /^(INPUT|TEXTAREA)$/.test(ctrl.tagName)) label.appendChild(aiButton(f, ctrl, row, section, page));
    return row;
  }

  // ------------------------------------------------------------------ IA (admin/ai.php): propone y el cliente decide
  function aiButton(f, input, row, section, page) {
    var langs = cfg.languages ? Object.keys(cfg.languages.languages) : [];
    var actions = [];
    if (f.label === 'Descripción para Google') actions.push(['seo_description', 'Escribir con IA a partir de la página']);
    if (f.label === 'Título de la pestaña') actions.push(['seo_title', 'Escribir con IA a partir de la página']);
    actions.push(['improve', 'Mejorar el texto'], ['fix', 'Corregir ortografía']);
    if (langs.length > 1) actions.push(['translate', 'Traducir a los otros idiomas']);
    var menu = h('div', { class: 'np-ai-menu', hidden: true }, actions.map(function (a) {
      return h('button', { type: 'button', text: a[1], onclick: function () { menu.hidden = true; askAi(a[0], f, input, row, section, page); } });
    }));
    document.addEventListener('click', function (e) { if (!menu.contains(e.target) && !btn.contains(e.target)) menu.hidden = true; });
    var btn = h('button', { type: 'button', class: 'np-ai', title: 'Ayuda de la IA', text: '✨ IA', onclick: function () { menu.hidden = !menu.hidden; } });
    return h('span', { class: 'np-ai-wrap' }, [btn, menu]);
  }

  function pageText(page) {
    var out = [];
    page.sections.forEach(function (s) {
      s.fields.forEach(function (f) {
        if (f.type !== 'text' || f.label === 'Título de la pestaña' || f.label === 'Descripción para Google') return;
        var v = valueOf(f);
        if (typeof v === 'string' && v.trim()) out.push(v.trim());
      });
    });
    return out.join('\n');
  }

  function askAi(action, f, input, row, section, page) {
    var old = row.querySelector('.np-ai-box');
    if (old) old.remove();
    var box = h('div', { class: 'np-ai-box' }, [h('p', { class: 'np-muted', text: 'La IA está escribiendo una propuesta…' })]);
    row.appendChild(box);
    var langs = cfg.languages ? Object.keys(cfg.languages.languages) : [];
    var kind = f.label === 'Descripción para Google' ? 'description' : f.label === 'Título de la pestaña' ? 'tab'
      : f.label === 'Título' || f.label === 'Subtítulo' ? 'title' : f.label === 'Botón o enlace' ? 'button' : 'text';
    var body = { action: action, text: input.value, lang: state.lang || (cfg.languages && cfg.languages.default) || document.documentElement.lang,
      kind: kind, context: [document.querySelector('.np-site').textContent, page.title, section.title].join(' · ') };
    if (action === 'translate') body.to = langs.filter(function (l) { return l !== body.lang; });
    if (action === 'seo_description' || action === 'seo_title') body.page = pageText(page);
    post('ai.php', body).then(function (d) {
      box.innerHTML = '';
      if (!d.ok) { box.appendChild(h('p', { class: 'np-error', text: d.error || 'La IA no ha podido responder' })); return; }
      if (action === 'translate') return showTranslations(box, f, d.translations);
      box.appendChild(h('div', { class: 'np-ai-head', text: 'Propuesta de la IA' }));
      var proposal = h('textarea', { class: 'np-input', rows: Math.min(8, Math.max(2, Math.ceil(d.text.length / 80))) });
      proposal.value = d.text;
      box.appendChild(proposal);
      if (kind === 'description' || kind === 'tab') box.appendChild(h('p', { class: 'np-muted np-small', text: d.text.length + ' caracteres' }));
      box.appendChild(h('div', { class: 'np-ai-actions' }, [
        h('button', { type: 'button', class: 'np-button np-small-button', text: 'Usar', onclick: function () {
          input.value = proposal.value;
          input.dispatchEvent(new Event('input', { bubbles: true }));
          box.remove();
          input.focus();
        } }),
        h('button', { type: 'button', class: 'np-button np-secondary np-small-button', text: 'Descartar', onclick: function () { box.remove(); } })
      ]));
    }).catch(function () { box.innerHTML = ''; box.appendChild(h('p', { class: 'np-error', text: 'Error de red' })); });
  }

  // Traducciones: se guardan en cada idioma (cada uno es su fichero de textos), sin tocar el que se está editando
  function showTranslations(box, f, translations) {
    var names = cfg.languages.languages;
    var inputs = {};
    box.appendChild(h('div', { class: 'np-ai-head', text: 'Traducciones propuestas' }));
    Object.keys(translations).forEach(function (l) {
      var t = h('textarea', { class: 'np-input', rows: Math.min(6, Math.max(1, Math.ceil(translations[l].length / 80))) });
      t.value = translations[l];
      inputs[l] = t;
      box.appendChild(h('label', { class: 'np-ai-lang' }, [h('span', { text: names[l] || l }), t]));
    });
    var status = h('span', { class: 'np-muted np-small' });
    box.appendChild(h('div', { class: 'np-ai-actions' }, [
      h('button', { type: 'button', class: 'np-button np-small-button', text: 'Guardar las traducciones', onclick: function (e) {
        e.target.disabled = true;
        status.textContent = 'Guardando…';
        var langs = Object.keys(inputs);
        Promise.all(langs.map(function (l) {
          var payload = { lang: l, fields: {}, images: {}, collections: {} };
          payload.fields[f.key] = inputs[l].value;
          return post('save.php', payload);
        })).then(function (rs) {
          var bad = rs.filter(function (r) { return !r.ok; });
          status.textContent = bad.length ? 'Error: ' + (bad[0].error || '') : 'Guardado en ' + langs.map(function (l) { return l.toUpperCase(); }).join(', ') + ' ✓';
          if (bad.length) e.target.disabled = false;
        }).catch(function () { status.textContent = 'Error de red'; e.target.disabled = false; });
      } }),
      h('button', { type: 'button', class: 'np-button np-secondary np-small-button', text: 'Cerrar', onclick: function () { box.remove(); } }),
      status
    ]));
  }

  // El control según el tipo; onChange recibe el valor nuevo
  function control(f, value, onChange) {
    if (f.type === 'img') return imageControl(value, onChange);
    if (f.type === 'html') return richControl(value, onChange);
    if (f.type === 'collection') return collectionControl(f, Array.isArray(value) ? clone(value) : [], onChange);
    if (f.type === 'href') {
      return h('input', { type: 'url', class: 'np-input', value: value || '', placeholder: 'https://… (vacío: no se muestra)',
        oninput: function (e) { onChange(e.target.value.trim()); } });
    }
    var attrs = { class: 'np-input', oninput: function (e) { onChange(e.target.value); } };
    if (f.multiline) {
      var ta = h('textarea', attrs);
      ta.value = value || '';
      ta.rows = Math.min(10, Math.max(2, Math.ceil((value || '').length / 80)));
      return ta;
    }
    attrs.type = 'text';
    attrs.value = value || '';
    return h('input', attrs);
  }

  function imageControl(value, onChange) {
    var img = h('img', { alt: '', src: src(value) || '' });
    var msg = h('span', { class: 'np-muted np-small' });
    var input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', hidden: true, onchange: function () {
      if (!input.files[0]) return;
      msg.textContent = 'Preparando imagen…';
      upload(input.files[0]).then(function (url) {
        img.src = src(url);
        msg.textContent = '';
        onChange(url);
      }).catch(function (err) { msg.textContent = err.message || 'No se ha podido subir'; });
      input.value = '';
    } });
    return h('div', { class: 'np-image' }, [img, h('div', {}, [
      h('button', { type: 'button', class: 'np-button np-secondary', text: 'Cambiar imagen', onclick: function () { input.click(); } }), input, msg
    ])]);
  }

  function richControl(value, onChange) {
    var area = h('div', { class: 'np-rich', contenteditable: 'true', html: value || '' });
    var emit = function () { onChange(area.innerHTML.trim()); };
    area.addEventListener('input', emit);
    var tools = h('div', { class: 'np-rich-tools' });
    [['bold', 'N', 'Negrita'], ['italic', 'C', 'Cursiva'], ['h2', 'T2', 'Título'], ['h3', 'T3', 'Subtítulo'],
      ['insertUnorderedList', '• Lista', 'Lista'], ['createLink', 'Enlace', 'Enlace'], ['p', 'Párrafo', 'Texto normal']].forEach(function (t) {
      tools.appendChild(h('button', { type: 'button', title: t[2], text: t[1], onmousedown: function (e) { e.preventDefault(); }, onclick: function () {
        if (t[0] === 'h2' || t[0] === 'h3' || t[0] === 'p') document.execCommand('formatBlock', false, t[0]);
        else if (t[0] === 'createLink') { var url = prompt('Dirección del enlace (https://…)'); if (url) document.execCommand('createLink', false, url); }
        else document.execCommand(t[0]);
        emit();
      } }));
    });
    return h('div', { class: 'np-rich-wrap' }, [tools, area]);
  }

  // Lista: un bloque por elemento con sus campos; añadir, quitar y mover. Las sublistas, igual (recursivo).
  function collectionControl(f, items, onChange) {
    var box = h('div', { class: 'np-list' });
    function emit() { onChange(clone(items)); }
    function draw() {
      box.innerHTML = '';
      items.forEach(function (item, i) {
        var card = h('div', { class: 'np-item' });
        var head = h('div', { class: 'np-item-head' }, [h('span', { class: 'np-muted np-small', text: 'Elemento ' + (i + 1) }), h('span', { class: 'np-item-tools' }, [
          h('button', { type: 'button', title: 'Subir', text: '↑', disabled: i === 0, onclick: function () { items.splice(i - 1, 0, items.splice(i, 1)[0]); emit(); draw(); } }),
          h('button', { type: 'button', title: 'Bajar', text: '↓', disabled: i === items.length - 1, onclick: function () { items.splice(i + 1, 0, items.splice(i, 1)[0]); emit(); draw(); } }),
          h('button', { type: 'button', title: 'Quitar', text: '✕', onclick: function () { if (confirm('¿Quitar este elemento?')) { items.splice(i, 1); emit(); draw(); } } })
        ])]);
        card.appendChild(head);
        f.fields.forEach(function (sub) {
          var v = item[sub.key] !== undefined ? item[sub.key] : (sub.type === 'collection' ? [] : '');
          card.appendChild(h('div', { class: 'np-field' }, [h('div', { class: 'np-label' }, [h('span', { text: sub.label })]),
            control(sub, v, function (nv) { item[sub.key] = nv; emit(); })]));
        });
        box.appendChild(card);
      });
      box.appendChild(h('button', { type: 'button', class: 'np-button np-secondary np-add', text: '+ ' + (f.add || 'Añadir elemento'), onclick: function () {
        var empty = {};
        f.fields.forEach(function (sub) { empty[sub.key] = sub.type === 'collection' ? [] : (sub.type === 'img' && items[0] ? items[0][sub.key] || '' : ''); });
        items.push(empty);
        emit();
        draw();
      } }));
    }
    draw();
    return box;
  }

  function markDirty() {
    state.dirty = Object.keys(state.values).length > 0;
    state.saveBtn.disabled = !state.dirty;
    state.statusEl.textContent = state.dirty ? 'Cambios sin guardar' : '';
  }


  // ------------------------------------------------------------------ SEO y GEO (Pulsar, lib/seo.php)
  var FIX_LABELS = { title: 'Título de la pestaña', description: 'Descripción para Google' };
  var seo = { page: '', type: 'seo', data: null, running: false };

  function loadSeo() {
    main.innerHTML = '<p class="np-muted">Cargando…</p>';
    api('seo').then(function (d) { seo.data = d; renderSeo(); }).catch(failed);
  }

  function scoreClass(n) { return n >= 80 ? 'np-good' : n >= 50 ? 'np-fair' : 'np-bad'; }

  function renderSeo(message) {
    var d = seo.data;
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head' }, [h('h1', { text: 'SEO y GEO' }),
      h('span', { class: 'np-muted', text: 'Te quedan ' + d.remaining + ' de ' + d.limit + ' análisis hoy' })]));
    main.appendChild(h('p', { class: 'np-muted', text: 'SEO: cómo ve la página Google. GEO: cómo la entienden los asistentes de IA (ChatGPT, Gemini…). El análisis lo hace Pulsar, de AFI, y puede tardar hasta un minuto.' }));

    var pageSelect = h('select', { class: 'np-select', 'aria-label': 'Página', onchange: function () { seo.page = pageSelect.value; renderSeo(); } },
      d.pages.map(function (p) { return h('option', { value: p.page, selected: p.page === seo.page, text: p.title }); }));
    var run = function (type) {
      return h('button', { type: 'button', class: 'np-button' + (type === 'geo' ? ' np-secondary' : ''), disabled: seo.running || d.remaining < 1,
        text: seo.running === type ? 'Analizando…' : 'Analizar ' + type.toUpperCase(), onclick: function () { runSeo(type); } });
    };
    main.appendChild(h('div', { class: 'np-tools np-seo-tools' }, [pageSelect, run('seo'), run('geo')]));
    if (message) main.appendChild(h('p', { class: message.error ? 'np-error' : 'np-hint', text: message.text }));

    var key = seo.page === '' ? 'home' : seo.page;
    var tabs = h('div', { class: 'np-tabs' }, ['seo', 'geo'].map(function (t) {
      var r = d.reports[t] && d.reports[t][key];
      return h('button', { type: 'button', class: 'np-tab' + (seo.type === t ? ' is-active' : ''), onclick: function () { seo.type = t; renderSeo(); } },
        [t.toUpperCase(), r ? h('span', { class: 'np-score-mini ' + scoreClass(r.score), text: String(r.score) }) : null]);
    }));
    main.appendChild(tabs);
    var report = d.reports[seo.type] && d.reports[seo.type][key];
    main.appendChild(report ? seoReport(report) : h('section', { class: 'np-card' }, [h('p', { class: 'np-muted',
      text: 'Esta página aún no tiene ningún análisis ' + seo.type.toUpperCase() + '. Pulsa «Analizar ' + seo.type.toUpperCase() + '».' })]));

    if (d.history && d.history.length) {
      var titles = {};
      d.pages.forEach(function (p) { titles[p.page] = p.title; });
      main.appendChild(h('section', { class: 'np-card' }, [h('h2', { text: 'Análisis anteriores' }), h('ul', { class: 'np-recent' }, d.history.map(function (x) {
        return h('li', {}, [h('span', { class: 'np-score-mini ' + scoreClass(x.score), text: String(x.score) }),
          h('span', { text: (titles[x.page] || x.page) + ' · ' + x.type.toUpperCase() }), h('span', { class: 'np-muted', text: ago(Date.parse(x.at) / 1000) })]);
      }))]));
    }
  }

  function seoReport(r) {
    var card = h('section', { class: 'np-card' });
    card.appendChild(h('div', { class: 'np-seo-head' }, [
      h('div', { class: 'np-score ' + scoreClass(r.score) }, [h('strong', { text: String(r.score) }), h('span', { text: '/100' })]),
      h('div', {}, [
        h('p', { text: r.passed + ' de ' + r.total + ' comprobaciones correctas' }),
        h('p', { class: 'np-muted np-small', text: 'Analizado ' + ago(Date.parse(r.created_at) / 1000) + ' · ' + r.url })
      ])
    ]));
    if (r.error) card.appendChild(h('p', { class: 'np-error', text: r.error }));
    var cats = h('div', { class: 'np-cats' });
    Object.keys(r.categories || {}).forEach(function (k) {
      var c = r.categories[k];
      cats.appendChild(h('div', { class: 'np-cat' }, [h('span', { text: c.label }),
        h('span', { class: 'np-bar' }, [h('span', { class: scoreClass(c.score), style: 'width:' + Math.max(2, c.score) + '%' })]),
        h('strong', { text: String(c.score) })]));
    });
    card.appendChild(cats);
    var list = h('div', { class: 'np-issues' });
    (r.issues || []).forEach(function (i) {
      var fix = i.fix && FIX_LABELS[i.fix] ? h('button', { type: 'button', class: 'np-button np-small-button', text: 'Corregir', onclick: function () {
        state.page = seo.page;
        state.focusLabel = FIX_LABELS[i.fix];
        state.view = 'content';
        renderNav();
        loadContent(null, true);
      } }) : null;
      list.appendChild(h('div', { class: 'np-issue' }, [
        h('span', { class: 'np-tag ' + (i.status === 'fail' ? 'np-tag-bad' : 'np-tag-warn'), text: i.status === 'fail' ? 'A corregir' : 'Mejorable' }),
        h('div', {}, [h('strong', { text: i.title }), i.detail ? h('p', { text: i.detail }) : null,
          i.solution ? h('p', { class: 'np-muted', text: i.solution }) : null]),
        fix
      ]));
    });
    card.appendChild(h('h2', { class: 'np-issues-title', text: r.issues && r.issues.length ? 'Qué se puede mejorar' : 'Todo correcto' }));
    card.appendChild(list);
    return card;
  }

  function runSeo(type) {
    seo.running = type;
    renderSeo({ text: 'Analizando la página con Pulsar… puede tardar hasta un minuto. No cierres esta ventana.' });
    post('api.php?a=seo-run', { type: type, page: seo.page }).then(function (d) {
      seo.running = false;
      if (!d.ok) { renderSeo({ error: true, text: d.error || 'No se ha podido hacer el análisis' }); return; }
      seo.type = type;
      loadSeo();
    }).catch(function () { seo.running = false; renderSeo({ error: true, text: 'Error de red. Inténtalo de nuevo.' }); });
  }

  // ------------------------------------------------------------------ guardar (admin/save.php)
  function payload(force) {
    var types = {};
    state.pages.forEach(function (p) { p.sections.forEach(function (s) { s.fields.forEach(function (f) { types[f.key] = f.type; }); }); });
    var out = { fields: {}, images: {}, collections: {}, lang: state.lang, base: state.base };
    if (force) out.force = true;
    Object.keys(state.values).forEach(function (k) {
      var group = types[k] === 'img' ? 'images' : types[k] === 'collection' ? 'collections' : 'fields';
      out[group][k] = state.values[k];
    });
    return out;
  }

  function save(force) {
    if (!state.dirty) return;
    state.saveBtn.disabled = true;
    state.statusEl.textContent = 'Guardando…';
    post('save.php', payload(force)).then(function (d) {
      if (d.conflict) return conflict(d.conflict);
      if (!d.ok) { state.statusEl.textContent = 'Error: ' + (d.error || ''); state.saveBtn.disabled = false; return; }
      Object.keys(state.values).forEach(function (k) {
        state.original[k] = clone(state.values[k]);
        var target = payload().images[k] !== undefined && cfg.languages ? state.data.shared : state.data.values;
        target[k] = clone(state.values[k]);
      });
      state.values = {};
      state.base = d.base || state.base;
      markDirty();
      state.statusEl.textContent = 'Guardado ✓';
    }).catch(function () { state.statusEl.textContent = 'Error de red al guardar'; state.saveBtn.disabled = false; });
  }

  function conflict(keys) {
    var labels = {};
    state.pages.forEach(function (p) { p.sections.forEach(function (s) { s.fields.forEach(function (f) { labels[f.key] = s.title + ' › ' + f.label; }); }); });
    state.statusEl.textContent = 'Sin guardar: hay cambios de otra ventana';
    state.saveBtn.disabled = false;
    var overlay = h('div', { class: 'np-overlay' });
    var close = function () { overlay.remove(); };
    overlay.appendChild(h('div', { class: 'np-dialog', role: 'dialog' }, [
      h('h2', { text: 'Cambios en otra ventana' }),
      h('p', { text: 'Desde que abriste el panel se ha guardado otra versión de:' }),
      h('ul', {}, keys.map(function (k) { return h('li', { text: labels[k] || k }); })),
      h('p', { text: 'Si guardas, se sustituye por lo que tienes aquí. Si recargas, verás la otra versión y perderás tus cambios sin guardar.' }),
      h('div', { class: 'np-dialog-buttons' }, [
        h('button', { type: 'button', class: 'np-button np-secondary', text: 'Recargar', onclick: function () { close(); state.dirty = false; loadContent(state.lang, true); } }),
        h('button', { type: 'button', class: 'np-button', text: 'Guardar lo mío', onclick: function () { close(); save(true); } })
      ])
    ]));
    document.body.appendChild(overlay);
  }

  // ------------------------------------------------------------------ imágenes (como en editor.js)
  function upload(file) {
    return optimize(file).then(function (ready) {
      var fd = new FormData();
      fd.append('image', ready);
      return fetch('upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) { if (!d.ok) throw new Error(d.error || 'No se ha podido subir'); return d.url; });
    });
  }
  function optimize(file) {
    if (!/^image\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap) return Promise.resolve(file);
    return createImageBitmap(file, { imageOrientation: 'from-image' }).then(function (bmp) {
      var scale = Math.min(1, MAX_SIDE / Math.max(bmp.width, bmp.height));
      var canvas = document.createElement('canvas');
      canvas.width = Math.round(bmp.width * scale);
      canvas.height = Math.round(bmp.height * scale);
      canvas.getContext('2d').drawImage(bmp, 0, 0, canvas.width, canvas.height);
      var base = file.name.replace(/\.[^.]+$/, '') || 'imagen';
      return toBlob(canvas, 'image/webp').then(function (blob) {
        if (blob && blob.type === 'image/webp') return new File([blob], base + '.webp', { type: 'image/webp' });
        var type = file.type === 'image/png' ? 'image/png' : 'image/jpeg';
        return toBlob(canvas, type).then(function (b) { return b ? new File([b], base + (type === 'image/png' ? '.png' : '.jpg'), { type: type }) : file; });
      }).then(function (out) { return out.size < file.size || scale < 1 ? out : file; });
    }).catch(function () { return file; });
  }
  function toBlob(canvas, type) { return new Promise(function (resolve) { canvas.toBlob(resolve, type, QUALITY); }); }

  // ------------------------------------------------------------------ arranque
  if (!cfg.loggedIn) { showLogin(); return; }
  layout();
  loadHome();
})();
