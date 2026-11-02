/*
 * Faldón de cookies del núcleo (RGPD + art. 22.2 LSSI). Lo inyecta el render cuando el sitio tiene
 * site/cookies.json, junto a <script id="neutron-cookies-config"> (ver lib/cookies.php).
 *
 * - Nada que no sea técnico se carga sin consentimiento previo: iframes con data-consent-src y
 *   <script type="text/plain" data-consent="…"> esperan a que se acepte su categoría.
 * - Rechazar es tan fácil como aceptar; [data-cookie-settings] vuelve a abrir las opciones y
 *   [data-cookie-accept="categoría"] acepta una (el aviso de un vídeo o de un mapa).
 * - La elección se guarda 12 meses en una cookie técnica. Si se retira un consentimiento ya dado, se
 *   recarga la página para descargar lo que se había cargado.
 * - window.neutronCookies.has('media') dice si una categoría está aceptada; al cambiar la elección se
 *   lanza el evento "neutron:consent" en document.
 */
(function () {
  var cfgEl = document.getElementById('neutron-cookies-config');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent || '{}');
  var cats = cfg.categories || [];
  var t = cfg.texts || {};
  var MAX_AGE = 365 * 24 * 60 * 60;
  var banner, prefs, inputs = {};

  function read() {
    var m = document.cookie.match(new RegExp('(?:^|; )' + cfg.cookie + '=([^;]*)'));
    if (!m) return null;
    try {
      var c = JSON.parse(decodeURIComponent(m[1]));
      if (!c || c.v !== cfg.version) return null;
      // Formato de los faldones propios de cada sitio anteriores a Neutron 1.5.0: { v, media: true }
      if (!c.c) { c.c = {}; cats.forEach(function (cat) { if (c[cat.id]) c.c[cat.id] = true; }); }
      return c;
    } catch (e) { return null; }
  }

  function has(id) {
    var c = read();
    return !!(c && c.c[id]);
  }

  function save(chosen) {
    var before = read();
    var c = { v: cfg.version, c: {}, date: new Date().toISOString().slice(0, 10) };
    cats.forEach(function (cat) { if (chosen[cat.id]) c.c[cat.id] = true; });
    document.cookie = cfg.cookie + '=' + encodeURIComponent(JSON.stringify(c)) +
      '; max-age=' + MAX_AGE + '; path=/; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
    hide();
    var withdrawn = before && cats.some(function (cat) { return before.c[cat.id] && !c.c[cat.id]; });
    if (withdrawn) { location.reload(); return; }
    apply(c);
    document.dispatchEvent(new CustomEvent('neutron:consent', { detail: c.c }));
  }

  function categoryOf(el) {
    return el.getAttribute('data-consent') || (cats[0] && cats[0].id) || '';
  }

  // Carga lo que esperaba el consentimiento
  function apply(c) {
    if (!c) return;
    document.querySelectorAll('iframe[data-consent-src]').forEach(function (f) {
      if (!c.c[categoryOf(f)] || f.getAttribute('src')) return;
      f.setAttribute('src', f.getAttribute('data-consent-src'));
      if (f.parentNode.classList) f.parentNode.classList.add('is-loaded');
    });
    document.querySelectorAll('script[type="text/plain"][data-consent]').forEach(function (s) {
      if (!c.c[categoryOf(s)]) return;
      var run = document.createElement('script');
      Array.prototype.forEach.call(s.attributes, function (a) {
        if (a.name !== 'type' && a.name !== 'data-consent') run.setAttribute(a.name, a.value);
      });
      run.text = s.text;
      s.parentNode.replaceChild(run, s);
    });
  }

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  function button(cls, action, text) {
    var b = el('button', 'nc-btn' + (cls ? ' ' + cls : ''), text);
    b.type = 'button';
    b.setAttribute('data-nc-action', action);
    return b;
  }

  function pref(id, name, description, checked, disabled) {
    var row = el('div', 'nc-pref');
    var box = el('span', 'nc-switch');
    var input = document.createElement('input');
    input.type = 'checkbox';
    input.id = 'nc-' + id;
    input.checked = checked;
    input.disabled = disabled;
    box.appendChild(input);
    var label = document.createElement('label');
    label.htmlFor = input.id;
    label.appendChild(el('strong', '', name));
    if (description) label.appendChild(el('small', '', description));
    row.appendChild(box);
    row.appendChild(label);
    return { row: row, input: input };
  }

  function build() {
    banner = el('div', 'nc-banner');
    banner.setAttribute('role', 'region');
    banner.setAttribute('aria-label', t.label || '');
    banner.hidden = true;
    if (cfg.accent) banner.style.setProperty('--nc-accent', cfg.accent);

    var inner = el('div', 'nc-inner');
    var text = el('div', 'nc-text');
    text.appendChild(el('p', 'nc-title', t.title));
    // "… a la {policy}." → el texto con el enlace a la política
    var p = el('p');
    var parts = String(t.text || '').split('{policy}');
    p.appendChild(document.createTextNode(parts[0]));
    if (parts.length > 1) {
      if (cfg.policy) {
        var a = el('a', '', t.policy);
        a.href = cfg.policy;
        p.appendChild(a);
      } else {
        p.appendChild(document.createTextNode(t.policy || ''));
      }
      p.appendChild(document.createTextNode(parts.slice(1).join('')));
    }
    text.appendChild(p);
    inner.appendChild(text);

    var actions = el('div', 'nc-actions');
    var configure = button('nc-btn--ghost', 'config', t.configure);
    configure.setAttribute('aria-expanded', 'false');
    configure.setAttribute('aria-controls', 'nc-prefs');
    actions.appendChild(configure);
    actions.appendChild(button('', 'reject', t.reject));
    actions.appendChild(button('', 'accept', t.accept));
    inner.appendChild(actions);

    prefs = el('div', 'nc-prefs');
    prefs.id = 'nc-prefs';
    prefs.hidden = true;
    prefs.appendChild(pref('technical', t.technical, t.technical_description, true, true).row);
    cats.forEach(function (cat) {
      var r = pref(cat.id, cat.name, cat.description, false, false);
      inputs[cat.id] = r.input;
      prefs.appendChild(r.row);
    });
    var saveRow = el('div', 'nc-actions');
    saveRow.appendChild(button('', 'save', t.save));
    prefs.appendChild(saveRow);
    inner.appendChild(prefs);
    banner.appendChild(inner);
    document.body.appendChild(banner);

    banner.addEventListener('click', function (e) {
      var b = e.target.closest('[data-nc-action]');
      if (!b) return;
      var action = b.getAttribute('data-nc-action');
      var all = {};
      if (action === 'accept') { cats.forEach(function (cat) { all[cat.id] = true; }); save(all); }
      else if (action === 'reject') save({});
      else if (action === 'save') { cats.forEach(function (cat) { all[cat.id] = inputs[cat.id].checked; }); save(all); }
      else if (action === 'config') togglePrefs(prefs.hidden);
    });
  }

  function togglePrefs(open) {
    prefs.hidden = !open;
    banner.querySelector('[data-nc-action="config"]').setAttribute('aria-expanded', open ? 'true' : 'false');
  }

  function show(withPrefs) {
    if (!banner) return;
    var c = read();
    cats.forEach(function (cat) { inputs[cat.id].checked = !!(c && c.c[cat.id]); });
    togglePrefs(!!withPrefs);
    banner.hidden = false;
    var first = withPrefs && cats.length ? inputs[cats[0].id] : banner.querySelector('.nc-btn');
    first.focus();
  }

  function hide() { if (banner) banner.hidden = true; }

  function init() {
    var c = read();
    apply(c);
    window.neutronCookies = { has: has, open: function () { show(true); } };
    // Solo cookies técnicas: no hace falta faldón (ni el enlace para configurarlas)
    if (!cats.length) {
      document.querySelectorAll('[data-cookie-settings]').forEach(function (b) { b.hidden = true; });
      return;
    }
    build();
    // En el editor (?admin) no molestamos con el faldón
    var isAdmin = !!document.getElementById('neutron-config');
    if (!c && !isAdmin) banner.hidden = false;

    document.addEventListener('click', function (e) {
      if (e.target.closest('[data-cookie-settings]')) {
        e.preventDefault();
        show(true);
        return;
      }
      // data-cookie-accept="media" (o el antiguo data-cookie-accept-media)
      var acc = e.target.closest('[data-cookie-accept], [data-cookie-accept-media]');
      if (!acc) return;
      e.preventDefault();
      var id = acc.getAttribute('data-cookie-accept') || (cats[0] && cats[0].id);
      var chosen = {};
      var cur = read();
      cats.forEach(function (cat) { chosen[cat.id] = !!(cur && cur.c[cat.id]) || cat.id === id; });
      save(chosen);
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
