// Cronología (bloque timeline): una etapa cada vez, con la línea de etapas para pasarlas (clic, flechas, teclado o
// deslizando). En el editor (?admin) no se toca: se ven todas para poder editarlas.
(function () {
  if (document.body.classList.contains('neutron-editing') || /[?&]admin\b/.test(location.search)) return;
  document.querySelectorAll('[data-timeline]').forEach(function (box) {
    var items = [].slice.call(box.querySelectorAll('.b-timeline__item'));
    var nav = box.querySelector('.b-timeline__nav'), track = box.querySelector('.b-timeline__track');
    var prev = box.querySelector('[data-timeline-prev]'), next = box.querySelector('[data-timeline-next]');
    if (items.length < 2 || !nav || !track) return;
    var current = 0;
    var steps = items.map(function (it, i) {
      var when = it.querySelector('.b-timeline__when'), title = it.querySelector('h3');
      var b = document.createElement('button');
      b.type = 'button'; b.className = 'b-timeline__step'; b.setAttribute('role', 'tab');
      b.textContent = (title && title.textContent.trim()) || (when && when.textContent.trim()) || String(i + 1);
      if (when && title) b.title = when.textContent.trim();
      b.addEventListener('click', function () { show(i); });
      track.appendChild(b);
      return b;
    });
    function show(i, focus) {
      if (i < 0 || i >= items.length) return;
      box.style.setProperty('--dir', (i < current ? -18 : 18) + 'px');
      current = i;
      items.forEach(function (it, k) { it.classList.toggle('is-active', k === i); });
      steps.forEach(function (s, k) {
        s.classList.toggle('is-active', k === i); s.classList.toggle('is-done', k < i);
        s.setAttribute('aria-selected', k === i ? 'true' : 'false'); s.tabIndex = k === i ? 0 : -1;
      });
      track.style.setProperty('--progress', items.length > 1 ? i / (items.length - 1) : 0);
      prev.disabled = i === 0; next.disabled = i === items.length - 1;
      if (steps[i].scrollIntoView && track.scrollWidth > track.clientWidth) steps[i].scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' });
      if (focus) steps[i].focus();
    }
    prev.addEventListener('click', function () { show(current - 1); });
    next.addEventListener('click', function () { show(current + 1); });
    track.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight') { e.preventDefault(); show(current + 1, true); }
      if (e.key === 'ArrowLeft') { e.preventDefault(); show(current - 1, true); }
    });
    // Deslizar con el dedo sobre la etapa
    var x0 = null;
    box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
      if (x0 === null) return;
      var dx = e.changedTouches[0].clientX - x0; x0 = null;
      if (Math.abs(dx) > 50) show(current + (dx < 0 ? 1 : -1));
    });
    nav.hidden = false;
    box.classList.add('is-ready');
    show(0);
  });
})();
