/*
 * Vídeos y pases de fotos del texto con formato (el cuerpo de las entradas). Lo inyecta index.php en las páginas que
 * tienen <figure data-embed="…">, con <script id="neutron-embeds-config"> (ver lib/embeds.php).
 *
 * - <figure data-embed="video" data-src="https://www.youtube-nocookie.com/embed/ID">: el reproductor en 16:9. Con una
 *   categoría de cookies (config.consent), el iframe lleva data-consent y lo carga cookies.js al aceptarla; hasta
 *   entonces, un aviso con [data-cookie-accept]. Sin categorías, se carga directamente.
 * - <figure data-embed="slider"> con sus <img>: una foto cada vez, flechas, contador, teclado y deslizamiento.
 */
(function () {
  var cfgEl = document.getElementById('neutron-embeds-config');
  var cfg = cfgEl ? JSON.parse(cfgEl.textContent || '{}') : {};
  var t = cfg.texts || {};

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text) e.textContent = text;
    return e;
  }

  function consented(id) {
    return !!(window.neutronCookies && window.neutronCookies.has && window.neutronCookies.has(id));
  }

  function video(fig) {
    var src = fig.getAttribute('data-src') || '';
    if (!/^https:\/\/(www\.youtube-nocookie\.com\/embed\/[\w-]{11}|player\.vimeo\.com\/video\/\d+)$/.test(src)) return;
    var frame = el('div', 'ne-video');
    var iframe = el('iframe');
    iframe.title = t.title || 'Vídeo';
    iframe.setAttribute('loading', 'lazy');
    iframe.setAttribute('allow', 'fullscreen; picture-in-picture; encrypted-media');
    iframe.setAttribute('allowfullscreen', '');
    iframe.setAttribute('referrerpolicy', 'strict-origin-when-cross-origin'); // YouTube no reproduce sin saber desde qué web
    frame.appendChild(iframe);
    var yt = /youtube-nocookie\.com\/embed\/([\w-]{11})/.exec(src);
    if (cfg.consent && !consented(cfg.consent.id)) {
      // Lo carga cookies.js cuando se acepta la categoría (también desde el faldón)
      iframe.setAttribute('data-consent', cfg.consent.id);
      iframe.setAttribute('data-consent-src', src);
      if (yt) {
        var thumb = el('img', 'ne-video__thumb');
        thumb.alt = '';
        thumb.loading = 'lazy';
        thumb.src = 'https://i.ytimg.com/vi/' + yt[1] + '/hqdefault.jpg';
        frame.appendChild(thumb);
      }
      var notice = el('div', 'ne-video__consent');
      notice.appendChild(el('p', '', (t.video || '').replace('{category}', cfg.consent.name)));
      var b = el('button', 'ne-video__accept', t.accept || 'OK');
      b.type = 'button';
      b.setAttribute('data-cookie-accept', cfg.consent.id);
      notice.appendChild(b);
      frame.appendChild(notice);
    } else {
      iframe.src = src;
      frame.classList.add('is-loaded');
    }
    fig.appendChild(frame);
  }

  function slider(fig) {
    var imgs = Array.prototype.slice.call(fig.querySelectorAll('img'));
    if (!imgs.length) return;
    var track = el('div', 'ne-slider__track');
    track.tabIndex = 0;
    imgs.forEach(function (img) {
      var slide = el('div', 'ne-slider__slide');
      slide.appendChild(img);
      track.appendChild(slide);
    });
    fig.appendChild(track);
    if (imgs.length < 2) return;
    var prev = el('button', 'ne-slider__btn ne-slider__btn--prev', '‹');
    var next = el('button', 'ne-slider__btn ne-slider__btn--next', '›');
    prev.type = next.type = 'button';
    prev.setAttribute('aria-label', t.prev || '‹');
    next.setAttribute('aria-label', t.next || '›');
    var count = el('span', 'ne-slider__count');
    count.setAttribute('aria-live', 'polite');
    fig.appendChild(prev);
    fig.appendChild(next);
    fig.appendChild(count);

    function current() { return Math.round(track.scrollLeft / (track.clientWidth || 1)); }
    function go(i) {
      i = (i + imgs.length) % imgs.length; // de la última vuelve a la primera
      track.scrollTo({ left: i * track.clientWidth, behavior: 'smooth' });
    }
    function update() { count.textContent = (current() + 1) + ' / ' + imgs.length; }
    prev.addEventListener('click', function () { go(current() - 1); });
    next.addEventListener('click', function () { go(current() + 1); });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { e.preventDefault(); go(current() - 1); }
      if (e.key === 'ArrowRight') { e.preventDefault(); go(current() + 1); }
    });
    var timer;
    track.addEventListener('scroll', function () { clearTimeout(timer); timer = setTimeout(update, 60); });
    update();
  }

  function init() {
    document.querySelectorAll('figure[data-embed="video"]').forEach(video);
    document.querySelectorAll('figure[data-embed="slider"]').forEach(slider);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
