// Buscador de la web (lib/search.php): el índice /search.json se baja la primera vez que se usa y la búsqueda se hace
// aquí, sin acentos ni mayúsculas, con todas las palabras (más peso en el título). Marcado en la plantilla:
//
//   <form data-search role="search">
//     <input type="search" name="q" placeholder="Cerca…" aria-label="Cerca">
//     <div data-search-results></div>
//   </form>
//
// Textos propios: data-search-empty="No hi ha res per «{q}»" en el form. Con ?q= en la dirección, busca al abrir.
(function () {
  var cfgEl = document.getElementById('neutron-search-config');
  if (!cfgEl) return;
  var cfg = JSON.parse(cfgEl.textContent || '{}');
  var index = null, loading = null;
  var fold = function (s) { return (s || '').normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase(); };
  var esc = function (s) { return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); };

  function load() {
    if (index) return Promise.resolve(index);
    if (!loading) loading = fetch(cfg.index, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
      index = d.map(function (e) { return { e: e, t: fold(e.t), x: fold(e.x) }; });
      return index;
    });
    return loading;
  }

  // Un trozo del texto alrededor de la primera coincidencia, con las palabras resaltadas
  function snippet(text, words) {
    var f = fold(text), at = -1;
    words.forEach(function (w) { var i = f.indexOf(w); if (i >= 0 && (at < 0 || i < at)) at = i; });
    var start = Math.max(0, at - 60), cut = text.slice(start, start + 180);
    var out = esc((start > 0 ? '…' : '') + cut + (start + 180 < text.length ? '…' : ''));
    words.forEach(function (w) {
      if (w.length < 2) return;
      var re = new RegExp('(' + w.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'gi');
      out = out.replace(new RegExp('[^<>]+(?=<|$)', 'g'), function (seg) {
        var fs = fold(seg), res = '', i = 0, m;
        re.lastIndex = 0;
        while ((m = re.exec(fs))) { res += seg.slice(i, m.index) + '<mark>' + seg.slice(m.index, m.index + m[0].length) + '</mark>'; i = m.index + m[0].length; }
        return res + seg.slice(i);
      });
    });
    return out;
  }

  function search(q) {
    var words = fold(q).split(/\s+/).filter(function (w) { return w.length > 0; });
    if (!words.length) return [];
    return index.map(function (r) {
      var score = 0;
      for (var i = 0; i < words.length; i++) {
        var w = words[i], inT = r.t.indexOf(w) >= 0, inX = r.x.indexOf(w) >= 0;
        if (!inT && !inX) return null;
        score += (inT ? 10 : 0) + (inX ? 1 : 0) + (r.t.indexOf(w) === 0 ? 5 : 0);
      }
      return { r: r, score: score };
    }).filter(Boolean).sort(function (a, b) { return b.score - a.score; }).slice(0, 12);
  }

  function setup(form) {
    var input = form.querySelector('input[type=search], input[name=q]');
    var box = form.querySelector('[data-search-results]');
    if (!input || !box) return;
    box.setAttribute('aria-live', 'polite');
    var emptyText = form.getAttribute('data-search-empty') || cfg.texts.empty;
    var timer;
    function run() {
      var q = input.value.trim();
      if (q.length < 2) { box.innerHTML = ''; box.hidden = true; return; }
      load().then(function () {
        var res = search(q), words = fold(q).split(/\s+/).filter(Boolean);
        box.hidden = false;
        if (!res.length) { box.innerHTML = '<p class="nsearch-empty">' + esc(emptyText.replace('{q}', q)) + '</p>'; return; }
        box.innerHTML = '<ul class="nsearch-list">' + res.map(function (x) {
          var e = x.r.e, href = /^(https?:|\/)/.test(e.u) ? e.u : cfg.root + e.u;
          return '<li><a href="' + esc(href) + '"><span class="nsearch-kind">' + esc(e.k === 'page' ? cfg.texts.page : e.k) + '</span>' +
            '<strong>' + snippet(e.t, words) + '</strong>' + (e.x ? '<span class="nsearch-text">' + snippet(e.x, words) + '</span>' : '') + '</a></li>';
        }).join('') + '</ul>';
      }).catch(function () { box.hidden = true; });
    }
    input.addEventListener('focus', function () { load(); });
    input.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(run, 120); });
    input.addEventListener('keydown', function (e) { if (e.key === 'Escape') { input.value = ''; run(); } });
    form.addEventListener('submit', function (e) { e.preventDefault(); run(); var a = box.querySelector('a'); if (a && box.querySelectorAll('a').length === 1) location.href = a.href; });
    var q = new URLSearchParams(location.search).get('q');
    if (q) { input.value = q; run(); }
  }
  document.querySelectorAll('form[data-search]').forEach(setup);
})();
