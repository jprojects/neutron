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

  // ------------------------------------------------------------------ idioma (lib/panel_lang.php)
  // Los textos están en castellano; cfg.i18n los traduce (claves con {} para las partes que cambian). Pasan por t() los
  // textos y títulos de h(), los avisos (alert, confirm, prompt) y los estados.
  var DICT = cfg.i18n || {}, PATTERNS = null, LOCALE = cfg.ui === 'ca' ? 'ca-ES' : LOCALE;
  function t(s) {
    if (typeof s !== 'string' || !s || cfg.ui === 'es' || !cfg.ui) return s;
    if (Object.prototype.hasOwnProperty.call(DICT, s)) return DICT[s];
    if (PATTERNS === null) {
      PATTERNS = Object.keys(DICT).filter(function (k) { return k.indexOf('{}') >= 0; })
        .sort(function (a, b) { return b.replace(/\{\}/g, '').length - a.replace(/\{\}/g, '').length; })
        .map(function (k) {
          return [new RegExp('^' + k.split('{}').map(function (x) { return x.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }).join('([\\s\\S]+?)') + '$'), DICT[k]];
        });
    }
    for (var i = 0; i < PATTERNS.length; i++) {
      var m = s.match(PATTERNS[i][0]);
      if (m) { var n = 0; return PATTERNS[i][1].replace(/\{\}/g, function () { return t(m[++n]); }); }
    }
    return s;
  }
  function alert(m) { window.alert(t(m)); }
  function confirm(m) { return window.confirm(t(m)); }
  function prompt(m, v) { return window.prompt(t(m), v); }

  // ------------------------------------------------------------------ utilidades
  function h(tag, attrs, children) {
    var el = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') el.textContent = t(attrs[k]);
      else if ((k === 'title' || k === 'placeholder' || k === 'aria-label') && typeof attrs[k] === 'string') el.setAttribute(k, t(attrs[k]));
      else if (k === 'html') el.innerHTML = attrs[k];
      else if (k.slice(0, 2) === 'on') el.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] !== null && attrs[k] !== undefined && attrs[k] !== false) el.setAttribute(k, attrs[k] === true ? '' : attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) el.appendChild(typeof c === 'string' ? document.createTextNode(t(c)) : c); });
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
        else { error.textContent = t(d.error || 'Error de acceso'); error.hidden = false; }
      }).catch(function () { error.textContent = t('Error de red'); error.hidden = false; });
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
    var cols = (cfg.collections || []).map(function (c) { return ['col:' + c.name, c.label]; });
    [['home', 'Inicio'], ['content', 'Contenido']].concat(cols, cfg.menu ? [['menu', 'Menú']] : [], [['messages', 'Mensajes'], ['history', 'Copias'], ['chat', 'Chat']], cfg.seo ? [['seo', 'SEO']] : []).forEach(function (item) {
      nav.appendChild(h('button', { type: 'button', class: 'np-nav-item' + (state.view === item[0] ? ' is-active' : ''), onclick: function () { go(item[0]); } },
        [item[1], item[0] === 'messages' && state.unread ? h('span', { class: 'np-badge', text: String(state.unread) }) : null]));
    });
  }

  function go(view) {
    if (view === state.view) return;
    if (!confirmLeave()) return;
    state.dirty = false;
    state.view = view;
    renderNav();
    if (view.indexOf('col:') === 0) loadCollection(view.slice(4));
    else if (view === 'home') loadHome(); else if (view === 'seo') loadSeo(); else if (view === 'messages') loadMessages();
    else if (view === 'history') loadHistory(); else if (view === 'menu') loadMenu(); else if (view === 'chat') loadChat(); else loadContent();
  }

  function confirmLeave() {
    return !state.dirty || confirm('Hay cambios sin guardar. ¿Salir sin guardarlos?');
  }
  window.addEventListener('beforeunload', function (e) { if (state.dirty) { e.preventDefault(); e.returnValue = ''; } });

  // ------------------------------------------------------------------ inicio
  function loadHome() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    Promise.all([api('home'), api('content')]).then(function (r) {
      var home = r[0], content = r[1];
      document.querySelector('.np-site').textContent = home.name;
      document.title = t('Panel') + ' · ' + home.name;
      var where = keyIndex(content.schema);
      state.unread = home.unread || 0;
      renderNav();
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
      if (home.unread) {
        main.appendChild(h('button', { type: 'button', class: 'np-alert', onclick: function () { go('messages'); } },
          [h('strong', { text: home.unread === 1 ? '1 mensaje nuevo' : home.unread + ' mensajes nuevos' }), h('span', { text: 'del formulario de contacto · Ver →' })]));
      }
      var langs = home.languages ? Object.keys(home.languages.languages).length : 1;
      main.appendChild(h('div', { class: 'np-stats' }, [
        stat(home.pages, home.pages === 1 ? 'página' : 'páginas'),
      ].concat((home.collections || []).slice(0, 3).map(function (c) { return stat(c.count, c.label); }),
        [langs > 1 ? stat(langs, 'idiomas') : null])));
      if (home.stats) main.appendChild(statsCard(home.stats));
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
  // Visitas de los últimos 30 días (lib/stats.php): totales, una barra por día y las páginas más vistas
  function statsCard(st) {
    var days = Object.keys(st.days), max = Math.max.apply(null, days.map(function (d) { return st.days[d].views; }).concat([1]));
    var chart = h('div', { class: 'np-chart', role: 'img', 'aria-label': 'Visitas por día' }, days.map(function (d) {
      var v = st.days[d], day = new Date(d + 'T12:00');
      return h('span', { class: 'np-bar-day' + (day.getDay() === 0 || day.getDay() === 6 ? ' is-weekend' : ''), style: 'height:' + Math.max(2, Math.round(v.views * 100 / max)) + '%',
        title: day.toLocaleDateString(LOCALE, { weekday: 'short', day: 'numeric', month: 'short' }) + ': ' + v.views + ' páginas vistas, ' + v.visitors + ' visitantes' });
    }));
    var pages = Object.keys(st.pages);
    return h('section', { class: 'np-card' }, [
      h('div', { class: 'np-card-head' }, [h('h2', { text: 'Visitas' }), h('span', { class: 'np-muted np-small', text: 'Últimos 30 días · sin cookies' })]),
      h('div', { class: 'np-visits' }, [
        h('div', {}, [h('strong', { text: st.visitors.toLocaleString(LOCALE) }), h('span', { text: 'visitantes' })]),
        h('div', {}, [h('strong', { text: st.views.toLocaleString(LOCALE) }), h('span', { text: 'páginas vistas' })])
      ]),
      chart,
      pages.length ? h('ul', { class: 'np-recent np-top-pages' }, pages.map(function (p) {
        return h('li', {}, [h('span', { text: p === '/' || p === '' ? 'Portada' : decodeURIComponent(p) }), h('span', { class: 'np-muted', text: String(st.pages[p]) })]);
      })) : h('p', { class: 'np-muted np-small', text: 'Todavía no hay visitas contadas.' })
    ]);
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
  function failed() { main.innerHTML = '<p class="np-error">' + t('No se han podido cargar los datos. Recarga la página.') + '</p>'; }

  // ------------------------------------------------------------------ contenido
  function loadContent(lang, keepPage) {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
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
        status.textContent = t('Guardando…');
        var langs = Object.keys(inputs);
        Promise.all(langs.map(function (l) {
          var payload = { lang: l, fields: {}, images: {}, collections: {}, source: 'ai' };
          payload.fields[f.key] = inputs[l].value;
          return post('save.php', payload);
        })).then(function (rs) {
          var bad = rs.filter(function (r) { return !r.ok; });
          status.textContent = t(bad.length ? 'Error: ' + (bad[0].error || '') : 'Guardado en ' + langs.map(function (l) { return l.toUpperCase(); }).join(', ') + ' ✓');
          if (bad.length) e.target.disabled = false;
        }).catch(function () { status.textContent = t('Error de red'); e.target.disabled = false; });
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
      msg.textContent = t('Preparando imagen…');
      upload(input.files[0]).then(function (url) {
        img.src = src(url);
        msg.textContent = t('');
        onChange(url);
      }).catch(function (err) { msg.textContent = t(err.message || 'No se ha podido subir'); });
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
    state.statusEl.textContent = t(state.dirty ? 'Cambios sin guardar' : '');
  }




  // ------------------------------------------------------------------ colecciones (admin/items.php)
  // Blog, documentos, galería, vídeos…: la lista y la ficha salen de la definición de cada colección.
  var col = { name: null, def: null, items: [], lang: null, search: '', category: '' };

  function itemsApi(params) {
    var q = Object.keys(params).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch('items.php?' + q, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
  }
  function itemsPost(body) { return post('items.php', body); }

  function loadCollection(name, keepFilters) {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    if (!keepFilters || col.name !== name) { col.search = ''; col.category = ''; }
    col.name = name;
    col.lang = col.lang || (cfg.languages ? cfg.languages.default : null);
    Promise.all([itemsApi({ a: 'collections', lang: col.lang || '' }), itemsApi({ a: 'list', c: name, lang: col.lang || '' })]).then(function (r) {
      col.def = r[0].collections.filter(function (c) { return c.name === name; })[0];
      col.items = r[1].items || [];
      renderCollection();
    }).catch(failed);
  }

  function fieldOf(type) { return col.def.fields.filter(function (f) { return f.type === type; })[0]; }
  function categoryTitle(id) {
    var c = col.def.categories.filter(function (x) { return x.id === id; })[0];
    return c ? c.title : '';
  }

  function langSelect(onChange) {
    if (!cfg.languages) return null;
    var sel = h('select', { class: 'np-select', 'aria-label': 'Idioma', onchange: function () { col.lang = sel.value; onChange(); } },
      Object.keys(cfg.languages.languages).map(function (code) { return h('option', { value: code, selected: code === col.lang, text: cfg.languages.languages[code] }); }));
    return sel;
  }

  function renderCollection() {
    var d = col.def;
    main.innerHTML = '';
    var add = h('button', { type: 'button', class: 'np-button', text: '+ Nuevo: ' + d.item.toLowerCase(), onclick: function () { createItem(); } });
    main.appendChild(h('header', { class: 'np-head' }, [h('h1', { text: d.label }), add]));
    if (d.help) main.appendChild(h('p', { class: 'np-muted', text: d.help }));
    var tools = [h('input', { type: 'search', class: 'np-input np-search', placeholder: 'Buscar…', value: col.search, oninput: function (e) { col.search = e.target.value; drawList(); } })];
    var catField = fieldOf('category');
    if (catField) {
      var cat = h('select', { class: 'np-select', onchange: function () { col.category = cat.value; drawList(); } },
        [h('option', { value: '', text: 'Todas las categorías' })].concat(d.categories.map(function (c) { return h('option', { value: c.id, selected: c.id === col.category, text: c.title }); })));
      tools.push(cat, h('button', { type: 'button', class: 'np-link', text: 'Categorías…', onclick: function () { editCategories(); } }));
    }
    var ls = langSelect(function () { loadCollection(col.name, true); });
    if (ls) tools.push(ls);
    main.appendChild(h('div', { class: 'np-tools np-col-tools' }, tools));
    var list = h('section', { class: 'np-card np-items' });
    main.appendChild(list);

    function drawList() {
      list.innerHTML = '';
      var q = col.search.trim().toLowerCase();
      var rows = col.items.filter(function (it) {
        return (!q || (it.title + ' ' + (it.file || '')).toLowerCase().indexOf(q) >= 0) && (!col.category || it.category === col.category);
      });
      if (!rows.length) { list.appendChild(h('p', { class: 'np-muted', text: col.items.length ? 'Nada coincide con la búsqueda.' : 'Todavía no hay ninguno. Pulsa «+ Nuevo».' })); return; }
      var manual = d.sort === 'order' && !q && !col.category;
      rows.forEach(function (it, i) {
        var meta = [];
        if (it.category) meta.push(categoryTitle(it.category));
        if (it.date) meta.push(new Date(it.date + 'T12:00').toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }));
        if (it.images !== undefined) meta.push(it.images + (it.images === 1 ? ' foto' : ' fotos'));
        if (it.file) meta.push(it.file);
        if (it.downloads) meta.push(it.downloads + ' descargas');
        list.appendChild(h('div', { class: 'np-item-row' }, [
          it.thumb ? h('img', { class: 'np-item-thumb', src: src(it.thumb), alt: '', loading: 'lazy' }) : h('span', { class: 'np-item-thumb np-item-thumb-empty', text: fileIcon(it) }),
          h('button', { type: 'button', class: 'np-item-main', onclick: function () { openItem(it.slug); } }, [
            h('strong', { text: it.title || '(sin título)' }), h('span', { class: 'np-muted np-small', text: meta.join(' · ') })]),
          it.status !== 'published' ? h('span', { class: 'np-tag np-tag-warn', text: 'Borrador' }) : null,
          cfg.languages && col.lang !== cfg.languages.default && !it.translated ? h('span', { class: 'np-tag', text: 'Sin traducir' }) : null,
          manual ? h('span', { class: 'np-item-tools' }, [
            h('button', { type: 'button', title: 'Subir', text: '↑', disabled: i === 0, onclick: function () { moveItem(i, -1); } }),
            h('button', { type: 'button', title: 'Bajar', text: '↓', disabled: i === rows.length - 1, onclick: function () { moveItem(i, 1); } })]) : null
        ]));
      });
    }
    drawList();
  }

  function fileIcon(it) { return it.file ? (it.file.split('.').pop() || '').toUpperCase().slice(0, 4) : '—'; }

  function moveItem(i, delta) {
    var it = col.items.splice(i, 1)[0];
    col.items.splice(i + delta, 0, it);
    renderCollection();
    itemsPost({ a: 'order', c: col.name, slugs: col.items.map(function (x) { return x.slug; }) });
  }

  function createItem() {
    var title = prompt('Título del nuevo ' + col.def.item.toLowerCase() + ':');
    if (!title || !title.trim()) return;
    itemsPost({ a: 'create', c: col.name, title: title.trim(), lang: col.lang || '' }).then(function (r) {
      if (!r.ok) { alert(r.error || 'No se ha podido crear'); return; }
      openItem(r.slug);
    });
  }

  // Ficha de un elemento: un control por campo de la definición
  function openItem(slug) {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    itemsApi({ a: 'get', c: col.name, i: slug, lang: col.lang || '' }).then(function (r) {
      if (!r.ok) { main.innerHTML = ''; main.appendChild(h('p', { class: 'np-error', text: r.error })); return; }
      var it = r.item, values = {}, d = col.def, dirty = false;
      var status = h('span', { class: 'np-status' });
      var translating = cfg.languages && col.lang !== cfg.languages.default;
      var published = h('input', { type: 'checkbox', checked: it.status === 'published', onchange: function () { dirty = true; status.textContent = t('Cambios sin guardar'); } });
      var saveBtn = h('button', { type: 'button', class: 'np-button', text: 'Guardar', onclick: function () {
        saveBtn.disabled = true; status.textContent = t('Guardando…');
        itemsPost({ a: 'save', c: col.name, i: slug, lang: col.lang || '', fields: values, status: published.checked ? 'published' : 'draft' }).then(function (res) {
          saveBtn.disabled = false;
          if (!res.ok) { status.textContent = t('Error: ' + (res.error || '')); return; }
          dirty = false; state.dirty = false; status.textContent = t('Guardado ✓');
        }).catch(function () { saveBtn.disabled = false; status.textContent = t('Error de red'); });
      } });
      var back = h('button', { type: 'button', class: 'np-link', text: '← ' + d.label, onclick: function () {
        if (dirty && !confirm('Hay cambios sin guardar. ¿Salir sin guardarlos?')) return;
        state.dirty = false; loadCollection(col.name, true);
      } });
      main.innerHTML = '';
      var ls = langSelect(function () { openItem(slug); });
      main.appendChild(h('header', { class: 'np-head np-sticky' }, [
        h('div', { class: 'np-tools' }, [back, ls]),
        h('div', { class: 'np-actions' }, [h('label', { class: 'np-check' }, [published, ' Publicado']), status, saveBtn])
      ]));
      var card = h('section', { class: 'np-card' });
      d.fields.forEach(function (f) {
        var set = function (v) { values[f.name] = v; dirty = true; state.dirty = true; status.textContent = t('Cambios sin guardar'); };
        var label = h('div', { class: 'np-label' }, [h('span', { text: f.label })]);
        if (translating && d.translated.indexOf(f.name) >= 0 && r.own.indexOf(f.name) < 0 && it[f.name]) label.appendChild(h('span', { class: 'np-tag np-tag-warn', text: 'Sin traducir' }));
        if (translating && d.translated.indexOf(f.name) < 0) label.appendChild(h('span', { class: 'np-tag', text: 'Común a todos los idiomas' }));
        card.appendChild(h('div', { class: 'np-field' }, [label, itemControl(f, it[f.name], set, r.files[f.name])]));
      });
      main.appendChild(card);
      main.appendChild(h('div', { class: 'np-ai-actions' }, [
        r.url ? h('a', { class: 'np-button np-secondary', href: '../' + r.url + '?admin', target: '_blank', rel: 'noopener', text: 'Ver en la web ↗' }) : null,
        h('button', { type: 'button', class: 'np-button np-secondary np-danger', text: 'Borrar', onclick: function () {
          if (!confirm('¿Borrar «' + (it.title || '') + '»?')) return;
          itemsPost({ a: 'delete', c: col.name, i: slug }).then(function (res) {
            if (!res.ok) { alert(res.error || 'No se ha podido borrar'); return; }
            state.dirty = false; loadCollection(col.name, true);
          });
        } })
      ]));
    }).catch(failed);
  }

  function itemControl(f, value, onChange, fileInfo) {
    switch (f.type) {
      case 'textarea': return control({ type: 'text', multiline: true }, value || '', onChange);
      case 'html': return richControl(value || '', onChange);
      case 'image': return imageControl(value || '', onChange);
      case 'images': return imagesControl(Array.isArray(value) ? value.slice() : [], onChange);
      case 'file': return fileControl(value || '', onChange, fileInfo);
      case 'date': return h('input', { type: 'date', class: 'np-input np-input-short', value: value || '', oninput: function (e) { onChange(e.target.value); } });
      case 'category':
        var sel = h('select', { class: 'np-select', onchange: function () { onChange(sel.value); } },
          [h('option', { value: '', text: '— Sin categoría —' })].concat(col.def.categories.map(function (c) { return h('option', { value: c.id, selected: c.id === value, text: c.title }); })));
        return sel;
      case 'video':
        var thumb = h('img', { class: 'np-video-thumb', alt: '', hidden: !value });
        var showThumb = function (url) {
          var m = /(?:youtube(?:-nocookie)?\.com\/(?:watch\?(?:.*&)?v=|embed\/|shorts\/|live\/)|youtu\.be\/)([\w-]{11})/.exec(url || '');
          thumb.hidden = !m; if (m) thumb.src = 'https://i.ytimg.com/vi/' + m[1] + '/mqdefault.jpg';
        };
        showThumb(value);
        return h('div', { class: 'np-video' }, [h('input', { type: 'url', class: 'np-input', value: value || '', placeholder: 'https://www.youtube.com/watch?v=…',
          oninput: function (e) { onChange(e.target.value.trim()); showThumb(e.target.value); } }), thumb]);
      case 'url': return h('input', { type: 'url', class: 'np-input', value: value || '', placeholder: 'https://…', oninput: function (e) { onChange(e.target.value.trim()); } });
      case 'tel': return h('input', { type: 'tel', class: 'np-input np-input-short', value: value || '', placeholder: '600 000 000', oninput: function (e) { onChange(e.target.value.trim()); } });
      case 'email': return h('input', { type: 'email', class: 'np-input', value: value || '', placeholder: 'nom@exemple.cat', oninput: function (e) { onChange(e.target.value.trim()); } });
      case 'options':
        // Casillas con su icono; se guardan los id marcados
        var chosen = Array.isArray(value) ? value.slice() : [];
        var lang = (cfg.languages && (col.lang || cfg.languages.default)) || '';
        return h('div', { class: 'np-options' }, (f.options || []).map(function (o) {
          var label = typeof o.label === 'object' ? (o.label[lang] || o.label[Object.keys(o.label)[0]]) : o.label;
          return h('label', { class: 'np-option' }, [
            h('input', { type: 'checkbox', checked: chosen.indexOf(o.id) >= 0, onchange: function (e) {
              chosen = chosen.filter(function (x) { return x !== o.id; });
              if (e.target.checked) chosen.push(o.id);
              onChange(chosen.slice());
            } }),
            o.icon ? h('img', { src: src(o.icon), alt: '' }) : null,
            h('span', { text: label })
          ]);
        }));
      default: return control({ type: 'text', multiline: false }, value || '', onChange);
    }
  }

  // Varias imágenes (un álbum): subir muchas a la vez, quitar y cambiar el orden
  function imagesControl(list, onChange) {
    var box = h('div', { class: 'np-images' });
    var msg = h('span', { class: 'np-muted np-small' });
    var input = h('input', { type: 'file', accept: 'image/jpeg,image/png,image/webp,image/gif', multiple: true, hidden: true, onchange: function () {
      var files = Array.prototype.slice.call(input.files);
      input.value = '';
      var done = 0;
      var next = function () {
        if (!files.length) { msg.textContent = t(done ? done + (done === 1 ? ' foto añadida' : ' fotos añadidas') : ''); return; }
        msg.textContent = t('Subiendo ' + (done + 1) + ' de ' + (done + files.length) + '…');
        upload(files.shift()).then(function (url) { list.push(url); done++; onChange(list.slice()); draw(); next(); })
          .catch(function (err) { msg.textContent = t(err.message || 'No se ha podido subir'); });
      };
      next();
    } });
    function draw() {
      box.innerHTML = '';
      list.forEach(function (url, i) {
        box.appendChild(h('div', { class: 'np-images-item' }, [h('img', { src: src(url), alt: '', loading: 'lazy' }), h('span', { class: 'np-item-tools' }, [
          h('button', { type: 'button', title: 'Antes', text: '←', disabled: i === 0, onclick: function () { list.splice(i - 1, 0, list.splice(i, 1)[0]); onChange(list.slice()); draw(); } }),
          h('button', { type: 'button', title: 'Después', text: '→', disabled: i === list.length - 1, onclick: function () { list.splice(i + 1, 0, list.splice(i, 1)[0]); onChange(list.slice()); draw(); } }),
          h('button', { type: 'button', title: 'Quitar', text: '✕', onclick: function () { list.splice(i, 1); onChange(list.slice()); draw(); } })
        ])]));
      });
    }
    draw();
    return h('div', {}, [box, h('div', { class: 'np-ai-actions' }, [
      h('button', { type: 'button', class: 'np-button np-secondary', text: '+ Añadir fotos', onclick: function () { input.click(); } }), input, msg])]);
  }

  // Un documento: se sube con admin/upload.php (campo «file») a site/uploads/docs/
  function fileControl(value, onChange, info) {
    var name = h('strong', { text: value ? value.split('/').pop() : 'Ningún fichero' });
    var icon = h('span', { class: 'np-file-icon', text: value ? value.split('.').pop().toUpperCase().slice(0, 4) : '—' });
    var detail = h('span', { class: 'np-muted np-small', text: info ? [info.size ? sizeText(info.size) : '', info.downloads ? info.downloads + ' descargas' : ''].filter(Boolean).join(' · ') : '' });
    var msg = h('span', { class: 'np-muted np-small' });
    var input = h('input', { type: 'file', hidden: true, accept: '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.odp,.txt,.csv,.rtf,.zip', onchange: function () {
      var file = input.files[0];
      input.value = '';
      if (!file) return;
      msg.textContent = t('Subiendo ' + file.name + ' (' + sizeText(file.size) + ')…');
      var fd = new FormData();
      fd.append('file', file);
      fetch('upload.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-CSRF-Token': cfg.csrf }, body: fd })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (!d.ok) { msg.textContent = t(d.error || 'No se ha podido subir'); return; }
          name.textContent = d.url.split('/').pop(); detail.textContent = sizeText(d.size); msg.textContent = t('Subido. Recuerda guardar.');
          icon.textContent = d.url.split('.').pop().toUpperCase().slice(0, 4);
          onChange(d.url);
        }).catch(function () { msg.textContent = t('Error de red al subir'); });
    } });
    return h('div', { class: 'np-file' }, [icon,
      h('div', {}, [name, detail]), h('button', { type: 'button', class: 'np-button np-secondary', text: value ? 'Cambiar fichero' : 'Subir fichero', onclick: function () { input.click(); } }), input, msg]);
  }
  function sizeText(b) { return b > 1048576 ? (b / 1048576).toFixed(1).replace('.', ',') + ' MB' : Math.max(1, Math.round(b / 1024)) + ' KB'; }

  function editCategories() {
    var cats = col.def.categories.map(function (c) {
      var t = cfg.languages && col.lang !== cfg.languages.default && c.i18n && c.i18n[col.lang] ? c.i18n[col.lang] : c;
      return { id: c.id, title: t.title || c.title, description: t.description || '' };
    });
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head' }, [h('div', {}, [h('button', { type: 'button', class: 'np-link', text: '← ' + col.def.label, onclick: function () { loadCollection(col.name, true); } }),
      h('h1', { text: 'Categorías' })])]));
    var box = h('section', { class: 'np-card' });
    main.appendChild(box);
    function draw() {
      box.innerHTML = '';
      cats.forEach(function (c, i) {
        box.appendChild(h('div', { class: 'np-item' }, [
          h('div', { class: 'np-item-head' }, [h('span', { class: 'np-muted np-small', text: c.id ? 'Categoría' : 'Nueva' }), h('span', { class: 'np-item-tools' }, [
            h('button', { type: 'button', text: '↑', disabled: i === 0, onclick: function () { cats.splice(i - 1, 0, cats.splice(i, 1)[0]); draw(); } }),
            h('button', { type: 'button', text: '↓', disabled: i === cats.length - 1, onclick: function () { cats.splice(i + 1, 0, cats.splice(i, 1)[0]); draw(); } }),
            h('button', { type: 'button', text: '✕', onclick: function () { if (confirm('¿Quitar la categoría? Sus elementos quedan sin categoría.')) { cats.splice(i, 1); draw(); } } })])]),
          h('input', { class: 'np-input', value: c.title, placeholder: 'Nombre', oninput: function (e) { c.title = e.target.value; } }),
          h('textarea', { class: 'np-input', rows: 2, placeholder: 'Descripción (opcional)', oninput: function (e) { c.description = e.target.value; } }, [c.description])
        ]));
      });
      box.appendChild(h('div', { class: 'np-ai-actions' }, [
        h('button', { type: 'button', class: 'np-button np-secondary', text: '+ Añadir categoría', onclick: function () { cats.push({ id: '', title: '', description: '' }); draw(); } }),
        h('button', { type: 'button', class: 'np-button', text: 'Guardar categorías', onclick: function () {
          itemsPost({ a: 'categories', c: col.name, lang: col.lang || '', categories: cats }).then(function (r) {
            if (!r.ok) { alert(r.error || 'No se han podido guardar'); return; }
            loadCollection(col.name, true);
          });
        } })
      ]));
    }
    draw();
  }

  // ------------------------------------------------------------------ menú (lib/menu.php, site/menu.json)
  function loadMenu() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    api('menu').then(renderMenu).catch(failed);
  }

  function renderMenu(d) {
    var items = d.items.map(function (it) { return { href: it.href, label: clone(it.label), button: !!it.button, hidden: !!it.hidden }; });
    var codes = cfg.languages ? Object.keys(cfg.languages.languages) : [null];
    var status = h('span', { class: 'np-status' });
    var saveBtn = h('button', { type: 'button', class: 'np-button', text: 'Guardar menú', disabled: true, onclick: save });
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head np-sticky' }, [h('h1', { text: 'Menú' }), h('div', { class: 'np-actions' }, [status, saveBtn])]));
    main.appendChild(h('p', { class: 'np-muted np-small', text: 'Los enlaces del menú de la web, en este orden. Un enlace puede ir a una página de la web o a otra web (se abre aparte).' +
      (d.custom ? '' : ' Ahora es el de la plantilla: al guardar, pasa a ser el de aquí.') }));
    var targets = h('datalist', { id: 'np-menu-targets' }, d.targets.map(function (t) { return h('option', { value: t }); }));
    var box = h('section', { class: 'np-card' });
    main.appendChild(targets);
    main.appendChild(box);
    if (d.custom) main.appendChild(h('p', {}, [h('button', { type: 'button', class: 'np-link', text: 'Volver al menú de la plantilla', onclick: function () {
      if (!confirm('¿Volver al menú de la plantilla? Se pierde el de aquí.')) return;
      post('api.php?a=menu-save', { reset: true }).then(function (r) { if (r.ok) loadMenu(); else alert(r.error); });
    } })]));
    function changed() { state.dirty = true; saveBtn.disabled = false; status.textContent = t('Cambios sin guardar'); }
    function move(i, dir) { items.splice(i + dir, 0, items.splice(i, 1)[0]); changed(); draw(); }
    function draw() {
      box.innerHTML = '';
      items.forEach(function (it, i) {
        var labels = codes.map(function (code) {
          return h('label', { class: 'np-field' }, [h('span', { class: 'np-label', text: code ? 'Texto (' + cfg.languages.languages[code] + ')' : 'Texto' }),
            h('input', { class: 'np-input', value: it.label[code || 'ca'] || (code ? '' : it.label[Object.keys(it.label)[0]] || ''), oninput: function (e) {
              if (!code) it.label = {};
              it.label[code || 'ca'] = e.target.value; changed();
            } })]);
        });
        box.appendChild(h('div', { class: 'np-item' + (it.hidden ? ' is-hidden' : '') }, [
          h('div', { class: 'np-item-head' }, [h('strong', { text: (it.label[state.lang || (cfg.languages && cfg.languages.default) || 'ca'] || it.label[Object.keys(it.label)[0]] || 'Enlace nuevo') }),
            h('span', { class: 'np-item-tools' }, [
              h('button', { type: 'button', title: 'Subir', text: '↑', disabled: i === 0, onclick: function () { move(i, -1); } }),
              h('button', { type: 'button', title: 'Bajar', text: '↓', disabled: i === items.length - 1, onclick: function () { move(i, 1); } }),
              h('button', { type: 'button', title: 'Quitar', text: '✕', onclick: function () { if (confirm('¿Quitar este enlace del menú?')) { items.splice(i, 1); changed(); draw(); } } })])]),
          h('label', { class: 'np-field' }, [h('span', { class: 'np-label', text: 'Va a' }),
            h('input', { class: 'np-input', list: 'np-menu-targets', value: it.href, placeholder: 'pagina.html o https://…', oninput: function (e) { it.href = e.target.value.trim(); changed(); } })])
        ].concat(labels, [h('div', { class: 'np-tools' }, [
          h('label', { class: 'np-check' }, [h('input', { type: 'checkbox', checked: it.hidden, onchange: function (e) { it.hidden = e.target.checked; changed(); } }), ' Ocultar']),
          h('label', { class: 'np-check' }, [h('input', { type: 'checkbox', checked: it.button, onchange: function (e) { it.button = e.target.checked; changed(); } }), ' Destacado (botón)'])
        ])])));
      });
      box.appendChild(h('div', { class: 'np-ai-actions' }, [h('button', { type: 'button', class: 'np-button np-secondary', text: '+ Añadir enlace', onclick: function () {
        var label = {}; codes.forEach(function (c) { label[c || 'ca'] = ''; });
        items.push({ href: '', label: label, button: false, hidden: false }); changed(); draw();
      } })]));
    }
    function save() {
      var bad = items.filter(function (it) { return !it.href || !Object.keys(it.label).some(function (k) { return (it.label[k] || '').trim(); }); });
      if (bad.length) { alert('Cada enlace necesita a dónde va y su texto.'); return; }
      saveBtn.disabled = true; status.textContent = t('Guardando…');
      post('api.php?a=menu-save', { items: items }).then(function (r) {
        if (!r.ok) { saveBtn.disabled = false; status.textContent = t(r.error || 'No se ha podido guardar'); return; }
        state.dirty = false; status.textContent = t('Guardado');
      }).catch(function () { saveBtn.disabled = false; status.textContent = t('No se ha podido guardar'); });
    }
    draw();
  }

  // ------------------------------------------------------------------ chat de Kepler (lib/kepler.php, data/kepler.json)
  function loadChat() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    api('kepler').then(renderChat).catch(failed);
  }

  function renderChat(d) {
    var status = h('span', { class: 'np-status' });
    var enabled = h('input', { type: 'checkbox', checked: d.enabled });
    var token = h('input', { class: 'np-input', value: d.token, placeholder: t('El valor de data-kepler'), spellcheck: 'false', autocomplete: 'off',
      oninput: function () { var m = token.value.match(/data-kepler="([0-9a-fA-F]{32})"/); if (m) token.value = m[1]; changed(); } });
    var saveBtn = h('button', { type: 'button', class: 'np-button', text: t('Guardar'), disabled: true, onclick: save });
    function changed() { state.dirty = true; saveBtn.disabled = false; status.textContent = t('Cambios sin guardar'); }
    enabled.addEventListener('change', changed);
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head np-sticky' }, [h('h1', { text: t('Chat') }), h('div', { class: 'np-actions' }, [status, saveBtn])]));
    main.appendChild(h('p', { class: 'np-muted np-small', text: t('El asistente de Kepler contesta a los clientes en todas las páginas de la web. Copia el código de tu panel de Kepler (Ponerlo en tu web) y pégalo aquí: basta con el código entero o solo el valor de data-kepler.') }));
    main.appendChild(h('section', { class: 'np-card' }, [
      h('label', { class: 'np-field' }, [h('span', { class: 'np-label', text: t('Código del chat') }), token]),
      h('label', { class: 'np-check' }, [enabled, ' ' + t('Mostrar el chat en la web')]),
      d.source === 'config' ? h('p', { class: 'np-muted np-small', text: t('Ahora sale de la configuración del servidor; si lo guardas aquí, manda lo de aquí.') }) : null,
      h('p', { class: 'np-small' }, [h('a', { class: 'np-link', href: d.url, target: '_blank', rel: 'noopener', text: t('Abrir el panel de Kepler ↗') })])
    ]));
    function save() {
      var v = token.value.trim();
      if (v && !/^[0-9a-fA-F]{32}$/.test(v)) { alert(t('El código no es válido: copia el valor de data-kepler del panel de Kepler')); return; }
      saveBtn.disabled = true; status.textContent = t('Guardando…');
      post('api.php?a=kepler-save', { token: v, enabled: enabled.checked }).then(function (r) {
        if (!r.ok) { saveBtn.disabled = false; status.textContent = t(r.error || 'No se ha podido guardar'); return; }
        state.dirty = false; status.textContent = t('Guardado');
      }).catch(function () { saveBtn.disabled = false; status.textContent = t('No se ha podido guardar'); });
    }
  }

  // ------------------------------------------------------------------ mensajes del formulario (lib/messages.php)
  var inbox = { items: [], current: null, total: 0, keep: 365 };

  function loadMessages() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    api('messages').then(function (d) {
      inbox.items = d.items; inbox.total = d.total; inbox.keep = d.keep_days;
      state.unread = d.unread; renderNav();
      renderMessages();
    }).catch(failed);
  }

  function dateText(iso) {
    var d = new Date(iso);
    return d.toLocaleDateString(LOCALE, { day: 'numeric', month: 'short', year: 'numeric' }) + ' · ' + d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' });
  }

  function renderMessages() {
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head' }, [h('h1', { text: 'Mensajes' }),
      h('span', { class: 'np-muted', text: inbox.total + (inbox.total === 1 ? ' mensaje' : ' mensajes') })]));
    main.appendChild(h('p', { class: 'np-muted np-small', text: 'Los mensajes del formulario de contacto de la web. También llegan por correo; aquí se guardan ' + inbox.keep + ' días.' }));
    if (!inbox.items.length) { main.appendChild(h('section', { class: 'np-card' }, [h('p', { class: 'np-muted', text: 'Todavía no ha llegado ningún mensaje.' })])); return; }
    var detail = h('section', { class: 'np-card np-msg-detail' }, [h('p', { class: 'np-muted', text: 'Elige un mensaje para leerlo.' })]);
    var list = h('div', { class: 'np-card np-msg-list' }, inbox.items.map(function (m) {
      return h('button', { type: 'button', class: 'np-msg' + (m.read ? '' : ' is-unread') + (inbox.current === m.id ? ' is-active' : ''), onclick: function () { openMessage(m, detail, list); } }, [
        h('span', { class: 'np-msg-top' }, [h('strong', { text: m.from || 'Sin nombre' }), h('span', { class: 'np-muted np-small', text: ago(Date.parse(m.at) / 1000) })]),
        h('span', { class: 'np-muted np-small', text: m.excerpt || '' })
      ]);
    }));
    main.appendChild(h('div', { class: 'np-msg-wrap' }, [list, detail]));
  }

  function openMessage(m, detail, list) {
    inbox.current = m.id;
    Array.prototype.forEach.call(list.children, function (b, i) { b.classList.toggle('is-active', inbox.items[i].id === m.id); });
    detail.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    api('message', { id: m.id }).then(function (d) {
      if (!d.ok) { detail.innerHTML = ''; detail.appendChild(h('p', { class: 'np-error', text: d.error })); return; }
      var msg = d.message;
      if (!m.read) { m.read = true; state.unread = Math.max(0, (state.unread || 0) - 1); renderNav(); list.children[inbox.items.indexOf(m)].classList.remove('is-unread'); }
      detail.innerHTML = '';
      detail.appendChild(h('div', { class: 'np-card-head' }, [h('h2', { text: msg.from || 'Mensaje' }), h('span', { class: 'np-muted np-small', text: dateText(msg.at) })]));
      if (msg.mailed === false) detail.appendChild(h('p', { class: 'np-hint', text: 'Este mensaje no se pudo enviar por correo: solo está aquí.' }));
      var dl = h('dl', { class: 'np-msg-fields' });
      msg.fields.forEach(function (f) {
        if (!f.value) return;
        dl.appendChild(h('dt', { text: f.label }));
        dl.appendChild(h('dd', { text: /^(1|on|yes|true)$/i.test(f.value) ? 'Sí' : f.value }));   // casillas (privacidad)
      });
      detail.appendChild(dl);
      if (msg.page) detail.appendChild(h('p', { class: 'np-muted np-small', text: 'Enviado desde ' + msg.page + (msg.lang ? ' (' + msg.lang.toUpperCase() + ')' : '') }));
      var subject = 'Re: ' + (document.querySelector('.np-site').textContent || 'tu mensaje');
      detail.appendChild(h('div', { class: 'np-ai-actions' }, [
        msg.email ? h('a', { class: 'np-button', href: 'mailto:' + encodeURIComponent(msg.email) + '?subject=' + encodeURIComponent(subject), text: 'Responder' }) : null,
        h('button', { type: 'button', class: 'np-button np-secondary', text: 'Marcar como no leído', onclick: function () {
          post('api.php?a=message-unread&id=' + encodeURIComponent(m.id), {}).then(function () { m.read = false; state.unread++; renderNav(); inbox.current = null; renderMessages(); });
        } }),
        h('button', { type: 'button', class: 'np-button np-secondary np-danger', text: 'Borrar', onclick: function () {
          if (!confirm('¿Borrar este mensaje? No se puede deshacer.')) return;
          post('api.php?a=message-delete&id=' + encodeURIComponent(m.id), {}).then(function (r) {
            if (!r.ok) { alert('No se ha podido borrar'); return; }
            inbox.items.splice(inbox.items.indexOf(m), 1); inbox.total--; inbox.current = null; renderMessages();
          });
        } })
      ]));
    }).catch(function () { detail.innerHTML = '<p class="np-error">' + t('Error de red') + '</p>'; });
  }

  // ------------------------------------------------------------------ copias del contenido (lib/history.php)
  var SOURCES = { editor: 'En la web', panel: 'Panel', ai: 'Traducción con IA', restore: 'Antes de volver a una copia' };

  function loadHistory() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    Promise.all([api('history'), api('content'), itemsApi({ a: 'collections' })]).then(function (r) {
      state.historyIndex = fieldIndex(r[1].schema);
      state.historyCols = {};
      (r[2].collections || []).forEach(function (c) { state.historyCols[c.name] = c; });
      renderHistory(r[0].items);
    }).catch(failed);
  }

  // Clave → {page, section, label, type} para poner nombre a los cambios
  function fieldIndex(schema) {
    var out = {};
    function add(pageTitle, sections) {
      sections.forEach(function (s) { s.fields.forEach(function (f) { out[f.key] = out[f.key] || { page: pageTitle, section: s.title, label: f.label, type: f.type }; }); });
    }
    add('Común', schema.common);
    schema.pages.forEach(function (p) { add(p.title, p.sections); });
    return out;
  }

  var ITEM_ACTIONS = { save: 'Cambio', delete: 'Borrado', categories: 'Categorías', restore: 'Antes de volver a una copia' };
  function describeVersion(v) {
    if (v.kind !== 'item') return describeKeys(v.keys);
    var c = state.historyCols[v.collection];
    return (c ? c.label : v.collection) + ' › ' + (v.action === 'categories' ? 'Categorías' : v.title) + (v.action === 'delete' ? ' (borrado)' : '');
  }

  function describeKeys(keys) {
    var idx = state.historyIndex, places = [];
    keys.forEach(function (k) {
      var w = idx[k];
      var place = w ? w.page + ' › ' + w.section : k;
      if (places.indexOf(place) < 0) places.push(place);
    });
    return places.slice(0, 3).join(', ') + (places.length > 3 ? ' y ' + (places.length - 3) + ' más' : '');
  }

  function renderHistory(items) {
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head' }, [h('h1', { text: 'Copias' })]));
    main.appendChild(h('p', { class: 'np-muted', text: 'Cada vez que se guarda un cambio, queda una copia de cómo estaba antes. Puedes recuperar un texto concreto o volver a una versión entera. (Las copias completas de la web las hace AFI aparte.)' }));
    if (!items.length) { main.appendChild(h('section', { class: 'np-card' }, [h('p', { class: 'np-muted', text: 'Aún no hay copias: se harán a partir del próximo cambio que se guarde.' })])); return; }
    var list = h('section', { class: 'np-card' });
    var lastDay = '';
    items.forEach(function (v) {
      var d = new Date(v.at);
      var day = d.toLocaleDateString(LOCALE, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
      if (day !== lastDay) { list.appendChild(h('h2', { class: 'np-day', text: day.charAt(0).toUpperCase() + day.slice(1) })); lastDay = day; }
      list.appendChild(h('button', { type: 'button', class: 'np-version-row', onclick: function () { openVersion(v.id); } }, [
        h('span', { class: 'np-muted', text: d.toLocaleTimeString(LOCALE, { hour: '2-digit', minute: '2-digit' }) }),
        h('span', { text: describeVersion(v) }),
        h('span', { class: 'np-tag', text: (SOURCES[v.source] || v.source) + (v.lang ? ' · ' + v.lang.toUpperCase() : '') })
      ]));
    });
    main.appendChild(list);
  }

  // Copia de un elemento de una colección: antes y ahora de cada campo, y volver a esa versión (o recuperarlo)
  function openItemVersion(v) {
    var c = state.historyCols[v.collection] || { label: v.collection, fields: [] };
    var labels = {}, types = {};
    (c.fields || []).forEach(function (f) { labels[f.name] = f.label; types[f.name] = f.type; });
    main.innerHTML = '';
    main.appendChild(h('header', { class: 'np-head' }, [
      h('div', {}, [h('button', { type: 'button', class: 'np-link', text: '← Todas las copias', onclick: function () { loadHistory(); } }),
        h('h1', { text: describeVersion(v) })]),
      h('button', { type: 'button', class: 'np-button np-secondary', text: v.deleted ? 'Recuperar el elemento' : 'Volver a esta versión', onclick: function () {
        if (!confirm(v.deleted ? 'Se vuelve a crear tal como estaba. ¿Seguimos?' : 'El elemento vuelve a estar como en esta copia; lo de ahora queda en otra copia. ¿Seguimos?')) return;
        post('api.php?a=history-restore&id=' + encodeURIComponent(v.id), {}).then(function (r) {
          if (!r.ok) { alert(r.error || 'No se ha podido'); return; }
          alert('Hecho.'); loadHistory();
        });
      } })
    ]));
    main.appendChild(h('p', { class: 'np-muted', text: 'Copia del ' + dateText(v.at) + ' · ' + (ITEM_ACTIONS[v.action] || v.action) + (SOURCES[v.source] ? ' · ' + SOURCES[v.source] : '') }));
    if (!v.changes.length) main.appendChild(h('section', { class: 'np-card' }, [h('p', { class: 'np-muted', text: 'Ahora está igual que en esta copia.' })]));
    v.changes.forEach(function (ch) {
      var name = ch.key === 'categories' ? 'Categorías' : (labels[ch.key] || ch.key);
      main.appendChild(h('section', { class: 'np-card' }, [
        h('div', { class: 'np-card-head' }, [h('h2', { text: name }), ch.lang ? h('span', { class: 'np-tag', text: ch.lang.toUpperCase() }) : null]),
        h('div', { class: 'np-compare' }, [
          h('div', {}, [h('div', { class: 'np-label', text: 'Antes' }), valueView(ch.before, types[ch.key])]),
          h('div', {}, [h('div', { class: 'np-label', text: 'Ahora' }), v.deleted ? h('em', { class: 'np-muted', text: '(borrado)' }) : valueView(ch.now, types[ch.key])])
        ])
      ]));
    });
  }

  function valueView(v, type) {
    if (v === null || v === undefined || v === '') return h('em', { class: 'np-muted', text: '(vacío)' });
    if (type === 'img') return h('img', { class: 'np-thumb', src: src(v), alt: '' });
    if (type === 'images' && Array.isArray(v)) return h('div', { class: 'np-thumbs' }, v.slice(0, 8).map(function (x) { return h('img', { class: 'np-thumb-mini', src: src(x), alt: '' }); }));
    if (type === 'file') return h('span', { text: String(v).split('/').pop() });
    if (Array.isArray(v)) return h('span', { text: 'Lista de ' + v.length + (v.length === 1 ? ' elemento' : ' elementos') });
    if (type === 'html') return h('span', { text: String(v).replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 400) });
    return h('span', { text: String(v) });
  }

  function openVersion(id) {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
    api('history-detail', { id: id }).then(function (d) {
      if (!d.ok) { failed(); return; }
      var v = d.version, idx = state.historyIndex;
      if (v.kind === 'item') return openItemVersion(v);
      main.innerHTML = '';
      main.appendChild(h('header', { class: 'np-head' }, [
        h('div', {}, [h('button', { type: 'button', class: 'np-link', text: '← Todas las copias', onclick: function () { loadHistory(); } }),
          h('h1', { text: 'Copia del ' + dateText(v.at) })]),
        h('button', { type: 'button', class: 'np-button np-secondary', text: 'Volver a esta versión', onclick: function () {
          if (!confirm('Todos los campos de esta copia volverán a como estaban antes de este cambio. Lo de ahora queda guardado en otra copia, por si quieres deshacerlo. ¿Seguimos?')) return;
          post('api.php?a=history-restore&id=' + encodeURIComponent(id), {}).then(function (r) {
            if (!r.ok) { alert(r.error || 'No se ha podido'); return; }
            alert('Hecho: la web vuelve a estar como en esta copia.');
            loadHistory();
          });
        } })
      ]));
      main.appendChild(h('p', { class: 'np-muted', text: 'Lo que había antes del cambio, y lo que hay ahora. ' + (SOURCES[v.source] ? 'Cambio hecho: ' + SOURCES[v.source].toLowerCase() + '.' : '') }));
      v.changes.forEach(function (c) {
        var w = idx[c.key] || { page: '', section: c.key, label: '', type: Array.isArray(c.before) ? 'collection' : 'text' };
        var same = JSON.stringify(c.before) === JSON.stringify(c.now);
        var card = h('section', { class: 'np-card' }, [
          h('div', { class: 'np-card-head' }, [h('h2', { text: (w.page ? w.page + ' › ' : '') + w.section + (w.label ? ' · ' + w.label : '') }),
            c.lang ? h('span', { class: 'np-tag', text: c.lang.toUpperCase() }) : null]),
          h('div', { class: 'np-compare' }, [
            h('div', {}, [h('div', { class: 'np-label', text: 'Antes' }), valueView(c.before, w.type)]),
            h('div', {}, [h('div', { class: 'np-label', text: 'Ahora' }), valueView(c.now, w.type)])
          ])
        ]);
        if (same) card.appendChild(h('p', { class: 'np-muted np-small', text: 'Ahora vuelve a estar igual que antes.' }));
        else card.appendChild(h('button', { type: 'button', class: 'np-button np-secondary np-small-button', text: 'Recuperar lo de antes', onclick: function (e) {
          var payload = { lang: c.lang, fields: {}, images: {}, collections: {}, source: 'panel' };
          var group = w.type === 'img' ? 'images' : Array.isArray(c.before) ? 'collections' : 'fields';
          payload[group][c.key] = c.before === null ? '' : c.before;
          e.target.disabled = true;
          post('save.php', payload).then(function (r) {
            if (!r.ok) { e.target.disabled = false; alert(r.error || 'No se ha podido'); return; }
            openVersion(id);
          });
        } }));
        main.appendChild(card);
      });
    }).catch(failed);
  }

  // ------------------------------------------------------------------ SEO y GEO (Pulsar, lib/seo.php)
  var FIX_LABELS = { title: 'Título de la pestaña', description: 'Descripción para Google' };
  var seo = { page: '', type: 'seo', data: null, running: false };

  function loadSeo() {
    main.innerHTML = '<p class="np-muted">' + t('Cargando…') + '</p>';
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
    var out = { fields: {}, images: {}, collections: {}, lang: state.lang, base: state.base, source: 'panel' };
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
    state.statusEl.textContent = t('Guardando…');
    post('save.php', payload(force)).then(function (d) {
      if (d.conflict) return conflict(d.conflict);
      if (!d.ok) { state.statusEl.textContent = t('Error: ' + (d.error || '')); state.saveBtn.disabled = false; return; }
      Object.keys(state.values).forEach(function (k) {
        state.original[k] = clone(state.values[k]);
        var target = payload().images[k] !== undefined && cfg.languages ? state.data.shared : state.data.values;
        target[k] = clone(state.values[k]);
      });
      state.values = {};
      state.base = d.base || state.base;
      markDirty();
      state.statusEl.textContent = t('Guardado ✓');
    }).catch(function () { state.statusEl.textContent = t('Error de red al guardar'); state.saveBtn.disabled = false; });
  }

  function conflict(keys) {
    var labels = {};
    state.pages.forEach(function (p) { p.sections.forEach(function (s) { s.fields.forEach(function (f) { labels[f.key] = s.title + ' › ' + f.label; }); }); });
    state.statusEl.textContent = t('Sin guardar: hay cambios de otra ventana');
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
